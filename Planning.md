# SME ERP / Business Operating System — Planning

**Status:** PLANNING ONLY — NOT APPROVED FOR EXECUTION
**Author:** Iris (CoreSentinel-governed)
**Date:** 2026-08-15
**Doc version:** 1.1 — all 13 open questions resolved by Fakrul, plus 2 new questions raised and resolved
**Approval required from:** Fakrul — **execution still NOT approved**

> **v1.1 changes:** §43 open questions are resolved and recorded as ADR-005…ADR-010 in the
> project decision ledger. Scope reduced (no SaaS shell, §35). Commission basis and its
> provisional/final consequence added (§13.4). Attribution tie-break fixed to first-touch
> (§14.4). Stack selected (§36). Phases re-cut (§44). Two new risks (R-14, R-15).

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

| Short name | Path                              | What it is                                                                                                         |
| ---------- | --------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| **SMEOS**  | `../SMEOS`                        | Multi-tenant B2B SaaS "business command center", Laravel 12 + PostgreSQL 16. P0–P3 shipped, 40 commits, ~291 tests |
| **OMS**    | `../SaaS-OMS`                     | Order + fulfilment engine, Laravel 13.8 + MariaDB 11.8. 465 tests / 2084 assertions                                |
| **DZI**    | `../dzi-holistik-ordering-system` | Single-tenant ordering + commission + marketing-spend system, Laravel 12, 47 models, 76 migrations                 |

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

| #    | Objective                                                                             | Success measure                                                                                  |
| ---- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| BO-1 | Let an SME run customers, products, orders, stock, purchasing and money in one system | A trading SME can operate a full month without a spreadsheet                                     |
| BO-2 | Attribute every order to its true commercial origin                                   | Any order answers all 12 attribution questions in §14                                            |
| BO-3 | Pay marketers, salespeople and teams correctly and explainably                        | Every commission renders a plain-language explanation naming rule, basis, rate and source orders |
| BO-4 | Let each SME enable only the modules it needs                                         | A services SME can run with Inventory and Purchasing disabled and see no dead nav                |
| BO-5 | Enforce who-sees-what at the data layer                                               | A salesperson cannot read another salesperson's orders through any route, export, report or API  |
| BO-6 | Stay extensible                                                                       | Adding a module touches the module registry and its own namespace, not the kernel                |

**Explicit non-objectives for v1:** full double-entry accounting, manufacturing/MRP, payroll,
POS hardware integration, native mobile apps, public API.

---

## 3. Target SME Profiles

The architecture must serve at least these four without forcing unused modules on any of them.

| Profile                               | Shape                                                                      | Modules used                                                                         | Attribution need                                                                   |
| ------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------- |
| **P-A — Social-commerce trader**      | Sells via FB/IG/WhatsApp/TikTok, marketers generate leads, closers convert | Orders, Products, Inventory, Marketing, Commission, Finance                          | **Highest.** Campaign → marketer → lead → order → commission is the whole business |
| **P-B — B2B distributor**             | Field sales team, territories, credit terms, purchase orders               | Customers, Suppliers, Orders, Purchasing, Inventory, Sales Team, Commission, Finance | Salesperson + team, not campaign                                                   |
| **P-C — Multi-branch retail/service** | 2–8 branches, branch managers, branch stock                                | Branches, Products, Orders, Inventory, RBAC scope, Finance                           | Branch + channel                                                                   |
| **P-D — Agent/reseller network**      | Tiered resellers buying at tier price and reselling                        | Products (tier pricing), Orders, Commission (margin model), Inventory                | Upline/downline                                                                    |

P-A and P-D are the profiles that justify this project existing rather than buying an
off-the-shelf ERP. **P-C is the profile that forces branch into the scope model from day one.**

---

## 4. Functional Modules

Core (22), matching the brief. `is_core` modules cannot be disabled.

| #   | Module                | Key             | Core? | Notes                                              |
| --- | --------------------- | --------------- | ----- | -------------------------------------------------- |
| 1   | Dashboard             | `dashboard`     | ✔     | Role-aware; five variants (§20)                    |
| 2   | Company Management    | `companies`     | ✔     | The tenant entity                                  |
| 3   | Branch Management     | `branches`      | ✔     | Kernel dependency — see §5                         |
| 4   | User Management       | `users`         | ✔     |                                                    |
| 5   | RBAC / Authorization  | `access`        | ✔     | Kernel, not a module in the disableable sense      |
| 6   | Customer Management   | `customers`     | ✔     |                                                    |
| 7   | Supplier Management   | `suppliers`     |       |                                                    |
| 8   | Product Management    | `products`      | ✔     |                                                    |
| 9   | Order Management      | `orders`        | ✔     | Central transaction engine                         |
| 10  | Sales Management      | `sales`         |       | Quotation → SO → DO → Invoice → Payment            |
| 11  | Sales Team Management | `sales_teams`   |       |                                                    |
| 12  | Marketer Management   | `marketers`     |       |                                                    |
| 13  | Marketing / Campaign  | `campaigns`     |       |                                                    |
| 14  | Marketing Attribution | `attribution`   |       | Domain service; surfaces as reports                |
| 15  | Commission            | `commission`    |       |                                                    |
| 16  | Inventory             | `inventory`     |       |                                                    |
| 17  | Purchasing            | `purchasing`    |       |                                                    |
| 18  | Finance               | `finance`       |       |                                                    |
| 19  | Reporting             | `reports`       |       |                                                    |
| 20  | Approval Workflow     | `approvals`     | ✔     | Kernel service; other modules register approvables |
| 21  | Audit Log             | `audit`         | ✔     | Kernel service                                     |
| 22  | Notification          | `notifications` | ✔     | Kernel service                                     |

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

| Process             | Entry                  | Exit                                 | Owning module                |
| ------------------- | ---------------------- | ------------------------------------ | ---------------------------- |
| Lead-to-Cash        | Campaign/lead captured | Payment received, commission accrued | Marketing → Orders → Finance |
| Order-to-Fulfilment | Order approved         | Delivered                            | Orders → Inventory           |
| Procure-to-Pay      | Purchase request       | Supplier paid                        | Purchasing → Finance         |
| Stock-to-Truth      | Any movement           | `SUM(movements) == on_hand`          | Inventory                    |
| Earn-to-Payout      | Order qualifies        | Commission paid + ledger posted      | Commission → Finance         |
| Request-to-Approval | Approvable submitted   | Approved/rejected with history       | Approvals                    |

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

| #     | Anti-pattern                                                                                             | Where verified                                                                           | Consequence                                                                   |
| ----- | -------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| CA-1  | Rate on a **mutable** settings row, no effective dating                                                  | DZI `commission_ranks.rate`, AgentStockit `commission_settings.rate`, AgentMgmt KV store | Editing a rate silently changes what a re-run of a **closed** period produces |
| CA-2  | Commission row **cannot name the rule that made it** — no `rank_id`, `setting_id`, rate or basis columns | all three                                                                                | Explanation exists only as English prose in a `note` string; machine-useless  |
| CA-3  | Aggregate commissions carry `order_id = NULL`                                                            | DZI `type='earned'`                                                                      | A month's commission is unauditable — cannot join back to its source orders   |
| CA-4  | Calculation **inline in a payment-gateway callback**, no unique index, no idempotency                    | AgentStockit `PaymentController:36`, AgentMgmt `PaymentController:208`                   | Gateways retry → **duplicate money**                                          |
| CA-5  | Payout sweeps approved commissions but never flips them to paid                                          | AgentStockit `PayoutService::createBatch()`                                              | **Run twice → pays twice**                                                    |
| CA-6  | **No reversal path anywhere.** Refund/return/cancel after payout does nothing                            | all three                                                                                | Overpaid commission is unrecoverable except by free-text adjustment           |
| CA-7  | Hard-`delete()` of a commission row to "correct" it                                                      | DZI `CommissionService::discardStale()`                                                  | Money rows vanish with no tombstone                                           |
| CA-8  | Free-transition status setter (`paid → pending` allowed)                                                 | DZI `CommissionController::setStatus()`                                                  | No state integrity                                                            |
| CA-9  | Config surface the engine never reads (`scope=rank                                                       | product`, `conditions` jsonb)                                                            | DZI                                                                           | Lies to the admin |
| CA-10 | Payout never posts to the financial ledger                                                               | DZI `CommissionPayoutService::pay()`                                                     | Commission expense absent from P&L                                            |

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

### 13.4 Commission basis — DECIDED (ADR-009)

**Default strategy: `PercentageOfMargin`, where margin is *full* contribution margin:**

```
margin = sales − product cost − shipping/postage − payment fees − allocated ad spend
```

This is the truest-profit basis (the DZI model). It was chosen deliberately over simpler
bases, and it carries **three consequences that are now mandatory design, not options**:

**(a) Commission has a two-stage life.** Shipping is not known until the parcel ships, payment
fees are not known until settlement, and ad spend is attributed per campaign and period rather
than per order. Therefore a commission **accrues provisionally on estimates** and is
**restated at period close against reconciled actuals**.

- The restatement is an **audited adjustment entry**, never a silent re-run — DZI's silent
  re-run is exactly what makes its figures unauditable.
- `commissions.is_provisional` plus a `finalised_at` timestamp. A provisional commission may
  reach `approved` but **never `payable`**.
- DZI's `Order::effective*()` idiom (use reconciled actuals when present, else estimates) is
  adopted for the basis computation. It exists in that codebase precisely because this problem
  is real.

**(b) Ad spend needs an allocation rule.** Ad spend lands on `(campaign, period)`, commission
lands on an order. The allocation rule is a **plan setting**, not a hard-coded formula:
`pro_rata_by_order_value` (default) · `equal_per_order` · `pro_rata_by_margin` ·
`excluded` (ignore ad spend for this plan). Orders in a period with zero attributed campaign
spend simply carry a zero ad component.

**(c) Marketers see their own ad spend reduce their pay.** This is a business consequence, not
a technical one, but it must be surfaced in the UI or it will be discovered as a dispute. The
commission explanation (§13.3) therefore itemises **every deduction**:

> **Commission RM38.50** — Recipient: Ali (Marketer) · Rule: "FB Campaign Margin" v3
> (effective 2026-07-01) · **12% of margin RM320.80**
> Sales RM1,000.00 − Cost RM520.00 − Shipping RM49.20 − Fees RM30.00 − Ads RM80.00
> · Order #10025 · **Provisional** — final at period close

**Cost accuracy is now a payroll problem.** Margin-based commission means a wrong landed cost
produces a wrong payment. Purchasing/landed-cost (P4) must therefore be correct **before**
Commission (P6) goes live — the phase order already satisfies this, but it is now a hard
dependency rather than a convenience. See R-14.

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

### 14.4 Tie-break — DECIDED (ADR-008)

**First touch wins.** When two or more marketers touch the same lead before it converts,
commission credit goes to the marketer who **originally generated the lead**.

Rationale: it rewards lead generation, and it protects a marketer whose lead takes weeks to
convert from having the credit taken by whoever happened to touch it last. This is the fair
model for the social-commerce profile, where lead generation is the scarce work.

Implementation consequences:

- **Every touch is still recorded** in `attribution_touches`. First-touch is the *credit* rule,
  not a reason to discard data — last-touch and multi-touch reporting remain available, and
  changing the credit rule later is a rule change, not a migration.
- `attributions.touch_type` distinguishes `first` from `last`; commission joins on `first`.
- The first touch is **immutable once an order exists against the lead**. Re-attributing a lead
  after money has been paid on it is forbidden by the same logic that freezes the order snapshot.
- **Edge case that must be tested:** a lead with no recorded first touch (walk-in, manual entry,
  direct marketplace order). Credit is *unattributed*, not defaulted to whoever is convenient.
  A null marketer is a valid, first-class state.

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

| Scope     | Constraint                                                                                                     |
| --------- | -------------------------------------------------------------------------------------------------------------- |
| `own`     | `owner_user_id = user.id`                                                                                      |
| `team`    | `owner_user_id IN (user's team member ids)` — resolved through `sales_team_members`, honouring hierarchy depth |
| `branch`  | `branch_id IN (user's branch ids)`                                                                             |
| `company` | no additional constraint (the company global scope already applies)                                            |
| `all`     | platform guard only; never grantable to a company role                                                         |

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

| User  | Role            | `orders.view`                       | Effective query                                    |
| ----- | --------------- | ----------------------------------- | -------------------------------------------------- |
| Ahmad | Salesperson     | ✔ scope `own`                       | `WHERE company_id = X AND owner_user_id = ahmad`   |
| Siti  | Sales Manager   | ✔ scope `team`                      | `WHERE company_id = X AND owner_user_id IN (team)` |
| Rahim | Branch Manager  | ✔ scope `branch`                    | `WHERE company_id = X AND branch_id IN (rahim's)`  |
| Lina  | Finance Manager | ✔ (`invoices.view`) scope `company` | `WHERE company_id = X`                             |
| Admin | System Admin    | ✔ scope `all`                       | platform guard, audited, reason required           |

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

**Revised for ADR-005 (client project, no SaaS shell):** with no platform tier, there is no
platform operator to separate from tenant users, so the second guard has nothing to guard.
**v1 ships a single `web` guard.**

What is **kept regardless**, because it is the part that actually mattered: **no privilege
boolean on `users`.** SMEOS conflict C-01 records that three of its lanes designed a
`users.is_platform_owner` flag and its security lead vetoed it as *extinction-level* (THR-005)
— one boolean on a shared table is one mass-assignment bug away from total compromise. The
architecture test asserting the **absence** of `is_platform_owner`, `is_super_admin`,
`is_admin` is adopted as-is. Elevated access comes from a role, which goes through the scope
resolver, and is therefore audited.

If the client later resells the system, the second guard is added then — and because no
privilege boolean exists, that addition is additive rather than a security rewrite.

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

| Contribution                                                       | Effect on this plan                                                                                                                                  |
| ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| **ADR-003** (project-scoped memory)                                | Project was bound *before* any fact was recorded, so ERP facts did not leak into the shared Core store — the exact failure ADR-003 exists to prevent |
| **AP-004 `STRICT_BLOCK`** — "Hardcoded Secrets & Unscoped Queries" | The data-scope architecture (§17) is enforced by the scanner, not just by intent                                                                     |
| **Anti-pattern: empty grep ≠ proof of absence**                    | Every "absent" claim in this document names its scan scope                                                                                           |
| **Anti-pattern: unverified success claims**                        | Every architectural claim here is marked read-verified or inferred                                                                                   |
| **Skill: snapshot money, render branding live**                    | §8 invoice design                                                                                                                                    |
| **Skill: separate verified from secondhand**                       | §0 and the agent-vs-direct-read distinction throughout                                                                                               |
| `task plan`                                                        | Produced the 12-role pipeline used to structure §44                                                                                                  |
| `doctor` / `verify` / `score`                                      | Established an honest zero baseline (6 UNKNOWN, INDETERMINATE) rather than a false green                                                             |

**11 facts were written to project memory and the failures layer** during planning
(6 project-scope, 3 failures-scope, plus stack survey). They are listed in §42.

### 34.2 Existing decisions that must be preserved

From the sibling projects, these are decided-and-proven and should not be relitigated:

| ID   | Decision                                                                         | Source                                |
| ---- | -------------------------------------------------------------------------------- | ------------------------------------- |
| E-1  | Single DB, row-scoped tenancy; no tenancy package                                | SMEOS ADR-001/008                     |
| E-2  | Tenant context **throws** when unresolved                                        | SMEOS G-1a, OMS `idOrFail()`          |
| E-3  | `company_id` never in `$fillable`; stamped on create; immutable on update        | SMEOS `BelongsToOrganization`         |
| E-4  | Composite `(company_id, id)` FKs so the DB rejects cross-tenant references       | SMEOS + OMS                           |
| E-5  | Dual auth guards, separate platform user table                                   | SMEOS ADR-013 (security veto)         |
| E-6  | No `Gate::before` super-admin bypass                                             | OMS `BasePolicy`                      |
| E-7  | Money through a value object; never float                                        | SMEOS `Money`, OMS `Money` cast       |
| E-8  | Status columns excluded from `$fillable`; state machine is sole writer           | OMS                                   |
| E-9  | Append-only ledgers enforced at model **and** DB trigger                         | OMS                                   |
| E-10 | Reservation-based inventory; `available` derived                                 | OMS ADR-07                            |
| E-11 | Zero-comment policy, enforced by test + CI                                       | SMEOS §17, and the global `CLAUDE.md` |
| E-12 | Reflection-driven isolation suite (discovers models, not a hand-maintained list) | SMEOS `TenantIsolationTest`           |
| E-13 | Server-built navigation; hide not lock                                           | SMEOS `NavigationBuilder`             |
| E-14 | Seed only shipped modules                                                        | SMEOS C-05                            |

### 34.3 New decisions proposed (require approval)

| ID   | Decision                                                                                             | Why                                                                              |
| ---- | ---------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| N-1  | **`Permission × DataScope`** authorization, scope on the role↔permission pivot                       | §17; no prior system has it and retrofit is the most expensive available mistake |
| N-2  | **Branch is kernel, not a deferred module**                                                          | It participates in scope resolution, therefore in every query                    |
| N-3  | **Attribution is its own polymorphic domain**                                                        | §14; five systems prove the cost of columns-on-order                             |
| N-4  | **Commission rules immutable + effective-dated; every commission stamps its rule version and basis** | Fixes CA-1, CA-2, CA-3                                                           |
| N-5  | **Commission reversal as a contra entry**, never delete                                              | Fixes CA-6, CA-7                                                                 |
| N-6  | **Commission calculated in a queued job with a DB unique index**, never in a gateway callback        | Fixes CA-4, CA-5                                                                 |
| N-7  | **Three-axis order status**                                                                          | §7                                                                               |
| N-8  | **Payout posts to Finance in the same transaction**                                                  | Fixes CA-10                                                                      |
| N-9  | **Plural permission group naming** (`products.view`)                                                 | Matches 49 shipped SMEOS keys; brief delegated this                              |
| N-10 | **Share permissions to the frontend for UX**, backend remains the boundary                           | Fixes SMEOS's always-render-then-403 UX                                          |
| N-11 | **Component library built in P0**, not deferred                                                      | SMEOS deferred it and pays in duplication                                        |
| N-12 | **Partial fulfilment per line**                                                                      | Absent in OMS; an ERP needs it                                                   |

### 34.4 Conflicts identified

| ID      | Conflict                                                                                                                                                                                                    | Resolution proposed                                                                                                                                                                                                    |
| ------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **C-1** | **Database engine.** SMEOS = PostgreSQL 16 (hard requirement, `phpunit.xml` pins it). OMS = MariaDB 11.8 LTS, with a recorded "never MySQL" position because its row-locking guarantees are engine-specific | **Decide once, at approval.** Recommend PostgreSQL — see §36 Option 1. Whichever wins, the other codebase's SQL-level patterns need translation, not copy-paste                                                        |
| **C-2** | **Money representation.** SMEOS = `NUMERIC(15,4)` + bcmath `Money`. OMS = integer cents + a cast that rejects floats                                                                                        | **Recommend `NUMERIC(15,4)` + Money VO.** Integer cents is chosen in OMS to avoid float drift, but PostgreSQL `NUMERIC` is already exact, and an ERP needs sub-cent precision for unit costs and percentage commission |
| **C-3** | **RBAC mechanism.** SMEOS = spatie teams mode (DB-driven, customer-editable). OMS = hand-rolled enum matrix (auditable in one file, but roles are code)                                                     | **Recommend spatie teams mode** — an SME must create its own roles without a deploy                                                                                                                                    |
| **C-4** | **Primary keys.** SMEOS = ULID everywhere (ADR-002). OMS = auto-increment                                                                                                                                   | **Recommend ULID.** Bigint leaks tenant volume and is enumerable — a real IDOR-adjacent risk in multi-tenant                                                                                                           |
| **C-5** | **Tenant entity naming.** SMEOS = `Organization`. OMS = `Tenant`. Brief = `Company`                                                                                                                         | **Use `Company`**, matching the brief and SME vocabulary. Note this means SMEOS code is renamed on the way in, not copied                                                                                              |
| **C-6** | **DTO layer.** SMEOS has none (empty `app/Data/`). OMS uses readonly DTOs                                                                                                                                   | **Adopt OMS's** — SMEOS's absence causes the duplication it complains about                                                                                                                                            |
| **C-7** | **Scope of "ERP" itself.** SMEOS positions explicitly as *"not an ERP"*; OMS declares full ERP/accounting *"integration target, not build target"*                                                          | Both are narrower products. **This project is a genuinely larger build than either**, and the phase plan (§44) must not be estimated from their velocity                                                               |
| **C-8** | Brief §16 proposes singular permission names; house convention is plural                                                                                                                                    | N-9                                                                                                                                                                                                                    |
| **C-9** | `02-team-protocol.md` Standing Rule #7 mandates Windows/PowerShell/`python`; this machine is macOS/zsh/`python3`                                                                                            | Needs a `gate waive` or a CSE proposal to make Rule 7 platform-conditional. Flagged, not silently ignored                                                                                                              |

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

