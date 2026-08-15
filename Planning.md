# SME ERP / Business Operating System — Planning

**Status:** PLANNING ONLY — NOT APPROVED FOR EXECUTION
**Author:** Iris (CoreSentinel-governed)
**Date:** 2026-08-15
**Doc version:** 1.0
**Approval required from:** Fakrul

> Nothing in this document has been implemented. No migration, model, controller, service,
> component or package has been created. The only files written are `Planning.md` and
> `.coresentinel/` (project binding + memory), both of which are planning artifacts.

---

## 0. How to read this document

Status markers: `[ ]` not started · `[~]` in progress · `[✔]` done · `[!]` blocked/at-risk.

Every architectural claim about an existing codebase in this document was **read from the
file**, not inferred, unless explicitly marked *(inferred)*. Where prior art is absent, this
document says **absent** rather than assuming.

Three sibling codebases were inspected in depth. They are referenced throughout as:

| Short name | Path | What it is |
|---|---|---|
| **SMEOS** | `../SMEOS` | Multi-tenant B2B SaaS "business command center", Laravel 12 + PostgreSQL 16. P0–P3 shipped, 40 commits, ~291 tests |
| **OMS** | `../SaaS-OMS` | Order + fulfilment engine, Laravel 13.8 + MariaDB 11.8. 465 tests / 2084 assertions |
| **DZI** | `../dzi-holistik-ordering-system` | Single-tenant ordering + commission + marketing-spend system, Laravel 12, 47 models, 76 migrations |

Two further systems were read for commission modelling only: `../saas-multi-tenant-AgentStockit-Management-System` (**AgentStockit**) and `../SAAS-Agent-Management-System` (**AgentMgmt**).

---

## 1. Executive Summary

This plan proposes a **modular, multi-tenant SME ERP** whose distinguishing capability is not
CRUD coverage but **order attribution** — the ability of every order to answer *where did this
business come from, who generated it, who closed it, and who gets paid for it* — and a
**commission engine that can explain every ringgit it computes**.

The target directory was empty. There is no existing ERP repository to extend and no existing
architecture to conflict with. However, the five sibling projects inspected contain
**production-grade, directly liftable solutions** to roughly 60% of this ERP's infrastructure —
tenancy, money, state machines, entitlement, isolation testing, CI guards — and contain
**instructive failures** in exactly the two areas this ERP is differentiated on: attribution
and commission.

The core recommendation is therefore: **build fresh, but lift the tenancy kernel and testing
discipline from SMEOS wholesale, port the order state-machine and inventory-reservation
*patterns* from OMS, and treat DZI/AgentStockit/AgentMgmt commission code as a catalogue of
mistakes not to repeat.**

Three headline design positions, each argued in full below:

1. **Authorization is `Permission × DataScope`, designed into the kernel from commit one.**
   None of the five prior systems has a data-scope model. SMEOS explicitly warns that adding a
   second scoping dimension later roughly doubles the isolation test surface. Retrofitting it
   is the single most expensive mistake available on this project.
2. **Attribution is its own domain, never columns on `orders`.** Every prior system hard-coded
   loose "source" strings onto the order and could not answer campaign-level questions.
3. **Commission rules are immutable and effective-dated, and every commission row records the
   rule version, basis and inputs that produced it.** All five prior systems store a mutable
   rate with no effective dating — editing a rate silently rewrites the meaning of closed
   periods.

---

## 2. Business Objectives

| # | Objective | Success measure |
|---|---|---|
| BO-1 | Let an SME run customers, products, orders, stock, purchasing and money in one system | A trading SME can operate a full month without a spreadsheet |
| BO-2 | Attribute every order to its true commercial origin | Any order answers all 12 attribution questions in §14 |
| BO-3 | Pay marketers, salespeople and teams correctly and explainably | Every commission renders a plain-language explanation naming rule, basis, rate and source orders |
| BO-4 | Let each SME enable only the modules it needs | A services SME can run with Inventory and Purchasing disabled and see no dead nav |
| BO-5 | Enforce who-sees-what at the data layer | A salesperson cannot read another salesperson's orders through any route, export, report or API |
| BO-6 | Stay extensible | Adding a module touches the module registry and its own namespace, not the kernel |

**Explicit non-objectives for v1:** full double-entry accounting, manufacturing/MRP, payroll,
POS hardware integration, native mobile apps, public API.

---

## 3. Target SME Profiles

The architecture must serve at least these four without forcing unused modules on any of them.

| Profile | Shape | Modules used | Attribution need |
|---|---|---|---|
| **P-A — Social-commerce trader** | Sells via FB/IG/WhatsApp/TikTok, marketers generate leads, closers convert | Orders, Products, Inventory, Marketing, Commission, Finance | **Highest.** Campaign → marketer → lead → order → commission is the whole business |
| **P-B — B2B distributor** | Field sales team, territories, credit terms, purchase orders | Customers, Suppliers, Orders, Purchasing, Inventory, Sales Team, Commission, Finance | Salesperson + team, not campaign |
| **P-C — Multi-branch retail/service** | 2–8 branches, branch managers, branch stock | Branches, Products, Orders, Inventory, RBAC scope, Finance | Branch + channel |
| **P-D — Agent/reseller network** | Tiered resellers buying at tier price and reselling | Products (tier pricing), Orders, Commission (margin model), Inventory | Upline/downline |

P-A and P-D are the profiles that justify this project existing rather than buying an
off-the-shelf ERP. **P-C is the profile that forces branch into the scope model from day one.**

---

## 4. Functional Modules

Core (22), matching the brief. `is_core` modules cannot be disabled.

| # | Module | Key | Core? | Notes |
|---|---|---|---|---|
| 1 | Dashboard | `dashboard` | ✔ | Role-aware; five variants (§20) |
| 2 | Company Management | `companies` | ✔ | The tenant entity |
| 3 | Branch Management | `branches` | ✔ | Kernel dependency — see §5 |
| 4 | User Management | `users` | ✔ | |
| 5 | RBAC / Authorization | `access` | ✔ | Kernel, not a module in the disableable sense |
| 6 | Customer Management | `customers` | ✔ | |
| 7 | Supplier Management | `suppliers` | | |
| 8 | Product Management | `products` | ✔ | |
| 9 | Order Management | `orders` | ✔ | Central transaction engine |
| 10 | Sales Management | `sales` | | Quotation → SO → DO → Invoice → Payment |
| 11 | Sales Team Management | `sales_teams` | | |
| 12 | Marketer Management | `marketers` | | |
| 13 | Marketing / Campaign | `campaigns` | | |
| 14 | Marketing Attribution | `attribution` | | Domain service; surfaces as reports |
| 15 | Commission | `commission` | | |
| 16 | Inventory | `inventory` | | |
| 17 | Purchasing | `purchasing` | | |
| 18 | Finance | `finance` | | |
| 19 | Reporting | `reports` | | |
| 20 | Approval Workflow | `approvals` | ✔ | Kernel service; other modules register approvables |
| 21 | Audit Log | `audit` | ✔ | Kernel service |
| 22 | Notification | `notifications` | ✔ | Kernel service |

Future (not built, registry-ready): `hr`, `payroll`, `pos`, `crm`, `projects`, `assets`,
`tickets`, `subscriptions`, `manufacturing`, `accounting_advanced`, `analytics_advanced`.

**Registry pattern (from MPT-SaaS, verified):** a `modules` table (`key`, `name`, `icon`,
`route`, `nav_group`, `sort`, `is_core`, `min_plan`) plus per-company `company_module_settings`
(`module_key`, `enabled`, `settings` jsonb). **Seed only shipped modules** — SMEOS conflict
C-05 records why: seeding all 29 produces a "ModuleLocked graveyard" of nav entries that only
advertise what the customer cannot have.

**Navigation is server-built and hides rather than locks** (SMEOS `NavigationBuilder`,
verified). A disabled module is *absent* from nav, not padlocked.

---

## 5. Module Dependencies

```
                    ┌──────────────────────────────────┐
                    │  KERNEL (never optional)         │
                    │  Company · Branch · User · RBAC  │
                    │  DataScope · Audit · Approval    │
                    │  Notification · Money · Numbering│
                    └───────────────┬──────────────────┘
                                    │
        ┌──────────────┬────────────┼────────────┬──────────────┐
        ▼              ▼            ▼            ▼              ▼
    Customers      Suppliers    Products     Attribution    Reporting
        │              │            │            │              ▲
        │              │            ├─────► Inventory           │
        │              │            │            │              │
        │              ▼            ▼            │              │
        │          Purchasing ──────┘            │              │
        │                                        │              │
        └──────────────► ORDERS ◄────────────────┘              │
                            │                                   │
              ┌─────────────┼─────────────┐                     │
              ▼             ▼             ▼                     │
          Sales        Sales Teams    Marketers                 │
              │             │             │                     │
              └─────────────┴──────┬──────┘                     │
                                   ▼                            │
                              Commission ──────► Finance ───────┘
```

**Hard dependency rules:**

- `Branch` is **kernel, not a module**, because `branch_id` participates in the data-scope
  resolver and therefore in every scoped query. SMEOS deferred branch to P9+ and its own
  architect recorded why that is expensive to reverse. We take the cost up front.
- `Attribution` depends on nothing and is depended on by Orders, Commission and Reporting. It
  must be buildable and testable **without** Orders.
- `Commission` depends on Orders + Attribution + Products. It must never be depended on *by*
  Orders — the order does not know it generates commission.
