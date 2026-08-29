# SaverBro Recommerce OS Architecture

Status: implementation contract; native POS integration and local QA exist, but pilot/release approval is still required
Companion documents: `CODEBASE_AUDIT.md`, `RECOMMERCE_DATA_MODEL.md`, `RECOMMERCE_WORKFLOWS.md`, `RECOMMERCE_QR_SCAN_ARCHITECTURE.md`, `REPAIR_SERVICE_ARCHITECTURE.md`

Current ownership decision: SaverBro will not use the unavailable provider `Modules/Repair` module. SaverBro's owned Repair domain will be implemented inside `Modules/Recommerce`. Provider Repair recovery is not a dependency for new capability; it remains relevant only to legacy containment, data preservation, and cutover safety.

## 1. Decision summary

| Decision | Selected design |
|---|---|
| A. Canonical physical device | One `recommerce_devices` row for every identifiable physical device, regardless of current owner; internal bigint key, permanent SaverBro Device ID, and opaque public token |
| B. Ownership | Ownership is explicit and historized. `BUSINESS` devices participate in POS stock; `CUSTOMER` devices do not. Custody/location is separate from ownership. |
| C. Repair | One repair engine and one job table with `INTERNAL_REFURBISHMENT` or `CUSTOMER_REPAIR`; shared diagnostics/actions/parts/QC, with type-specific billing and cost posting |
| D. Device QR | Permanent HTTPS resolver URL containing a random, revocable public-safe token; never mutable device data or a database ID |
| E. Repair QR | Permanent HTTPS repair resolver URL containing a different random, revocable job token |
| F. Scan router | Authenticated, tenant-aware exact resolver for device code/token and repair code/token; returns an authorized destination and allowed actions |
| G. Parts consumption | Reuse Ultimate POS products/variations. Customer jobs consume stock through the linked final sale; internal jobs use a linked stock-adjustment posting. Reservations live in Recommerce. |
| H. Cost flow | One immutable recommerce cost-entry ledger: internal costs post to a device; customer-direct costs post to a repair job. Revenue/payment remains in Ultimate POS. |
| I. Stock integrity | Device subledger and Ultimate POS aggregate stock change in the same DB transaction, under locks and idempotency keys, with continuous reconciliation |

## 1A. Integrated POS experience contract

SaverBro operates as one Ultimate POS system. Recommerce is a domain capability
inside that system, not a second application, login, customer list, product
catalogue, payment flow, or accounting ledger.

The operator-facing journey is deliberately continuous:

```text
Purchases → received POS purchase line → Serialise devices → Device Registry
         ↘ ordinary quantity-only stock

Customer Repairs → customer/contact → customer-owned Device → repair record
Internal refurbishment → business-owned Device → repair record → POS stock adjustment
```

### Non-negotiable integration rules

- The native POS shell, business, locations, users, roles, contacts, products,
  variations, purchase records, sales, payments, tax, and accounting are shared.
- `Customer Repairs` is a first-class main workspace in the POS navigation;
  `Stock & Devices` is the inventory/device workspace. They are distinct
  operator contexts, not separate products.
- A normal stock purchase is created in **Purchases**. For a received,
  approved whole-unit line, **Serialise devices** adds Device identity,
  ownership, custody, movement, and audit evidence to that exact purchase line.
  It does not create another purchase or mutate aggregate stock, payments, or
  accounting.
- The standalone controlled serialised-receiving path remains available only
  when the operator is intentionally creating the POS purchase and device
  evidence together in one transaction.
- Customer-owned repair Devices always have `stock_participation=NONE`.
  Internal refurbishment consumes stock only through the existing POS
  adjustment boundary. No Recommerce screen may edit `qty_available` directly.

### Purchase serialisation guard

The post-purchase attachment flow accepts only a received POS purchase line in
the approved business/location/variation cohort, with a positive whole-unit
quantity, a Device count no greater than the remaining unassigned units, a
named permission, and a client idempotency UUID. Large lines can be completed
in bounded batches. It locks the business, core line, and assignment scope
before writing the Recommerce evidence. This lets the device subledger close an
existing stock-identity gap without double-posting the POS ledger.

## 2. Architectural boundaries

### Ultimate POS remains system of record for

