# Security review brief

**For the reviewer.** This document exists so you do not spend your first day working out what the
system does. It states what the system claims, how those claims were tested, where I think the weak
points are, and what I already know is missing.

Everything here was written by the person who wrote the code. **Treat it as the claim under test,
not as evidence.**

---

## 1. What this is

A single-tenant-per-database-row SME ERP: sales orders, invoicing, inventory, purchasing, commission
and marketing attribution, for a Malaysian company operating across multiple branches.

| | |
|---|---|
| Backend | Laravel 12, PHP 8.4 |
| Database | PostgreSQL 16 |
| Frontend | Inertia 3 + React 19 + TypeScript, server-rendered routing, no separate API |
| Auth | Session cookie, single `web` guard, no tokens, no SSO, no 2FA |
| Authorization | `spatie/laravel-permission` in teams mode + a custom data-scope layer |
| Routes | 108 total, 54 state-changing |
| Roles | 11 | 
| Permissions | 65 |
| Data scopes | 5 (own, team, branch, company, all) |
| Tests | 1,496 passing / 2,879 assertions |

There is **no public API, no webhook receiver, and no file upload**. Every route requires a session
except `GET|POST /login`.

---

## 2. The claim under test

> A signed-in user can reach exactly the records their role and data scope permit, through **any**
> route — list, detail, mutation, or report — and no more.

That is the whole security posture. Injection, XSS and CSRF are handled by the framework and pinned
by tests; **they are not where I expect you to find anything.** The interesting failures in this
system are authorization logic errors.

### Where the boundary is

Authorization is enforced in **controllers and policies**. React hides buttons for usability only —
the brief this was built to states it explicitly:

> *"Frontend authorization is for UX. Backend authorization is the actual security boundary."*

Every privileged action has a test that POSTs directly to the endpoint with the button hidden and
asserts 403.

### The two layers

1. **Permission** — does this role hold `orders.approve` at all? (`spatie`, per company)
2. **Data scope** — of the records this permission covers, which ones? Resolved by
   `App\Services\Access\ScopeResolver` and applied as a query constraint via the `visibleTo()` scope
   on the model, and via `BasePolicy::allowsRecord()` for single records. **Both share one resolver**
   — that was deliberate, so a list and a detail page cannot disagree.

Company isolation is separate and lower: a global Eloquent scope on `BelongsToCompany`, backed by
`CompanyContext`, which throws if no company is bound.

---

## 3. Start here — what I would attack

Ordered by where I think the risk actually is.

### 3.1 Raw query builders bypass the global scope

`DB::table()` does **not** apply the company scope. I found this in P5 after writing twelve
attribution reports that would have summed revenue across companies. It is now guarded by an
architecture test, but the guard matches on source text and can be evaded.

```
grep -rn "DB::table(" app/     # 17 occurrences as of this brief
```

Every one should carry an explicit `->where('company_id', …)`. The architecture guard
(`tests/Architecture/GuardsTest.php`, *"it scopes every raw query builder usage to a company"*)
only asserts that a **file** using `DB::table()` mentions `company_id` **somewhere**. A file with
two raw queries where only one is scoped would pass. **Check every call site, not every file.**

### 3.2 Scope columns that do not exist

`Invoice::ownerColumn()` returns `null`. So `invoices.view` at `own` scope resolves to *nothing* —
not to "your invoices". That is correct, but the same pattern applied to a model where it is *not*
intended would silently widen or narrow access.

Check every `implements Scopeable` model (17 of them) and ask whether its `ownerColumn` and
`branchColumn` match what an administrator setting that scope would expect.

### 3.3 Privilege escalation through user administration

`App\Domain\Access\AccessAdministrator` enforces four rules:

- you cannot grant a role carrying a permission you do not hold
- you cannot change your own access
- the last active owner cannot be demoted or deactivated
- you cannot set a role's data scope wider than your own on that permission, nor edit a role you hold

**Try to break each.** Note that the escalation check runs only when the role actually *changes* —
that was a deliberate fix, and it is exactly the kind of decision that deserves a second opinion.

### 3.4 The scope-editing screen

`/admin/roles` lets `roles.update` holders change any role's data scope. The guard is
`DataScope::covers()`. Two attacks worth trying: widen a role you do not hold but which is held by
someone who then grants it back to you; and whether `sales_manager` at `team` scope can reach
`branch` through an intermediate role.

### 3.5 Reports and aggregates

Reports are where scope leaks hide, because an aggregate does not look like a record. Check:

- `App\Domain\Reporting\DashboardService` — every figure should be `visibleTo()`-filtered
- `App\Domain\Attribution\AttributionReport` — seven of its methods are surfaced at `/attribution`,
  and they are built on `DB::table()` (see 3.1)
- `App\Domain\Finance\AgeingReport`

### 3.6 Mass assignment on status columns

Order status is supposed to be writable only by `OrderStateMachine`. There is an architecture test
grepping for `'payment_status' =>` outside allowed files, and status columns are excluded from
`$fillable`. Try to move an order's status through any route other than
`POST /orders/{order}/transition`.

### 3.7 Immutability

These tables reject `UPDATE` at the database level, not just in Eloquent: `stock_movements`,
`approval_actions`, `journal_lines`, `audit_logs`, `commission_events`, `commission_rule_versions`,
`attribution_touches`, `order_events`. `audit_logs` and `journal_entries` also reject `DELETE`.