- `Finance` consumes Commission and Sales; neither may write to Finance directly. Payout emits
  a ledger entry through a Finance service in the same transaction (fixes DZI's real gap where
  commission expense never reached the P&L).

---

## 6. Core Business Processes

Six processes define the system. Each is a transaction boundary and a test suite.

| Process | Entry | Exit | Owning module |
|---|---|---|---|
| Lead-to-Cash | Campaign/lead captured | Payment received, commission accrued | Marketing → Orders → Finance |
| Order-to-Fulfilment | Order approved | Delivered | Orders → Inventory |
| Procure-to-Pay | Purchase request | Supplier paid | Purchasing → Finance |
| Stock-to-Truth | Any movement | `SUM(movements) == on_hand` | Inventory |
| Earn-to-Payout | Order qualifies | Commission paid + ledger posted | Commission → Finance |
| Request-to-Approval | Approvable submitted | Approved/rejected with history | Approvals |

---

## 7. Order Lifecycle

**Decision: three independent status axes, not one status column.** Ported from OMS ADR-06
(verified in `../SaaS-OMS/app/Domain/Orders/OrderStateMachine.php`).

The brief proposes a single 12-step chain (Draft → … → Completed). That chain cannot express
two situations an SME hits weekly:

- A COD order that is **packed and shipped but unpaid** — legitimate and common.
- An order that is **shipped and then refunded** — a single chain must either go backwards or
  invent a hybrid state.

Three axes solve this without hybrid states:

**`payment_status`** — `unpaid → partially_paid → paid → refunded`
`unpaid → [partially_paid, paid]` · `partially_paid → [paid, refunded]` · `paid → [refunded]` · `refunded` terminal

**`fulfilment_status`** — `draft → pending → approved → allocated → picked → packed → shipped → delivered → completed`
Reversible before despatch. `shipped → [delivered]`, `delivered → [completed]`, `completed` terminal.

**`exception_status`** — `none → [on_hold, cancelled, returned]`
`on_hold → [none, cancelled, returned]` · `cancelled`, `returned` terminal.

**Mechanism (lifted pattern, not code — DB and money conventions differ):**

- Each enum owns its own `allowedNext(): array`.
- A single `OrderStateMachine` owns **cross-axis rules no individual enum can know**: an open
  exception blocks fulfilment progress; cannot cancel after `hasLeftWarehouse()`; cannot mark
  returned before shipped; cannot approve while credit limit is exceeded.
- `reasonAgainst(Order, BackedEnum): ?string` returns **a human sentence or null**, never a
  bare boolean — *"This order has already shipped. Record a return instead of cancelling."*
  This is the explainability primitive reused by Approvals and Commission.
- Status columns are **excluded from `$fillable`**; only the state machine writes them, via
  `forceFill` inside `DB::transaction`. Enforced by a CI grep (§30).
- Every transition writes an append-only `order_events` row.

**Acceptance criterion (adopted from OMS):** *no status logic exists outside
`OrderStateMachine`* — verified by grep, enforced at review.

**Additionally supported:** partial payment (`paid_amount` vs `total`, with an invariant test
tying `paid_amount` to `payment_status` — OMS defect P1-25 is that this invariant is missing),
partial fulfilment (per-line `quantity_allocated`/`_picked`/`_shipped` — **absent in OMS**,
new here), returns with an RMA entity, refunds against a real `payments` table, credit notes,
order modification gated by a mutability policy, timeline, attachments, notes.

**Order mutability policy** (adopted from OMS `OrderMutabilityPolicy`): five field groups —
`items`, `address`, `customer`, `money`, `notes` — each locked at the point a change would
invalidate something physical or financial, with `reasonLocked()` returning a readable
sentence. Money edits **refuse rather than clamp**.

---

## 8. Sales Lifecycle

`Quotation → Sales Order → Delivery Order → Invoice → Payment`, with `Credit Note` and
`Return` as exception branches.

- Quotation carries a 9-state machine, revisions (`revision_of_id`, `-R{n}` numbering) and a
  public share token — **liftable from SMEOS `QuotationService`, which is production-tested.**
- A Sales Order **is** an Order (§7). There is no parallel order entity for "sales" — that
  duplication is the most common ERP modelling error and DZI shows its cost (`orders` +
  `invoices` with no `invoice_items`, lines re-derived).
- Invoices snapshot money at issue; branding renders live. (Recorded skill from the Core's
  self-evolution log — see §34.)
- Per-tenant document numbering via a `document_sequences` table with a concurrency test
  (SMEOS pattern, verified: quote numbering unique under concurrent load).

---

## 9. Marketing Lifecycle

```
Campaign ──► Channel ──► Touch ──► Lead ──► Customer ──► Order
    │                                 │                    │
    └── spend (AdCost) ───────────────┴──── ROAS ──────────┘
```

- `Campaign` and `Channel` are **first-class entities**. In all five prior systems they are
  free strings that nothing joins — DZI has `orders.source`, `leads.source` and
  `ad_costs.platform` as three unrelated unconstrained columns.
- `Lead → Customer → Order` conversion is bidirectional: `leads.converted_customer_id` **and**
  an attribution record on the order pointing back at the lead. DZI's link is one-way, so an
  order cannot name the lead that produced it.
- **Advance → claim → acquittal** for marketing float, lifted verbatim in concept from DZI
  (`AdsFund` → `AdsFundClaim` with `receipt_path`). This is genuinely good prior art.
- **Cost-double-counting guard from day one.** DZI's `CashFlow::scopeWithoutAdSpend()` and
  `scopeDuplicatingOrderCost()` encode hard-won domain knowledge: the same cost arrives twice
  (once as ad spend, once as a cash-flow row; once as per-parcel postage, once as a courier
  invoice). Design the rule "which ledger owns which cost" **before** the first report.

---

## 10. Product Lifecycle

`Draft → Active → Discontinued`, with availability resolved per branch and per channel.

Pricing resolution order (first match wins), evaluated by one `PriceResolver`:

1. Customer-specific price
2. Customer-group price
3. Tier / member price (quantity or level based)
4. Channel price
5. Branch price
6. Promotion / discount rule (date-bounded)
7. Wholesale price
8. Base selling price

**The resolver returns a decomposition, not a number** — `{price, source, rule_id, base,
discount}` — for the same reason commission does (§13). A price the salesperson cannot explain
is a price the customer will argue with.

v1 includes: products, categories, brands, variants, SKU, barcode, images, attributes, UoM,
cost/selling/wholesale/member/tier pricing, tax, bundles, packages, status, branch and channel
availability, pricing rules, promotions, discount rules, product commission rules.

**Deliberately deferred** (schema reserved, not built): serial numbers, batch/lot, expiry,
warranty, composite/kit assembly. Rationale: each adds a dimension to every stock movement.
Reserve the `stock_movements.lot_id` / `serial_id` nullable columns now so the later migration
is additive, never destructive.

---

## 11. Purchase Lifecycle

`Purchase Request → Purchase Order → Goods Received → Supplier Bill → Payment`, plus
`Purchase Return`.

- Every step is an approvable (§19).
- GRN writes stock movements with `reason = received`; it never writes `on_hand` directly.
- Supplier bill three-way match: PO ↔ GRN ↔ Bill. Mismatch blocks payment and raises an
  exception, it does not silently accept.
- Landed cost (freight, duty) allocatable across GRN lines — affects `unit_cost`, therefore
  affects margin, therefore affects commission where the plan is margin-based. This is why
  cost must be snapshotted onto the order line at sale (§12).

---

## 12. Inventory Lifecycle

**Decision: reservation-based, never direct decrement.** Ported from OMS ADR-07 (verified).

- `stock` — one row per `(company_id, branch_id, warehouse_id, variant_id)`.
  `on_hand` is a **signed** integer. Real warehouses go negative after a miscount; refusing to
  represent it turns a counting error into a 500.
- **`available = on_hand − reserved` is derived, never stored.**
- `on_hand` and `reserved` are excluded from `$fillable`; one `InventoryService` is the sole
  writer.
- `stock_reservations` — `held → committed | released`, with `expires_at`. Order-backed
  reservations never expire; speculative holds expire and are swept by a scheduled job with
  per-item fault isolation.
- `stock_movements` — **append-only ledger**, enforced at model *and* database trigger, with
  `quantity_delta`, denormalised `balance_after`, mandatory `reason`
  (`received|sold|returned|adjustment|stock_take|damaged|transfer_in|transfer_out`),
  polymorphic `reference`, `actor_id`, `correlation_id`.
- **Invariant, tested:** `SUM(quantity_delta) == on_hand` per stock row.
- Concurrency: blocking `SELECT … FOR UPDATE` on the stock line, deliberately **not**
  `SKIP LOCKED` — last-unit scarcity is queue-and-wait, not skip.

**Two known OMS defects to fix rather than inherit** (both verified in its own planning doc):
`commit()`/`release()` must take their own lock (OMS H-02: a reservation was discharged twice
in a two-process repro), and lock ordering must be consistent between commit-on-ship and
order-cancel (OMS H-03: 6/6 deadlocks).

Multi-branch/warehouse is **in scope from day one** here, unlike OMS which shipped a sentinel
`location_id = 0` and no location entity.

---

## 13. Commission Lifecycle

`Calculate → Pending → Approved → Payable → Paid`, plus `Cancelled` and **`Reversed`**.

This is the module where prior art is richest in *mistakes*. All five findings below were read
from shipped code.