**SaaS — DECIDED: NOT BUILT (ADR-005).** This is client delivery for one SME with several
branches. The following are **explicitly out of scope**: `packages`, `package_modules`,
`features`, `subscriptions`, the platform-owner console, and any billing or plan-gating layer.

What is still built, and why:

| Kept                                                | Reason                                                                                         |
| --------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `company_id` + global scope on every business table | Cheap now, expensive to retrofit. One company row exists; the mechanism costs almost nothing   |
| Module registry + per-company enable/disable        | The brief requires modules to be enable-able. Registry stays; **`min_plan` gating is dropped** |
| Branch, Department, User hierarchy                  | Kernel — it feeds data scope                                                                   |

**Dropped from §23:** `packages`, `package_modules`, `features`, `subscriptions` — 4 tables.
**Dropped from §4:** `min_plan` on the module registry.

**Consequence worth stating plainly: data scope is now the primary authorization axis.** With
one company, company isolation protects nothing on its own — every user is in the same company.
What actually stands between a salesperson and another salesperson's orders is §17. This raises
the stakes on the scope layer and lowers them on tenant isolation, which is the reverse of
SMEOS's situation.

**Handoff applies.** Client work triggers `52-handoff-protocol.md` at ship: documentation,
credentials transfer, and a deployment runbook the client can execute without the developer.

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

|              |                                                             |
| ------------ | ----------------------------------------------------------- |
| Backend      | **Laravel 12 LTS**, PHP 8.3                                 |
| Database     | **PostgreSQL 16**                                           |
| Keys / Money | ULID · `NUMERIC(15,4)` + bcmath `Money` VO                  |
| Frontend     | Inertia 3 + React 19 + TypeScript + Bootstrap 5 + Vite      |
| RBAC         | `spatie/laravel-permission` teams mode + custom scope layer |
| Queue        | Redis + Horizon                                             |
| Testing      | Pest 3 + Larastan + Pint                                    |

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

|              |                                                                      |
| ------------ | -------------------------------------------------------------------- |
| Backend      | **Laravel 13.8**, PHP 8.3/8.4                                        |
| Database     | **MariaDB 11.8 LTS**                                                 |
| Keys / Money | auto-increment · integer cents + rejecting cast                      |
| Frontend     | Inertia 3 + React 19 + TypeScript 7 + Bootstrap 5 + Vite 8           |
| RBAC         | Hand-rolled `Ability` enum matrix *(would need replacing — see C-3)* |
| Queue        | Redis + Horizon, three lanes                                         |
| Testing      | Pest 4                                                               |

**Inherits:** the three-axis order engine, reservation inventory, append-only ledgers with DB
triggers, mutability policy, intake adapters — i.e. **the transaction core rather than the
kernel**.

**Cost:** the RBAC has to be rebuilt anyway (roles-as-code cannot serve an ERP), MariaDB gives
up `CHECK`-constraint richness and JSONB indexing, integer cents is awkward for cost accounting,
and auto-increment keys leak volume across tenants.

**Choose this if:** order/fulfilment throughput is the product and you want the newest Laravel.

---

### Option 3 — Filament-accelerated back office

|          |                                                                                             |
| -------- | ------------------------------------------------------------------------------------------- |
| Backend  | Laravel 12, PHP 8.3, **Filament 4** for admin/back-office                                   |
| Database | PostgreSQL 16                                                                               |
| Frontend | Filament for the 22 CRUD modules; **Inertia + React only** for dashboards, order board, POS |
| RBAC     | spatie + `filament-shield`                                                                  |

**Why consider it:** you have ~6 Filament projects. 22 modules of master-data CRUD is where
most ERP build time actually goes, and Filament removes most of it.

**Cost:** two frontend paradigms in one app. Filament's authorization integrates with spatie
permissions but **not** with a custom data-scope layer without real work — and §17 is the
architectural centrepiece here. The scope resolver would need a Filament integration written
and tested separately from the Inertia one, which is exactly the "two isolation surfaces"
problem SMEOS warns about.

**Choose this if:** time-to-first-demo dominates and you accept a scope-enforcement seam.

---

### SELECTED: Option 1 (ADR-006)

Chosen by Fakrul 2026-08-15. Q-2 (PostgreSQL 16) and Q-3 (`NUMERIC(15,4)` + Money VO) are
resolved by this choice. Recorded as ADR-006 in the project ledger.

### Recommendation *(as argued at the time of the decision)*

**Option 1**, with the OMS order/inventory patterns ported in. Reasoning: this ERP's hardest
problem is not order throughput and not CRUD volume — it is **authorization correctness across
22 modules with two scoping dimensions**. Option 1 inherits the only production-tested
isolation kernel and isolation test suite you own, and PostgreSQL gives the constraint and
indexing tools that make scoped queries provable rather than hopeful. Option 3's seam runs
straight through that centrepiece.

---

## 37. Security Considerations

Mapped to `40-security-protocol.md`, which is loaded and governs this project.

| Area                 | Position                                                                                                                 |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Secrets              | `.env` only, `.env.example` with placeholders, CI secret scan. AP-004 is `STRICT_BLOCK`                                  |
| SQL injection        | Eloquent/parameterised only; allowlists for any dynamic column                                                           |
| XSS                  | React auto-escapes; no `dangerouslySetInnerHTML` with user data; CSP headers                                             |
| CSRF                 | Tokens on all state-changing forms; webhook routes exempt **with a documented reason**                                   |
| Mass assignment      | `company_id`, `branch_id`, status columns, money totals, `owner_user_id` all excluded from `$fillable`; CI grep enforces |
| **Tenant isolation** | Global scope + composite FKs + reflection-driven suite (§39)                                                             |
| **Data scope**       | §17, fail-closed, one greppable escape hatch                                                                             |
| File upload          | MIME + extension validation, size cap, rename, **private disk** `company/{id}/`, cross-company download → 404            |
| API/webhook          | HMAC signature, replay window, idempotency key, rate limit                                                               |
| Rate limiting        | Login, exports, report generation, webhook ingress                                                                       |
| PII                  | Not written into audit payloads; erasure path designed before launch (PDPA)                                              |
| Production           | Debug off, HTTPS enforced, HSTS/X-Frame-Options/X-Content-Type-Options, tested restores                                  |

**Security gates are satisfiable only by a passing automated test, never by self-assessment**
— adopted verbatim from SMEOS's §15, which is the right standard.

---

## 38. Performance Considerations

Identified now, optimised only when measured (`45-performance-protocol.md`).

| Risk                                   | Mitigation                                                                                          |
| -------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Scoped queries add joins to every list | `(company_id, branch_id, owner_user_id)` composite indexes; `company_id` **leading** on every index |
| Order board at 50k+ orders/company     | Keyset pagination, TanStack Virtual, no `COUNT(*)` on load                                          |
| Commission runs over a period          | Queued, chunked, per-recipient fault isolation                                                      |
| Dashboard aggregates                   | Precomputed reporting tables + live-query oracle in tests                                           |
| Stock movement ledger growth           | Denormalised `balance_after`; partitioning candidate at scale                                       |
| Audit log growth                       | Retention policy + archival from day one                                                            |
| N+1                                    | **Query-count assertions in the Definition of Done** (SMEOS DoD item — cheap and effective)         |
| Attribution reporting joins            | Precomputed campaign-performance rollups                                                            |

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

| ID       | Risk                                                                                                                                                                 | Sev      | Mitigation                                                                                                                                                                                       |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| R-01     | **Data-scope bug leaks records between users**                                                                                                                       | Critical | Reflection-driven scope suite; fail-closed resolver; single escape hatch; CI count guard                                                                                                         |
| R-02     | **Cross-company leak**                                                                                                                                               | Critical | Global scope + composite FKs + isolation suite                                                                                                                                                   |
| R-03     | **Commission pays twice** (observed in two prior systems)                                                                                                            | Critical | Queued job + DB unique index + payout reservation + idempotency test                                                                                                                             |
| R-04     | **Commission cannot be explained to a marketer who disputes it**                                                                                                     | High     | Rule versioning + basis/inputs persisted + `commission_sources`                                                                                                                                  |
| R-05     | Scope creep — 22 modules is a multi-year surface                                                                                                                     | High     | Phase gates; §3 profiles decide priority; nothing past P4 until one real SME uses it                                                                                                             |
| R-06     | Attribution ambiguity (two marketers claim one lead)                                                                                                                 | High     | Explicit first/last touch + tie-break rules **decided before build**, not discovered in production                                                                                               |
| R-07     | Inventory oversell under concurrency                                                                                                                                 | High     | Blocking row locks, consistent lock ordering (fix OMS H-02/H-03), multi-process tests                                                                                                            |
| R-08     | Approval engine over-generalised into unusable                                                                                                                       | Medium   | Build for the 11 named approvables only                                                                                                                                                          |
| R-09     | Reporting drift between precomputed and live                                                                                                                         | Medium   | Live-query oracle in tests                                                                                                                                                                       |
| R-10     | Solo support burden                                                                                                                                                  | High     | Named by OMS as its highest real-world risk. Ten SME tenants is a part-time support job                                                                                                          |
| R-11     | PDPA vs append-only audit                                                                                                                                            | Medium   | Field-name-only audit payloads; erasure path designed now                                                                                                                                        |
| R-12     | Estimating from SMEOS/OMS velocity                                                                                                                                   | Medium   | Both are narrower products (C-7)                                                                                                                                                                 |
| R-13     | Two frontend paradigms (Option 3 only)                                                                                                                               | Medium   | **Retired** — Option 1 selected (ADR-006)                                                                                                                                                        |
| **R-14** | **Wrong landed cost produces wrong commission.** ADR-009 makes commission a function of margin, so a costing error is a payment error, and marketers will dispute it | **High** | Landed cost correct and tested in P4 before Commission ships in P6; cost snapshotted onto the order line at sale; a costing-change report showing which commissions a cost correction would move |
| **R-15** | **Provisional commission never gets finalised.** Accruals sit provisional forever because nobody closes the period, and marketers are paid on estimates              | **High** | A provisional commission can reach `approved` but **never `payable`**; a period-close job is scheduled, not manual; an ageing alert fires on provisional accruals older than one closed period   |

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

**Written after Fakrul resolved §43 (2026-08-15)** — six ADRs now in the project ledger at
`.coresentinel/memory/decisions.json`:

| ADR     | Decision                                                            |
| ------- | ------------------------------------------------------------------- |
| ADR-005 | Client project, single company multi-branch, no SaaS shell          |
| ADR-006 | Stack: Laravel 12 + PostgreSQL 16 + Inertia/React 19/TS/Bootstrap 5 |
| ADR-007 | Authorization is `Permission × DataScope` from commit one           |
| ADR-008 | Attribution is its own polymorphic domain; first-touch wins         |
| ADR-009 | Commission pays a percentage of full gross margin                   |
| ADR-010 | Commission rules immutable and effective-dated                      |

The remaining proposals (N-7 three-axis order status, N-11 component library in P0, N-12
partial fulfilment, and the rest) are **still proposals** and are not in the ledger — they were
not individually put to Fakrul, and recording an unasked decision as decided is the failure
`55-self-evolution.md` separates *approve* from *apply* to prevent.

**Governance actions taken:** EVO-003 approved (apply refused — stale, see Q-13);
EVO-004 filed for CoreSentinel Standing Rule 7, `PENDING_REVIEW`.

---

## 43. Open Questions — RESOLVED 2026-08-15

All 13 resolved by Fakrul, plus 2 new questions raised during resolution and also resolved.

| #                | Question                            | **Decision**                                                                                                                                                                                                    | Recorded       |
| ---------------- | ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- |
| **Q-1**          | Tech stack                          | **Option 1 — SMEOS lineage**                                                                                                                                                                                    | ADR-006        |
| **Q-2**          | Database engine (C-1)               | **PostgreSQL 16**                                                                                                                                                                                               | ADR-006        |
| **Q-3**          | Money representation (C-2)          | **`NUMERIC(15,4)` + bcmath Money VO**                                                                                                                                                                           | ADR-006        |
| **Q-4**          | Primary target profile              | **P-A social-commerce trader**                                                                                                                                                                                  | §3             |
| **Q-5**          | Work mode                           | **Client project** — handoff protocol applies                                                                                                                                                                   | ADR-005        |
| **Q-6**          | Attribution tie-break               | **First touch wins**                                                                                                                                                                                            | ADR-008, §14.4 |
| **Q-7**          | Default commission strategy         | **Percentage of gross margin**                                                                                                                                                                                  | ADR-009, §13.4 |
| **Q-8**          | Approval delegation in v1           | **Defer to v1.1**                                                                                                                                                                                               | §19            |
| **Q-9**          | Multi-currency in v1                | **MYR only**; `currency` column retained                                                                                                                                                                        | §24            |
| **Q-10**         | SaaS billing in v1                  | **Not built** — superseded by Q-14                                                                                                                                                                              | ADR-005, §35   |
| **Q-11**         | `git init`                          | **Done** — repo initialised, planning committed `e30910b`                                                                                                                                                       | —              |
| **Q-12**         | CoreSentinel Rule 7 (C-9)           | **CSE proposal filed → EVO-004, `PENDING_REVIEW`**                                                                                                                                                              | EVO-004        |
| **Q-13**         | EVO-003                             | **Approved.** Apply refused — target is a governance doc outside the safe-change set. **Proposal is stale**: `04-memory-ecosystem-protocol.md` already documents all six lifecycle operations. Nothing to apply | —              |
| **Q-14** *(new)* | Deployment shape, given client work | **One client, multi-branch. No SaaS shell**                                                                                                                                                                     | ADR-005, §35   |
| **Q-15** *(new)* | What "gross margin" deducts         | **Full: − cost − shipping − fees − ad spend**                                                                                                                                                                   | ADR-009, §13.4 |

### Still genuinely open (raised by the answers, not blocking P0–P2)

| #        | Question                                                                                                                                | Needed by |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------- | --------- |
| **Q-16** | Ad-spend allocation rule default — `pro_rata_by_order_value`, `equal_per_order`, `pro_rata_by_margin` or `excluded` (§13.4b)            | Before P6 |
| **Q-17** | Who closes a commission period, and on what schedule? R-15 depends on this being a scheduled job with a named owner, not a manual habit | Before P6 |
| **Q-18** | Does the client have real landed-cost data today, or is `unit_cost` currently a guess? ADR-009 makes this a payroll input (R-14)        | Before P4 |
| **Q-19** | Payment gateway(s) and courier(s) in scope — affects fee capture (§13.4a) and shipping cost timing                                      | Before P3 |

---

## 44. Recommended Implementation Phases

Derived from the dependency graph (§5), not from the brief's example. Each phase has an
**exit gate satisfiable only by a passing test**.

| Phase    | Name                    | Contents                                                                                                                                                                                                                               | Exit gate                                                                                                                                                                                     |
| -------- | ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **P0 ✔** | Foundation              | Laravel 12 + PostgreSQL 16 + Inertia/React scaffold, CI with forbidden-pattern guards, `Money` VO, **company tenancy kernel**, single `web` guard with no privilege boolean, **component library**, module registry *(no plan gating)* | **GATE CLOSED 2026-08-15** — see Appendix C                                                                                                                                                   |
| **P1 ✔** | Access                  | RBAC (spatie teams) + **DataScope layer** + `ScopeResolver` + `Scopeable` + policies, Company/Branch/User admin, Audit log. *(**Department admin was listed here and never built** — corrected 2026-08-16, see Appendix AE)*                                                                                                  | **GATE CLOSED 2026-08-15** — see Appendix D. A salesperson cannot reach another's record via route, export, report or API — proven by test                                                    |
| **P2 ✔** | Master data             | Customer, Supplier, Product (variants, pricing, tax, bundles), `PriceResolver`, document numbering                                                                                                                                     | Price resolution returns a decomposition; numbering unique under concurrency                                                                                                                  |
| **P3 ✔** | Orders                  | Order + items + three-axis state machine + mutability policy + `order_events`, Quotation→SO→DO→Invoice→Payment                                                                                                                         | No status logic outside the state machine (grep-verified); illegal transitions rejected with a readable reason                                                                                |
| **P4 ✔** | Inventory & Purchasing  | Warehouses, stock, reservations, movements, transfers, counts; PR→PO→GRN→Bill→Payment; Approval engine                                                                                                                                 | `SUM(movements) == on_hand`; last-unit reservation correct under 8 concurrent processes; three-way match blocks                                                                               |
| **P5 ✔** | Sales force & Marketing | Sales teams, territories, targets, activities; marketers, channels, campaigns, leads, referral/promo codes; **Attribution domain**                                                                                                     | All 12 attribution questions answered by a named tested query                                                                                                                                 |
| **P6 ✔** | Commission              | Plans, immutable versioned rules, strategies, queued calculation, **provisional→final restatement**, **ad-spend allocation**, reversal, payout, Finance posting                                                                        | Re-run is idempotent (unique index proven); reversal produces a contra entry; every commission renders its full deduction breakdown from data; **no provisional accrual can reach `payable`** |
| **P7 ✔** | Finance                 | Accounts, journal, cash flow, AR/AP, expenses, payments, refunds, credit notes                                                                                                                                                         | Invoice→payment→outstanding reconciles to the cent; ageing buckets match fixture                                                                                                              |
| **P8 ✔** | Reporting & Dashboards  | Five role dashboards, precomputed rollups, exports                                                                                                                                                                                     | Every dashboard figure scope-filtered — proven by test; precomputed matches live-query oracle                                                                                                 |
| **P9 ~** | Hardening & Launch      | Security review, performance pass, PDPA erasure, backup + **rehearsed restore**, deploy                                                                                                                                                | External security review clean; restore rehearsed and documented                                                                                                                              |
| **P10 ~** | Optional modules       | **HR ~** (leave), Payroll, **POS ✔**, **CRM ✔**, Projects, Assets, Tickets, **Subscriptions ✔**, **Online payment ✔** (Billplz)                                                                                                        | Per module. POS — V–AA. CRM — AB. HR leave — AD. Subscriptions — AF. Billplz — AG                                                                                                             |

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

---

## Appendix C — P0 Gate Evidence (closed 2026-08-15)

### Verified toolchain

| Component  | Version                | Note                                                                                                         |
| ---------- | ---------------------- | ------------------------------------------------------------------------------------------------------------ |
| PHP        | 8.4.10                 |                                                                                                              |
| Laravel    | 12.66.0                |                                                                                                              |
| PostgreSQL | **16.14 on port 5433** | `psql --version` reports 14.18 — that is the **client** binary. Server version taken from `select version()` |
| Redis      | 8.4.0                  |                                                                                                              |
| Node / npm | 20.19.4 / 10.8.2       | Vite 7 requires `^20.19.0`; this is the minimum                                                              |

`@vitejs/plugin-react` is pinned to `^5.2.0` because v6 requires Vite 8 while Laravel 12 pins
Vite 7. The pairing was resolved by pinning, not by `--legacy-peer-deps`.

### Gate results

| Gate            | Result                                                                                                                                                                                       |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pest            | **143 passed, 213 assertions** (Unit 62 · Architecture 4 · Isolation 69 · Feature 8)                                                                                                         |
| PHPStan         | **level 6, no errors**                                                                                                                                                                       |
| Pint            | **pass**                                                                                                                                                                                     |
| `tsc --noEmit`  | **pass** (strict + `exactOptionalPropertyTypes`)                                                                                                                                             |
| `npm run build` | **pass**, 621 modules                                                                                                                                                                        |
| Schema          | 22 tables, `company_id NOT NULL` on all 9 scoped tables, 4 composite `(company_id, id)` FKs, CHECK constraints on both enums — verified from `information_schema`, not from migration output |

