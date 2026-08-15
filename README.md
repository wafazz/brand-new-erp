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

Nothing is scheduled yet. Before production, these must be wired into the scheduler:

| Command | Purpose | Suggested cadence |
|---|---|---|
| `RollupService::rebuildSales()` | Dashboard figures | every 15 minutes, plus a nightly full day rebuild |
| `RollupService::rebuildCommission()` | Commission dashboard | hourly |
| `InventoryService::sweepExpired()` | Release expired speculative stock holds | every 5 minutes |

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
./scripts/backup.sh                          # writes storage/backups/<db>-<utc>.dump
./scripts/restore.sh <dump-file> <target-db> # restores into a fresh database
```

Both scripts resolve a `pg_dump`/`pg_restore` whose **major version matches the running server**,
because the client on `PATH` is frequently older and refuses outright. `backup.sh` also refuses to
write a dump under 1 KB, so an empty file is never mistaken for a backup.

**The restore has been rehearsed, not assumed.** See `Planning.md` Appendix L for the verified
result, including confirmation that triggers, CHECK constraints and foreign keys survive the
round-trip and that the restored database still refuses to edit the journal.

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
- Landed cost is not apportioned into `unit_cost`, which **margin-based commission depends on**
  (see `Planning.md` Q-18 and R-14).