### 13.1 What the prior systems get wrong

| # | Anti-pattern | Where verified | Consequence |
|---|---|---|---|
| CA-1 | Rate on a **mutable** settings row, no effective dating | DZI `commission_ranks.rate`, AgentStockit `commission_settings.rate`, AgentMgmt KV store | Editing a rate silently changes what a re-run of a **closed** period produces |
| CA-2 | Commission row **cannot name the rule that made it** — no `rank_id`, `setting_id`, rate or basis columns | all three | Explanation exists only as English prose in a `note` string; machine-useless |
| CA-3 | Aggregate commissions carry `order_id = NULL` | DZI `type='earned'` | A month's commission is unauditable — cannot join back to its source orders |
| CA-4 | Calculation **inline in a payment-gateway callback**, no unique index, no idempotency | AgentStockit `PaymentController:36`, AgentMgmt `PaymentController:208` | Gateways retry → **duplicate money** |
| CA-5 | Payout sweeps approved commissions but never flips them to paid | AgentStockit `PayoutService::createBatch()` | **Run twice → pays twice** |
| CA-6 | **No reversal path anywhere.** Refund/return/cancel after payout does nothing | all three | Overpaid commission is unrecoverable except by free-text adjustment |
| CA-7 | Hard-`delete()` of a commission row to "correct" it | DZI `CommissionService::discardStale()` | Money rows vanish with no tombstone |
| CA-8 | Free-transition status setter (`paid → pending` allowed) | DZI `CommissionController::setStatus()` | No state integrity |
| CA-9 | Config surface the engine never reads (`scope=rank|product`, `conditions` jsonb) | DZI | Lies to the admin |
| CA-10 | Payout never posts to the financial ledger | DZI `CommissionPayoutService::pay()` | Commission expense absent from P&L |

### 13.2 What to carry forward

- **`grossProfitParts()` returning a decomposition** (DZI, genuinely the best idea in the three
  commission codebases) — but **persist it** instead of flashing it once and discarding it.
- **Margin-as-commission** (AgentMgmt): `(buyer_price − upline_tier_price) × qty` computed
  **per order item**. Where a tier price ladder exists, the commission *is* the ladder — no
  second rate to configure, no drift possible.
- **Idempotency by natural key** (DZI `earnForPeriod`) — but enforced with a **DB unique
  index**, which none of the three has.
- **"Locked once approved/paid"** (DZI) — but backed by a state machine, not an `if`.
- **Payout as a first-class aggregate with reservation** (DZI `commission_payouts` +
  `commission_payout_id` on each row, released on exclude) — materially better than
  AgentStockit/AgentMgmt's sweep.
- **Bank details snapshotted onto the payout request** (DZI `commission_requests`) — the
  voucher must not change when the user edits their profile.
- **Advance/acquittal** and **reconciled-actuals-vs-estimates** (DZI `Order::effective*()`).

### 13.3 The design

**Rules are immutable and effective-dated.** Editing a rule creates a **new version row**;
the old version is never mutated. Every commission stamps `rule_version_id`.

**Every commission explains itself from data, not prose.** Persisted on the row:
`basis_amount`, `rate_type`, `rate_applied`, `rule_id`, `rule_version_id`, `plan_id`,
`calc_inputs` (jsonb decomposition), plus a `commission_sources` join table linking the
commission to **every** order/order-line that contributed — including for period aggregates,
which fixes CA-3.

The required explanation from the brief is then generated, not stored:

> **Commission RM50.00** — Recipient: Ali (Marketer) · Rule: "Facebook Campaign Commission"
> v3 (effective 2026-07-01) · 5% of eligible order value · Basis: RM1,000.00 · Order #10025

**Strategy per plan, not `if` branches.** `commission_plans.strategy` selects one calculator
class: `PercentageOfValue`, `PercentageOfMargin`, `FixedPerOrder`, `FixedPerUnit`,
`TierLadder`, `MarginLadder`, `TargetAchievement`, `UplineOverride`. DZI threads
`if ($isStaffSales)` through four methods; that is what a strategy table replaces.

**Calculation runs in a queued job keyed on the order**, never inline in an HTTP callback
(fixes CA-4), guarded by a unique index on
`(company_id, order_id, order_item_id, recipient_id, plan_id, type)`.

**Reversal is first-class.** Order cancelled/returned/refunded after accrual emits a
`reversal` commission referencing the original — a contra entry, never a delete (fixes CA-6,
CA-7). If already paid, it becomes a recoverable balance against the next payout.

**Payout posts to Finance in the same transaction** (fixes CA-10).

**States:** `pending → approved → payable → paid`, plus `cancelled` and `reversed`. Enforced
by a state machine with a transition log (fixes CA-8).

---

## 14. Attribution Model

**Decision: attribution is a reusable domain with its own tables, never columns on `orders`.**

The brief asks for this explicitly, and the prior art proves the cost of not doing it: across
five systems, campaign and channel are absent as entities; "source" exists as three unrelated
free strings; there is no touchpoint record, no UTM capture, and no cost-per-lead join.

### 14.1 Dimensions

`channel` · `campaign` · `source` · `medium` · `referral_code` · `referral_link` ·
`promo_code` · `marketer` · `salesperson` · `sales_team` · `branch` · `company`

### 14.2 Shape

- **`attributions`** — polymorphic (`attributable_type`/`attributable_id`), so a **Lead**, a
  **Customer** and an **Order** are all attributable through one mechanism. Carries the
  resolved dimension FKs, `touch_type` (`first` | `last`), `captured_at`, `raw` jsonb (UTM and
  click ids as received).
- **`attribution_touches`** — append-only multi-touch trail. v1 records touches and reports
  first/last only; the table shape makes linear/positional models a later query change, not a
  migration.
- **Order inherits, then freezes.** On order creation, attribution is **resolved from the
  customer/lead trail and snapshotted onto the order's attribution row.** A campaign renamed
  or a marketer reassigned six months later must never change what an order was attributed to,
  because commission was paid on it.

### 14.3 The twelve questions v1 must answer

1. Where did this customer come from? 2. Where did this order come from? 3. Who generated the
lead? 4. Who closed the order? 5. Which campaign generated revenue? 6. Which marketer
generated revenue? 7. Which salesperson generated revenue? 8. Which channel converts best?
9. What did this campaign cost vs return (ROAS)? 10. Cost per lead by campaign?
11. Which team hit target? 12. Which branch generated what?

Each is a named, tested reporting query. **A dimension that cannot answer one of these is not
built in v1.**

---

## 15. RBAC Architecture

**Decision: `spatie/laravel-permission` in teams mode, team key `company_id`.**