### Anti-tautology proof

The gate requires that guards *fail* on a violation, not merely pass when clean. Three
violations were planted and reverted:

| Planted                                         | Caught by                         | Clean after revert |
| ----------------------------------------------- | --------------------------------- | ------------------ |
| `company_id` added to `Branch::$fillable`       | isolation suite                   | ✔                  |
| `BelongsToCompany` removed from `Department`    | schema guard                      | ✔                  |
| `withoutGlobalScope` added to `RoleProvisioner` | CI grep **and** architecture test | ✔                  |

The first planting exposed a **real defect in the guard itself**:
`expect($x)->not->toContain('company_id', 'message')` passes unconditionally, because Pest
reads the second argument as another expected value. The guard was green while the violation
was present. Rewritten as `expect(in_array(...))->toBeFalse(...)`. Recorded in the Core
failures layer.

### Decisions taken during P0

| #    | Decision                                                              | Reason                                                                                                                                                                       |
| ---- | --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P0-1 | `roles.company_id` is **NOT NULL** and `Role` uses `BelongsToCompany` | The trait guard found the column present while spatie scoped it invisibly to our suite. Every role belongs to a company; global roles are not used                           |
| P0-2 | `config/` is excluded from the strict-types and zero-comment guards   | Those files are published from vendor and regenerated by `vendor:publish`. A guard that fights `vendor:publish` gets disabled. `app/`, `database/`, `routes/` remain covered |
| P0-3 | `HasFactory` removed from models without a factory                    | PHPStan level 6 requires the generic annotation; an unused trait is not worth annotating                                                                                     |
| P0-4 | Permissions **are** shared to the frontend as `auth.can`              | Fixes the SMEOS always-render-then-403 UX. Backend authorization remains the only boundary                                                                                   |

### Known issues carried out of P0

| ID           | Issue                                                                                                                                                                                                                                                                                                                |
| ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **INC-0001** | `coresentinel verify` reports a **false FAIL** on any Pest project: `coresentinel_evidence.py:169` probes only `vendor/bin/phpunit`, and Pest's phpunit shim always exits 1. Fix is to probe `vendor/bin/pest` first. Until then the `verify` tests line is not trustworthy here — read `./vendor/bin/pest` directly |
| **EVO-004**  | CoreSentinel Standing Rule 7 (Windows/PowerShell) is still `PENDING_REVIEW`                                                                                                                                                                                                                                          |
| P0-5         | No auth UI yet. `/dashboard` returns 401 for guests rather than redirecting, because no `login` route exists. Auth scaffolding is P1                                                                                                                                                                                 |

### Not built in P0 (deliberately)

`ScopeResolver` and the `Scopeable` contract — the `role_permission_scopes` table, the
`DataScope` enum and the per-role default scopes exist and are seeded, but the **query-layer
resolver is P1**. P0 delivered the tenancy axis; P1 delivers the data-scope axis and the six
additional isolation assertions that go with it (§39, items 11–16).

---

## Appendix D — P1 Gate Evidence (closed 2026-08-15)

### What shipped

