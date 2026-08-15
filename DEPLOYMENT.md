# Deployment

Target: a single Linux VPS running Nginx, PHP-FPM 8.4+, PostgreSQL 16 and Redis 7.

> **Never deploy with `php artisan serve`.** It is single-threaded; one long request blocks every
> other. Use Nginx + PHP-FPM.

---

## 1. Server requirements

```bash
php -v                      # 8.4 or newer — the lock file needs it
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

php artisan erp:sync-roles

php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Create the first company and owner

No seeder creates a user, by design. Create the first account with the interactive command, which
never accepts the password as an argument:

```bash
php artisan erp:create-owner
```

It prompts for the company name, the owner's name and email, then twice for a password (minimum 12
characters). It creates the company, provisions its roles, adds a default HQ branch and Main
warehouse, assigns the `owner` role and sets the active company.

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
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
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
- [ ] The sidebar shows every module the signed-in role should have, and no entry 404s
- [ ] A salesperson signed in sees no Branches, Suppliers or Inventory entry
- [ ] A salesperson POSTing directly to `/orders/{id}/transition` with `status=approved` gets 403
- [ ] A storekeeper POSTing directly to `/supplier-bills/{id}/approve` gets 403
- [ ] A non-owner administrator cannot select `owner` when adding a person, and a posted
      `role=owner` is refused
- [ ] The owner cannot demote or deactivate themselves, and cannot be demoted while they are the
      only active owner
- [ ] Every account added through **People** changes its password from `/profile` before use
- [ ] Receiving goods against a purchase order moves stock and writes a `stock_movements` row

---

## 8. Rollback

> **After any upgrade, run `php artisan erp:sync-roles`.** Permissions added by a release do not
> reach existing companies until you do. Tuned data scopes are preserved.

```bash
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache && php artisan route:cache
sudo systemctl reload php8.4-fpm
```

**Migrations are not automatically reversible in production.** Every migration has a `down()`, but
several drop tables that hold financial records. Restore from the pre-deploy backup instead of
rolling a migration back, and take that backup *before* the risky DDL, not after it goes wrong.


---

## Billplz (online payment collection)

Optional. Leave `BILLPLZ_ENABLED=false` and the "Request online payment" button never appears.

```
BILLPLZ_ENABLED=true
BILLPLZ_SANDBOX=true          # false only once a sandbox payment has been proven end to end
BILLPLZ_API_KEY=
BILLPLZ_X_SIGNATURE_KEY=
BILLPLZ_COLLECTION_ID=
```

All three credentials are required together — the client refuses to raise a bill unless every one is
present, and an absent X-Signature key makes **every** callback fail closed rather than open.

**The callback URL must be reachable from the public internet.** Billplz calls
`POST https://your-host/payments/billplz/callback` server-to-server; it is not a browser redirect and
will not traverse a firewall, a VPN or `localhost`. If that route cannot be reached, bills are still
created and paid, but no invoice will ever settle. Confirm it with the Billplz sandbox before going
live:

1. Set `BILLPLZ_SANDBOX=true` and raise a payment link from any issued invoice.
2. Pay it with a sandbox account.
3. Confirm the invoice moves to `paid` **on its own**, with a journal entry and a cash-flow row.
4. Check `payment_intents.last_callback` holds the payload Billplz actually sent.

Only step 3 proves the signature algorithm here agrees with theirs. Until it has been done once, the
integration is unverified — every test in the suite fakes the HTTP layer.

### Automatic collection on subscriptions

A subscription switched to **collect online** gets a payment link raised for every unpaid invoice it
produces. That is a scheduled sweep, not a queued job, so it needs no queue worker — only the cron
entry the scheduler already requires:

```
erp:bill-subscriptions   01:30    raises the invoices
erp:raise-payment-links  01:45    raises a Billplz bill for each unpaid one
```

The sweep is idempotent: it skips any invoice that already has a live link, and any invoice that is
paid or void. Whatever fails tonight is retried tomorrow. If Billplz is not configured it does
nothing and says so, rather than failing the run.

**Switching this on with live credentials raises real bills on the next sweep.** Prove the sandbox
loop above first.

Rotating the X-Signature key in the Billplz dashboard invalidates in-flight callbacks. Rotate it when
nothing is outstanding, or expect to reconcile by hand.