Rationale, following SMEOS ADR-014 (verified, and it resolved the same open question):
roles are **per membership, not per user** — one accountant is Owner of their own firm and
Staff at five clients. A `users.role` string column (OMS's approach) cannot express that.

Rejected alternatives:
- **Hand-rolled enum matrix** (OMS `Ability` + `UserRole::abilities()`): elegant and auditable
  in one file, but roles become code, so an SME cannot create "Senior Sales Executive" without
  a deploy. An ERP needs customer-defined roles.
- **DZI's hybrid** (`Permission` model + pivot + `$roleDefaults` empty array + owner bypass):
  demonstrably drifts.

### 15.1 Entities

`User` · `Role` · `Permission` · `role_has_permissions` · `model_has_roles` ·
**`role_permission_scopes`** (new — §17)

### 15.2 Naming convention

**`{group}.{ability}`, plural snake_case group, dot separator.**

The brief proposes singular (`product.view`). **Recommend plural** (`products.view`), matching
SMEOS's 49 shipped keys — consistency with the house convention beats the brief's placeholder,
and this is exactly the kind of decision the brief delegated to planning.

```
products.view    products.create   products.update   products.delete   products.export
orders.view      orders.create     orders.update     orders.approve    orders.cancel
commissions.view commissions.approve commissions.pay
```

Actions: `view create update delete approve reject export print manage`, plus
domain verbs where a generic one would lie (`orders.cancel`, `leads.convert`,
`invoices.void`, `stock.adjust`).

Authority: **one `PermissionRegistry` class** holding the group→abilities map and the
role→grant matrix with wildcard support (`'owner' => ['*']`). SMEOS proves the failure mode of
documenting this elsewhere — its `docs/PERMISSIONS.md` claims "50 keys × 14 policy classes"
while the code has 49 keys and 3 policy classes. **Code is the authority; docs are generated
from it.**

### 15.3 Roles (seeded defaults, all editable per company)

`owner` · `admin` · `branch_manager` · `sales_manager` · `salesperson` · `marketer` ·
`marketing_manager` · `purchaser` · `storekeeper` · `accountant` · `staff`

---

## 16. Permission Model

Permission is a **binary grant**. It answers *what can the user do?* and nothing else.
Everything about *which records* lives in §17. Conflating them is what makes ERP
authorization unmaintainable.

Resolution order: platform guard → company membership → role grants → explicit user
overrides → deny. **Fail closed at every step.** No `Gate::before` super-admin bypass
(adopted from OMS, verified: platform staff hold platform abilities only and cannot read
tenant business data or customer PII).

---

## 17. Data Scope Model

**This is the single largest new design in this plan. No prior system has it.**

SMEOS: *"every policy method is a flat `$user->can('x.y')`; `owner_user_id` exists but is only
used for assignment, never for query filtering"* — verified by grep. Its own architect's note
on adding a second dimension later: *"roughly doubles the isolation test surface."*
OMS: one axis (tenant) only; its second axis is planned but has **no column to filter on**.
DZI: per-controller `abort_unless($order->creator_id === ...)` repeated 8× in one file — one
missed line is a data leak.

### 17.1 The model

Scope is stored **on the role↔permission pivot**, so the same role can be `own` for orders and
`branch` for reports:

```
role_permission_scopes
  company_id · role_id · permission_id · scope ENUM(own, team, branch, company, all)
```

`DataScope` enum with `rank()` so scopes compare and a user's effective scope is the **maximum**
across their roles.

### 17.2 Resolution

```php
ScopeResolver::for(User $user, string $permission): DataScope
```

Applied through a `Scopeable` contract each scoped model implements, declaring how each scope
maps to a constraint:

| Scope | Constraint |
|---|---|
| `own` | `owner_user_id = user.id` |
| `team` | `owner_user_id IN (user's team member ids)` — resolved through `sales_team_members`, honouring hierarchy depth |
| `branch` | `branch_id IN (user's branch ids)` |
| `company` | no additional constraint (the company global scope already applies) |
| `all` | platform guard only; never grantable to a company role |

### 17.3 Non-negotiable properties

1. **Fail closed.** Unresolvable scope → `whereRaw('1 = 0')`. Adopted from MPT-SaaS
   `UserScope`, verified — and the same instinct as OMS's `idOrFail()` throwing rather than
   compiling `tenant_id IS NULL`.
2. **Applied in the query layer, not the controller.** DZI's per-controller checks are the
   anti-pattern; one missed line leaks.
3. **Exactly one greppable escape hatch** — `Model::withoutDataScope()` — so the audit is one
   grep, and CI fails if it appears outside an allowlisted namespace.
4. **Exports, reports, PDFs, search and background jobs go through the same resolver.** SMEOS
   security gate G-4 exists precisely because an unscoped export is the classic leak.
5. **Scope is orthogonal to company.** Company isolation is a global scope that always applies;
   data scope narrows *within* it. A bug in one must not disable the other.

### 17.4 Worked examples (from the brief)

| User | Role | `orders.view` | Effective query |
|---|---|---|---|
| Ahmad | Salesperson | ✔ scope `own` | `WHERE company_id = X AND owner_user_id = ahmad` |
| Siti | Sales Manager | ✔ scope `team` | `WHERE company_id = X AND owner_user_id IN (team)` |
| Rahim | Branch Manager | ✔ scope `branch` | `WHERE company_id = X AND branch_id IN (rahim's)` |
| Lina | Finance Manager | ✔ (`invoices.view`) scope `company` | `WHERE company_id = X` |
| Admin | System Admin | ✔ scope `all` | platform guard, audited, reason required |

---

## 18. User Roles

Every user has: one company membership per company (with a role), zero-or-more branches, an
optional sales team membership, an optional manager. **The hierarchy is data, never
hard-coded** — the brief is explicit and the prior art agrees (AgentStockit hard-codes
`$level <= 10` in a loop, which is exactly the constraint that later needs a deploy to change).

---

## 19. Approval Workflow

A **reusable engine**, not per-module logic.

- `approval_flows` — what is approvable (`approvable_type`), conditions (amount bands,
  category, branch), and **ordered levels**.
- `approval_levels` — `sequence`, `approver_role_id` or `approver_user_id`, `min_amount`,
  `max_amount`, `can_delegate`.
- `approval_requests` — the instance, polymorphic to the approvable.
- `approval_actions` — append-only: `approve | reject | return_for_revision`, actor, comment,
  timestamp, delegated-from.

Amount bands are **rows, never code** (the brief is explicit; the RM1,000/RM10,000 example is
seed data). Approvables in v1: Purchase Request, Purchase Order, Supplier Bill, Payment, Sales
Order, Discount above threshold, Refund, Credit Note, Commission payout, Stock Adjustment,
Expense.

An approvable exposes `reasonBlocked()` returning a readable sentence, same idiom as §7 and §13.

---

## 20. Dashboard & Reporting Architecture

Five role-shaped dashboards (management, sales, marketing, marketer, salesperson) per the
brief, each rendering **only what the user's data scope permits** — a salesperson's "revenue"
tile is their own revenue, computed through the same resolver as the list screen. A dashboard
that bypasses the scope resolver is a leak with a chart on it.

Reporting: read models, not live joins across the whole schema. Heavy aggregates
(commission by period, ROAS by campaign, stock valuation, ageing) are **precomputed into
reporting tables by scheduled jobs**, with the live query kept as the correctness oracle in
tests.

Adopted from SMEOS §5.1: *"a dashboard widget with no alert and no action is deleted, not
redesigned."*

---

## 21. Important Entities

**Kernel:** Company, Branch, Department, User, CompanyUser, Role, Permission,
RolePermissionScope, Module, CompanyModuleSetting, AuditLog, Notification,
NotificationPreference, ApprovalFlow, ApprovalLevel, ApprovalRequest, ApprovalAction,
DocumentSequence.

**Master data:** Customer, CustomerContact, CustomerAddress, CustomerGroup, Supplier,
SupplierContact, SupplierAddress, Product, ProductVariant, ProductImage, ProductAttribute,
Category, Brand, UnitOfMeasure, PriceList, PriceListItem, TierPrice, PromotionRule,
DiscountRule, TaxRate, ProductBundle, BundleItem.

**Transactions:** Order, OrderItem, OrderEvent, Quotation, QuotationItem, DeliveryOrder,
Invoice, InvoiceItem, Payment, PaymentAllocation, CreditNote, ReturnOrder, ReturnItem,
PurchaseRequest, PurchaseOrder, PurchaseOrderItem, GoodsReceipt, GoodsReceiptItem,
SupplierBill, SupplierPayment, PurchaseReturn.

**Inventory:** Warehouse, Stock, StockMovement, StockReservation, StockTransfer,
StockAdjustment, StockCount, StockCountItem.

**Sales force:** SalesTeam, SalesTeamMember, Territory, SalesTarget, SalesAchievement,
SalesActivity, CustomerVisit, FollowUp, Pipeline, PipelineStage.

**Marketing:** Marketer, MarketingTeam, Channel, Campaign, CampaignCost, Lead, LeadActivity,
ReferralCode, PromoCode, AdvanceFund, AdvanceClaim.

**Attribution:** Attribution, AttributionTouch.

**Commission:** CommissionPlan, CommissionRule, CommissionRuleVersion, Commission,
CommissionSource, CommissionEvent, CommissionPayout, CommissionPayoutItem, CommissionRequest.

**Finance:** Account, JournalEntry, JournalLine, CashFlow, BankAccount, Expense,
ExpenseCategory, Receivable, Payable.

---

## 22. Entity Relationships

```
Company ─┬─ Branch ─── Department ─── User
         ├─ Module settings
         └─ Role ─── Permission ─── RolePermissionScope
                │
Customer ───────┤                  Campaign ─── Channel ─── Marketer
   │            │                      │                       │
   │            │                      └──── Lead ─────────────┘
   │            │                              │
   │            └──────► Attribution ◄─────────┘
   │                          │
   └──────────────────────► ORDER ◄──── Salesperson ─── SalesTeam
                              │
                    ┌─────────┼─────────┬──────────────┐
                    ▼         ▼         ▼              ▼
               OrderItem  OrderEvent  Payment    StockReservation
                    │                    │              │
                    ▼                    ▼              ▼
                 Product ◄────────── Invoice        StockMovement
                    │                    │              │
                    ▼                    ▼              ▼
              CommissionRule ────► Commission ────► Finance
                                        │
                                  CommissionSource
```

**Not every order has every relationship** — the brief is explicit and the schema honours it.
`marketer_id`, `campaign_id`, `salesperson_id`, `sales_team_id`, `lead_id` are all nullable on
the attribution row. A walk-in POS sale has a channel and a branch and nothing else, and that
must be a first-class valid state, not a row full of sentinel zeros.

---

## 23. Proposed Database Tables

~95 tables in v1. Grouped by concern; every one carries `company_id` unless marked *platform*.

**Platform (7):** `companies`, `platform_users`, `modules`, `features`, `packages`,
`package_modules`, `subscriptions`

**Kernel (14):** `branches`, `departments`, `users`, `company_users`, `roles`, `permissions`,
`role_has_permissions`, `model_has_roles`, **`role_permission_scopes`**,
`company_module_settings`, `document_sequences`, `audit_logs`, `notifications`,
`notification_preferences`

**Approval (4):** `approval_flows`, `approval_levels`, `approval_requests`, `approval_actions`

**Master data (22):** `customers`, `customer_contacts`, `customer_addresses`,
`customer_groups`, `suppliers`, `supplier_contacts`, `supplier_addresses`, `categories`,
`brands`, `products`, `product_variants`, `product_images`, `product_attributes`,
`units_of_measure`, `price_lists`, `price_list_items`, `tier_prices`, `tax_rates`,
`promotion_rules`, `discount_rules`, `product_bundles`, `bundle_items`

**Orders & Sales (16):** `orders`, `order_items`, `order_events`, `quotations`,
`quotation_items`, `delivery_orders`, `delivery_order_items`, `invoices`, `invoice_items`,
`payments`, `payment_allocations`, `credit_notes`, `credit_note_items`, `return_orders`,
`return_items`, `order_attachments`

**Inventory (9):** `warehouses`, `stock`, `stock_movements`, `stock_reservations`,
`stock_transfers`, `stock_transfer_items`, `stock_adjustments`, `stock_counts`,
`stock_count_items`

**Purchasing (9):** `purchase_requests`, `purchase_request_items`, `purchase_orders`,
`purchase_order_items`, `goods_receipts`, `goods_receipt_items`, `supplier_bills`,
`supplier_payments`, `purchase_returns`

**Sales force (9):** `sales_teams`, `sales_team_members`, `territories`, `sales_targets`,
`sales_achievements`, `sales_activities`, `customer_visits`, `follow_ups`, `pipeline_stages`

**Marketing (10):** `marketers`, `marketing_teams`, `channels`, `campaigns`, `campaign_costs`,
`leads`, `lead_activities`, `referral_codes`, `promo_codes`, `advance_funds`

**Attribution (2):** `attributions`, `attribution_touches`

**Commission (9):** `commission_plans`, `commission_rules`, `commission_rule_versions`,
`commissions`, `commission_sources`, `commission_events`, `commission_payouts`,
`commission_payout_items`, `commission_requests`

**Finance (9):** `accounts`, `journal_entries`, `journal_lines`, `cash_flows`,
`bank_accounts`, `expenses`, `expense_categories`, `receivables`, `payables`

---

## 24. Key Fields

**`orders`** — `id`, `company_id`, `branch_id`, `order_number`, `customer_id`,
`payment_status`, `fulfilment_status`, `exception_status`, `is_cod`, `currency`, `subtotal`,
`discount_amount`, `tax_amount`, `shipping_amount`, `total`, `paid_amount`, `placed_at`,
`owner_user_id`, customer/address snapshot columns, timestamps, `deleted_at`.
**Deliberately absent:** any attribution column — attribution lives in §14.

**`order_items`** — full frozen snapshot at sale: `sku`, `product_name`, `variant_name`,
`options` jsonb, `unit_price`, **`unit_cost`**, `discount_amount`, `tax_amount`, `line_total`
stored not derived, `quantity`, `quantity_allocated`, `quantity_picked`, `quantity_shipped`,
`quantity_returned`. `unit_cost` is snapshotted because margin-based commission must use
cost-as-at-sale, not today's cost.

**`attributions`** — `attributable_type/id`, `channel_id`, `campaign_id`, `source`, `medium`,
`referral_code_id`, `promo_code_id`, `marketer_id`, `salesperson_id`, `sales_team_id`,
`lead_id`, `touch_type`, `raw` jsonb, `captured_at`. All dimension FKs nullable.

**`commission_rule_versions`** — `rule_id`, `version`, `strategy`, `rate_type`, `rate_value`,
`tier_config` jsonb, `conditions` jsonb, **`valid_from`, `valid_to`**, `created_by`.
**Immutable after creation.**

**`commissions`** — `order_id`, `order_item_id`, `recipient_id`, `recipient_role`, `plan_id`,
`rule_id`, **`rule_version_id`**, `type` (`direct|override|bonus|adjustment|reversal`),
`reverses_commission_id`, `basis_amount`, `rate_type`, `rate_applied`, `amount`, `currency`,
`calc_inputs` jsonb, `period`, `status`, `payout_id`, `approved_by`, `approved_at`, `paid_at`.
Unique index on `(company_id, order_id, order_item_id, recipient_id, plan_id, type)`.

**`role_permission_scopes`** — `company_id`, `role_id`, `permission_id`, `scope`.

**`stock`** — `company_id`, `branch_id`, `warehouse_id`, `variant_id`, `on_hand` (signed),
`reserved`, `incoming`, `low_stock_threshold`. Unique `(company_id, warehouse_id, variant_id)`.

---

## 25. Module Boundaries

- A module owns its namespace (`App\Domain\<Module>`), its tables, its routes, its policies and
  its React pages. **It does not reach into another module's tables.**
- Cross-module communication is by **domain event or an injected service interface**, never a
  direct query.
- The kernel is depended on by everything and depends on nothing.
- **Enforced by architecture test**, not convention: a Pest architecture test asserts
  `App\Domain\Commission` does not reference `App\Domain\Inventory` models, etc. SMEOS proves
  this is enforceable (its `tests/Architecture/` suite is real and runs in CI).

---

## 26. API / Service Architecture

**Layering** (following the OMS house style, which is the cleaner of the two):

```
Route → Middleware (auth, company, module, permission+scope)
     → Controller (thin: authorize, validate, delegate, render)
     → Domain Service (one public verb per use case, DB::transaction)
     → Model (Eloquent, enum casts, global scopes)
```

- `app/Domain/<Context>/` holds the service, its state machine, its DTOs and its exceptions
  **together**. `app/Services/` is reserved for cross-cutting infrastructure only.
- Services constructor-inject collaborators as `private readonly`. **Never `new Service()`** —
  AgentStockit and AgentMgmt both do, and both are untestable as a result.
- **DTOs are `readonly class` with promoted properties**, no package. SMEOS left `app/Data/`
  empty and pays for it in duplicated shape declarations across PHP and TypeScript; OMS uses
  readonly DTOs and does not.
- **Result objects for expected failures, exceptions for programmer errors.** OMS's
  `IntakeResult::accepted()/duplicate()/rejected()` exists because *"a 500-row CSV must produce
  500 verdicts; stopping at row 3 loses row 400 silently."* Same applies to bulk commission
  runs and stock counts.
- No public REST API in v1 (SMEOS's Sage: a public API *"permanently freezes the schema in the
  twelve months you most need refactoring freedom"*). Internal Inertia endpoints only. Webhook
  **ingress** for payment gateways and marketplaces is in scope.

---

## 27. Frontend Architecture

Inertia monolith + React 19 + TypeScript + Bootstrap 5, matching the three newest sibling
projects. Pages at `resources/js/Pages/{Area}/{Resource}/{Action}.tsx`.

**Four corrections to the house convention, each justified by an observed cost:**

1. **Build the shared component library in P0.** SMEOS's `FEAT-019` never shipped, so every
   page is inline Bootstrap strings and `formatMoney` is duplicated verbatim across files. An
   ERP with 22 modules cannot absorb that.
2. **Share permissions AND scope to the frontend.** SMEOS shares module entitlement but **not**
   permissions — `useAccess()` is imported by zero pages, and buttons always render, so a
   `staff` user clicks "New quotation" and gets a 403. Share `auth.can` and let the UI hide
   what the user cannot do. **This is UX, never security** — the backend check remains the
   boundary (§18 of the brief).
3. **Money crosses the wire as a string, never a number** (SMEOS branded type
   `MoneyString = string & { readonly __brand: 'Money' }`). Correct, keep.
4. **Typed shared props.** One `Types/` module, generated where possible from the PHP DTOs.

---

## 28. Authentication

Dual guards: `web` → `User`, `platform` → `PlatformUser` in a separate table with its own
sessions and password resets.

This is **not optional**. SMEOS conflict C-01 records that three of its lanes designed a
`users.is_platform_owner` flag and its security lead vetoed it as *extinction-level* (THR-005):
one boolean on a shared table is one mass-assignment bug away from total compromise. An
architecture test asserts the column's **absence**. Adopt both the design and the test.

Plus: bcrypt/argon2, login rate limiting, session regeneration, 2FA mandatory for platform
users, single-use time-limited reset tokens, `httpOnly`/`secure`/`sameSite` cookies.

---

## 29. Authorization

Every sensitive operation verifies, in order:

1. Authentication
2. Company/tenant membership (active)
3. Module enabled
4. Permission granted
5. **Data scope satisfied**
6. Business rule / state machine allows it
7. Approval obtained where required

Steps 1–3 are middleware; 4–5 are policy + query scope; 6 is the domain service; 7 is the
approval engine. **All server-side.** Frontend hiding is UX only.

---

## 30. Audit & Logging

- `audit_logs` — `user_id`, `action`, `module`, `auditable_type/id`, `old_values` jsonb,
  `new_values` jsonb, `ip`, `user_agent`, `reason`, `correlation_id`, `created_at`.
- **Append-only, enforced twice**: model `updating`/`deleting` throw, **and** a database
  trigger. OMS does exactly this for `order_events` and it is the right level of paranoia for
  money.
- `correlation_id` auto-stamped per request/job so one user action is traceable across HTTP,
  queue and webhook.
- **PII is not written into audit payloads.** Record *which* fields changed, not their values,
  for flagged-sensitive columns. OMS records this as an open PDPA conflict precisely because
  its event table is inerasable by construction — design for erasure now, not later.
- Retention and an erasure path defined before launch (PDPA).

**CI forbidden-pattern guards** (adopted from SMEOS, verified to exist and run):
no `company_id` in `$fillable`; no `withoutGlobalScope`/`withoutDataScope` outside allowlisted
namespaces; no `Storage::disk('public')` for business documents; no plan-name literals in code;
no status-column writes outside a state machine.

---

## 31. Notification Architecture

One `Notification` domain with pluggable channels. **v1 ships in-app + email only.** WhatsApp
and push are interface-ready but not built — the brief says implement only justified channels,
and a WhatsApp integration is a project, not a feature.

Preferences per user per event type. Digest batching for high-volume events (low stock).
Events: new order, approval required, payment received, low stock, commission approved,
commission payable, new lead, new assignment, target achieved, payment failed.

---

## 32. Queue / Background Processing

Redis + Horizon, priority lanes: `default`, `notifications`, `commission`, `reports`, `imports`.

- Every supervisor's `retry_after > timeout` (SMEOS asserts this in an architecture test —
  cheap, catches a real class of duplicate-processing bug).
- `after_commit => true` on every connection.
- **Commission calculation, reporting rollups, stock reservation sweeps and imports are jobs**,
  never inline request work.
- Jobs run inside an explicit company context (`CompanyContext::runAs()`), never an ambient one.

---

## 33. Reporting Architecture

Live queries for operational lists; **precomputed reporting tables** for aggregates. Every
precomputed figure has a live-query oracle in tests so drift is caught, not discovered.

Reports are **scope-filtered through the same resolver as list screens** — the most likely
place for a data-scope bug to hide, and therefore explicitly covered in the isolation suite.

---

## 34. CoreSentinel Decision Record

### 34.1 Existing knowledge discovered through CoreSentinel

**Honest statement: CoreSentinel's knowledge layer contained nothing about this domain.**

`coresentinel recall` was run for *ERP, order, commission, tenant, Laravel, SMEOS,
attribution, RBAC* — **8 queries, 0 hits**. `pattern list` returned "No patterns recorded yet."
The decision ledger held 4 ADRs, all about CoreSentinel's own memory engine. The Phase-8
persistence obligation in `02-team-protocol.md` was never executed for SMEOS, SaaS-OMS or DZI,
so that prior work is not in the knowledge layer.

What CoreSentinel **did** contribute:

| Contribution | Effect on this plan |
|---|---|
| **ADR-003** (project-scoped memory) | Project was bound *before* any fact was recorded, so ERP facts did not leak into the shared Core store — the exact failure ADR-003 exists to prevent |
| **AP-004 `STRICT_BLOCK`** — "Hardcoded Secrets & Unscoped Queries" | The data-scope architecture (§17) is enforced by the scanner, not just by intent |
| **Anti-pattern: empty grep ≠ proof of absence** | Every "absent" claim in this document names its scan scope |
| **Anti-pattern: unverified success claims** | Every architectural claim here is marked read-verified or inferred |
| **Skill: snapshot money, render branding live** | §8 invoice design |
| **Skill: separate verified from secondhand** | §0 and the agent-vs-direct-read distinction throughout |
| `task plan` | Produced the 12-role pipeline used to structure §44 |
| `doctor` / `verify` / `score` | Established an honest zero baseline (6 UNKNOWN, INDETERMINATE) rather than a false green |

**11 facts were written to project memory and the failures layer** during planning
(6 project-scope, 3 failures-scope, plus stack survey). They are listed in §42.

### 34.2 Existing decisions that must be preserved

From the sibling projects, these are decided-and-proven and should not be relitigated:

| ID | Decision | Source |
|---|---|---|
| E-1 | Single DB, row-scoped tenancy; no tenancy package | SMEOS ADR-001/008 |
| E-2 | Tenant context **throws** when unresolved | SMEOS G-1a, OMS `idOrFail()` |
| E-3 | `company_id` never in `$fillable`; stamped on create; immutable on update | SMEOS `BelongsToOrganization` |
| E-4 | Composite `(company_id, id)` FKs so the DB rejects cross-tenant references | SMEOS + OMS |
| E-5 | Dual auth guards, separate platform user table | SMEOS ADR-013 (security veto) |
| E-6 | No `Gate::before` super-admin bypass | OMS `BasePolicy` |
| E-7 | Money through a value object; never float | SMEOS `Money`, OMS `Money` cast |
| E-8 | Status columns excluded from `$fillable`; state machine is sole writer | OMS |
| E-9 | Append-only ledgers enforced at model **and** DB trigger | OMS |
| E-10 | Reservation-based inventory; `available` derived | OMS ADR-07 |
| E-11 | Zero-comment policy, enforced by test + CI | SMEOS §17, and the global `CLAUDE.md` |
| E-12 | Reflection-driven isolation suite (discovers models, not a hand-maintained list) | SMEOS `TenantIsolationTest` |
| E-13 | Server-built navigation; hide not lock | SMEOS `NavigationBuilder` |
| E-14 | Seed only shipped modules | SMEOS C-05 |

### 34.3 New decisions proposed (require approval)

| ID | Decision | Why |
|---|---|---|
| N-1 | **`Permission × DataScope`** authorization, scope on the role↔permission pivot | §17; no prior system has it and retrofit is the most expensive available mistake |
| N-2 | **Branch is kernel, not a deferred module** | It participates in scope resolution, therefore in every query |
| N-3 | **Attribution is its own polymorphic domain** | §14; five systems prove the cost of columns-on-order |
| N-4 | **Commission rules immutable + effective-dated; every commission stamps its rule version and basis** | Fixes CA-1, CA-2, CA-3 |
| N-5 | **Commission reversal as a contra entry**, never delete | Fixes CA-6, CA-7 |
| N-6 | **Commission calculated in a queued job with a DB unique index**, never in a gateway callback | Fixes CA-4, CA-5 |
| N-7 | **Three-axis order status** | §7 |
| N-8 | **Payout posts to Finance in the same transaction** | Fixes CA-10 |
| N-9 | **Plural permission group naming** (`products.view`) | Matches 49 shipped SMEOS keys; brief delegated this |
| N-10 | **Share permissions to the frontend for UX**, backend remains the boundary | Fixes SMEOS's always-render-then-403 UX |
| N-11 | **Component library built in P0**, not deferred | SMEOS deferred it and pays in duplication |
| N-12 | **Partial fulfilment per line** | Absent in OMS; an ERP needs it |

### 34.4 Conflicts identified

| ID | Conflict | Resolution proposed |
|---|---|---|
| **C-1** | **Database engine.** SMEOS = PostgreSQL 16 (hard requirement, `phpunit.xml` pins it). OMS = MariaDB 11.8 LTS, with a recorded "never MySQL" position because its row-locking guarantees are engine-specific | **Decide once, at approval.** Recommend PostgreSQL — see §36 Option 1. Whichever wins, the other codebase's SQL-level patterns need translation, not copy-paste |
| **C-2** | **Money representation.** SMEOS = `NUMERIC(15,4)` + bcmath `Money`. OMS = integer cents + a cast that rejects floats | **Recommend `NUMERIC(15,4)` + Money VO.** Integer cents is chosen in OMS to avoid float drift, but PostgreSQL `NUMERIC` is already exact, and an ERP needs sub-cent precision for unit costs and percentage commission |
| **C-3** | **RBAC mechanism.** SMEOS = spatie teams mode (DB-driven, customer-editable). OMS = hand-rolled enum matrix (auditable in one file, but roles are code) | **Recommend spatie teams mode** — an SME must create its own roles without a deploy |
| **C-4** | **Primary keys.** SMEOS = ULID everywhere (ADR-002). OMS = auto-increment | **Recommend ULID.** Bigint leaks tenant volume and is enumerable — a real IDOR-adjacent risk in multi-tenant |
| **C-5** | **Tenant entity naming.** SMEOS = `Organization`. OMS = `Tenant`. Brief = `Company` | **Use `Company`**, matching the brief and SME vocabulary. Note this means SMEOS code is renamed on the way in, not copied |
| **C-6** | **DTO layer.** SMEOS has none (empty `app/Data/`). OMS uses readonly DTOs | **Adopt OMS's** — SMEOS's absence causes the duplication it complains about |
| **C-7** | **Scope of "ERP" itself.** SMEOS positions explicitly as *"not an ERP"*; OMS declares full ERP/accounting *"integration target, not build target"* | Both are narrower products. **This project is a genuinely larger build than either**, and the phase plan (§44) must not be estimated from their velocity |
| **C-8** | Brief §16 proposes singular permission names; house convention is plural | N-9 |
| **C-9** | `02-team-protocol.md` Standing Rule #7 mandates Windows/PowerShell/`python`; this machine is macOS/zsh/`python3` | Needs a `gate waive` or a CSE proposal to make Rule 7 platform-conditional. Flagged, not silently ignored |

### 34.5 Unresolved questions requiring your decision

Listed in §43.

---

## 35. Multi-company / Multi-branch / SaaS Strategy

**Multi-company:** single database, `company_id` on every business table, Eloquent global
scope, resolved from `users.active_company_id` and re-validated against an **active
membership on every request**. Not subdomain-based — the brief's SME reality is one login
across several companies (an accountant, a group of companies), which subdomain tenancy
handles badly.

**Multi-branch:** `branch_id` on transactional tables, nullable only where a record is
genuinely company-wide. Branch is a **scope dimension** (§17), a stock location (§12) and an
approval routing dimension (§19).

**SaaS:** `Company → Branch → Department → User`. Package/module/feature entitlement chain
adopted from SMEOS (verified working: a platform owner creates a package, attaches modules,
and it appears in tenant nav with **zero code changes**). Not built in v1 beyond the module
registry; the tables are shaped so subscription billing is additive later.

**Deliberately not over-engineered:** no database-per-tenant, no schema-per-tenant, no row-level
security. AgentStockit uses `stancl/tenancy` DB-per-tenant and it is genuinely cleaner for
isolation — but it makes cross-company platform analytics a fan-out query, and this ERP needs
those.

---

## 36. Technical Decisions — Tech Stack Options

All three options are Laravel + Inertia + React, because 14 of your 36 projects are and the
three newest all are. The differences that matter are **database, Laravel version, and how much
of an existing kernel you inherit.**

### Option 1 — SMEOS lineage *(recommended)*

| | |
|---|---|
| Backend | **Laravel 12 LTS**, PHP 8.3 |
| Database | **PostgreSQL 16** |
| Keys / Money | ULID · `NUMERIC(15,4)` + bcmath `Money` VO |
| Frontend | Inertia 3 + React 19 + TypeScript + Bootstrap 5 + Vite |
| RBAC | `spatie/laravel-permission` teams mode + custom scope layer |
| Queue | Redis + Horizon |
| Testing | Pest 3 + Larastan + Pint |

**Inherits:** the tenancy kernel (`TenantContext`, `BelongsToOrganization`,
`OrganizationScope`, `ResolveOrganization`), the composite-FK schema discipline, the `Money`
VO, the entitlement chain, the reflection-driven isolation suite, the CI forbidden-pattern
guards. That is roughly **60% of the kernel already written and tested.**

**Why PostgreSQL for an ERP specifically:** `CHECK` constraints (SMEOS uses them for every
enum), partial and expression indexes for the scoped queries §17 generates, `JSONB` with real
indexing for `calc_inputs` and `attribution.raw`, window functions for commission ladders and
ageing buckets, and exact `NUMERIC`.

**Cost:** Laravel 12 not 13. The order state machine and reservation inventory must be
**ported as patterns** from OMS's MariaDB code, not copied.

**Risk:** low. This is your most-tested architecture.

---

### Option 2 — OMS lineage

| | |
|---|---|
| Backend | **Laravel 13.8**, PHP 8.3/8.4 |
| Database | **MariaDB 11.8 LTS** |
| Keys / Money | auto-increment · integer cents + rejecting cast |
| Frontend | Inertia 3 + React 19 + TypeScript 7 + Bootstrap 5 + Vite 8 |
| RBAC | Hand-rolled `Ability` enum matrix *(would need replacing — see C-3)* |
| Queue | Redis + Horizon, three lanes |
| Testing | Pest 4 |

**Inherits:** the three-axis order engine, reservation inventory, append-only ledgers with DB
triggers, mutability policy, intake adapters — i.e. **the transaction core rather than the
kernel**.

**Cost:** the RBAC has to be rebuilt anyway (roles-as-code cannot serve an ERP), MariaDB gives
up `CHECK`-constraint richness and JSONB indexing, integer cents is awkward for cost accounting,
and auto-increment keys leak volume across tenants.

**Choose this if:** order/fulfilment throughput is the product and you want the newest Laravel.

---

### Option 3 — Filament-accelerated back office

| | |
|---|---|
| Backend | Laravel 12, PHP 8.3, **Filament 4** for admin/back-office |
| Database | PostgreSQL 16 |
| Frontend | Filament for the 22 CRUD modules; **Inertia + React only** for dashboards, order board, POS |
| RBAC | spatie + `filament-shield` |

**Why consider it:** you have ~6 Filament projects. 22 modules of master-data CRUD is where
most ERP build time actually goes, and Filament removes most of it.

**Cost:** two frontend paradigms in one app. Filament's authorization integrates with spatie
permissions but **not** with a custom data-scope layer without real work — and §17 is the
architectural centrepiece here. The scope resolver would need a Filament integration written
and tested separately from the Inertia one, which is exactly the "two isolation surfaces"
problem SMEOS warns about.

**Choose this if:** time-to-first-demo dominates and you accept a scope-enforcement seam.

---

### Recommendation

**Option 1**, with the OMS order/inventory patterns ported in. Reasoning: this ERP's hardest
problem is not order throughput and not CRUD volume — it is **authorization correctness across
22 modules with two scoping dimensions**. Option 1 inherits the only production-tested
isolation kernel and isolation test suite you own, and PostgreSQL gives the constraint and
indexing tools that make scoped queries provable rather than hopeful. Option 3's seam runs
straight through that centrepiece.

---

## 37. Security Considerations

Mapped to `40-security-protocol.md`, which is loaded and governs this project.

| Area | Position |
|---|---|
| Secrets | `.env` only, `.env.example` with placeholders, CI secret scan. AP-004 is `STRICT_BLOCK` |
| SQL injection | Eloquent/parameterised only; allowlists for any dynamic column |
| XSS | React auto-escapes; no `dangerouslySetInnerHTML` with user data; CSP headers |
| CSRF | Tokens on all state-changing forms; webhook routes exempt **with a documented reason** |
| Mass assignment | `company_id`, `branch_id`, status columns, money totals, `owner_user_id` all excluded from `$fillable`; CI grep enforces |
| **Tenant isolation** | Global scope + composite FKs + reflection-driven suite (§39) |
| **Data scope** | §17, fail-closed, one greppable escape hatch |
| File upload | MIME + extension validation, size cap, rename, **private disk** `company/{id}/`, cross-company download → 404 |
| API/webhook | HMAC signature, replay window, idempotency key, rate limit |
| Rate limiting | Login, exports, report generation, webhook ingress |
| PII | Not written into audit payloads; erasure path designed before launch (PDPA) |
| Production | Debug off, HTTPS enforced, HSTS/X-Frame-Options/X-Content-Type-Options, tested restores |

**Security gates are satisfiable only by a passing automated test, never by self-assessment**
— adopted verbatim from SMEOS's §15, which is the right standard.

---

## 38. Performance Considerations

Identified now, optimised only when measured (`45-performance-protocol.md`).

| Risk | Mitigation |
|---|---|
| Scoped queries add joins to every list | `(company_id, branch_id, owner_user_id)` composite indexes; `company_id` **leading** on every index |
| Order board at 50k+ orders/company | Keyset pagination, TanStack Virtual, no `COUNT(*)` on load |
| Commission runs over a period | Queued, chunked, per-recipient fault isolation |
| Dashboard aggregates | Precomputed reporting tables + live-query oracle in tests |
| Stock movement ledger growth | Denormalised `balance_after`; partitioning candidate at scale |
| Audit log growth | Retention policy + archival from day one |
| N+1 | **Query-count assertions in the Definition of Done** (SMEOS DoD item — cheap and effective) |
| Attribution reporting joins | Precomputed campaign-performance rollups |

`Model::shouldBeStrict()` outside production makes lazy loading fail loudly in development.

---

## 39. Testing Strategy

Governed by `25-test-protocol.md`. Pest, real database (never SQLite), four suites:
`Unit`, `Architecture`, **`Isolation`**, `Feature`.

**The isolation suite is the centrepiece and it is reflection-driven.** SMEOS's
`TenantIsolationTest` discovers every model carrying the tenancy trait rather than listing
them, with the stated rationale: *"a test that must be remembered is a test that will
eventually be forgotten."* Adopt this **and extend it to data scope** — a second reflection
pass over models implementing `Scopeable`.

Isolation assertions (10 from SMEOS + 6 new for data scope):

1. Discovers at least one scoped model (guards against reflection finding nothing)
2. Never returns another company's rows
3. Never finds another company's record by primary key (IDOR)
4. Stamps company on create rather than trusting input
5. Refuses to move a record between companies
6. Fails closed when no company is bound
7. `company_id` absent from `$fillable`
8. `company_id` NOT NULL on every scoped table (queried from `information_schema`)
9. Company context resolves before route-model binding
10. Context does not leak between requests
11. **`own` scope returns only the user's records**
12. **`team` scope returns exactly the team's records, no more**
13. **`branch` scope respects multi-branch membership**
14. **Exports, reports and PDFs apply the same scope as list screens**
15. **Escalating a role's scope does not escalate company isolation**
16. **`withoutDataScope()` appears only in allowlisted namespaces**

Plus: commission calculation golden-file tests (including **reversal and re-run idempotency**),
attribution resolution tests, order lifecycle illegal-transition tests, stock invariant
(`SUM(movements) == on_hand`), concurrency tests outside `RefreshDatabase` (last-unit
reservation, document numbering), approval routing by amount band, multi-company and
multi-branch feature tests.

**CI rule adopted from SMEOS with no override path:** the build fails if the isolation suite's
test count drops below `main` — *"deleting an isolation test breaks the build as loudly as
breaking one."*

---

## 40. Deployment Considerations

Governed by `51-deployment-protocol.md`.

VPS or container, PostgreSQL 16 + Redis 7, Nginx (**never `php -S`** — recorded anti-pattern:
it is single-threaded and any long-poll endpoint freezes the app), queue workers under
supervisor, Horizon dashboard behind platform auth, scheduler for reservation sweeps and
report rollups.

Zero-downtime deploys, migrations reviewed for lock duration (recorded anti-pattern: a wedged
`ALTER TABLE` holds a metadata lock that `KILL` does not clear). **Backup before risky DDL,
not after it goes wrong.** Nightly backup with a **rehearsed, documented restore** — an
untested backup is not a backup.

Environments: local → staging → production, with separate secrets. Test database isolated and
asserted by name in `Pest.php` (recorded anti-pattern: a bootstrap that falls back to `.env`
will migrate over the dev database).

---

## 41. Risks

| ID | Risk | Sev | Mitigation |
|---|---|---|---|
| R-01 | **Data-scope bug leaks records between users** | Critical | Reflection-driven scope suite; fail-closed resolver; single escape hatch; CI count guard |
| R-02 | **Cross-company leak** | Critical | Global scope + composite FKs + isolation suite |
| R-03 | **Commission pays twice** (observed in two prior systems) | Critical | Queued job + DB unique index + payout reservation + idempotency test |
| R-04 | **Commission cannot be explained to a marketer who disputes it** | High | Rule versioning + basis/inputs persisted + `commission_sources` |
| R-05 | Scope creep — 22 modules is a multi-year surface | High | Phase gates; §3 profiles decide priority; nothing past P4 until one real SME uses it |
| R-06 | Attribution ambiguity (two marketers claim one lead) | High | Explicit first/last touch + tie-break rules **decided before build**, not discovered in production |
| R-07 | Inventory oversell under concurrency | High | Blocking row locks, consistent lock ordering (fix OMS H-02/H-03), multi-process tests |
| R-08 | Approval engine over-generalised into unusable | Medium | Build for the 11 named approvables only |
| R-09 | Reporting drift between precomputed and live | Medium | Live-query oracle in tests |
| R-10 | Solo support burden | High | Named by OMS as its highest real-world risk. Ten SME tenants is a part-time support job |
| R-11 | PDPA vs append-only audit | Medium | Field-name-only audit payloads; erasure path designed now |
| R-12 | Estimating from SMEOS/OMS velocity | Medium | Both are narrower products (C-7) |
| R-13 | Two frontend paradigms (Option 3 only) | Medium | Avoided by Option 1 |

---

## 42. CoreSentinel Facts Recorded

Written to project-scoped memory at `Brand New ERP/.coresentinel/memory/`:

1. Target directory was empty at planning start
2. House stack convention from SMEOS (full dependency list)
3. CoreSentinel held zero domain knowledge (8 recall queries, 0 hits)
4. MPT-SaaS fail-closed `UserScope` data-scope pattern
5. MPT-SaaS module registry + plan-gating pattern
6. Stack survey of 36 sibling Laravel projects
7. OMS three-axis order status pattern
8. OMS tenancy pattern (`idOrFail` throws, composite FKs, no super-admin bypass)
9. OMS attribution/commission gap
10. AgentMgmt margin-as-commission pattern

Written to the shared failures layer (transferable across projects):

11. Commission rate on a mutable settings row with no effective dating
12. Commission calculated inline in a gateway callback with no idempotency
13. No reversal/clawback path in any prior commission system

**Not yet written, pending approval:** the 12 new ADRs (N-1…N-12). Recording an ADR asserts a
decision has been made; these are proposals. On approval they go to
`coresentinel decision add`.

---

## 43. Open Questions — your decision required

| # | Question | Options | My recommendation |
|---|---|---|---|
| **Q-1** | **Tech stack** | Option 1 / 2 / 3 (§36) | **Option 1** |
| **Q-2** | **Database engine** (C-1) | PostgreSQL 16 / MariaDB 11.8 | **PostgreSQL 16** |
| **Q-3** | **Money representation** (C-2) | `NUMERIC(15,4)` + VO / integer cents | **`NUMERIC(15,4)` + VO** |
| **Q-4** | **Primary target profile** (§3) | P-A social-commerce / P-B distributor / P-C multi-branch / P-D agent network | **P-A first**, it justifies the differentiator; P-C forces branch which we build anyway |
| **Q-5** | **Client work, own SaaS, or internal?** | — | **Still unanswered from Phase 0.** Changes multi-company, billing and handoff defaults |
| **Q-6** | **Attribution tie-break rule** | First-touch wins / last-touch wins / split by rule | Decide before P5. R-06 |
| **Q-7** | **Default commission strategy** | Percentage-of-value / percentage-of-margin / margin-as-ladder | Depends on Q-4 |
| **Q-8** | **Approval delegation in v1?** | Yes / defer | **Defer** — adds a permissions dimension |
| **Q-9** | **Multi-currency in v1?** | Yes / MYR only | **MYR only**, schema carries `currency` |
| **Q-10** | **SaaS billing in v1?** | Yes / registry only | **Registry only** |
| **Q-11** | **`git init` the project?** | — | Yes — unblocks 4 of 6 evidence checks |
| **Q-12** | **CoreSentinel Rule 7 (Windows)** (C-9) | Waive / CSE proposal | **CSE proposal** to make it platform-conditional |
| **Q-13** | **EVO-003** pending your review | Approve / reject | Your call |

---

## 44. Recommended Implementation Phases

Derived from the dependency graph (§5), not from the brief's example. Each phase has an
**exit gate satisfiable only by a passing test**.

| Phase | Name | Contents | Exit gate |
|---|---|---|---|
| **P0** | Foundation | Laravel + PG + Inertia/React scaffold, CI with forbidden-pattern guards, `Money` VO, **company tenancy kernel**, dual auth guards, **component library**, module registry | Isolation suite green for every scoped model; CI guards fail on a planted violation |
| **P1** | Access | RBAC (spatie teams) + **DataScope layer** + `ScopeResolver` + `Scopeable` + policies, Company/Branch/Department/User admin, Audit log | **All 16 isolation assertions green.** A salesperson cannot reach another's record via route, export, report or API — proven by test |
| **P2** | Master data | Customer, Supplier, Product (variants, pricing, tax, bundles), `PriceResolver`, document numbering | Price resolution returns a decomposition; numbering unique under concurrency |
| **P3** | Orders | Order + items + three-axis state machine + mutability policy + `order_events`, Quotation→SO→DO→Invoice→Payment | No status logic outside the state machine (grep-verified); illegal transitions rejected with a readable reason |
| **P4** | Inventory & Purchasing | Warehouses, stock, reservations, movements, transfers, counts; PR→PO→GRN→Bill→Payment; Approval engine | `SUM(movements) == on_hand`; last-unit reservation correct under 8 concurrent processes; three-way match blocks |
| **P5** | Sales force & Marketing | Sales teams, territories, targets, activities; marketers, channels, campaigns, leads, referral/promo codes; **Attribution domain** | All 12 attribution questions answered by a named tested query |
| **P6** | Commission | Plans, immutable versioned rules, strategies, queued calculation, reversal, payout, Finance posting | Re-run is idempotent (unique index proven); reversal produces a contra entry; every commission renders its explanation from data |
| **P7** | Finance | Accounts, journal, cash flow, AR/AP, expenses, payments, refunds, credit notes | Invoice→payment→outstanding reconciles to the cent; ageing buckets match fixture |
| **P8** | Reporting & Dashboards | Five role dashboards, precomputed rollups, exports | Every dashboard figure scope-filtered — proven by test; precomputed matches live-query oracle |
| **P9** | Hardening & Launch | Security review, performance pass, PDPA erasure, backup + **rehearsed restore**, deploy | External security review clean; restore rehearsed and documented |
| **P10** | Optional modules | HR, Payroll, POS, CRM, Projects, Assets, Tickets, Subscriptions | Per module |

**Hard scope gate:** no work past P4 until one real SME is using P0–P4. Adopted from SMEOS's
Sage veto, which is the most valuable governance rule in that document.

**Sequencing note:** P5 (Attribution) precedes P6 (Commission) because commission consumes
attribution. Building commission first is the mistake DZI made — it produced a commission
engine that cannot say which campaign earned the money.

---

## 45. Future Expansion Modules

HR · Payroll · POS · CRM · Project Management · Asset Management · Service/Ticketing ·
Subscription billing · Manufacturing/MRP · Advanced Accounting (full double-entry, tax
submission, LHDN MyInvois) · Advanced Analytics/AI.

**Two reservations made now so the later migration is additive, not destructive:**

1. **LHDN MyInvois** — SMEOS reserved invoice schema for it in P4 *"so the later integration is
   never a destructive migration on the ledger."* Malaysian e-invoicing is a legal requirement
   trending toward mandatory for SMEs. Reserve the columns.
2. **Serial / batch / expiry** — nullable `lot_id` and `serial_id` on `stock_movements` (§10).

---

## Appendix A — Verification Baseline

Captured before any work, so progress is measured against an honest zero rather than an assumed green.

```
coresentinel verify  → INDETERMINATE · 0 pass, 0 fail, 6 UNKNOWN · evidence coverage 0/100
coresentinel score   → CRITICAL · 0/100 across 5 evaluable dimensions
coresentinel doctor  → DEGRADED · 2 findings (EVO-003 pending review; no git repository)
coresentinel agent   → 17 contracts complete, permissions enforced
```

All six UNKNOWNs trace to the same root: empty directory, no git, no test runner, no linter,
no dependency manifest. Per `02-quality-gates-protocol.md`, `UNKNOWN` is **not a pass** — it is
the honest state for a gate that could not be evaluated.

---

## Appendix B — Sources

**Read directly by Iris:** `00-identity.md`, `02-team-protocol.md`,
`02-quality-gates-protocol.md`, `05-init-protocol.md`, `40-security-protocol.md`,
`55-self-evolution.md`, `anti-patterns.json`; `MPT-SaaS` (`UserScope`, `Module`,
`UserModuleSetting`, `Plan`); `SMEOS` manifests; composer/package manifests of all 36 sibling
projects; CoreSentinel CLI output (`status`, `doctor`, `brief`, `verify`, `score`, `agent
list`, `task plan`, `recall` ×8, `pattern list`, `decision list`, `evolve list`).

**Read by specialist sub-agents** (read-only, findings verified against file paths):
`SMEOS` full architecture · `SaaS-OMS` full architecture · commission and attribution
comparison across `dzi-holistik-ordering-system`, `saas-multi-tenant-AgentStockit-Management-System`,
`SAAS-Agent-Management-System`.

**Not read:** the contents of SMEOS's 8 `docs/*.md` companion specs; DZI's `ReportService` in
full. Available on request.