| Component            | Detail                                                                                                                                                                                                                          |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ScopeResolver`      | Resolves the widest `DataScope` across a user's roles for a permission, then constrains the query. **Fails closed** on: no permission, no scope row, owner-less model asked for `own`/`team`, branch-scoped user with no branch |
| `Scopeable` contract | `ownerColumn(): ?string` + `branchColumn(): ?string`. Nullable by design — `Branch` has no owner, and asking for `own` on it must refuse rather than guess                                                                      |
| `AppliesDataScope`   | `Model::query()->visibleTo($user, 'permission')`                                                                                                                                                                                |
| `BasePolicy`         | Record-level checks call **the same resolver** as list queries, so route access and list access cannot diverge                                                                                                                  |
| `AuditLog`           | Company-scoped, `Scopeable` on `actor_user_id` / `branch_id`, append-only at model **and** database trigger                                                                                                                     |
| `AuditPurger`        | The single PDPA erasure path — `SET LOCAL app.audit_purge = 'on'` inside a transaction, requires a stated reason, logged at `warning`                                                                                           |
| Auth                 | Login/logout, throttled, guest redirect, login recorded to the audit trail                                                                                                                                                      |
| Admin                | Branch CRUD (scoped + audited), Audit log viewer (scoped), Bootstrap/React pages                                                                                                                                                |

### Gate results

| Gate            | Result                         |
| --------------- | ------------------------------ |
| Pest            | **177 passed, 295 assertions** |
| PHPStan         | level 6, no errors             |
| Pint            | pass                           |
| `tsc --noEmit`  | pass                           |
| `npm run build` | pass                           |

### Data scope proven at two layers

**Query layer** (`tests/Isolation/DataScopeTest.php`) — salesperson 2 of 5 rows, sales manager 3 (own + subordinates), branch manager 4 (whole branch), owner 5 (whole company, none from the rival company).

**HTTP layer** (`tests/Feature/DataScopeRouteTest.php`) — the same expectations through real routes, plus an assertion that another user's record id **does not appear anywhere in the response body**, which is the check that catches a leak through a serialiser rather than a query.

### Anti-tautology proof

| Planted                                                         | Caught by                                    |
| --------------------------------------------------------------- | -------------------------------------------- |
| `DataScope::Own` stops filtering                                | "shows a salesperson only their own records" |
| Fail-open instead of `whereRaw('1 = 0')` when no scope resolves | both fail-closed tests                       |
| `priority([...])` replacing the default middleware list         | middleware-ordering test                     |

All three reverted clean.

### Two real defects found and fixed

**D-1 — `$middleware->priority([...])` replaces the framework's entire default list.** Passing two classes left a 2-entry list with `Authenticate` absent, so `ResolveCompany` sorted to position 0 and ran *before authentication*; its `abort(401)` pre-empted the guest redirect. Presented as an auth-config problem, was an ordering problem. Fixed with `prependToPriorityList`, restoring all 13 defaults with **Authenticate [9] → ResolveCompany [10] → SubstituteBindings [11]**, and pinned by a test.

**D-2 — an append-only audit table makes its parent undeletable.** The `DELETE` trigger blocked the cascade from `companies`, so a company with audit rows could never be removed — the PDPA-erasure collision §30 predicted. Resolved with a deliberate purge flag: `UPDATE` is refused unconditionally, `DELETE` only under `app.audit_purge`. Both paths proven with raw SQL against the live database.

### Carried forward

| ID       | Item                                                                                                                                                         |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| P1-1     | Department and User admin screens are **not** built. Branch CRUD and the audit viewer are; `CompanyUser` is `Scopeable` and policy-covered but has no UI yet |
| P1-2     | Role/permission editing UI is not built. Roles are seeded by `RoleProvisioner`; changing a scope is currently a data operation                               |
| P1-3     | `attribution` / export scoping (§39 item 14) is asserted for list and aggregate, but there is no export endpoint yet to test against                         |
| INC-0001 | `coresentinel verify` still reports a false FAIL on Pest projects                                                                                            |
| EVO-004  | Standing Rule 7 still `PENDING_REVIEW`                                                                                                                       |

---

## Appendix E — P2 Gate Evidence (closed 2026-08-15)

### Schema

**44 tables**, 26 CHECK constraints, 28 composite `(company_id, id)` foreign keys, **zero nullable `company_id`**, and every money column `NUMERIC(15,4)` — all verified from `information_schema`.

Added in P2: `customer_groups`, `customers`, `customer_contacts`, `customer_addresses`, `suppliers`, `supplier_contacts`, `supplier_addresses`, `categories`, `brands`, `units_of_measure`, `tax_rates`, `products`, `product_variants`, `product_images`, `product_bundles`, `bundle_items`, `price_lists`, `price_list_items`, `tier_prices`, `promotion_rules`, `document_sequences`.

### Gate results

| Gate         | Result                                                                                                 |
| ------------ | ------------------------------------------------------------------------------------------------------ |
| Pest         | **436 passed, 636 assertions** (Unit 63 · Architecture 5 · Isolation 334 · Feature 30 · Concurrency 4) |
| PHPStan      | level 6, no errors                                                                                     |
| Pint / `tsc` | pass                                                                                                   |

### Price resolution returns a decomposition

`PriceResolver` walks six sources in order and **records every step it considered, matched or not, with a reason** — not just the winner:

```
Customer price list  → no dedicated price list
Group price list     → belongs to no group with a price list
Quantity tier        → no tier cleared at this quantity
Branch price list    → no branch supplied
Wholesale            → not requested
Base selling price   → MATCHED  100.0000
```

Promotions apply **on top** of the resolved base rather than as a seventh "first match wins" step — a deviation from §10's flat list, made because a promotion is a discount on a price, not an alternative source of one. `PriceQuote::explain()` renders the sentence a salesperson can defend:

> `Base selling price MYR 100.00 less MYR 10.00 (Ten percent off) = MYR 90.00`

A discount larger than the base clamps to zero and records the full base as the discount — it never produces a negative price.

### Numbering proven under real concurrency

`DocumentNumberService` uses `SELECT … FOR UPDATE` inside a transaction, with a retry on unique violation for the first-allocation race.

The test spawns **8 real OS processes** via `proc_open`, each allocating 10 numbers, and asserts all 80 are unique and form exactly `1..80`. It runs in its own `Concurrency` suite **without** `RefreshDatabase`, because a transaction-wrapped test is invisible to other processes and would prove nothing.

**Anti-tautology proof:** removing `->lockForUpdate()` produced duplicate numbers on **3 of 3 runs**; restoring it passed. The test contends for real.

### Three defects found

| #    | Defect                                                                                                                                                                                                                                                                              |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P2-1 | **A database DEFAULT does not populate an in-memory model.** `Customer::create()` left `currency` null despite the column defaulting to `MYR`, and the resolver threw. Fixed by mirroring meaningful DB defaults into `protected $attributes` on 14 models                          |
| P2-2 | **`UnitOfMeasure` mapped to `unit_of_measures`; the table is `units_of_measure`.** Nothing failed until first use. A permanent guard now asserts every model maps to a table that exists                                                                                            |
| P2-3 | **Larastan types `decimal:N` as float.** Probed at runtime: it returns a **string**. Money was never at risk, but explicit `(string)` casts were added at the boundary plus a unit test asserting the cast type, so a future change to float fails loudly instead of drifting money |

### Carried forward

| ID   | Item                                                                                                                                                                                                                                   |
| ---- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P2-4 | **No UI for any P2 entity.** Customers, suppliers and products have models, schema, scoping and pricing but no controllers or screens. The `PriceResolver` is service-only                                                             |
| P2-5 | `Customer` is the only P2 model that is `Scopeable`. Products and suppliers are company-scoped but carry no owner, so `own`/`team` on them currently fails closed — correct, but it means product visibility is company-wide by design |
| P2-6 | Channel pricing is a `price_lists.type` value with no resolver step, because channels do not exist until P5. The step slots in without a migration                                                                                     |
| P2-7 | Product attributes, serial/batch/expiry and discount rules remain deferred per §10                                                                                                                                                     |

---

## Appendix F — P3 Gate Evidence (closed 2026-08-15)

### Gate results

| Gate         | Result                                                                                                 |
| ------------ | ------------------------------------------------------------------------------------------------------ |
| Pest         | **499 passed, 730 assertions** (Unit 63 · Architecture 8 · Isolation 378 · Feature 43 · Concurrency 7) |
| PHPStan      | level 6, no errors                                                                                     |
| Pint / `tsc` | pass                                                                                                   |
| Schema       | 48 tables; `orders`, `order_items`, `order_events`, `payments` added                                   |

### Three independent status axes

| Axis                | States                                                                                                                    |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `payment_status`    | `unpaid → partially_paid → paid → refunded`                                                                               |
| `fulfilment_status` | `draft → pending → approved → allocated → picked → packed → shipped → delivered → completed` (reversible before despatch) |
| `exception_status`  | `none → on_hold / cancelled / returned`                                                                                   |

Both situations §7 predicted are expressible without a hybrid state, and both are tested:
a **COD order packed while still unpaid**, and an order **shipped and then refunded**.

### The gate: no status logic outside the state machine

An architecture test greps `app/` for any write to `payment_status`, `fulfilment_status` or
`exception_status` outside `OrderStateMachine` and the `Order` model's own defaults. Two further
guards assert the status columns and the money totals are absent from `Order::$fillable`.

**Anti-tautology proof:** adding `'payment_status' => 'paid'` to a `forceFill` inside
`OrderService` failed the guard; removing it passed.

### Illegal transitions rejected with a readable reason

`reasonAgainst()` returns `null` or a merchant-readable sentence — never a bare boolean.
`canTransition()` is `reasonAgainst() === null` and `availableTransitions()` filters on it, so
the UI, the API error and the guard share one source of truth. Sentences under test:

- *"This order has already shipped. Record a return instead of cancelling it."*
- *"This order has not shipped yet, so it cannot be returned. Cancel it instead."*
- *"This order is not COD and is not fully paid, so it cannot ship. Mark it COD or record the payment first."*
- *"Only MYR 50.00 of MYR 200.00 has been received, so this order is not fully paid."*
- *"This order is On hold. Clear the exception before moving fulfilment on."*
- *"An order that is draft cannot become shipped."*

### The OMS defect we did not inherit

OMS records **P1-25**: nothing tied `paid_cents` to `payment_status`, so the machine could set
`Paid` with zero received. Here the machine **refuses** `Paid` while `paid_amount < total`, and
`OrderService::recordPayment()` derives the status from the money rather than setting it
directly. Status can never contradict the money.

### Optimistic locking proven under real concurrency

`transition()` takes `lockForUpdate()` and re-checks the from-state inside the transaction.
Six concurrent processes racing the same `draft → pending` transition produce **exactly one
`APPLIED`, five `REFUSED`, and exactly one `order_events` row**.

**Anti-tautology proof:** removing the lock and the re-check failed on **3 of 3 runs**.

### Cost snapshotting — the input Q-18 depends on

Every order line freezes `unit_cost` at sale alongside `unit_price`, plus the full
`price_basis` decomposition as JSONB. `OrderItem::marginAtSale()` computes margin from
cost-as-at-sale, never today's cost. **This is the number ADR-009 turns into commission**, which
is why Q-18 (is the client's landed cost real?) is now the highest-value open question.

### Carried forward

| ID   | Item                                                                                                                                            |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| P3-1 | **No order UI.** Service, state machine, policy and events exist; no controllers or screens                                                     |
| P3-2 | **Quotation → SO → DO → Invoice** chain is not built. Orders and payments are; quotations, delivery orders, invoices and credit notes are not   |
| P3-3 | `quantity_allocated/picked/shipped/returned` columns exist on lines but nothing writes them yet — partial fulfilment lands with Inventory in P4 |
| P3-4 | Stock is not reserved on allocation. The fulfilment axis moves freely through `allocated`; the reservation hook belongs to P4                   |
| P3-5 | Tax is captured per line but always `0` — the `TaxRate` on a product is not yet applied by `OrderService`                                       |
| P3-6 | `payments` has no allocation table; one payment belongs to one order. Multi-order settlement is a P7 concern                                    |

---

## Appendix G — P4 Gate Evidence (closed 2026-08-15)

### Gate results

| Gate         | Result                                                                                                    |
| ------------ | --------------------------------------------------------------------------------------------------------- |
| Pest         | **777 passed, 1,098 assertions** (Unit 63 · Architecture 9 · Isolation 609 · Feature 86 · Concurrency 10) |
| PHPStan      | level 6, no errors                                                                                        |
| Pint / `tsc` | pass                                                                                                      |
| Schema       | **69 tables**, zero nullable `company_id`                                                                 |

### The three gate criteria

**1. `SUM(movements) == on_hand`** — asserted per stock line after a mixed sequence of receive,
damage, reserve-and-commit and stock-take, and again across a full order lifecycle, and again
after a contested reservation round. `balance_after` is also checked against the running total
movement by movement.

**2. Last-unit reservation under 8 concurrent processes** — one unit, eight real OS processes:
**exactly one `RESERVED`, seven `REFUSED`**, and `reserved` never exceeds `on_hand`.
Removing the row lock reproduces the oversell on **3 of 3 runs**.

**3. Three-way match blocks** — PO ↔ GRN ↔ Bill. Over-billed quantity and price variance are
both caught, **all discrepancies are reported rather than stopping at the first**, and
`assertBillPayable()` refuses with the collected reason:

> *"This bill does not match the order and the goods received: WIDGET-STD: billed 10 but only 2 was received. WIDGET-STD: ordered at MYR 60.00 but billed at MYR 75.00."*

### Both OMS inventory defects avoided

| OMS defect                                                                                           | What we did                                                                                                                                                        |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **H-02** — `commit()`/`release()` did not take their own lock, so a reservation was discharged twice | Both take `lockForUpdate()` on the reservation **and** the stock line; a second `commit()` returns `null` and a second `release()` returns `false`, proven by test |
| **H-03** — lock-ordering cycle between commit-on-ship and order-cancel                               | Reservations are always iterated `orderBy('id')`, giving a single deterministic lock order                                                                         |

### P3 gaps closed

- **P3-4 — stock is now reserved on allocation.** Wired by an `OrderStatusChanged` domain event
  and an inventory listener, so Orders never reaches into Inventory directly (§25). Allocate
  reserves, ship commits, cancel releases — each tested end to end, including that allocating
  more than exists refuses with *"Only 2 of this item is available and 3 was requested."*
- **P3-5 — tax is applied per line** from the product's `TaxRate`, exclusive and inclusive both
  tested (`6%` on RM300 → RM18.00 exclusive, RM16.9812 extracted inclusive).

### Approval engine

Amount bands are **rows, not code**, exactly as §19 requires. The RM1,000 / RM10,000 example
from the brief is seed data: a 500 order stops at the manager, 5,000 goes manager → finance,
25,000 goes manager → finance → director. Self-approval is refused, a wrong-level approver is
refused, and every action lands in an append-only trail enforced by a database trigger.

### A real defect found and guarded

**Laravel auto-discovers listeners in `app/Listeners`.** Registering the same listener manually
as well registers it **twice**, so every side effect runs twice — here it reserved stock twice
per order line, which presented as a reservation-logic bug rather than a wiring bug. Fixed by
removing the manual registration, and guarded by an architecture test asserting the listener
count. Planting the duplicate fails both that guard and the behavioural test.

### Carried forward

| ID     | Item                                                                                                                                                                                                                               |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P4-1   | **No UI for inventory or purchasing.** Services, state and invariants exist; no controllers or screens                                                                                                                             |
| P4-2   | `stock_transfers` and `stock_adjustments` have schema and models but **no service** — transferring and adjusting is not yet possible through code                                                                                  |
| P4-3   | The approval engine is not yet **wired to** purchase orders or stock adjustments; it is a working engine with no callers in the domain flows                                                                                       |
| P4-4 ✔ | ~~No landed-cost allocation~~ **CLOSED** — see Appendix M. Original: no landed-cost allocation. `unit_cost` on a GRN line is the PO cost; freight and duty are not apportioned — **this is the accuracy gap behind R-14 and Q-18** |
| P4-5   | Purchase returns and supplier payment settlement are schema-only                                                                                                                                                                   |
| P4-6   | Reservation expiry is swept by `sweepExpired()` but **nothing schedules it** yet                                                                                                                                                   |

---

## Appendix H — P5 Gate Evidence (closed 2026-08-15)

### Gate results

| Gate         | Result                                                                                                      |
| ------------ | ----------------------------------------------------------------------------------------------------------- |
| Pest         | **972 passed, 1,366 assertions** (Unit 63 · Architecture 10 · Isolation 785 · Feature 104 · Concurrency 10) |
| PHPStan      | level 6, no errors                                                                                          |
| Pint / `tsc` | pass                                                                                                        |
| Schema       | **85 tables**, zero nullable `company_id`                                                                   |

### The gate: all twelve questions answered by a named tested query

Each method is named after the question it answers, so the code and §14.3 cannot drift:

| #   | Method                                  | Result in the test scenario                      |
| --- | --------------------------------------- | ------------------------------------------------ |
| 1   | `whereDidThisCustomerComeFrom()`        | Facebook / Raya 2026                             |
| 2   | `whereDidThisOrderComeFrom()`           | Facebook / Raya 2026 / lead LD-0001              |
| 3   | `whoGeneratedTheLead()`                 | Ali (MK-ALI)                                     |
| 4   | `whoClosedTheOrder()`                   | Siti, North Team                                 |
| 5   | `whichCampaignGeneratedRevenue()`       | RAYA2026 — MYR 1,000 across 1 order              |
| 6   | `whichMarketerGeneratedRevenue()`       | Ali — MYR 1,000                                  |
| 7   | `whichSalespersonGeneratedRevenue()`    | Siti — MYR 1,000                                 |
| 8   | `whichChannelConvertsBest()`            | FB: 1 lead, 1 order, MYR 1,000 · WALKIN: 0       |
| 9   | `whatDidThisCampaignCostVersusReturn()` | spend 500, revenue 1,000, net 500, **ROAS 2.0**  |
| 10  | `whatIsTheCostPerLeadByCampaign()`      | 1 lead, spend 500, **CPL 500**                   |
| 11  | `whichTeamHitTarget()`                  | North: target 800, achieved 1,000, **125%, hit** |
| 12  | `whichBranchGeneratedWhat()`            | HQ — MYR 1,000                                   |

### ADR-008 behaviour, tested

- **First touch wins.** Two marketers touch one lead three days apart; credit goes to the
  first, and `lastTouchFor()` still returns the second — the data for a future rule change is
  retained rather than discarded.
- **Every touch kept.** Three touches on one lead, all three stored.
- **Attribution is frozen on the order.** Re-attributing throws:
  *"Attribution is frozen once an order exists, because commission is paid on it."*
- **Unattributed is first-class.** A walk-in order returns a populated answer with
  `attributed: false` and null dimensions — never defaulted to a convenient marketer.
- **A converted customer inherits its lead's first touch**, which is what makes Q1 answerable
  for customers that began as leads.

### A tenant-isolation hole caught before it shipped

The twelve reports are raw query-builder aggregates, and **`DB::table()` bypasses the Eloquent
global scope entirely**. As first written, every report would have summed revenue across all
companies. Fixed by scoping each query explicitly (16 filters), and guarded permanently: an
architecture test fails any file that uses `DB::table()` without ever mentioning `company_id`.

### An honest note on proving that fix

Three successive planted violations did **not** fail the cross-company test, because the
scoping is layered — `attributions` and the joined `orders` are each filtered, so removing
either alone still yields the right answer. Only stripping **every** filter from the query made
it fail (2 rows instead of 1). That is redundant protection rather than a weak test, but it is
worth stating plainly: a single-mutation proof was inconclusive here, and the conclusion
required removing the whole layer.

### A PostgreSQL constraint worth knowing

`FOR UPDATE is not allowed with aggregate functions`. The touch-sequence allocator was written
as `lockForUpdate()->max('sequence')`, which is invalid on Postgres and fails at runtime, not at
analysis. Rewritten to lock the **last row** and compute the next value in PHP, with a retry on
unique violation for the first-insert race.

### Carried forward

| ID   | Item                                                                                                                                           |
| ---- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| P5-1 | **No UI for any P5 entity.** Leads, campaigns, marketers, sales teams and the twelve reports are all service-layer only                        |
| P5-2 | Referral and promo codes have schema and models but **no capture path** — nothing resolves a code at order entry into an attribution touch yet |
| P5-3 | Sales activities, customer visits, follow-ups and the pipeline are schema-only; no service, no kanban                                          |
| P5-4 | `whichTeamHitTarget()` measures revenue only. Other target metrics are a column value with no calculator                                       |
| P5-5 | Campaign spend is captured per `(campaign, period)` and is **ready for P6's ad-spend allocation**, but nothing allocates it to orders yet      |
| P5-6 | Attribution touches are recorded by explicit service calls; there is no web capture (UTM landing, click id)                                    |

---

## Appendix I — P6 Gate Evidence (closed 2026-08-15)

### Gate results

| Gate         | Result                                                                                                        |
| ------------ | ------------------------------------------------------------------------------------------------------------- |
| Pest         | **1,094 passed, 1,544 assertions** (Unit 63 · Architecture 10 · Isolation 884 · Feature 127 · Concurrency 10) |
| PHPStan      | level 6, no errors                                                                                            |
| Pint / `tsc` | pass                                                                                                          |
| Schema       | **94 tables**                                                                                                 |

### The four gate criteria

**1. Re-run is idempotent, and the unique index is proven.** Three consecutive accruals produce one commission. Removing the engine's idempotency check produces a `UniqueConstraintViolationException` rather than a duplicate — the database is the backstop, not the intention.

**2. Reversal produces a contra entry.** The original moves to `reversed`; a `reversal` row is created with the negated amount, `reverses_commission_id` set, and **negated `commission_sources`** so the source trail nets to zero. A reversal cannot itself be reversed, and a commission cannot be reversed twice. `DELETE` is refused by a database trigger: *"a commission is never deleted; reverse it with a contra entry."*

**3. Every commission renders its full deduction breakdown from data.** Nothing is stored as prose:

> `Commission MYR 38.50 — Recipient: Ali (marketer) · Rule: "Facebook Campaign Margin" v1 (effective 2025-08-15) · 12% of MYR 320.80 · Sales MYR 1,000.00 − Cost MYR 520.00 − Shipping MYR 49.20 − Fees MYR 30.00 − Ads MYR 80.00 = MYR 320.80 · Order SO-00001 · Provisional — final at period close`

**4. No provisional accrual can reach payable.** Enforced twice: the state machine refuses with a readable reason, and a database `CHECK (NOT (is_provisional AND status IN ('payable','paid')))` refuses the row even under `forceFill`.

### All ten prior-art anti-patterns addressed

| #     | Anti-pattern (from §13.1)               | How P6 answers it                                                                                                                             |
| ----- | --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| CA-1  | Mutable rate, no effective dating       | `commission_rule_versions` are immutable (DB trigger) and effective-dated; a rate change creates v2 and existing commissions keep v1 — tested |
| CA-2  | Commission cannot name its rule         | `rule_version_id`, `basis_amount`, `rate_type`, `rate_applied`, `calc_inputs` all persisted                                                   |
| CA-3  | Aggregate rows with `order_id = NULL`   | `commission_sources` links every commission to its contributing orders                                                                        |
| CA-4  | Inline in a retrying callback, no index | Accrual is a service call guarded by a `NULLS NOT DISTINCT` unique constraint                                                                 |
| CA-5  | Payout sweep never flips to paid        | `paid` requires a payout; the state machine refuses otherwise                                                                                 |
| CA-6  | No reversal path                        | First-class contra entries                                                                                                                    |
| CA-7  | Hard delete to "correct"                | `DELETE` refused by trigger                                                                                                                   |
| CA-8  | Free-transition status setter           | State machine with `reasonAgainst()`                                                                                                          |
| CA-9  | Config the engine never reads           | The strategy CHECK lists only the four implemented strategies                                                                                 |
| CA-10 | Payout not posted to the ledger         | **Not yet closed — see below**                                                                                                                |

### A real PostgreSQL trap found

**A `UNIQUE` index does not prevent duplicates when a participating column is NULL** — Postgres treats NULLs as distinct. The idempotency constraint silently allowed unlimited duplicate accruals for *order-level* commissions, which is exactly the case it existed to protect. Found only because a test inserted a deliberate duplicate and nothing threw. Fixed with `UNIQUE NULLS NOT DISTINCT` (PG 15+), then re-proven by removing the application check.

### ADR-009 in practice

Margin is `sales − cost − shipping − fees − allocated ad spend`, where sales is subtotal less discount plus shipping charged. Ad spend is apportioned from `campaign_costs` for `(campaign, period)` by the plan's allocation rule — the pro-rata split across two campaign orders is tested (MYR 100 → 50/50).

The two-stage life works as designed: accrual is **provisional** while `costs_reconciled` is false, `finalise()` restates it against reconciled costs and records the before/after, and only then can it become payable.

### The Q-18 assumption, stated explicitly

**This engine is only as correct as `unit_cost`.** P4-4 remains open: there is no landed-cost allocation, so freight and duty are not apportioned into `unit_cost`. In the worked example a MYR 38.50 commission rests on a MYR 520.00 cost figure taken from the purchase order.

**Assumption on record:** the client's `cost_price` and PO costs are accurate enough to pay commission on. If they are estimates, every commission this engine computes will be confidently wrong in the same direction. That is R-14, and it is now live rather than theoretical.

### Carried forward

| ID   | Item                                                                                                                                                                                  |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P6-1 | **CA-10 is not closed.** `commission_payouts` exists but payout does not post to a ledger, because Finance is P7. The hook belongs in `CommissionPayoutService`, which is not built   |
| P6-2 | **No payout run.** Payout, payout items and payout requests have schema and models; nothing sweeps approved commissions into a payout, snapshots bank details, or generates a voucher |
| P6-3 | Accrual is a synchronous service call. §13.3 specifies a **queued job keyed on the order**; the unique constraint already makes that safe, but the job is not written                 |
| P6-4 | Nothing triggers accrual automatically. An order reaching a qualifying state should enqueue it; today `accrueForOrder()` must be called explicitly                                    |
| P6-5 | No reversal is triggered by an order being refunded or returned — the mechanism exists, the event wiring does not                                                                     |
| P6-6 | Only four of the eight strategies are implemented; tier ladders, target achievement and upline override are deliberately absent from the CHECK rather than half-built                 |
| P6-7 | No UI                                                                                                                                                                                 |

---

## Appendix J — P7 Gate Evidence (closed 2026-08-15)

### Gate results

| Gate         | Result                                                                                                        |
| ------------ | ------------------------------------------------------------------------------------------------------------- |
| Pest         | **1,212 passed, 1,717 assertions** (Unit 63 · Architecture 10 · Isolation 983 · Feature 146 · Concurrency 10) |
| PHPStan      | level 6, no errors                                                                                            |
| Pint / `tsc` | pass                                                                                                          |
| Schema       | **103 tables**                                                                                                |

### The two gate criteria

**1. Invoice → payment → outstanding reconciles to the cent.** Three invoices totalling MYR 3,180.00, two partly settled: invoiced 3,180.00, paid 1,260.00, outstanding 1,920.00, and `invoiced − (paid + outstanding) = 0.0000` asserted exactly, not approximately.

**2. Ageing buckets match the fixture.** Four invoices due 10, 45, 75 and 120 days ago land one per bucket at MYR 530.00 each across `0-30`, `31-60`, `61-90`, `90+`. A settled invoice drops out of the report entirely.

### The journal is real, and balance is enforced three ways

Not a cash-book with a ledger-shaped name:

- the `Ledger` service refuses an unbalanced post — *"This entry does not balance: debits MYR 100.00 against credits MYR 90.00."*
- a `CHECK` requires `total_debit = total_credit` on every entry
- a per-line `CHECK` requires exactly one of debit or credit to be positive

`journal_lines` are append-only at the database — both `UPDATE` and `DELETE` are rejected — so a correction must be a reversing entry. **`trialBalance()` is asserted zero after issue, after payment, after void and after payout.** That single assertion catches nearly every posting mistake.

### CA-10 is now closed

The last of the ten prior-art anti-patterns. Commission flows through the ledger properly in two steps:

| Event                      | Entry                                         |
| -------------------------- | --------------------------------------------- |
| Commission becomes payable | Dr Commission Expense / Cr Commission Payable |
| Payout is paid             | Dr Commission Payable / Cr Bank               |

The payable account clears to zero, a `cash_flows` row is written against the same journal entry, and **the payout flips every commission to `paid`** — the exact defect (CA-5) that let AgentStockit pay the same commission twice. Both were proven by planted violation: removing the status flip fails the sweep test, and removing the ledger post fails the payable-clears test.

### Also closed here

**P3-2 — invoices now exist.** `issueFromOrder()` snapshots the money and lines at issue (financial truth frozen, per the recorded skill), issues sequential numbering, posts Dr AR / Cr Sales / Cr Tax, and refuses to void an invoice that has payments against it — *"Issue a credit note rather than voiding it."* Overpayment is refused rather than clamped.

### Carried forward

| ID   | Item                                                                                                                                                                                    |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P7-1 | **No UI for anything in Finance.** Invoices, journal, ageing, expenses and payouts are all service-layer                                                                                |
| P7-2 | **Credit notes are not built.** Void covers an unpaid invoice; a paid invoice needs a credit note and the message says so, but the mechanism does not exist                             |
| P7-3 | Expenses and expense categories have schema, models and a chart-of-accounts link, but **no service** — nothing posts an expense to the ledger or routes it through approvals            |
| P7-4 | AR and AP are **derived** from invoices and supplier bills rather than stored as separate tables. Deliberate, but it means there is no aged-payables report yet — only aged receivables |
| P7-5 | Supplier bill payment does not post to the ledger; only sales and commission do                                                                                                         |
| P7-6 | Bank reconciliation, opening balances and period close are absent                                                                                                                       |
| P7-7 | Q-18 / P4-4 remain open. The ledger is exact, but the **cost figures flowing into it are still unvalidated**                                                                            |

---

## Appendix K — P8 Gate Evidence (closed 2026-08-15)

### Gate results

| Gate                        | Result                                                                                                          |
| --------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Pest                        | **1,261 passed, 1,824 assertions** (Unit 65 · Architecture 10 · Isolation 1,016 · Feature 160 · Concurrency 10) |
| PHPStan                     | level 6, no errors                                                                                              |
| Pint / `tsc` / `vite build` | pass                                                                                                            |
| Schema                      | **106 tables**                                                                                                  |

### The two gate criteria

**1. Precomputed matches the live-query oracle — and the comparison is not a tautology.**
The rollup is built by SQL `GROUP BY`; the oracle recomputes the same figures by **iterating orders in PHP with exact `Money` arithmetic**. The two share no code, so agreement means something. Planting a real aggregation bug — summing `unit_cost` without multiplying by quantity — fails the comparison immediately.

Also proven: rebuilding three times does not double-count, and a cancelled order drops out of the rollup on rebuild (MYR 1,500 → 500).

**2. Every dashboard figure is scope-filtered.** Two salespeople and an owner over the same data:

| Viewer                  | Revenue seen |
| ----------------------- | ------------ |
| Siti (scope `own`)      | MYR 1,000.00 |
| Rahim (scope `own`)     | MYR 500.00   |
| Owner (scope `company`) | MYR 1,500.00 |

A salesperson with no orders gets `0.0000`, not somebody else's numbers. Removing `visibleTo()` from the dashboard query fails the test. Breakdown tables (`top_salespeople`, `top_campaigns`, team/channel/marketer) run through the same scoped query, so a chart cannot leak what a list would not.

### The UI gap is partly closed

P2–P7 shipped as domain services with no screens. P8 adds the first real working surface: a **role-aware dashboard** with five variants, served by `DashboardController`, which resolves the allowed variants from the user's company role and **falls back rather than honouring a variant the role is not entitled to** — requesting `?view=management` as a salesperson returns the salesperson dashboard, tested.

Components added: `SliceTable`, plus `StatCard`/`DataTable`/`MoneyText` reused from P0.

### A P0 assertion replaced rather than deleted

Two P0 tests asserted the placeholder dashboard props (`branchCount`, `userCount`). The real dashboard sends `figures` instead. One test was updated to the new contract; the other was **proving company isolation through `branchCount`**, so its intent was preserved by asserting branch isolation directly (`1` vs `3`) rather than dropping the coverage.

### Carried forward

| ID   | Item                                                                                                                                                                     |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| P8-1 | **Rollups are not scheduled.** `rebuildSales()`/`rebuildCommission()` exist and are tested, but nothing runs them — no scheduler entry, no queued job                    |
| P8-2 | **No exports.** §39 item 14 (exports apply the same scope as list screens) remains only partly proven: list and aggregate are covered, there is still no export endpoint |
| P8-3 | Commission rollups are built and scoped but **no dashboard reads them by period range** — only current-period totals                                                     |
| P8-4 | Dashboards read only sales and commission rollups. Inventory value, cash position and top products from §20 are not implemented                                          |
| P8-5 | Only the dashboard has a UI. Customers, products, orders, inventory, purchasing, invoices and commission remain service-layer only                                       |
| P8-6 | No charts — figures are stat tiles and tables. Deliberate for now (D-07 in the original prior art was a chart-library choice; nothing has been chosen here)              |

---

## Appendix L — P9 Evidence (gate PARTIALLY met, 2026-08-15)

### Honest status

The P9 exit gate reads: *"External security review clean; restore rehearsed and documented."*

**The second half is met and evidenced below. The first half is not, and cannot be met by me** —
an external security review is by definition performed by a third party. What was done instead is
an internal review against `40-security-protocol.md`, converted into a permanent test suite, plus
three real findings fixed. The phase is therefore marked `[~]`, not `[✔]`.

### Gate results

| Gate                        | Result                                                                                                                            |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Pest                        | **1,283 passed, 1,883 assertions** (Unit 65 · Architecture 10 · Isolation 1,016 · Feature 163 · Concurrency 10 · **Security 19**) |
| PHPStan                     | level 6, no errors                                                                                                                |
| Pint / `tsc` / `vite build` | pass                                                                                                                              |
| CoreSentinel scanner        | clean                                                                                                                             |

### Three security findings, fixed and guarded

| #       | Finding                                                                                                                                                                                                                                                                                                                         | Fix                                                                                              |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| **S-1** | **The private disk was serving unauthenticated routes.** Laravel 11/12 ships `config/filesystems.php` with `'serve' => true` on the `local` disk, registering `GET /storage/{path}` **and `PUT /storage/{path}` with no middleware at all** — unauthenticated read *and write* against the disk intended for business documents | `'serve' => false`; a test now fails if any route under `storage/` has an empty middleware stack |
| **S-2** | **The queue dashboard had no explicit gate.** Horizon fell back to its environment default, so access depended on `APP_ENV` rather than on a decision                                                                                                                                                                           | `Gate::define('viewHorizon', …)` requiring `modules.manage`; asserted by test                    |
| **S-3** | The root path answered `POST`, `PUT`, `PATCH` and `DELETE` because `Route::redirect` registers for every verb                                                                                                                                                                                                                   | Explicit `Route::get`; asserted by test                                                          |

S-1 is the one that mattered. It is invisible in a `route:list` review unless you inspect middleware, and nothing in the default test suite catches it.

### The security review is now a suite, not a document

19 assertions that run on every commit: passwords hashed and hidden, login throttled, **every**
state-changing route carrying `auth` + `company`, CSRF in the web group, private disk serving
nothing, Horizon gated, session cookies `httpOnly`/`SameSite`, no privilege boolean on `users`,
debug never on in production, and no secret in `.env.example`.

### PDPA erasure is implemented

`ErasureService::eraseCustomer()` anonymises the customer, contacts, addresses, converted leads and
the customer snapshot on every order, then **retains invoices under accounting obligation** and
records the erasure itself in the audit trail. It refuses to run without a stated reason. Tests
confirm the identity survives nowhere it was written (`Aminah`, the phone number, the street) while
order and invoice totals remain intact so the ledger still reconciles.

This closes the collision §30 predicted between an append-only audit trail and a right to erasure:
operational PII is anonymised, financial records are retained, and the audit trail itself has a
deliberate purge path (`AuditPurger`, from P1).

### Restore rehearsed — and the rehearsal found a defect

**The first backup attempt failed outright:** `pg_dump: aborting because of server version mismatch`.
The client on `PATH` is 14.18; the server is 16.14. Had this not been rehearsed, the first real
backup would have failed during an incident.

`backup.sh` and `restore.sh` now resolve a client whose major version matches the running server,
and `backup.sh` refuses to write a dump under 1 KB so an empty file is never mistaken for a backup.

Verified round-trip:

| Check                                                                  | Original     | Restored  |
| ---------------------------------------------------------------------- | ------------ | --------- |
| Tables                                                                 | 106          | 106       |
| Triggers                                                               | 11           | 11        |
| CHECK constraints                                                      | 91           | 91        |
| Foreign keys                                                           | 270          | 270       |
| Row counts (companies/branches/products/customers/permissions/modules) | 1/1/1/1/24/6 | identical |

**Integrity survives the round-trip, proven against real rows rather than empty tables.** Inserting
a journal line into the restored database and attempting to edit it returns
*"the journal is append-only; UPDATE is not permitted. Post a reversing entry instead."*
`UNIQUE NULLS NOT DISTINCT` is preserved on `commissions_unique_accrual`.

### Performance pass

Query-budget tests now guard the two screens that exist. The audit screen holds at **7 queries for
3 rows and for 30** — flat, not N+1 — and removing its eager load fails the test.

One measurement lesson worth recording: the first attempt compared a cold request against a warm
one and read **12 queries for 3 rows versus 7 for 30**. The count went *down*, because the first
request primes the permission cache. Both a "pass" and a "fail" read from that would have been
wrong. The test now warms up before measuring.

### Handoff artefacts

`README.md` and `DEPLOYMENT.md` written per `52-handoff-protocol.md`, including a post-deploy
verification checklist that explicitly tests the S-1 fix (`curl -I …/storage/anything` must 404).

Cleanup verified, not assumed: no `dd`/`dump`/`var_dump`, no `console.log`, no hardcoded
localhost, `.env.example` complete and secret-free, migration from zero produces 106 tables, all
routes resolve.

**No seeder creates a user.** The first account is created interactively at deploy time, so no
known-credential account can reach production.

### What remains before this can go live

> **Status as of 2026-08-15: P9 is NOT closed, and one item is why.** Its gate has two halves —
> *external security review clean* and *restore rehearsed and documented*. The second is done
> (Appendix N). The first has not happened and **cannot be done from inside this project**.
>
> Five of the six items below are now closed. P9-1 is the only one left, and no amount of further
> work by me can close it: an external review is external by definition. `SECURITY-REVIEW.md` is the
> scoping brief prepared for whoever performs it, and section 9 of that document is the closing
> procedure.


| ID     | Item                                                                                                                                                                                                             |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P9-1   | **External security review** — the gate's first half. `SECURITY-REVIEW.md` is the scoping brief for whoever performs it; the closing procedure is section 9 of that document |
| P9-2 ✔ | ~~Nothing is scheduled~~ **CLOSED** — see Appendix M. Original: nothing is scheduled. Rollups and the reservation sweep have no scheduler entries; dashboards will go stale and expired holds will never release |
| P9-3 ✔ | ~~No CI run against this suite in a real pipeline~~ **CLOSED** — green on GitHub Actions at `9a40f85`, run 31887436160. It took five runs and found five real defects, all documented in Appendix U |
| P9-4 ✔ | ~~Backups not off-machine, no scheduled rehearsal~~ **CLOSED** — see Appendix N                                                                                                                                  |
| P9-5 ✔ | ~~**The UI gap.** Nine phases of domain logic sit behind authentication, branch admin, an audit viewer and dashboards~~ **CLOSED** — Appendices O, P, Q, R and S. Sales, purchasing, access, commission and marketing all have screens; the residue is listed as P9-5b…P9-5m |
| P9-6 ✔ | ~~Q-18 / P4-4 / R-14 — landed cost is still not apportioned~~ **CLOSED** — Appendix M implemented it; Appendix P fixed the by-weight path that had never worked. Q-18 (is the client's cost data real?) remains **their** question, not a code one |

---

## Appendix M — Post-P9 closures (2026-08-15)

Three items closed after the P9 gate, chosen because two were cheap blockers and the third was
the longest-standing correctness risk in the project.

| Gate                   | Result                             |
| ---------------------- | ---------------------------------- |
| Pest                   | **1,308 passed, 1,933 assertions** |
| PHPStan / Pint / `tsc` | pass                               |
| Schema                 | 107 tables                         |

### P9-2 — scheduled work is now registered ✔

`erp:rebuild-rollups` and `erp:sweep-reservations` iterate **every active company**, isolate each
one in its own `runAs` block, and **continue past a failing company rather than aborting the run** —
one tenant's bad data must not stop everyone else's dashboards updating.

| Command                                  | Cadence          |
| ---------------------------------------- | ---------------- |
| `erp:sweep-reservations`                 | every 5 minutes  |
| `erp:rebuild-rollups`                    | every 15 minutes |
| `erp:rebuild-rollups --date=<yesterday>` | daily 02:15      |

All three are `withoutOverlapping()` and `onOneServer()`, asserted by test. CI now fails if either
command disappears from the schedule.

### P9-3 — CI verified locally ✔ (partially)

**Stated plainly: I cannot run GitHub Actions.** What was done instead — the workflow was structurally
validated (no tabs, every declared step has an action) and **every guard it declares was executed
locally and passed**: mass-assignment, escape-hatch confinement, public-disk, TODO markers,
strict types, isolation floor.

The workflow also gained two guards it was missing: the **private disk must serve no route** (the S-1
finding from P9) and **scheduled work must stay registered**. The isolation floor was raised from 24
to 28 against a current count of 29.

**It still has never executed on a runner.** That remains open as P9-3.

### P4-4 / R-14 — landed cost is implemented ✔

The longest-standing correctness gap. Since P4, `unit_cost` was whatever someone typed, and since
P6 margin-based commission has been paying from it.

Now: `goods_receipt_costs` records freight, duty, handling or insurance with an allocation rule.
The allocator apportions each pool and writes `landed_unit_cost` plus a `landed_cost_basis` JSONB
carrying every component, its share, its per-unit effect and an explanation:

> `Purchase MYR 40.00 plus freight 4.0000 per unit = MYR 44.00`

The allocation modes are genuinely different, proven by test:

| Pool            | Rule          | Cheap line (40) | Pricey line (160) |
| --------------- | ------------- | --------------- | ----------------- |
| MYR 200 freight | `by_value`    | → **44.00**     | → **176.00**      |
| MYR 200 duty    | `by_quantity` | → **50.00**     | → **170.00**      |

**`average_cost` is recomputed from the whole receipt history, not maintained incrementally** — so
re-applying costing is idempotent by construction, with no reversal bookkeeping and no drift.
Receiving 10 at 40 then 10 at 60 gives a weighted average of exactly 50.

Order lines now snapshot `average_cost` when it exists, falling back to `cost_price`, and record
**`unit_cost_source`** — so any margin or commission figure can say whether it rests on a real
receipt or a typed guess.

Proven by mutation twice: collapsing every allocation to `by_quantity` fails four tests, and making
the average ignore landed cost fails four more.

### What this does and does not do for R-14

**Does:** cost is now derived from actual receipts and actual freight, and every figure carries its
provenance.

**Does not:** it cannot make the client's *input* data true. `Q-18` stands — if purchase orders are
raised at estimated prices, or freight invoices are never entered, `average_cost` will faithfully
average wrong numbers. The mechanism is now correct; the data question is still theirs to answer.

### Still open

P9-1 (external security review), P9-3 (CI never run), P9-4 (backups off-machine, scheduled
rehearsal), P9-5 (**the UI gap**), and the 55 remaining carried-forward items.

---

## Appendix N — P9-4 closed: backups and a scheduled rehearsal (2026-08-15)

| Gate                   | Result                             |
| ---------------------- | ---------------------------------- |
| Pest                   | **1,318 passed, 1,959 assertions** |
| PHPStan / Pint / `tsc` | pass                               |

### What was missing

P9 proved a restore **once**, by hand. That is a demonstration, not a control — a restore verified
once decays as the schema moves.

### What exists now

| Command             | Cadence       | What it does                                                        |
| ------------------- | ------------- | ------------------------------------------------------------------- |
| `erp:backup`        | nightly 02:00 | Dump, prune to `BACKUP_KEEP_DAYS`, optionally copy offsite          |
| `erp:verify-backup` | Mondays 03:00 | Restore the newest dump into a scratch database, verify it, drop it |

`erp:verify-backup` compares **table, trigger, CHECK-constraint and foreign-key counts** against the
live schema, then **proves append-only protection survived by inserting a real journal line into the
restored copy and attempting to update it**. It exits non-zero on any mismatch, so a monitor can
alert on it.

Verified run against the live database:

```
tables 107/107 ok · triggers 11/11 ok · checks 96/96 ok · foreign_keys 273/273 ok · append-only held
```

### The verifier catches a bad backup — proven with a real one

Pointed at a dump taken before the landed-cost migration, it correctly refuses:

```
tables 107/106 MISMATCH · checks 96/91 MISMATCH · foreign_keys 273/270 MISMATCH
The restored copy does not match the original. This backup is not trustworthy.
```

Exit code 1. That is a genuine detection against a genuinely outdated artefact, not a synthetic one.

### Three defects the exercise found in the backup path itself

| #   | Defect                                                                                                                                                  | Fix                                                                                                        |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| B-1 | **A failed dump left a truncated file behind** — the P9 version-mismatch failure wrote a 0-byte `.dump` that sat in the directory looking like a backup | The script now removes its own partial file and says so                                                    |
| B-2 | **Two dumps in the same second overwrote each other**, because the filename carried only second resolution                                              | Filenames now carry a random suffix                                                                        |
| B-3 | `latest()` was non-deterministic when two files shared an mtime                                                                                         | Ties now break on filename, and the test asserts only what second-resolution mtimes can actually guarantee |

B-1 is the one that matters: a failed backup that leaves a plausible-looking file is worse than one
that leaves nothing.

### Offsite

`BACKUP_OFFSITE_ENABLED` + `BACKUP_OFFSITE_COMMAND` (with `{file}` as the placeholder) run any
transport — rsync, `aws s3 cp`, rclone. **Enabling it without a command is a hard error**, because
a silently-not-copying backup is the failure mode worth refusing.

**Stated plainly: no real offsite destination has been exercised.** The hook is implemented and its
guard is tested; the transport itself must be verified on the client's infrastructure.

### Documentation corrected

`README.md` and `DEPLOYMENT.md` carried three claims that this work made false — *"nothing is
scheduled yet"*, *"landed cost is not apportioned"*, and *"the scheduled jobs are not yet
registered"*. All three were corrected rather than left to age.


---

## Appendix O — P9-5 closed for the operational core: the user interface (2026-08-15)

Nine phases produced a domain engine that could not be operated by anyone who does not write PHP.
This appendix records what was built, and — more usefully — what the work found.

### Scope delivered

| Area | Screens | Server-side gate |
|---|---|---|
| Customers | list · detail · create · edit | `CustomerPolicy` + `visibleTo` |
| Leads | list · detail · create · edit | `LeadPolicy` + `visibleTo` |
| Orders | list · detail · create · transitions · payment | `OrderPolicy` + `visibleTo` + `OrderStateMachine` |
| Invoices | list with ageing · detail · payment · void | `InvoicePolicy` + `visibleTo` |
| Products | list · detail with stock · create · edit with inline variants | `ProductPolicy` |
| Inventory | stock lines · movement history · adjustment · open a line | `inventory.view` / `inventory.adjust` |
| Suppliers | list · detail · create · edit | `SupplierPolicy` |
| Commission | list with period totals · detail with full explanation · transitions | `CommissionPolicy` |

The permission registry grew from 42 to **62 permissions across 18 groups**, with per-role grants
for all eleven roles.

### Navigation is a server decision

`NavigationBuilder` emits a module only when **all four** hold: the module is active, it is enabled
for the company, the user holds its permission, and `app('router')->has($module->route)` resolves.
The last condition is not defensive padding — it caught a real defect during this work.

### D-1: a route-name mismatch would have hidden Commission from every user, silently

`ModuleSeeder` named the route `commissions.index`. I registered `commission.index`. Every check
passed: Pint, PHPStan, TypeScript, the build, and all 1,387 tests. The only symptom would have been
a Commission entry that never appeared in anyone's sidebar — and because the filter exists precisely
to prevent 404s, it would have swallowed the mistake without a word.

Two architecture tests now close this permanently: every seeded module must name a route that
resolves, and a permission that exists in the registry. **Both were proven by planting the exact
mismatch and confirming the failure**, with the message naming the offending module.

The lesson generalises: *a filter that hides broken things hides your own mistakes too.* Any such
filter needs a test asserting the filtered set is empty.

### D-2: the status-column write guard fired on a read

`GuardsTest` forbids `'payment_status' => …` outside the state machine. The order presenter emitted
those keys in a **read-only** Inertia payload. The guard cannot distinguish a read from a write.

I renamed the payload keys to `payment` / `fulfilment` / `exception` rather than narrowing the
guard. Weakening a guard to accommodate new code is how guards die; the cost here was three lines
of TypeScript.

### D-3: three screen tests were passing for the wrong reason

Caught only by planting violations:

| Test | Why the pass was hollow |
|---|---|
| "salesperson cannot see the customer list" | The salesperson role **does** hold `customers.view` by design. Rewritten against `storekeeper`, which does not |
| "salesperson cannot cancel an order" | Asserted the rendered `permissions.cancel` flag, never the endpoint. Removing the server-side `cancel` mapping left it green. A direct-POST test was added |
| "branch manager cannot void an invoice" | An invoice has **no owner column**, so an `own` scope was refusing it — not the `invoices.void` permission. Rewritten with a branch-scoped manager who can plainly open the invoice and is still refused the void |

D-3 is the reason this project plants violations rather than trusting green runs. All three tests
were written by me, reviewed by me, and were worthless until a planted violation exposed them.

### §18 compliance — hidden buttons are not security

The brief was explicit: *"Frontend authorization is for UX. Backend authorization is the actual
security boundary."*

Every destructive or privileged action is refused server-side even when the button is hidden, and
each refusal is proven by a test that posts directly to the endpoint. Each of those tests was then
proven to **fail** when the server-side check is removed:

| Action | Server gate | Planted violation → test failed |
|---|---|---|
| Approve an order | `orders.approve`, not `orders.update` | ✔ 403 became 302 |
| Cancel an order | `orders.cancel`, not `orders.update` | ✔ 403 became 302 |
| Record an order payment | `payments.create` | ✔ 403 became 302 |
| List orders / customers / leads / commissions | `visibleTo` on the query | ✔ 1 row became 2 |
| Adjust stock | `inventory.adjust`, not `inventory.view` | ✔ 403 became 302 |
| Mark commission paid | `commissions.pay`, not `commissions.approve` | ✔ 403 became 302 |
| Void an invoice | `invoices.void` | ✔ 403 became 200 |
| See the supplier list | `suppliers.view` | ✔ 403 became 200 |

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` | passed |
| `phpstan` (level 6) | no errors |
| `tsc --noEmit` (strict, `exactOptionalPropertyTypes`) | clean |
| `npm run build` | built |
| `pest` | **1,407 passed / 2,351 assertions** (up from 1,318) |

