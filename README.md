# SME ERP

A modular SME ERP / business operating system built for a single company operating across
multiple branches. Its distinguishing capabilities are **order attribution** — every order can say
where the business came from and who is owed for it — and a **commission engine that explains
every ringgit it computes**.

Planning, architecture decisions and per-phase gate evidence live in [`Planning.md`](Planning.md).

---

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.3+ |
| Database | **PostgreSQL 16** |
| Frontend | Inertia 3 + React 19 + TypeScript (strict) + Bootstrap 5, built with Vite |
| Queue / cache | Redis, Laravel Horizon |
| Keys | ULID on every table |
| Money | `NUMERIC(15,4)` with a bcmath `Money` value object — never floats |
| Authorization | `spatie/laravel-permission` in teams mode + a custom data-scope layer |
| Tests | Pest, PHPStan level 6, Pint |

---

## Requirements

- PHP **8.3+** with `pdo_pgsql`, `bcmath`, `intl`
- PostgreSQL **16** (features used require 15+, notably `UNIQUE NULLS NOT DISTINCT`)
- Redis 7+
- Node **20.19+** (Vite 7 minimum)
- Composer 2

> **PostgreSQL client version matters.** `pg_dump`/`pg_restore` refuse to work against a newer
> server. The backup scripts resolve a client matching the server major version automatically —
> see [Backups](#backups).

---

## Installation

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

createdb sme_erp
createdb sme_erp_test

php artisan migrate
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=ModuleSeeder

npm run build
php artisan serve
```

Never use `php artisan serve` for anything but local work — see [Deployment](DEPLOYMENT.md).

---

## Environment variables

| Key | Purpose | Notes |
|---|---|---|
| `APP_ENV` | Environment | `production` on the server |
| `APP_DEBUG` | Debug output | **must be `false` in production** |
| `APP_KEY` | Encryption key | generated, never committed |
| `DB_CONNECTION` | Driver | `pgsql` only — MySQL is not supported |
| `DB_HOST` / `DB_PORT` | Database host | port is commonly `5433` on Homebrew |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Credentials | |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_CLIENT` | Queue and cache | `predis` unless phpredis is installed |
| `QUEUE_CONNECTION` | Queue driver | `redis` |
| `CACHE_STORE` | Cache driver | `redis` |
| `SESSION_DRIVER` | Sessions | `database` |
| `FILESYSTEM_DISK` | Storage | **`local`** — never `public`, business documents are private |
| `MAIL_*` | Mail | |

---

## Running the tests

```bash
composer gate          # Pint + PHPStan + Pest
./vendor/bin/pest      # tests only
```

Six suites, and each has a distinct job:

| Suite | What it protects |
|---|---|
| `Unit` | Money arithmetic, enums, the permission registry |
| `Architecture` | Source-level rules: no comments, strict types, no unscoped queries, one listener per event, status columns written only by the state machine |
| `Isolation` | Company isolation and data scope — **reflection-driven**, so a new model is covered the moment it exists |
| `Feature` | Domain behaviour end to end, plus query budgets |
| `Concurrency` | Real multi-process races: document numbering, stock reservation, order transitions |
| `Security` | Hardening assertions and the PDPA erasure path |

The `Isolation` suite discovers models by reflection. **Adding a company-scoped model without a
seed recipe fails the suite** — that is intentional.

---

## Scheduled work

All scheduled work is registered. Add the single Laravel cron entry (see
[DEPLOYMENT.md](DEPLOYMENT.md)) and these run themselves:

| Command | Purpose | Cadence |
|---|---|---|
| `erp:sweep-reservations` | Release expired speculative stock holds | every 5 minutes |
| `erp:rebuild-rollups` | Dashboard figures | every 15 minutes |
| `erp:rebuild-rollups --date=<yesterday>` | Settle the previous day | daily 02:15 |
| `erp:backup` | Dump, prune, copy offsite | daily 02:00 |
| `erp:verify-backup` | Restore rehearsal | Mondays 03:00 |

Each is `withoutOverlapping()` and `onOneServer()`. The rollup and sweep commands iterate every
active company and **continue past a failing one** rather than aborting the whole run.

---

## Folder structure

```
app/
  Contracts/        Scopeable
  Domain/           the business logic, one namespace per bounded context
    Approvals/ Attribution/ Commission/ Finance/ Inventory/
    Numbering/ Orders/ Pricing/ Privacy/ Purchasing/ Reporting/
  Enums/            status axes, roles, data scopes
  Http/             thin controllers and middleware
  Models/           Eloquent models (company-scoped by trait)
  Policies/         permission + data scope, sharing one resolver with list queries
  Services/         cross-cutting only (access, audit)
  Support/          CompanyContext, Money, PermissionRegistry
database/migrations/
resources/js/       Inertia pages, layouts, shared components
scripts/            backup.sh, restore.sh
tests/              Unit / Architecture / Isolation / Feature / Concurrency / Security
```

---

## Backups

```bash
php artisan erp:backup           # dump, prune, copy offsite
php artisan erp:verify-backup    # restore the latest dump and prove it is usable

./scripts/backup.sh                          # the underlying dump script
./scripts/restore.sh <dump-file> <target-db> # restore into a named database
```

`erp:backup` runs nightly at 02:00 and `erp:verify-backup` weekly on Monday at 03:00. **The
rehearsal is scheduled, not a one-off** — a restore verified once decays.

`erp:verify-backup` restores the newest dump into a scratch database, compares table, trigger,
CHECK and foreign-key counts against the live schema, proves the restored copy still refuses a
journal edit, then drops the scratch database. It exits non-zero on any mismatch.

Both scripts resolve a `pg_dump`/`pg_restore` whose **major version matches the running server**,
because the client on `PATH` is frequently older and refuses outright. `backup.sh` also refuses to
write a dump under 1 KB, so an empty file is never mistaken for a backup.

A failed dump deletes its own partial file, so a truncated file can never be mistaken for a
backup. Filenames carry a random suffix so two runs in the same second cannot overwrite each other.

Set `BACKUP_OFFSITE_ENABLED=true` and `BACKUP_OFFSITE_COMMAND` (with `{file}` as the placeholder)
to copy dumps off the machine. Enabling it without a command is a hard error — *a backup that lives
only on the machine it protects is not a backup.*

**The restore has been rehearsed, not assumed.** See `Planning.md` Appendix L and Appendix N.

---

## Default accounts

**There are none, deliberately.** No seeder creates a user. The first account must be created
during deployment — see [DEPLOYMENT.md](DEPLOYMENT.md) — so no known-credential account can
ever reach production.

---

## Known limitations

This is a correct and heavily tested domain engine with a **limited user interface**. Before it is
usable by staff, the screens listed in `Planning.md` under each phase's *carried forward* section
must be built. The largest gaps:

- Only authentication, branch administration, the audit log and dashboards have screens.
  Customers, products, orders, inventory, purchasing, invoices and commission are service-layer only.
- No exports.
- No credit notes.
- Landed cost **is** apportioned into `average_cost`, and order lines record `unit_cost_source`.
  What remains is a data question, not a code one: if purchase orders carry estimated prices or
  freight invoices are never entered, the average will faithfully average wrong numbers
  (see `Planning.md` Q-18).