- businesses/tenants, users, roles, and location access;
- contacts (suppliers/customers);
- products, variations, and service items;
- purchase/sale/transfer/adjustment transactions and lines;
- aggregate variation/location stock;
- FIFO/LIFO purchase-to-sale cost mapping;
- invoices, tax, payments, cash registers, and accounting events;
- generic printer/label settings where compatible.

### Recommerce becomes system of record for

- physical-device identity and identifiers;
- ownership and custody history;
- per-device lifecycle and unit assignments to POS lines;
- serialized receiving and movements;
- diagnostics, repair jobs, actions, quotations, approvals, QC, and collection;
- parts reservation and repair-specific posting links;
- grades and grade criteria/version;
- permanent domain timeline;
- per-device and per-repair direct-cost attribution;
- QR tokens, scan resolution, and device/repair labels;
- reconciliation between unit records and POS quantities.

There will be no second product catalog, customer master, payment ledger, tax engine, or aggregate stock engine.

## 3. Deployment shape

Use a modular monolith in the existing Laravel application:

```text
Ultimate POS Laravel application
├── Core POS (preserved)
│   ├── products / variations
│   ├── purchases / sales / transfers / adjustments
│   ├── variation_location_details
│   ├── contacts / users / permissions
│   └── invoices / payments / cash registers
└── Modules/Recommerce
    ├── Device Registry
    ├── Scan + Label
    ├── Receiving + Serialized Stock Coordinator
    ├── Diagnostics + Grading
    ├── Shared Repair Engine
    ├── Cost Attribution
    ├── Timeline + Audit
    └── Reconciliation + Outbox
```

`Modules/Recommerce` is an implementation boundary, not a user-facing system
boundary. Its routes render inside the native POS layout and its navigation is
added to the native admin sidebar and POS header.

No microservices are justified for Alpha. Atomic stock/device updates require the same database transaction, and the installed queue defaults to synchronous execution.

## 4. Canonical device design

One row represents one enduring physical device. It is not a SKU, lot, purchase line, sale line, or repair ticket.

Core identity:

- internal `id`: database relationship only;
- `device_uuid`: immutable globally unique internal correlation value;
- `device_code`: permanent human-readable SaverBro ID such as `SB-LAP-000182`;
- active token relationship: opaque QR tokens live in a separate scan-token table so they can be rotated/revoked without changing device identity;
- manufacturer/service identifiers: normalized in a child identifiers table with tenant/type uniqueness.

Catalog relationship:

- SaverBro-owned stock device: `product_id` and `variation_id` required once received into stock.
- Customer-owned repair device: product/variation nullable; brand/model/specifications are captured in device attributes.
- When a customer device is traded in, keep the same device row, close the customer ownership period, open a SaverBro ownership period, attach the chosen variation, record acquisition, and start stock participation.

Why not reuse existing structures:

- `Variation` is a catalog option shared by quantities.
- `PurchaseLine` represents a quantity/cost layer and lot.
- `sell_line_note` is free text and cannot enforce identity.
- One row per unit in any of those tables would distort prices, reports, and stock algorithms.

## 5. Ownership, custody, lifecycle, and stock participation

These concepts must remain separate:

- **Ownership**: `BUSINESS` or `CUSTOMER`, with dated ownership periods.
- **Custody**: supplier, a SaverBro location, in transit, customer, or lost/unknown.
- **Lifecycle**: registered, receiving, diagnostics, repair, QC, ready for sale, reserved, in transfer, sold, ready for collection, returned, retired.
- **Stock participation**: whether this unit contributes one unit to Ultimate POS aggregate stock.

Rules:

1. Customer ownership always means `stock_participation=NONE`.
2. A business-owned on-hand unit has exactly one variation and one current business location.
3. `SOLD` means no on-hand stock participation and custody normally with customer.
4. `IN_TRANSFER` has no sale eligibility; origin/destination are held in the movement/transfer link.
5. Repair state is authoritative on the active repair job; the device lifecycle stores only the operational summary needed for routing.

## 6. Serialization profiles and legacy stock

Add a per-variation serialization profile:

- `NOT_SERIALIZED`: existing quantity-only behavior.
- `TRACKED_REQUIRED`: every on-hand unit must have one active device row.
- `LEGACY_MIXED`: temporary migration state with explicit `legacy_unserialized_qty` per location.

For `TRACKED_REQUIRED`:

```text
POS qty_available
= count(business-owned devices at location in on-hand stock states)
+ active legacy exception quantity (must be zero after cutover)
```