### Carried forward

| ID | Item |
|---|---|
| P9-5a ✔ | ~~**No purchasing UI.** PR → PO → GRN → Bill, three-way match and landed cost are service-layer only~~ **CLOSED** — see Appendix P |
| P9-5b ~ | **No setup UI** for ~~commission plans and rules~~ (Appendix R), ~~campaigns and channels~~ (Appendix S); price lists, territories, marketing teams, referral codes and approval flows are still seeded or configured in `tinker` |
| P9-5c | **No exports**, on any screen |
| P9-5d | **No credit-note screen** |
| P9-5e | The order create screen lists up to 500 variants in a plain `<select>`. Fine for an SME catalogue; it needs a typeahead beyond that |
| P9-5f | **No screen has been used by a human.** Every claim here rests on tests and a build, not on anyone completing a day's work in the system |
| P9-5g | No accessibility audit, and no browser testing beyond the Vite build |


---

## Appendix P — P9-5a closed: the purchasing interface (2026-08-15)

The full PR → PO → GRN → Bill → Payment chain now has screens, plus an approvals inbox. The
interesting part of this work was not the screens; it was what building them exposed.

### Scope delivered

| Screen | Actions | Server gate |
|---|---|---|
| Purchase requests | list · detail · create · submit · approve/reject | `purchasing.view` / `create` / `approve` + branch scope |
| Purchase orders | list · detail · create (blank or seeded from an approved request) · submit · approve/reject | `purchasing.*` + branch scope |
| Receive goods | inline on the order, defaulting to what is still outstanding | `purchasing.receive` |
| Goods receipts | detail · landed-cost list · add a landed cost | `purchasing.view` / `purchasing.receive` |
| Supplier bills | list · detail with three-way match · create from an order · approve · pay | `purchasing.*` + `payments.create` |
| Approvals | inbox of everything pending, each row showing why you cannot decide it | `purchasing.approve` |

Five modules were added to the navigation under a new **Purchasing** group, and Suppliers moved
there from Catalogue.

### D-4: landed cost apportioned by weight had always been apportioning nothing

`LandedCostAllocator` reads `$item->variant->weight_grams ?? 0`. **`GoodsReceiptItem` had no
`variant` relation.** In PHP, `$null->weight_grams ?? 0` quietly yields `0`, so every by-weight
allocation had been dividing by a zero denominator and apportioning **nothing at all** — while
reporting itself as successful.

Nine phases of tests never caught it, because there was no by-weight test. The fixture set
`weight_grams` on its variants, which made the gap look covered; both variants carried the *same*
weight, so even a by-weight assertion on that fixture would have matched the by-quantity result.

It surfaced only because adding the `variant` relation for the receipt screen turned the silent
`null` into a `LazyLoadingViolationException` — eight tests failed at once. The bug announced
itself only when I stopped it from being silent.

Fixed by eager-loading `variant` in the allocator. Two tests added:

- **by weight with genuinely different weights** — 400 freight over 1000 g and 3000 g gives 50.00
  and 190.00, a split that is neither the by-value (48 / 168) nor the by-quantity (60 / 180) answer,
  so it can only pass if weight is really being read
- **by weight with no weights recorded** — apportions nothing and says so in the stored basis

Both were proven by removing the relation again and confirming the first test fails.

The lesson, which generalises past this bug: **`?? 0` on a chained property access converts a
missing relation into a plausible number.** Any fixture whose values are identical across rows
cannot tell a correct split from a broken one — vary them, or the test proves nothing.

### D-5: a cross-company test that proved company isolation, not data scope

"Never shows a purchase order belonging to another company" passed with `visibleTo()` deleted from
the query, because the global company scope already refuses the other company's rows. Real
protection, wrong test — it says nothing about the branch scope.

Replaced with two branch-scoped tests (list and detail), both proven by planting.

This is the same shape as D-3's invoice-void test in Appendix O: **a test passes for the wrong
reason whenever a second, unrelated guard is also refusing.** Where defence is layered, the test has
to isolate the layer it names.

### Approvals

`ApprovalEngine` decides; it never touched the thing being approved. A new
`ApprovalOutcomeApplier` maps a resolved request back onto its subject (`approved` → approved,
`rejected` → rejected, or `cancelled` for an order, `returned` → back to draft), so the engine stays
generic and the mapping is in one testable place.

Where no `ApprovalFlow` is configured — the state of a fresh install — submit still moves the record
to `pending` and a holder of `purchasing.approve` decides directly. **The screen says so in plain
words** rather than presenting an approval trail that does not exist.

### §18 compliance

Every purchasing action is refused server-side with the button hidden, and each refusal was proven
by removing the check and confirming the test fails:

