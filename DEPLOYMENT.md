# Deployment

Target: a single Linux VPS running Nginx, PHP-FPM 8.3+, PostgreSQL 16 and Redis 7.

> **Never deploy with `php artisan serve`.** It is single-threaded; one long request blocks every
> other. Use Nginx + PHP-FPM.

---

## 1. Server requirements

```bash
php -v                      # 8.3 or newer
php -m | grep -E 'pdo_pgsql|bcmath|intl'
psql -V                     # client
redis-server --version      # 7+
node -v                     # 20.19+
```

Confirm the **server** version, not the client binary:

```bash
psql -h 127.0.0.1 -p 5432 -U postgres -d postgres -tAc "select version();"
```

This distinction has bitten this project three times, including a backup that failed outright. A
PG14 client reports 14 while the server runs 16, and `pg_dump` refuses across that gap.

---

## 2. First deploy

```bash
git clone <repo> /var/www/sme-erp && cd /var/www/sme-erp

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
# edit .env — see the table in README.md

sudo -u postgres createdb sme_erp
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=ModuleSeeder --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Create the first company and owner

No seeder creates a user, by design. Create the first account interactively via `php artisan tinker`
so no known credential is ever committed:

```php
$company = App\Models\Company::create(['name' => 'Client Sdn Bhd', 'slug' => 'client']);
app(App\Services\Access\RoleProvisioner::class)->provision($company);

$user = App\Models\User::create([
    'name' => 'Owner Name',
    'email' => 'owner@client.test',
    'password' => 'CHANGE-THIS-IMMEDIATELY',
]);

app(App\Support\CompanyContext::class)->runAs($company->id, function () use ($company, $user) {
    app(Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    App\Models\Branch::create(['code' => 'HQ', 'name' => 'Head Office', 'is_default' => true]);
    App\Models\Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_default' => true]);
    App\Models\CompanyUser::create(['user_id' => $user->id, 'role' => 'owner', 'is_active' => true]);
    $user->assignRole('owner');
});

$user->forceFill(['active_company_id' => $company->id])->save();
```

Then have the owner change the password before anyone else is given access.

---

## 3. Queue workers

```
[program:sme-erp-horizon]
command=php /var/www/sme-erp/artisan horizon
autostart=true
autorestart=true
user=www-data
stopwaitsecs=3600
```

Horizon is gated by the `viewHorizon` ability, which requires `modules.manage`. Verify after deploy
that an unauthenticated request to `/horizon` is refused.

---

## 4. Scheduler

```
* * * * * cd /var/www/sme-erp && php artisan schedule:run >> /dev/null 2>&1
```

That single entry is all that is needed. Every job — rollups, the reservation sweep, the nightly
backup and the weekly restore rehearsal — is already registered in `routes/console.php`. Confirm
with `php artisan schedule:list` after deploy.

---

## 5. Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name erp.client.test;
    root /var/www/sme-erp/public;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    index index.php;
    charset utf-8;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Redirect port 80 to 443. The document root is `public/` — never the project root, or `.env` becomes
fetchable.

---

## 6. Backups

Backups run through the scheduler, so the only cron entry needed is the Laravel one in section 4.

| Command | When |
|---|---|
| `erp:backup` | nightly 02:00 |
| `erp:verify-backup` | Mondays 03:00 |

Set these in `.env` so dumps leave the machine:

```
BACKUP_DIRECTORY=/var/backups/sme-erp
BACKUP_KEEP_DAYS=14
BACKUP_OFFSITE_ENABLED=true
BACKUP_OFFSITE_COMMAND=rsync -az {file} backup@offsite.example:/srv/sme-erp/
```

Enabling offsite without a command is a hard error, by design.

**The weekly rehearsal is the point.** It restores the newest dump into a scratch database,
compares table, trigger, CHECK and foreign-key counts against the live schema, proves the restored
copy still refuses a journal edit, and exits non-zero on any mismatch. **Alert on that exit code** —
a backup nobody has restored is a hope, not a backup.

## 7. Post-deploy verification

- [ ] `https://…/` redirects to `/dashboard`, and a guest is redirected to `/login`
- [ ] Owner can log in; the login writes an `audit_logs` row with action `logged_in`
- [ ] Dashboard renders and shows the role-appropriate variant
- [ ] A salesperson account sees only its own figures
- [ ] `/horizon` is refused to an unauthenticated request
- [ ] `curl -I https://…/storage/anything` returns 404 — the private disk serves no route
- [ ] `.env` is not fetchable over HTTP
- [ ] `php artisan about` shows `Debug Mode: OFF` and `Environment: production`
- [ ] `php artisan erp:backup` writes a dump, and `php artisan erp:verify-backup` exits 0
- [ ] Queue workers are processing (`php artisan horizon:status`)

---

## 8. Rollback

```bash
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache && php artisan route:cache
sudo systemctl reload php8.3-fpm
```

**Migrations are not automatically reversible in production.** Every migration has a `down()`, but
several drop tables that hold financial records. Restore from the pre-deploy backup instead of
rolling a migration back, and take that backup *before* the risky DDL, not after it goes wrong.