Never silently mix tracked and untracked units. A variation cannot move to `TRACKED_REQUIRED` until reconciliation passes.

## 7. Serialized stock coordinator

All tracked operations use one application service in `Modules/Recommerce`, not direct controller duplication:

1. Validate business, role, permitted location, device lifecycle, ownership, and command idempotency key.
2. Begin DB transaction.
3. Lock command/idempotency row.
4. Lock device rows in stable ID order.
5. Lock affected `variation_location_details` and linked POS transaction/line rows.
6. Validate aggregate quantity and exact device count before mutation.
7. Invoke the existing core stock/transaction services or create the standard core transaction/lines.
8. Write device assignments, movement, cost, and domain events.
9. Recalculate and assert post-conditions.
10. Commit; dispatch optional work through an outbox after commit.

Commands include serialized receive, sale assignment, transfer dispatch/receipt, adjustment/write-off, trade-in conversion, part consumption, and return.

## 8. Core integration seams

Prefer existing hooks/events:

- module `after_sale_saved` executes before the POS transaction commits and receives input;
- `PurchaseCreatedOrModified` is dispatched inside purchase transaction;
- `StockAdjustmentCreatedOrModified` is dispatched inside adjustment transaction;
- `StockTransferCreatedOrModified` is dispatched for create/edit;
- module permissions, views, assets, and product-form fields already have callbacks.

Required minimal core changes, subject to the runtime and legacy-containment audit:

1. Add/dispatch a stock-transfer event or module guard in `StockTransferController::updateStatus()` before commit.
2. Carry an opaque line correlation key/device assignment from POS request to the module hook; do not store device identity in `sell_line_note`.
3. Add a preflight module guard where a current path could mutate a `TRACKED_REQUIRED` variation without a device assignment.
4. Ensure module failures propagate and roll back the enclosing core transaction.

Implemented purchase handoff:

1. A received Purchase list row exposes **Serialise devices** only when the
   explicit module/permission gate allows the operator to enter the flow.
2. The receiving workspace loads only eligible whole-unit lines with remaining
   unassigned units from that POS purchase, scoped to the configured pilot
   cohort and bounded by the configured receive-batch limit.
3. The attachment command locks and validates that existing line, creates the
   Device evidence, and returns `core_stock_changed=false`. It never invokes a
   purchase writer or creates a payment/accounting row.

Do not fork or copy `ProductUtil`/`TransactionUtil` into the module.

## 9. Repair architecture

`repair_jobs` always references one canonical device and has a discriminator:

- `INTERNAL_REFURBISHMENT`: device must be SaverBro-owned; no customer invoice required; direct costs post to device.
- `CUSTOMER_REPAIR`: device must be customer-owned at intake; customer/contact required; quote and linked Ultimate POS sale drive billing; direct costs post to job.

Shared components:

- diagnostics sessions and observations;
- faults and repair actions;
- technicians and assignment history;
- parts requests/reservations/usages;
- labour and outsourced cost;
- notes/media references;
- state transition log;
- QC checks;
- repair warranty entitlement;
- timeline and QR/job sheet.

Type-specific policies are strategies, not separate tables or duplicated controllers.

## 10. Parts and inventory posting

Use existing Ultimate POS stock products for SSDs, RAM, batteries, keyboards, etc.

### Reservation

Recommerce records reservations by job, variation, location, and quantity. Available-to-promise for repair work is:

```text
Ultimate POS qty_available - active Recommerce reservations
```

Reservation does not alter the accounting quantity. It prevents double allocation.

### Customer repair

- The linked repair sale contains stocked part product lines and non-stock service lines.
- Physical installation changes reservation to `INSTALLED_PENDING_BILLING`.
- Finalizing the Ultimate POS sale consumes the part through the normal POS decrement and FIFO/LIFO mapping.
- The module hook binds the sale line to the repair usage and releases the reservation atomically.
- Cancellation before finalization releases reserved parts; an installed part must be removed/returned or explicitly written off.
- Cancellation after final sale uses standard sell-return/refund flows, linked back to the job.

### Internal refurbishment

- Posting consumption creates a standard Ultimate POS stock-adjustment transaction/line with a repair reason and unique source key.
- Existing mapping derives the actual purchase-layer cost.
- Recommerce links the adjustment line to the device/job and writes that actual part cost to the device cost ledger.
- Reversal is an explicit compensating operation, never deletion or silent quantity editing.