| Action | Gate | Planted violation → failed |
|---|---|---|
| List purchase requests / orders | `visibleTo` + branch scope | ✔ 1 row became 2 |
| Open an order outside your branch | `PurchaseOrderPolicy` record scope | ✔ 403 became 200 |
| Approve a purchase request | `purchasing.approve`, not `purchasing.create` | ✔ 403 became 302 |
| Receive goods | `purchasing.receive`, not `purchasing.view` | ✔ 403 became 302 |
| Receive against an unapproved order | status check | ✔ receipt was created |
| Add a landed cost | `purchasing.receive` | ✔ 403 became 302 |
| Approve a bill | `ThreeWayMatch` must pass first | ✔ a mismatched bill was approved |
| Pay a bill | `payments.create` | ✔ 403 became 302 |
| Pay an unapproved bill | status check | ✔ payment went through |
| Approvals inbox | `purchasing.approve` | ✔ 403 became 200 |

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` | passed |
| `phpstan` (level 6) | no errors |
| `tsc --noEmit` | clean |
| `npm run build` | built |
| `pest` | **1,433 passed / 2,535 assertions** (up from 1,407) |

### Carried forward

| ID | Item |
|---|---|
| P9-5h | **No approval-flow setup screen.** Flows, levels and approver roles are seeded or created in `tinker`; without one, approval is a direct decision |
| P9-5i | No stock-transfer or stock-count screens |
| P9-5j | No supplier debit notes, and no partial-return path on a receipt |
| P9-5k | A purchase order's lines cannot be edited after creation — it must be cancelled and re-raised |
| P9-5l | **The by-weight allocation had never run correctly in this codebase.** Any average cost computed from a by-weight receipt before today was wrong. No production data exists yet, so nothing needs restating — but if this code was ever copied elsewhere, it carries the bug |
| P9-5m | Still true from Appendix O: no screen has been used by a human |


---

## Appendix Q — Access administration (2026-08-15)

Chosen as the next step because a company could hold exactly **one** usable account: the only way to
add staff was `erp:create-owner`, which makes owners, or `tinker`. Everything else built so far was
unadoptable behind that.

### Scope delivered

| Screen | Actions |
|---|---|
| People | list · add (sign-in account + company membership in one step) · edit role, branch, department, employee number, active |
| Roles and reach | role × permission matrix, with the **data scope editable per role per permission** |
| Your profile | change your name, and change your own password |

The profile screen is not decoration: an administrator sets a new person's initial password, so
without a self-service change that password could never be rotated. Adding user administration
without it would have been a net loss in security.

### The four rules, all in `AccessAdministrator`

| Rule | Why |
|---|---|
| You cannot grant a role carrying a permission you do not hold | otherwise anyone with `users.create` promotes themselves to owner |
| You cannot change your own access | the same escalation by a shorter route |
| The last active owner cannot be demoted or deactivated | a company with no active owner can never be administered again |
| You cannot set a reach wider than your own on that permission, nor edit a role you hold | scope is a privilege like any other |

Which permissions a role carries stays fixed by `PermissionRegistry` in code. **Only reach is
editable at runtime** — so a deploy cannot be silently undone in the database, while the data-scope
layer the brief asked for is genuinely tunable.

### D-6: two guards were unreachable, and their tests were proving a different guard

The plant pass caught both:

| Test | What was really refusing |
|---|---|
| "refuses to change your own access" | the owner editing themselves to staff also tripped the **last-owner** guard. Removing the self-edit guard changed nothing |
| "refuses to deactivate the last active owner" | the request carried `role=owner`, which tripped the **escalation** guard first, because an admin does not hold every owner permission |

The second one exposed a real design flaw, not just a weak test: **re-submitting a member's
existing role was being treated as granting it.** An administrator therefore could not edit an
owner's branch or employee number at all. Fixed so the escalation check runs only when the role
actually changes — which is both correct and what makes the last-owner guard reachable.

Tests rewritten to isolate the guard each one names: self-edit now uses an administrator who is not
an owner; deactivation now keeps the role unchanged so escalation cannot pre-empt it. Two positive
tests added alongside (an admin editing an owner's details, and deactivating an owner once a second
active owner exists), so the guards are shown to permit as well as refuse.

This is the third appearance of the same failure mode — after Appendix O's invoice void and
Appendix P's cross-company purchase order. Stated once more, plainly: **where defence is layered,
a refusal proves nothing about the guard you meant to test.** Only planting the violation tells you
which layer answered.

### §18 compliance

| Action | Gate | Planted violation → failed |
|---|---|---|
| See the people list | `users.view` | ✔ 403 became 200 |
| See the role matrix | `roles.view` | ✔ 403 became 200 |
| Grant a role above your own | permission-subset check | ✔ the escalated account was created |
| Change your own access | self check | ✔ the self-demotion succeeded |
| Demote or deactivate the last owner | active-owner count | ✔ both succeeded |
| Widen a role's reach past your own | `DataScope::covers` | ✔ the widening was written |
| Edit the reach of your own role | own-role check | ✔ the change was written |
| Change a password without the current one | `Hash::check` | ✔ the password changed |

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` | passed |
| `phpstan` (level 6) | no errors |
| `tsc --noEmit` | clean |
| `npm run build` | built |
| `pest` | **1,459 passed / 2,653 assertions** (up from 1,439) |

### Carried forward

| ID | Item |
|---|---|
| Q-1 | **Nothing forces a password change.** An administrator-set password works indefinitely; the profile screen says so, but the system does not insist |
| Q-2 | No password reset by email, and no mail configuration is assumed anywhere |
| Q-3 | No two-factor authentication and no session-device list |
| Q-4 | A person cannot be removed from a company, only deactivated. That is deliberate — history references them — but there is no leaver workflow |
| Q-5 | Custom roles cannot be created; the eleven in `CompanyRole` are the catalogue |
| Q-6 | No screen shows *effective* access for one person (role permissions × scope × branch) — the matrix is per role, not per human |
| Q-7 | Still true from Appendix O: no screen has been used by a human |


---

## Appendix R — Commission configuration (2026-08-15)

The commission engine was the headline differentiator and, until today, **could not pay anybody**:
plans and rules existed only as tables, configurable through `tinker`. Six phases of engine sat
behind that.

### Scope delivered

| Screen | Actions |
|---|---|
| Commission plans | list · create · edit strategy, recipient, ad-spend allocation, active |
| Plan detail | add rules · **publish a rate as a new version** · stop or resume a rule · full version history |

A new permission, `commissions.configure`, separates *setting the rates* from *seeing and paying
them*. An accountant keeps `commissions.view/approve/pay` and is deliberately **not** given
`configure` — approving a payout and deciding what the rate is are different jobs.

### D-7: my design fought the ledger, and the database was right

`publishVersion()` originally closed the outgoing version by writing `valid_to` onto it. The second
publish failed every time: **a database trigger rejects any update to `commission_rule_versions`**,
because a published rate is a historical fact.

I could have dropped the trigger. Instead I read what the engine already does — `ruleVersionFor()`
orders by `valid_from DESC, version DESC` and takes the first — and concluded the design was wrong,
not the constraint. **Which version is in force is derived, not stored.** Rows are now written once
and never touched.

That turned out better than the original, not merely legal: a version can be published with a future
`valid_from` and the screen shows it as *scheduled* while the current rate stays *in force*, which
the write-back approach could not express. A test pins it.

The lesson: *when an invariant the codebase already enforces blocks a new feature, the feature is
usually the thing that is wrong.*

### D-8: making scope editable would have let every upgrade silently undo it

`RoleProvisioner::provision()` used `updateOrCreate` on `RolePermissionScope`, resetting each scope
to the role default. Harmless while scopes were only ever set by that same seeder — **destructive
the moment Appendix Q made them editable in the UI.**

An administrator narrows `customers.view` to branch; the next deploy re-provisions and silently
widens it back to company. Nobody would notice until data leaked.

Changed to `firstOrCreate`, so provisioning fills gaps and never overwrites. Added
`php artisan erp:sync-roles` — needed anyway, since a release that adds a permission does not reach
existing companies until roles are re-provisioned — with two tests: a tuned scope survives a sync,
and a newly shipped permission arrives through one. The first was proven by restoring
`updateOrCreate` and watching it fail.

Both `README.md` and `DEPLOYMENT.md` now carry the upgrade step.

### The claim that matters

Configuration is worth nothing if it does not pay. One test drives the whole path through HTTP:
create a plan → add a rule → publish a 5% rate → place a 400 order as a salesperson →
**accrue 20.00 to that salesperson**, and see it on the commission screen. A companion test proves
an order accrues **nothing** while no plan is configured, so the first test cannot be passing by
accident.

### §18 compliance

| Action | Gate | Planted violation → failed |
|---|---|---|
| See the plans screen | `commissions.configure` | ✔ 403 became 200 |
| Publish a rate | `commissions.configure` | ✔ 403 became 302 |
| Backdate a rate over the one in force | start-date check | ✔ the backdated version was written |
| Percentage above 100 | rate ceiling | ✔ 140% was accepted |
| Rate of zero or less | positivity check | ✔ 0 was accepted |
| Fixed rate on a percentage plan | strategy match | ✔ the mismatch was accepted |
| Change strategy after accrual | accrual lock | ✔ the strategy changed under paid commission |
| Future-dated rate taking effect early | `valid_from <= now` | ✔ the future rate applied immediately |
| Tuned scope surviving an upgrade | `firstOrCreate` | ✔ the scope reverted to the role default |

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` | passed |
| `phpstan` (level 6) | no errors |
| `tsc --noEmit` | clean |
| `npm run build` | built |
| `pest` | **1,478 passed / 2,769 assertions** (up from 1,459) |

### Carried forward

| ID | Item |
|---|---|
| R-1 | **Tiered rates are not editable.** `tier_config` and `conditions` are stored and honoured by the engine, but the screen publishes a flat rate only |
| R-2 | No screen shows *what a plan would pay* on a sample order before it goes live |
| R-3 ✔ | ~~The `upline` recipient role can be selected but the engine has no upline resolution~~ **CLOSED** — Appendix S implements it from `company_users.manager_id` |
| R-4 | Accrual is not triggered from the UI; it runs on the order lifecycle. There is no "recalculate this period" action |
| R-5 ✔ | ~~Ad-spend allocation is configurable per plan but campaign spend still has no screen~~ **CLOSED** — Appendix S |
| R-6 | Still true from Appendix O: no screen has been used by a human |


---

## Appendix S — Marketing setup and attribution reporting (2026-08-15)

Attribution is the brief's other named differentiator. It was fully built and fully tested — and
**unusable**, because nothing could create a channel or a campaign. Every order's attribution came
out with a salesperson and nothing else, and the twelve tested reports in `AttributionReport` had
never had a screen.

### Scope delivered

| Screen | Actions |
|---|---|
| Channels | list · create · turn on/off, with campaign and attribution counts |
| Campaigns | list · create · edit · **record ad spend** · budget against spend |
| Marketers | list · link a company member as a marketer · activate/deactivate |
| Attribution | seven reports over a date window: revenue by campaign, channel, marketer, salesperson and branch; **spend against return**; cost per lead |

A new `marketing` permission group (`view`, `manage`) separates running campaigns from reading them:
a marketer sees what they run, a marketing manager changes it, a salesperson sees neither.

This closes **R-5** — ad-spend allocation had been configurable per plan since Appendix R with no
way to enter any spend, which meant a margin plan treated every campaign as free and overpaid.

### R-3 closed: the trap I shipped last session

Appendix R left `upline` selectable as a commission recipient while the engine had no resolution for
it — a plan could be configured, look correct, and pay nobody, silently. That is worse than an
error message.

Implemented from `company_users.manager_id`: the upline of an order is the manager of the
salesperson frozen on its attribution. Two tests — one proving the manager is paid 2% of 500 rather
than the seller, one proving nothing accrues when the seller has no manager. The first was proven by
deleting the `'upline'` match arm.

The lesson is about last session, not this one: **offering a choice the engine cannot honour is a
defect even when nothing crashes.** Either implement it or do not list it.

### The claim that matters

A test drives the whole chain: create a channel → create a campaign → record 400 of ad spend →
place a 1,000 order → attribute it to that campaign → the report shows **spend 400, revenue 1,000,
net 600**. A second test shifts the window a year back and asserts the same data reports **nothing**,
so the first cannot be passing by ignoring its filters — which is exactly what the planted violation
on the date window confirmed.

### §18 compliance

| Action | Gate | Planted violation → failed |
|---|---|---|
| See channels, campaigns, marketers | `marketing.view` | ✔ 403 became 200 |
| Create a channel | `marketing.manage` | ✔ 403 became 302 |
| Record ad spend | `marketing.manage` | ✔ 403 became 302 |
| Make an outsider a marketer | company-membership rule | ✔ the outsider was linked |
| Link the same person twice | uniqueness rule | ✔ a duplicate marketer was created |
| Read the attribution report | `reports.view` | ✔ 403 became 200 |
| Honour the reporting window | date filter | ✔ out-of-window rows appeared |

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` | passed |
| `phpstan` (level 6) | no errors |
| `tsc --noEmit` | clean |
| `npm run build` | built |
| `pest` | **1,495 passed / 2,878 assertions** (up from 1,478) |

### Carried forward

| ID | Item |
|---|---|
| S-1 | **Attribution cannot be captured from the web.** There is no landing-page or UTM capture endpoint, so `attribution_touches` are only ever written by tests or by hand — the campaign on an order must be set deliberately |
| S-2 | No referral-code screen, and no marketing-team screen |
| S-3 | Cost per lead uses whole-life campaign spend against windowed leads. The screen says so; the numbers are not directly comparable |
| S-4 | No export from the attribution screen, and no chart — tables only |
| S-5 | Campaign spend has no approval or budget enforcement. Going over budget is reported, never prevented |
| S-6 | Still true from Appendix O: no screen has been used by a human |


---

## Appendix T — Preparing P9-1 for a third party (2026-08-15)

P9-1 cannot be closed from inside this project — an external review is external by definition. What
*can* be done from inside is remove every reason for the engagement to be slow, vague or expensive.
`SECURITY-REVIEW.md` is that work: the trust model, the claim under test, what is already asserted
and where I think the weak points are, ending with an explicit definition of *clean* and the
procedure for recording the outcome.

The most useful section is **§3, "what I would attack"** — seven specific places, ordered by where I
believe the risk actually sits, with the reasoning. A reviewer who disagrees with that ordering has
learned something in ten minutes rather than two days.

### D-9: the Security suite could not run on its own

Found while counting tests per suite for the brief. `pest --testsuite=Security` failed with
**"Call to undefined function person()"**: the Horizon gate test added in Appendix Q calls a helper
defined in `tests/Feature/DataScopeRouteTest.php`. A full run loads the Feature file first, so the
whole suite was green and the defect invisible.

It mattered for two reasons. A CI pipeline that shards by suite would have failed. And the first
thing a reviewer does is run the Security suite alone — the brief tells them to.

Shared helpers moved to `tests/Helpers.php`, loaded from `tests/Pest.php`. A static architecture
guard now fails if any suite calls a function defined in another suite's file, proven by moving
`person()` back and watching it name the offending file.

**Every suite now passes alone:** Unit 106 · Architecture 13 · Isolation 1,027 · Feature 310 ·
Concurrency 10 · Security 30. Total **1,496 / 2,879 assertions**.

The pattern is the same one this project keeps hitting: *a green full-suite run says nothing about
the ways the suite might be invoked.* Test the invocation, not only the tests.

### One claim in the brief was overstated, and was corrected

§3.1 originally told the reviewer that a grep for `DB::table(` would show hits that "should carry an
explicit company filter", implying the architecture guard covers them. It does not: the guard asserts
that a **file** using `DB::table()` mentions `company_id` somewhere, so a file with two raw queries
where only one is scoped would pass. The brief now says so plainly and tells the reviewer to check
call sites rather than files.

That limitation is worth carrying forward on its own account.

### Carried forward

| ID | Item |
|---|---|
| T-1 | **The raw-query architecture guard is file-level, not call-site-level.** Two raw queries in one file, one scoped, passes. Tightening it means parsing rather than grepping |
| T-2 | P9-1 still open — the brief is written, the engagement is not booked |
| T-3 | P9-3 still open — `.github/workflows/ci.yml` has never executed on a runner, and there is no remote |


---

## Appendix U — CI ran for the first time, and failed usefully (2026-08-15)

`.github/workflows/ci.yml` had existed since P0 and had never executed. On the first push to a real
remote it ran, and **two of four jobs failed**. Both were defects that no amount of local testing
could have surfaced, because both were caused by the local machine itself.

### D-10: the test suite only ran on my computer

```xml
<env name="DB_USERNAME" value="wafazztechnology"/>
```

`phpunit.xml` hardcoded the developer's macOS account as the PostgreSQL user. Every one of the
1,496 tests passed locally and **every one failed on the runner**, because that role does not exist
there.

The suite had been green for ten waves of work while being, in the strict sense, unportable. Nobody
else could have run it — including the external reviewer that `SECURITY-REVIEW.md` invites, whose
first instruction is to run the suite.

Fixed by deleting the credential from `phpunit.xml` so it falls through to the environment, and
setting `DB_USERNAME`/`DB_PASSWORD` at job level in CI. A guard now fails if either is ever pinned
there again.

### D-11: the documented PHP version could not install the project

CI tested PHP 8.3 and 8.4. **8.3 failed at `composer install`** — eight Symfony packages in
`composer.lock` require `php >=8.4.1`. `composer.json` says `^8.2`, which is true for a fresh
resolve but false for the lock that is actually committed.

`README.md` and `DEPLOYMENT.md` both promised **PHP 8.3+**, including an Nginx config pointing at
`php8.3-fpm.sock`. Anyone following the deployment guide on 8.3 would have failed at the first
command.

Matrix pinned to 8.4; both documents corrected. A guard now reads the minimum PHP the lock file
actually requires and fails if the CI matrix tests anything below it.

### Why this matters more than the two fixes

Every gate report in this document — *"1,496 passed"* — was true **on one machine**. That is a
weaker claim than it appeared, and the difference was invisible until something else ran it.

The project's own rule says a test that has never been seen to fail proves nothing. The same holds
one level up: **a suite that has only ever run in one place has only ever proven something about
that place.**

### D-12: an architecture test needed a database its suite never migrates

The second CI run got past the credential fix and ran Pest for four minutes before failing.

`ModuleRouteTest` seeds permissions and creates a company, but it lives in the **Architecture**
suite, which deliberately has no `RefreshDatabase` because its other tests only read source files.
Locally it passed for five waves — my `sme_erp_test` database carried schema left behind by earlier
runs. On a runner the database is empty when the Architecture suite runs, so it failed.

Reproduced exactly by dropping the local test database and running the suite alone. Fixed with a
per-file `uses(RefreshDatabase::class)`.

A guard now fails if a test in `Unit` or `Architecture` seeds, resolves a company or touches the
schema without declaring `RefreshDatabase`. **Writing that guard produced two of its own defects,
both caught by planting:**

- it first matched `::create(`, which flagged `Finder::create()` — a false positive
- it then checked for the *string* `RefreshDatabase`, which the `use` import alone satisfies, so
  removing the actual `uses()` call still passed

The second is precisely the failure this project keeps naming. A guard written to catch tests
passing for the wrong reason was itself passing for the wrong reason.

**The full suite now passes against a dropped and recreated database: 1,499 / 2,882.** Until today
that had never been tried.

### D-13: the backend suite needed a compiled frontend

Third run. 62 tests failed with `Vite manifest not found at public/build/manifest.json`.

Every test that renders an Inertia page needs a compiled frontend. The PHP job never compiles one —
that is a separate job with no artifact sharing. Locally `public/build/` had existed since the first
`npm run build`, so those 62 tests had always passed.

Fixed with `$this->withoutVite()` in the base `TestCase`, which is the right answer regardless of
CI: **the PHP suite tests PHP.** It asserts Inertia props and HTTP status, and has no business
depending on a compiled bundle. The `Frontend` job still runs `tsc` and `npm run build`, so the
build is covered where it belongs.

Proven by moving `public/build` out of the way entirely and running the full suite: **1,499 passed
with no compiled frontend present.**

### D-14: a capital letter, invisible on macOS

Fourth run, 26 failures: `Inertia page component file [Dashboard] does not exist.`

Inertia's default page path is `resource_path('js/pages')` — **lowercase**. This project's directory
is `resources/js/Pages`. macOS is case-insensitive by default, so the finder resolved it here for
five waves. Linux is case-sensitive, so on a runner the path simply does not exist and every
`assertInertia` fails.

Fixed by publishing `config/inertia.php` that names the path explicitly, rather than depending on a
filesystem that quietly ignores case. A guard now reads every configured page path and compares its
basename against the real directory entry, reporting *"is really named [Pages]"* when they differ
only in case.

### The pattern, stated once

Five CI runs, five defects, all the same shape:

| | The runner lacked… |
|---|---|
| D-10 | a PostgreSQL role named after the developer |
| D-11 | a PHP version the docs promised but the lock cannot install |
| D-12 | database schema left behind by previous local runs |
| D-13 | a `public/build` directory left behind by a previous `npm run build` |
| D-14 | a filesystem that ignores the difference between `Pages` and `pages` |

None was a logic error. Every one was **an undeclared dependency on the state of one machine**, and
none was findable there, because the machine supplying the state was the machine running the test.

`Planning.md` has claimed *"N passed"* since P0. Every one of those claims was conditional on
accumulated local state that was never written down. The suite is now portable, and that is a
different and stronger property than being green.

The local reproduction is now the real gate: **drop the test database, move `public/build` aside,
build `.env` from `.env.example`, and run.** Under those conditions the suite passes 1,501 / 2,884.
Any future claim of a green suite should be made after that, not before.

Two of the three guards written during this work were themselves defective and caught by planting —
one matched an import rather than an activation, and one used Pest's `toContain($needle, $message)`,
which treats the message as a **second needle**. That last one is the exact trap recorded in the
CoreSentinel failures layer during P0, encountered again from the opposite direction.

