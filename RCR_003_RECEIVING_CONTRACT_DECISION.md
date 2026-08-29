# RCR-003 — Atomic Tracked-Receiving Contract Decision

**Status:** `PROVISIONAL DESIGN — LOCAL PROOF PARTIAL — NOT RELEASE APPROVED`  
**Prepared:** 2026-08-28  
**Scope:** First Recommerce vertical slice only: approved receiving path → Device identity → label evidence → scan/view → reconciliation.

## 1. Decision summary

Use a **Recommerce-owned tracked-receiving service and screen** for the Alpha path. The service must call the existing Ultimate POS purchase/quantity utilities inside one database transaction, then create the Recommerce Device and receipt-assignment evidence before commit.

Do not add a broad generic purchase hook at this stage. The available source and Terra review do not show a purchase-form hook that safely supplies one physical identity per received unit. A post-commit event carrying only a purchase transaction cannot reconstruct exact Devices without an explicit unit-input contract.

This is a provisional design decision. It is not implementation approval, and it is not proven against the unavailable runtime or production schema.

## 2. Why this path is selected

The source audit establishes that:

- core purchase and stock paths already mutate Ultimate POS aggregate quantity;
- `ProductUtil` contains the existing quantity and purchase-line handling that Recommerce should not duplicate;
- stock changes can also come through sales, returns, adjustments, transfers, opening stock, and imports;
- Repair source and deployment data are unavailable and must remain outside this Alpha slice;
- the current checkout has no verified Recommerce module source or runtime boot.

The narrow service path limits the initial write surface to one reviewed receiving workflow. It permits explicit unit ordering, one idempotency key, one transaction boundary, and a deterministic link between each received unit and its core purchase line.

## 3. Proposed transaction sequence

The exact method names and lock behavior must be confirmed after source/runtime recovery.

1. Authenticate the user and enforce business, location, variation-cohort, and receiver permission.
2. Validate the request idempotency key, purchase header, approved location, variation, quantity, and one physical identifier per tracked unit.
3. Resolve the core product/variation and lock the relevant core rows in the documented lock order.
4. Start one database transaction.
5. Create or update the core purchase through the existing reviewed Ultimate POS purchase service/utilities.
6. Confirm the expected core purchase line, quantity, status, and location before creating Device rows.
7. Create one immutable Device row per physical unit, each with its own stable code and source purchase-line assignment.
8. Record the Device receipt movement and immutable operation receipt in the same transaction.
9. Record label issuance as pending/required evidence without making a failed print create another Device.
10. Commit only when core quantity, Device rows, assignments, and evidence records all pass validation.
11. Return a stored operation outcome for safe browser/scanner retry.

Any failure before commit must roll back both core purchase effects and Recommerce rows. A failure after commit must return or recover the original operation outcome; it must not repeat the purchase or create a second Device.

## 4. Invariants

| Invariant | Required control |
|---|---|
| One physical unit has one Device | Database uniqueness and same-transaction assignment |
| One Device is linked to one approved core receipt line | Foreign key/assignment constraint and source-line validation |
| Device stock participation is limited to approved business/location/variation cohort | Server-side policy before commit |
| Aggregate core quantity remains authoritative | Reuse core posting utilities; no parallel quantity ledger |
| Retry does not duplicate | Unique operation/idempotency receipt and stored outcome |
| Device failure cannot leave a core receipt behind | Single database transaction or an explicitly proven compensating boundary |
| Core failure cannot leave a Device behind | Same transaction; forced-failure rollback test |
| Printing does not create identity | Device exists before label attempt; print status is separate evidence |
| Scan-only view does not mutate stock | Read-only resolver/detail path and browser test |
| Repair is untouched | No `repair` subtype, Repair route, Repair field, or Repair table write |

## 5. Alternatives rejected for Alpha

### Post-commit purchase event only

Rejected as the sole contract. The event can identify a purchase transaction but does not necessarily carry one validated physical unit per quantity. It risks ambiguous assignment, delayed failure, and non-reconcilable stock.

### Generic core purchase hook before source recovery

Deferred. The checkout does not prove a stable purchase-form module hook, and a generic hook could change ordinary purchase behavior or expose an uncontrolled Recommerce write path.

### Separate Recommerce stock authority

Rejected. Ultimate POS remains authoritative for aggregate catalogue quantity, transactions, payments, and accounting. Recommerce records physical-unit identity and evidence, then reconciles to core quantity.

### Asynchronous queue for the first receipt

Deferred. The installed configuration defaults to synchronous queues, and an asynchronous split would make the core purchase/Device atomicity harder to prove. Use a transaction-local synchronous service for Alpha.

## 6. Required proof before implementation is accepted

| Test | Expected result | Current result |
|---|---|---|
| Core purchase success + Device success | One core receipt, exact Device count, and immutable receive evidence | Local disposable SQLite proof passes with a synthetic core callback and with the real Recommerce service/adapter classes over authenticated HTTP using mocked legacy utility seams; actual Ultimate POS production adapter/runtime remains unrun |
| Device validation failure | Core receipt and Device rows both roll back | Local quantity-mismatch rollback proof passes; production schema/writer path remains unrun |
| Core posting failure | Device rows, operation receipt, and immutable event evidence all roll back | Local callback/receipt mismatch rollback proof passes; actual core posting failure remains unrun |
| Duplicate idempotency key | Original outcome returned; no second receipt/Device | Local replay proof passes |
| Conflicting reused key | Request rejected without mutation | Local proof passes |
| Concurrent receipt of same identifier | One succeeds; duplicate is rejected safely | Per-business lock and unique hash guard implemented; repeatable file-backed SQLite process race produced one commit and one rejection; production database lock/error semantics remain unrun |
| Label failure after commit | One Device remains; print attempt is retryable; no duplicate Device or receive event | Local proof now covers both pre-issuance rejection and renderer failure: token/event issuance rolls back, then an immediate retry succeeds without rotation; physical printer path remains unrun |
| Ordinary purchase with Recommerce off | Existing behavior unchanged | Not run |
| Sale/return/transfer/adjustment alternative paths | Pilot variation is blocked or explicitly reconciled before commit | Not run |
| Cross-business/location request | Denied without data disclosure or mutation | Local boundary/service proof passes; authenticated production route proof remains unrun |

## 7. Dependencies and blockers

This provisional contract depends on:

- recovered licensed module/source inventory and Repair disposition;
- runnable PHP/Composer/Laravel baseline;
- approved sanitized production schema and Alpha stock/hardware profile;
- confirmed core purchase service and transaction lock behavior;
- approved Device/receipt/idempotency schema conventions;
- tested feature guard and rollback procedure.

Current status remains:

| Gate | Status |
|---|---|
| RCR-001 baseline and Repair disposition | `BLOCKED` |
| RCR-002 Alpha/data/hardware profile | `BLOCKED` |
| RCR-003 design choice | `PROVISIONAL` |
| RCR-003 source/runtime proof | `PARTIAL — local disposable service/controller/adapter proof passed; production adapter/runtime not proven` |
| Recommerce implementation | `LOCAL SCAFFOLD + DISPOSABLE RUNTIME PROOF — production enablement not approved` |

No application code, routes, migrations, database, module status, dependencies, or assets were modified for this decision record.