## 11. Cost architecture

`recommerce_cost_entries` is append-only and source-linked. A row targets exactly one of `device_id` or `repair_job_id`.

Categories:

- acquisition;
- inbound logistics/landed allocation;
- part actual cost;
- direct labour;
- outsourced repair;
- inspection/other direct cost;
- manual adjustment/reversal.

Internal true device cost is the sum of posted, non-reversed device entries. Customer repair gross profit is final POS repair revenue less posted job costs. Estimated quote cost and actual cost are never conflated.

Labour costing needs a human policy: actual payroll cost, standard technician rate, or contribution-margin-only reporting. Store method and rate version with each entry.

## 12. Domain events, audit, and outbox

Write a permanent append-only `recommerce_events` row for every meaningful change. Include business, device/job, event type, actor, location, occurred time, correlation/causation, idempotency key, and compact JSON payload.

Examples: `DEVICE_REGISTERED`, `OWNERSHIP_CHANGED`, `DEVICE_RECEIVED`, `LABEL_ISSUED`, `DIAGNOSTIC_COMPLETED`, `PART_INSTALLED`, `REPAIR_STATE_CHANGED`, `GRADE_ASSIGNED`, `TRANSFER_DISPATCHED`, `DEVICE_SOLD`.

Also call the existing Spatie activity logger for operator-facing audit, but never derive the permanent timeline solely from it. Optional notifications, large batch PDFs, and later integrations use an outbox row created in the same transaction.

## 13. Concurrency and idempotency

Required controls:

- database unique indexes for device code, token hash, job code, job token, identifiers, and device-to-sale-line assignment;
- `SELECT ... FOR UPDATE` on devices, stock location rows, repair jobs, quotes, and part reservations;
- integer `lock_version` on device and repair job for stale form detection;
- client-generated UUID command key on every mutating scan/action;
- unique `(business_id, command_key)` command receipt;
- unique source keys for stock/cost/event postings;
- stable row-lock ordering for batch operations;
- rollback the core and recommerce writes together on any invariant failure;
- after-commit outbox dispatch only.

The existing `ReferenceCount` read/increment is not concurrency-safe and has no unique `(business_id, ref_type)` index (`app/Utils/Util.php:309-331`; `database/migrations/2018_05_22_123527_create_reference_counts_table.php:16-22`). Device and repair IDs need a locked, unique sequence allocator in the Recommerce module.

## 14. Permissions and tenancy

Every query and mutation is scoped by `business_id`. Every location-bound action also checks `User::can_access_this_location()` semantics. Never trust a scanned token, route ID, hidden field, posted location, product, or customer without re-scoping.

Sensitive cost and customer-device details use separate abilities. Scan resolution is two-stage: exact identification, then authorization. Unauthorized users receive a generic denial without confirming whether a token exists.

See `RECOMMERCE_SECURITY_AND_PERMISSIONS.md` for the permission matrix.

## 15. Failure handling and reconciliation

- A QR/label print failure does not roll back device creation; label issuance has retryable status.
- A duplicate scan returns the prior command result, not a second mutation.
- A failed stock posting rolls back job/device/cost changes.
- A failed notification or PDF generation remains in outbox and does not corrupt stock.
- A scheduled reconciliation compares device counts, assignments, active reservations, core quantities, and linked transaction statuses.
- Mismatches create blocking exceptions and manager-visible cases; no automatic balancing entry is made without evidence.

## 16. Non-goals for Alpha

- no microservices;
- no replacement auth/accounting/inventory;
- no customer mobile app;
- no WhatsApp integration;
- no offline stock mutation;
- no AI diagnostics;
- no generalized “scan any enterprise object” registry beyond devices and repair jobs;
- no redesign of unrelated Ultimate POS screens.

## 17. Architecture acceptance gates

Implementation cannot start until:

1. The unavailable provider Repair integration is contained, and any legacy data preservation/cutover policy is approved separately.
2. Existing production schema/data and duplicate risks are profiled.
3. The serialized-stock invariant and legacy cutover policy are approved.
4. Human decisions in `RECOMMERCE_ROADMAP.md` are resolved or explicitly deferred.
5. The first vertical slice is agreed: receive tracked purchase units → create IDs → print labels → scan and view, with reconciliation tests.