### Carried forward

| ID | Item |
|---|---|
| U-1 ✔ | ~~A green run is still outstanding~~ **CLOSED** — run 31887436160 on `9a40f85` is green across all three jobs. Five runs, five defects, none of them a logic error |
| U-2 | PHP 8.3 is no longer supported. Dropping the Symfony 8 packages would restore it, if that ever matters to a deployment target |
| U-3 | No coverage reporting, no static analysis of the frontend beyond `tsc`, and CI does not run the Concurrency suite's multi-process tests under load |


---

## Appendix V — P10 begins: point of sale (2026-08-15)

### The scope gate was crossed knowingly

`§44` carries a hard rule: *no work past P4 until one real SME is using P0–P4.* Nobody has used any
screen to do a day's work, so starting P10 crosses it. **This was raised, and the decision to
proceed was taken deliberately** — recorded here so a later reader does not mistake it for an
oversight.

### The decision that shaped everything else

**A POS sale is an Order.** Not a parallel entity, not a lightweight receipt table.

Had the till written its own records, stock, commission, attribution, margin and every report would
have forked into two truths that drift apart within a week. Instead `PosService` calls the same
`OrderService` the sales screen does, and walks the result through the same `OrderStateMachine`:
draft → pending → approved → allocated → picked → packed → shipped → delivered → completed.

That is eight transitions for a two-second counter sale, and it writes eight `order_events`. The
cost is noise in the timeline. The benefit is that a counter sale is indistinguishable from any
other order to every downstream consumer — proven by a test asserting a POS sale produces an
attribution row crediting the cashier as closer.

### D-15: the till decremented stock twice

The first implementation moved stock itself, then walked the order through the state machine.
Selling 2 units removed 4.

`SyncStockWithFulfilment` already reserves on `Allocated` and commits on `Shipped` — the POS path
passes through both. This is the **second time** a duplicated listener has double-counted stock in
this project; P4 recorded the first, when a manual `Event::listen` registered an auto-discovered
listener twice.

Fixed by deleting `moveStock()` entirely. The lesson is the one P4 already recorded and I did not
apply: *before writing a side effect, check whether the lifecycle already produces it.*

That fix exposed a second question. The listener chooses a warehouse by branch, which is wrong for a
till — a register sells from its own shelf, not the branch default. `warehouseFor()` now prefers the
register's warehouse when an order carries a `pos_session_id`.

### D-16: a test that proved nothing, again

The warehouse test gave the counter shelf the same branch as the register, so **both** the POS path
and the branch fallback selected it. Removing the POS branch changed nothing and the test still
passed.

Rewritten so the branch warehouse and the counter shelf are different, and the fallback would
demonstrably pick the wrong one. Only then did the planted violation fail it.

Fourth occurrence of this shape. The rule stands: *a test only proves the guard it names when no
other guard can produce the same outcome.*

### What the till enforces

| Rule | Where |
|---|---|
| One open session per register | `PosService`, plus a **partial unique index** in PostgreSQL as a second line |
| A sale is fully tendered before goods leave | `PosService`, and independently the state machine refuses to ship an unpaid, non-COD order |
| Change is not takings | Tenders are applied up to the amount due and no further |
| Expected cash is derived | Float + cash sales ± till movements. **Variance is computed, never typed** |
| Till movements are append-only | Model guard *and* a database trigger |
| Sell only from an open session | `PosService` |
| A cashier reaches only their own till | `visibleTo` on `PosSession`, `own` scope by default |

Eleven guards, each proven by deleting it and watching the test fail.