`audit_logs` has an intentional escape hatch for PDPA erasure: `SET LOCAL app.audit_purge = 'on'`
inside a transaction. **That escape hatch is worth attacking** — see `App\Domain\Privacy`.

---

## 4. What is already asserted, so you can verify rather than rediscover

The `Security` suite (30 tests) runs on every commit and asserts:

| Claim | Where |
|---|---|
| Passwords hashed, hidden from serialisation | `tests/Security/HardeningTest.php` |
| Login throttled | same |
| Every state-changing route carries `auth` + `company` | same |
| CSRF applied to the web group | same |
| The private disk serves no route | same |
| Horizon gated on `modules.manage`, refused to guests and to staff | same |
| Session cookies `httpOnly` + `SameSite` | same |
| No privilege boolean on `users` | same |
| `APP_DEBUG` never true in production | same |
| No secret in `.env.example` | same |

The `Isolation` suite (1,027 tests) is **reflection-driven**: it discovers every company-scoped
model and asserts cross-company invisibility. Adding a scoped model without a seed recipe fails the
suite by design.

Run them yourself:

```bash
./vendor/bin/pest --testsuite=Security
./vendor/bin/pest --testsuite=Isolation
```

### How the guards were tested

Every authorization guard in this codebase was verified by **planting the violation** — removing the
check, confirming the test fails, restoring it. A green test that has never been seen to fail proves
nothing, and this method has caught nine defects where a test was passing for the wrong reason.

**It is still self-assessment.** It cannot find what I did not think to test. That is what you are
for.

---

## 5. Findings I already made against myself

Recorded in full in `Planning.md`. Listed here so you do not spend time on them.

| # | Finding |
|---|---|
| S-1 | Laravel ships the `local` disk with `serve => true`, registering `GET` **and `PUT`** `/storage/{path}` with **no middleware** — unauthenticated read and write against the private document disk. Fixed; guarded |
| S-2 | Horizon had no explicit gate, so access depended on `APP_ENV` rather than a decision. Fixed; guarded |
| S-3 | `Route::redirect` registers every HTTP verb, so `/` answered `DELETE`. Fixed; guarded |
| D-1 | `DB::table()` bypassed the company scope in twelve attribution reports. Fixed; guarded |
| D-2 | An append-only audit table blocked company deletion, colliding with PDPA erasure. Resolved with a scoped purge flag |
| D-3 | Three authorization tests passed for the wrong reason — a *different* guard was refusing. Rewritten to isolate |
| D-6 | Two access-control guards were unreachable; re-submitting an unchanged role counted as granting it |

---

## 6. Known gaps — not defects, decisions

Please confirm these are acceptable rather than reporting them as findings.

| | |
|---|---|
| **No 2FA** | Session cookie only |
| **No password reset** | No mail transport is configured anywhere |
| **Nothing forces a password change** | An administrator sets a new user's initial password; the user *can* change it at `/profile`, but nothing insists |
| **No session device list or forced logout** | |
| **No rate limiting beyond login** | |
| **No audit of reads** | Only mutations are audited |
| **Attribution cannot be captured from the web** | No UTM/landing endpoint exists |
| **No approval-flow setup screen** | Without a flow, purchase approval is a direct decision gated on `purchasing.approve` |

---

## 7. Environment for the review

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
createdb sme_erp && php artisan migrate
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=ModuleSeeder
php artisan erp:create-owner        # prompts; password never passed as an argument
php artisan serve
```

**No demo accounts are shipped, deliberately** — no seeder creates a user, so no known credential
can ever reach production. Create the owner with the command above, then add one account per role
through **Administration → People**. That is also a useful first test of the escalation rules.

`.env.example` is complete and secret-free. `APP_DEBUG=true` locally will expose stack traces; set it
false if you are testing error handling.

---

## 8. What "clean" means

P9-1 closes when a reviewer independent of this project confirms, in writing:

1. Scope of what was tested, and what was not
2. Every finding, with severity and reproduction
3. Re-test after fixes, confirming each finding closed
4. A statement on whether the authorization model as designed is sound — **not merely that no
   exploit was found today**

Point 4 matters most. A clean penetration test tells you nobody found a hole in the time available.
A judgement on the model tells you whether the design will keep holding as features are added.

### Suggested engagement

| Option | What it is | Best for |
|---|---|---|
| **Independent code review** | A senior Laravel developer, unconnected to this project, two days with the repo | Highest value here — the risks are logic errors, not injection |
| **Application penetration test** | A firm testing the running app as a low-privilege user attempting escalation | What "external review" conventionally means; do before real customer data |
| **PDPA review** | A privacy consultant against the Act | The erasure path is implemented but has never been reviewed by anyone qualified |

For a tester, look for OSWE (web-application specific) rather than OSCP alone, or CREST
accreditation. In Malaysia, providers registered with CyberSecurity Malaysia.

Start with the code review. A black-box test against this system will mostly confirm that Laravel
works.

---

## 9. Recording the outcome

When the review completes, add an appendix to `Planning.md` with: reviewer, date, scope, findings,
fixes, and re-test confirmation. Then mark **P9-1 ✔** in the phase table and move P9 from `[~]` to
`[✔]`, provided P9-3 (CI on a real runner) is also closed.

Until that appendix exists, **P9 is not complete**, regardless of how many tests pass.