Two of those rules turned out to have a second line of defence that answered first when the primary
was removed — the unique index caught the double session, and the state machine caught the unpaid
ship. Both tests still failed, so both guards are proven to matter; it is worth knowing that neither
is load-bearing alone.

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` | passed |
| `phpstan` (level 6) | no errors |
| `tsc --noEmit` | clean |
| `pest` | **1,562 passed / 3,052 assertions** (up from 1,501) |
| **CI-equivalent run** | fresh database, no compiled frontend, `.env` from `.env.example` — **all 1,562 pass** |

The Isolation suite grew from 1,027 to 1,060 on its own: it is reflection-driven, so the three new
POS models were covered the moment they existed, and the suite refused to pass until each had a seed
recipe. That is the mechanism working exactly as designed.

Adding POS permissions also broke one access test in a way worth keeping: a sales manager could no
longer create salespeople, because salespeople now hold `pos.*` and the manager did not. Rather than
weaken the escalation rule, the manager was given the same till permissions — a sales manager who
cannot cover the counter is not a realistic role anyway.

### Carried forward

| ID | Item |
|---|---|
| V-1 ✔ | ~~No receipt~~ **CLOSED** — a print-friendly receipt at `/pos/receipt/{order}`, showing tenders, change and a REFUNDED stamp |
| V-2 ✔ | ~~No refunds at the till~~ **CLOSED** — full and part returns, stock back, tenders refunded in proportion, commission adjusted by the share returned |
| V-3 | No held or parked sales — a sale in progress is lost if the page is left |
| V-4 | No barcode hardware integration; the scan box is a text input that scanners can type into |
| V-5 | No cash-drawer hardware, no receipt printer, no customer display |
| V-6 | Eight `order_events` per counter sale. Correct, but noisy — a session that sells 200 items writes 1,600 rows |
| V-7 | No X-report or Z-report beyond the closing variance |
| V-8 | Still true from Appendix O: **no screen has been used by a human** |


---

## Appendix W — Making the till usable: receipts and refunds (2026-08-15)

A till that cannot print a receipt and cannot correct a mistake is not a till. Appendix V shipped
the selling half; this closes the two gaps that stopped it being run in a shop.

### The refund had to be sequenced, not just implemented

A refund is four things at once: goods back on the shelf, money back to the customer, commission
un-earned, and the order marked returned. The first attempt did them in the obvious order and
failed — **the state machine refused `Refunded` because `paid_amount` had already reached zero**:

> *Nothing has been received against this order, so there is nothing to refund.*

That rule is correct and worth keeping. The sequence was wrong. The order now moves to
`Returned` and then `Refunded` **while the money is still recorded against it**, and only then are
the negative payments written. A refund reads, in the ledger, as: this order was paid, then returned,
then the money went back.

Each tender is refunded **to the method it arrived on** — card to card, cash out of the drawer —
so `expectedCash()` falls by the cash portion only. A split-tender sale of 60 card plus 40 cash
reduces the drawer by 40, not 100. There is a test for exactly that.

### D-17: a guard that was already redundant

`receipt()` opened with `abort_if($order->pos_session_id === null, 404)`. Planting proved nothing:
deleting it still produced a 404, because the very next line calls `firstOrFail()` on the session
and null matches no row.

The guard was dead code stating an intention the following line already enforced. **Removed.** The
test then had to be proven against the line that actually does the work — replacing `firstOrFail()`
with `first()` makes it fail — which is now the case.

Worth naming as its own category. The previous three occurrences were tests passing because a
*different* guard refused. This is the same shape one level down: **code passing review because a
neighbouring line does its job for it.** Planting finds both.

### What a refund enforces

| Rule | Proven by planting |
|---|---|
| A sale is refunded once | ✔ second refund succeeded |
| A refund needs a reason | ✔ empty reason accepted |
| Only a sale taken at a till can be refunded at one | ✔ a web order was refunded |
| Stock goes back on the shelf | ✔ shelf stayed short |
| Each tender returns to its own method | ✔ no refund payments written |
| Commission is reversed | ✔ the accrual survived |
| Refunding needs `pos.sell`, not `pos.view` | ✔ 403 became 302 |

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,571 passed / 3,113 assertions** |
| CI-equivalent run | fresh database, no compiled frontend, `.env` from `.env.example` — all pass |

### Carried forward

| ID | Item |
|---|---|
| W-1 ✔ | ~~Part-returns are not supported~~ **CLOSED** — see Appendix X |
| W-2 | A refund can only be taken at an open till, and returns the money to that drawer. A customer returning to a different branch has no path |
| W-3 | The receipt prints from the browser. No hardware, no cash drawer kick, no thermal-printer formatting |
| W-4 | No exchange flow — a return followed by a new sale is two separate transactions |
| W-5 ✔ | ~~Refunds are not restricted~~ **CLOSED** — a cashier may refund only sales from their own open session, and only up to the register's refund limit. Anything else needs `pos.manage` |


---

## Appendix X — Part returns, and a revenue figure that had been wrong all along (2026-08-15)

A shop where a customer returning one of three items must be refunded in full and re-sold is not a
shop anyone runs. This closes it — and closing it uncovered something older.

### The revenue figure was overstating every refund

`order_items.quantity_returned` has existed since P3 and **had never been written by anything**.
Reports excluded cancelled orders but not returned ones, and summed `orders.total`.

So the full refunds shipped one wave earlier were **counting as revenue in every report** — sales
rollups, campaign return-on-spend, salesperson revenue, branch revenue. A shop that sold 1,000 and
refunded 400 would have shown 1,000.

Nothing caught it because nothing asked. There was no test that a refunded sale leaves revenue, so
the wrong number was never contradicted.

Fixed at the source rather than per-report: a new `orders.returned_amount` records what came back,
and revenue is `total - returned_amount` in the rollup service and in all four attribution revenue
queries. `Order::outstanding()` nets it too, so a part-returned sale no longer looks like a debt.

### What a part return does

| | |
|---|---|
| Stock | only the returned quantity goes back on the register's shelf |
| The line | `quantity_returned` accumulates; `quantity` still records what was sold |
| Money | each original tender is refunded **in proportion**, with the remainder placed on the largest so the total is exact to the cent |
| Commission | a negative `adjustment` entry for the returned share — the original accrual stands rather than being reversed and re-earned |
| The order | reaches `Returned` only when the last item comes back |

The proportional commission is the part worth defending. Returning a quarter of a sale writes an
adjustment of −25% of the commission. Reversing the whole accrual would underpay; leaving it alone
would overpay. The `adjustment` type already existed in the schema for exactly this, and it avoids
the unique-accrual index that a re-accrual would have collided with.

### Constraints that answered before the code did

Two plants were caught by the database rather than by my guard:

- returning more than was sold hit `order_items_returned_check`, a CHECK constraint written in P3
- `orders_returned_not_over_total_check`, added here, refuses a `returned_amount` above the total

Both tests still failed, so both guards are proven to matter. It is worth knowing that neither is
the only thing standing there.

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,578 passed / 3,156 assertions** |
| CI-equivalent run | fresh database, no compiled frontend, `.env` from `.env.example` — all pass |

Six part-return rules proven by planting; a seventh plant was refused by the edit script because its
anchor was not unique, which is the script working as intended rather than guessing.

### Carried forward

| ID | Item |
|---|---|
| X-1 | **Revenue was overstated by every refund until today.** No production data exists, so nothing needs restating — but any figure quoted from an earlier build was wrong by the value of its refunds |
| X-2 | A part return refunds tenders proportionally. Real shops often refund cash first regardless of how it was paid; this is a policy choice the system currently makes for you |
| X-3 ~ | Still open from Appendix W: refunds only at an open till, to that drawer (W-2); no exchange flow (W-4). Supervisor control is now in place (W-5 closed) |
| X-4 ✔ | ~~`quantity_returned` is written by the till only~~ **CLOSED** — a return recorded anywhere puts the goods back, and the take-back is idempotent so the two paths cannot double-count |


---

## Appendix Y — Closing the refund hole, and one truth in two places (2026-08-15)

Two items from the POS review, both defects rather than gaps.

### W-5: a cashier could reverse yesterday's takings

Refunds were built because a till without them is unusable. The control that stops them being abused
was not, and that is the classic retail theft route: ring a sale, pocket the cash, refund it after
the customer has gone.

Two rules now, both enforced in `PosService`:

- **A cashier may only refund a sale from the session they are standing at.** Anything older needs
  `pos.manage`. This is the rule that closes the theft route, because the cash a cashier can reverse
  is cash still sitting in the drawer they are about to have counted.
- **A refund above the register's `refund_limit` needs `pos.manage`.** The limit is nullable, so a
  shop that does not want one is not forced into it.

The escalation is *"a supervisor performs it"*, not *"a supervisor approves it"*. No PIN pad, no
override dialogue — the person with the permission has to be the one signed in. That is weaker in
convenience and stronger in evidence: the audit trail names who actually did it, with no shared
override code to borrow.

### X-4: the same event meant two different things

The till wrote `quantity_returned` and put goods back on the shelf. Marking an order returned from
the **order screen** did neither — it set a status and left the stock sold.

One event, two behaviours, depending on which screen you used. That is worse than a missing feature,
because the numbers disagree quietly.

`SyncStockWithFulfilment` now handles `ExceptionStatus::Returned` the way it already handles
`Allocated` and `Shipped`. It returns whatever is still outstanding, sets `quantity_returned`, and
adds to `returned_amount`.

**The take-back is idempotent by construction** — it only moves what has not already come back. So
the POS path, which has already returned everything before it transitions, finds nothing to do. That
matters: this project has now double-counted stock twice from duplicated listeners (P4, and again in
Appendix V), and this design cannot do it a third time. Proven by a test that refunds through the
till and asserts stock came back once, and by planting the removal of the outstanding check.

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,584 passed / 3,174 assertions** |
| CI-equivalent run | fresh database, no compiled frontend, `.env` from `.env.example` — all pass |

Five guards proven by planting: cross-session escalation, the refund limit, the supervisor
exemption, the order-screen take-back, and its idempotence.

### Carried forward

| ID | Item |
|---|---|
| Y-1 | The refund limit is per register and set in the database — **there is no screen for it**. A shop cannot change its own limit without a developer |
| Y-2 | A supervisor still has no limit at all. There is no daily refund total, no report of who refunded what |
| Y-3 ✔ | ~~An order-screen return refunds no money~~ **CLOSED** — see Appendix Z. The debt is now derived, shown and payable |
| Y-4 | Still open: no exchange flow (W-4), no cross-branch returns (W-2), no held sales (V-3) |
| Y-5 | Still true: **no screen has been used by a human** |


---

## Appendix Z — Money owed back is now a number the system holds (2026-08-15)

Y-3: a return recorded away from the till put goods back on the shelf and refunded nothing. The
shop owed the customer and no part of the system said so.

### An order can owe, or be owed, never both

`Order::outstanding()` was `total − returned − paid`, which went **negative** once goods came back
without a refund. A negative outstanding is not a smaller debt; it is the opposite relationship, and
reading it as a debt is how a shop chases a customer it actually owes.

Split into two figures that cannot both be non-zero:

```
keptTotal   = total − returned_amount        what the customer is keeping
outstanding = max(0, keptTotal − paid)       what they still owe us
refundDue   = max(0, paid − keptTotal)       what we owe them
```

The order screen shows one or the other, labelled, and offers **Refund** when money is owed back.
`OrderService::refund()` writes the negative payment, refusing anything above what the returned
goods are worth, and moves the status to `Refunded` **before** the money leaves — the same ordering
the till already needed, for the same reason.

### D-18: a test that accepted the wrong refusal

Planting the removal of *"you cannot refund more than is owed"* did not fail the test. The refund of
250 against 100 still errored — because `paid_amount` went negative and the **database** CHECK
constraint from P3 rejected it.

The test asserted `assertSessionHas('error')`. Any error satisfied that, including one from a
completely different layer. It now asserts the message contains *"is owed"*, and the plant fails.

This is the fifth time in this project a test has passed for a reason other than the one it names,
and the second time the answer came from a database constraint. The correction generalises:
**asserting that something failed is not the same as asserting why.**

### A second thing the plant pass found

`takeBack()` returned early when no warehouse could be resolved, so an order with no warehouse
recorded **nothing** — not the returned quantities, not the money owed. Whether stock can be placed
somewhere is a separate question from whether goods came back. Recording the return now always
happens; moving stock happens only if there is somewhere to move it to.

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,587 passed / 3,208 assertions** |
| CI-equivalent run | fresh database, no compiled frontend, `.env` from `.env.example` — all pass |

### Carried forward

| ID | Item |
|---|---|
| Z-1 ✔ | ~~A refund away from the till is not restricted~~ **CLOSED** — refunding is its own permission, and a till sale keeps its supervisor rule wherever it is refunded |
| Z-2 | There is still no credit note. The refund is a negative payment on the original order, which is honest but leaves no document to hand a customer |
| Z-3 | Nothing reports orders with money owed back. Finding them means opening orders one at a time |
| Z-4 | Still open: no exchange flow (W-4), no cross-branch returns (W-2), no held sales (V-3), no screen for the register refund limit (Y-1) |
| Z-5 | Still true: **no screen has been used by a human** |


---

## Appendix AA — Closing the bypass I opened beside a fixed hole (2026-08-15)

Appendix Y put supervisor controls on till refunds. Appendix Z added a refund on the order screen
with none of them, needing only `payments.create`. **A cashier blocked at the till could do the same
thing one screen over.**

That is worth naming plainly: fixing a fraud hole and opening a smaller one beside it is a worse
outcome than leaving the first one visible, because the control now *looks* present.

### Two changes

**Refunding is its own permission.** `payments.refund` is separate from `payments.create`, because
taking money in and giving it back are different privileges with different fraud profiles. The
wildcard grants gave it to owner, admin and accountant; nobody else has it.

**A till sale keeps its till rule wherever it is refunded.** `OrderPolicy::refund()` requires
`pos.manage` for any order carrying a `pos_session_id`, regardless of which screen the request came
from. The accountant is the case that proves it: they hold `payments.refund` and not `pos.manage`,
so they can refund an ordinary order and are refused a counter sale.

The rule is deliberately about the **order's origin**, not the request's route. A control that
depends on which URL was used is a control that a different URL removes.

### D-19: a test that could not have failed

Planting the removal of the data-scope check passed. The test used an owner at company scope, so
removing the scope narrowing changed nothing — the actor could reach the order either way.

Added a test that names it: a salesperson at `own` scope, holding `payments.refund`, refused a
refund on a colleague's order. That one fails when the check is removed.

Sixth occurrence this project. The distinguishing question is always the same: **could this test
have failed if the guard it names were absent?** If the fixture makes the actor privileged enough to
pass either way, the answer is no, and the test is decoration.

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,591 passed / 3,223 assertions** |
| CI-equivalent run | fresh database, no compiled frontend, `.env` from `.env.example` — all pass |

Four guards proven by planting: the refund permission, the till-origin rule, the supervisor
exemption, and the data scope.

### Carried forward

| ID | Item |
|---|---|
| AA-1 ✘ | ~~The register refund limit still only applies at the till~~ **WITHDRAWN — the item was wrong.** See the correction below. The order screen is stricter than the till, not looser |
| AA-2 | `payments.refund` is not on the roles-and-reach screen as anything special — it reads like any other permission, though it is one of the few that moves money outward |
| AA-3 | Still open: no credit note (Z-2), nothing lists orders owing money back (Z-3), no exchange flow (W-4), no cross-branch returns (W-2), no held sales (V-3), no screen for the register limit (Y-1) |
| AA-4 | Still true: **no screen has been used by a human** |
| AA-5 | **No ceiling on refunds for ordinary orders.** Distinct from the withdrawn AA-1: the register limit is a register concept and does not apply to non-POS sales, so anyone holding `payments.refund` can refund any order they can reach, up to what the returned goods are worth. Whether that needs a limit is a policy question, not a defect |

### Correction: AA-1 was not a real gap

Asked whether AA-1 needed fixing, I read the two paths instead of building the control, and the item
does not survive the reading.

| | Who is refused |
|---|---|
| **At the till** | a cashier above the register limit; `pos.manage` holders are exempt |
| **On the order screen** | *every* non-`pos.manage` holder, for any order that came from a till |

The people who can refund a counter sale from the order screen are exactly the people already
unlimited at the till. There is no wider door — **the order screen is the narrower one.** A cashier
refused by the register limit cannot use it at all.

A test now pins that permanently: a salesperson granted `payments.refund` outright is refused by the
register limit at the till *and* refused at the order screen, and it fails if the till-origin rule is
removed.

What the item was reaching for is real but different, and is recorded as AA-5: an ordinary,
non-counter order has no refund ceiling for anyone holding the permission. That is a policy choice
rather than a bypass, and imposing one without being asked would be inventing a requirement.

**The lesson is about the carried-forward list itself.** Those entries are written at the end of a
wave, from memory of the code just written, and are not tested. AA-1 was plausible, adjacent to a
real hole I had just closed, and wrong. An item on that list is a claim, and claims in this project
are supposed to be checked before they are acted on.


---

## Appendix AB — CRM: the pipeline, and a panel that had never had anything in it (2026-08-15)

Second P10 module, chosen because CRM had the most domain already built and one live defect sitting
inside it.

### The defect: a screen reading a table nothing wrote

`Lead/Show.tsx` has displayed an **Activity** panel since Appendix O, with the empty state *"Calls,
messages and meetings appear here once recorded."* Nothing in the system ever recorded one.
`LeadActivity` was read in one place and written in none.

`PipelineStage` had the same shape from the other direction: the lead form offered a stage dropdown
and there was no way to create a stage, so on a fresh install it was permanently empty.

Both are the pattern this project keeps meeting — **a surface that promises something the domain
never delivers.** Third instance after `quantity_returned` (Appendix X) and the `upline` recipient
(Appendix S).

### Two activity tables, one job each

The schema carries `lead_activities` and `sales_activities`, overlapping enough to be confusing. They
now have distinct jobs:

| | |
|---|---|
| `lead_activities` | **the lead's timeline** — what happened, including system-written `status_changed` entries |
| `sales_activities` | **the salesperson's diary** — carries `follow_up_at`, and can hang off a customer as well as a lead |

Logging a contact writes both: the timeline entry because that is what the lead screen shows, and the
diary entry because that is what the follow-up list reads. One user action, two records with
different lifetimes — stated here rather than left for someone to discover.

### What was built

| Screen | |
|---|---|
| **Pipeline board** | leads by stage, with each column's value and its **weighted** value — the total multiplied by that stage's probability |
| **Follow up now** | everything due or overdue, on the board and on the lead |
| **Log a contact** | call, WhatsApp, email, meeting, visit or note, with an optional follow-up date |
| **Move stage** | with a note, recorded on the timeline |
| **Stages** | create and edit stages, their odds, order, and whether reaching one means won or lost |

A new `leads.configure` permission separates *working* the pipeline from *shaping* it. Adding it
revealed that `leads.*` gave marketers the ability to restructure the sales pipeline — narrowed to
explicit abilities for both marketing roles.

Moving a lead into a winning or losing stage sets its status to match, so **the board and the lead
list cannot disagree**. A converted lead is refused any further movement, and a won lead drops off
the follow-up list — nobody should be chased about a deal already closed.

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,606 passed / 3,293 assertions** |
| CI-equivalent run | fresh database, no compiled frontend, `.env` from `.env.example` — all pass |

Eight rules proven by planting, including the two that close the defect: the timeline entry is
really written, and the board really weights by probability.

### Carried forward

| ID | Item |
|---|---|
| AB-1 | **No quotations.** A lead that reaches "quoted" has no document behind it — the stage is a label, not a record. **If this is built, see the warning below: a quotation must not be modelled as a draft order** |
| AB-2 | The board is read-only. Moving a lead means opening it; there is **no drag between columns** |
| AB-3 | Follow-ups have no owner separate from the person who logged them, and no way to mark one done other than logging the next contact |
| AB-4 | `sales_activities` can hang off a customer, but **only leads have a screen that writes one**. Contact with an existing customer is still unrecordable |
| AB-5 | No territories, sales teams or targets screens, though all three exist in the domain and `SalesTarget` already feeds the dashboard |
| AB-6 | Still true: **no screen has been used by a human** |


---

## Appendix AC — Investigating AB-1, and a change I made and withdrew (2026-08-15)

Asked whether AB-1 needed fixing, the answer is **no, but the question was worth asking** — it found
something, and it also found the limit of what I should decide alone.

### AB-1 is accurate, unlike AA-1

There is no quotation anywhere: no table, no model, no status. `Planning.md` §263 describes one with
a nine-state machine and revisions, but that is **reference material from a prior system**, not a
record of anything built here. The P3 row listing *"Quotation → SO → DO → Invoice → Payment"* names
the intended flow; the quotation end of it was never implemented.

So the item stands. Whether to build it is a business decision — a B2B seller needs a document to
send, a counter shop does not.

### What the question uncovered

Probing whether a draft order could stand in for a quotation showed that **a draft order counts as
revenue**. Reports exclude only `cancelled`. A 5,000 draft appears in the sales rollup and in all
four attribution revenue queries.

That is the same shape as X-1, where refunds counted as revenue — found two waves ago, fixed for
returns, and never re-checked for anything else.

### The change I made, and why I took it back

I excluded `draft` from revenue. **It broke twenty tests**, and looking at why was more informative
than the change:

**No report fixture has ever set a fulfilment status.** Every order in the attribution, rollup and
dashboard suites is `draft`. The entire revenue-reporting surface has only ever been validated
against orders that never moved.

That could have been read as twenty wrong tests. Looking further, it is not:

`OrderService::create()` sets `placed_at = now()` and leaves the status at `draft`. **Every order
raised through the Orders screen is draft until somebody walks it through the state machine**, and
nothing forces them to. Excluding drafts would mean a shop entering orders normally sees **zero
revenue**, while counter sales — which the till walks to completed automatically — still count.

A system where typed orders vanish from revenue and till sales do not is worse than the problem.
Reverted.

### The conclusion that matters

`draft` in this codebase means *"not yet processed"*, not *"not yet accepted"*. Counting it is
defensible, because almost every real order passes through it.

**It stops being defensible the moment quotations exist.** If AB-1 is built, a quotation must be its
own record — not an order in `draft` — or every unaccepted proposal will inflate revenue on the day
it is written. That warning is now attached to AB-1 itself, where whoever builds it will see it.

### The wider lesson, twice over

Asking *"is this required?"* has now been worth it both times: **AA-1 was wrong and needed no work at
all**, and **AB-1 is right but the obvious implementation would have introduced a defect.**

And this appendix records a change I made, tested, and withdrew within the same session. The
twenty failures were not an obstacle to the change — **they were the evidence that the change was
wrong**, and reading them was worth more than making them pass.

### Carried forward

| ID | Item |
|---|---|
| AC-1 | **No report fixture sets a fulfilment status.** Revenue reporting is validated only against orders that never left `draft`, which is not what a real order looks like. Worth correcting even without changing behaviour |
| AC-2 | Nothing distinguishes *"draft, being typed"* from *"draft, sent to the customer and waiting"*. That distinction is what a quotation would provide |


---

## Appendix AD — HR: leave, and a balance that cannot be gamed (2026-08-16)

Third P10 module. Chosen because the person record already existed —
`CompanyUser` carries employee number, department, branch, joined date and **manager** — so leave
needed a domain, not a foundation.

### Approval by manager, not by flow

`ApprovalEngine` is generic and would have worked. It was not used.

Leave approval in an SME is *"your manager decides"*, and `CompanyUser.manager_id` already expresses
that — the commission engine uses the same field for upline. Routing leave through configurable
approval flows would have meant configuring a flow before anyone could take a day off, to express a
rule the data already stated.

`mayDecideFor()` is the whole rule: not yourself, and either their manager or a holder of
`leave.configure`. Everything else follows from it.

### The balance holds on request, not on approval

Asking for leave takes the days off the balance immediately. Rejecting or withdrawing gives them
back. Approval changes nothing about the balance — it was already spent when the request was made.

That ordering is what stops the obvious game: **two requests that each fit inside the balance, but
not together.** With the days held at approval time, both would pass validation and the second would
overdraw. Held at request time, the second is refused. A test pins the 14 → 11 → 14 sequence across
request and rejection.

Three further rules the same reasoning produced:

- **Weekends do not count.** Friday to Monday is two days, not four
- **Overlapping requests are refused**, backed by a partial unique index for the exact-duplicate case
- **A rejection needs a note.** The person has to know what to do next

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,650 passed / 3,396 assertions** |
| CI-equivalent run | fresh database, no compiled frontend, `.env` from `.env.example` — all pass |

Ten rules proven by planting. The Isolation suite grew by 22 on its own and **refused to pass until
both new models had seed recipes** — the same mechanism that caught the POS models, working without
being asked.

### Carried forward

| ID | Item |
|---|---|
| AD-1 | **Public holidays are not modelled.** Weekends are excluded; a Malaysian SME also needs state and federal holidays, which vary by state |
| AD-2 | **The document requirement is recorded but not enforced.** A leave type can be marked as needing a medical certificate and nothing uploads one — the screen says so |
| AD-3 | No half-days. Leave is whole working days only |
| AD-4 | No leave calendar or team view — a manager cannot see who is away next week without reading requests one at a time |
| AD-5 | Entitlement is a flat annual number. No accrual by month, no carry-forward, no pro-rating for a mid-year joiner, though `joined_at` is recorded |
| AD-6 ✔ | ~~Departments still have no screen~~ **CLOSED by removal** — the field is gone from the People form. See Appendix AE for why a screen would have been the wrong fix |
| AD-7 | Still true: **no screen has been used by a human** |


---

## Appendix AE — Removing a field rather than building a screen (2026-08-16)

Asked whether AD-6 needed fixing. Checking it produced a different answer than the item implied.

| | |
|---|---|
| Can a `Department` be created? | **Nowhere.** No `Department::create` in `app/` or any seeder |
| What reads `department_id`? | **Nothing.** No domain service, no report, no data scope |
| Is it on the People form? | Yes, as a dropdown |

So the form offered a dropdown that could never be populated, **for a field nothing consumed.**
Building the screen would have let somebody fill it in, and it would still have affected nothing —
a longer walk to the same place.

The defect was not "no screen". It was **a field with no purpose**, and the smallest honest fix was
to stop offering it. The table remains for whenever departments are given a job.

### The P1 gate claimed something that was never built

The P1 row reads *"Company/Branch/**Department**/User admin"* and is marked **✔ GATE CLOSED**.
Department admin does not exist and never did.

That is worse than the AA-1 case. AA-1 was a wrong note on a carried-forward list; this is an
overstatement inside a **closed phase gate**, which is the part of this document a reader is most
entitled to trust. Corrected in place rather than quietly deleted.

### D-20: the guard against unfillable fields was itself unfillable

A guard was added so no screen can offer a field nothing can populate. It passed. Restoring the
`department_id` field to prove it fires — and it **still passed**.

`glob('resources/js/Pages/**/*.tsx')` does not recurse in PHP. `**` is a bash feature; PHP's `glob`
treats it as a single directory level. The guard was scanning **zero files** and would have passed on
any codebase whatsoever.

Rewritten with `RecursiveDirectoryIterator`, and it now **asserts it found files before drawing any
conclusion from their absence**:

```php
expect($php)->not->toBeEmpty('the scan found no PHP, so this guard would pass on anything');
```

Seventh time in this project a test has passed for the wrong reason, and the second time it was a
guard written specifically to catch that class of problem. The generalisation is now explicit:
**a check that searches for something must first prove it looked somewhere.** An empty result set and
an empty search are indistinguishable in the assertion, and only one of them means anything.

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,651 passed / 3,399 assertions** |

### Carried forward

| ID | Item |
|---|---|
| AE-1 | The `departments` table, model and isolation seed recipe remain, with nothing reading or writing them. Kept deliberately — the cost of the table is nil and the decision to give departments a job has not been taken |
| AE-2 | **Other closed gates may carry the same overstatement.** P1's was found only because somebody asked about a related item. Nobody has re-read P0–P8 against what actually exists |


---

## Appendix AF — Subscriptions: billing that must not run twice (2026-08-16)

Fourth P10 module. Chosen for reuse — customers, orders, invoices, payments and document numbering
all existed — and it is the first module to use the **scheduler** for business value rather than
infrastructure.

### A subscription charge is an Order

The same decision as POS, for the same reason. `billOnce()` raises an ordinary `Order` with a line
for the plan's product variant, then calls `InvoiceService::issueFromOrder()`. Recurring revenue
therefore reaches rollups, attribution and commission by exactly the path everything else uses,
rather than a parallel one that drifts.

A plan sells a **product variant**, not an abstract amount, which is what makes that possible.

### The property that matters

**Running the billing job twice must not invoice a customer twice.** Three things enforce it:

1. `next_invoice_on` advances after each charge, so the subscription is not selected again
2. An existence check on `(subscription, period)` before inserting
3. A **partial unique index** on `orders (company_id, subscription_id, billing_period)`

A test bills three times and asserts one order. A second test **winds `next_invoice_on` back after a
successful charge** and bills again — removing the schedule as a defence, so only the data check
stands. That test exists because the first one could not fail when the check was deleted.

### D-21: catching a constraint violation inside a PostgreSQL transaction writes nothing

The first implementation inserted the order and caught the unique violation, advancing the period in
the catch block. The isolated test showed the period **did not move**.

In PostgreSQL a failed statement aborts the whole transaction; every subsequent statement is refused
until rollback. The catch block ran, its write was discarded, and the subscription would have been
retried on every run forever — a silent infinite retry that returned `false` and looked handled.

Rewritten to check before inserting. The unique index remains as the backstop for a genuine race,
where the exception now propagates and the run records it as skipped rather than pretending to
recover.

### Four guards that proved nothing until the tests were isolated

The first plant pass had **three of seven guards pass**, and a fourth test could not see what it
claimed to:

| Guard | Why the test could not fail |
|---|---|
| Duplicate detection | `next_invoice_on` had already advanced, so the row was never selected |
| Paused subscriptions | `billOnce()` also refuses them, landing in `skipped` — the order count was 0 either way |
| End of term | `moveToNextPeriod()` also sets `ended`, so the second run skipped it anyway |
| Price snapshot | asserted the subscription's stored price, never the price the **invoice charged** |

Each was rewritten to isolate the mechanism it names. The last is worth remembering: **storing a
snapshot and using it are different claims**, and only the second one bills correctly.

### Gate evidence

| Check | Result |
|---|---|
| `pint --test` · `phpstan` · `tsc` | pass |
| `pest` | **1,692 passed / 3,502 assertions** |
| CI-equivalent run | fresh database, no compiled frontend, `.env` from `.env.example` — all pass |

Ten guards proven by planting. The Isolation suite grew by 22 unprompted and refused to pass until
both new models had seed recipes.

### A near-miss worth recording

The commit for this module went out **before its documentation did**. A scripted edit to `README.md`
asserted on a line that had changed wording earlier in the session, threw, and stopped before
writing either file — but the `git commit` in the same command had already run.

The code and tests were correct and complete; `Planning.md` simply had no appendix for a module that
was in the repository. Caught by checking rather than assuming, and corrected in the next commit.

The lesson is narrow and practical: **chaining a commit behind a scripted edit means a failed edit
still commits.** The assertion did its job and the shell ran on regardless.

### Carried forward

| ID | Item |
|---|---|
| AF-1 | **Nothing collects the money.** A subscription raises an invoice; paying it is still manual. No card on file, no direct debit, no gateway |
| AF-2 | No proration. Starting mid-period bills the full amount, and cancelling mid-period refunds nothing |
| AF-3 | No trials, no discounts, no per-subscription price override — the plan price at signup is the only price |
| AF-4 | A subscription bills a **single** product. Several lines in one recurring charge is not supported |
| AF-5 | Nothing warns anybody when a subscription invoice goes unpaid. Dunning does not exist |
| AF-6 | Quantity can be changed only in the database — no screen for it, though the field is honoured |
| AF-7 | Still true: **no screen has been used by a human** |


---

## Appendix AG — Billplz: money that arrives without anybody typing it (2026-08-16)

AF-1 read *"nothing collects the money — no card on file, no direct debit, no gateway."* I judged it
accurate but not completable, on the grounds that a gateway needs a merchant account and credentials
I do not have. Fakrul has a Billplz account, which removed the blocker, and the work went ahead.

### What was built

| Piece | What it does |
|---|---|
| `config/billplz.php` | API key, X-Signature key, collection ID, sandbox flag — all from `.env`, none committed |
| `payment_intents` table | One row per bill raised: invoice, amount, provider reference, status, the last callback received |
| `BillplzClient` | Creates a bill. Refuses to run at all unless all three credentials are present |
| `BillplzSignature` | HMAC-SHA256 over the sorted callback parameters. **This is the whole authentication boundary for the webhook** |
| `PaymentLinkService` | Raises a bill for what is outstanding; applies a verified callback exactly once |
| `POST /payments/billplz/callback` | Server-to-server. No session, no company — signature only |
| `GET /payments/billplz/return` | Where the payer's browser lands. **Settles nothing** |
| Invoice screen | "Request online payment", the resulting link, copy button |

Money does not take a new path. A verified callback calls the existing
`InvoiceService::recordPayment`, so the ledger entry, the cash-flow row and the status transition are
the same ones a hand-recorded payment produces. A second way to pay an invoice would have been a
second version of the truth.

### The decision that shaped the rest

A payment gateway forces a route that no session can protect. The security suite has asserted since
P0 that **every** state-changing route carries `auth` and `company`; the callback can carry neither.

The temptation was to add the URI to the exemption list and move on. Instead the list became a named
constant that **two** tests walk: one skips those routes when checking for session middleware, and
the other posts a forged signature and an unsigned request to each and requires 403. An exemption is
now only as cheap as the signature check behind it. Adding a route to that array without a working
signature fails the suite.

The second decision: **the browser redirect settles nothing.** It is under the payer's control, and
treating it as proof of payment would let anyone who can read a URL mark an invoice paid. It reports;
the server-to-server callback pays.

### What planting found — twice in one wave

Every guard was verified by deleting it. Two of my own tests were green for the wrong reason.

**The replay test proved nothing.** Deleting the idempotency guard entirely left all fifteen tests
passing. A *different* guard — the clamp of the credited amount to what is still outstanding — was
refusing the replay. Because the test billed the invoice in full, the clamp caught the second
callback at zero and the replay guard was never reached. The same mistake made the amount-tampering
test hollow: it passed with the code trusting the callback's own figure, because the clamp capped the
inflated claim anyway.

Both were rewritten to bill **part** of a larger invoice, so a replay or an inflated claim stays
below what is outstanding and the clamp cannot cover for the guard under test. Re-planting then
failed exactly the test that names each guard, and nothing else.

That is the seventh and eighth time in this project a test has been found passing because a guard
other than the one it names did the refusing. The pattern is always the same shape: **a fixture
chosen for convenience makes two guards indistinguishable.**

### A real defect, found by writing the edge case down

A callback claiming a non-positive `paid_amount` credited nothing to the invoice — correct — but
still marked the payment intent `paid`. The genuine callback arriving behind it would then match the
replay guard and be discarded in silence. **A customer's payment would have vanished.**

Found because the negative-amount test asserted only that the invoice was not credited; asking the
further question — *what state is the intent in?* — exposed it. The intent now stays open unless
money actually moved.

### Deliberately not done

The signature source string is `key1value1|key2value2` sorted by key, with nothing escaping the
separator, so `{ab: 'c'}` and `{a: 'bc'}` sign identically. A test proves that ambiguity cannot be
aimed at a bill the sender does not already hold a genuine callback for, but I have not proven it is
harmless in general. It is written up in `SECURITY-REVIEW.md` §3.8 as something to attack rather than
patched, because a fix I am not confident in would look more reassuring than the flag does.

### The honest limit

**Every payment test fakes the HTTP layer.** The signature algorithm is implemented from the
published specification and has never been confirmed against a real sandbox callback. Nothing here
proves Billplz agrees with my arithmetic — only that this system is internally consistent about it.
That confirmation needs one sandbox payment against a publicly reachable callback URL, and it is the
first thing to do before this touches a real customer.

### Carried forward

| ID | Item |
|---|---|
| AG-1 | **Not one real payment has been made.** The signature is implemented from the spec and faked in every test. One sandbox payment through a tunnelled callback URL would settle it |
| AG-2 | The `\|` separator ambiguity in the signature source string is flagged, tested at one angle, and unproven in general |
| AG-3 | No refund through Billplz. A gateway refund happens in their dashboard and is only recorded here |
| AG-4 | Subscriptions still do not attach a payment link automatically — a recurring invoice must have one raised by hand, so AF-1 is narrowed rather than closed |
| AG-5 | No dunning. AF-5 stands: nothing chases an unpaid invoice, and nothing surfaces which unpaid invoices are recurring revenue |
| AG-7 | No receipt is emailed. The redirect promises one; no mail transport exists |
| AG-8 | Still true: **no screen has been used by a human** |
