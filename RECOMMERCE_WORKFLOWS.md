# SaverBro Recommerce OS — Workflows

## 1. Workflow rules

These workflows extend Ultimate POS 7.3. They do not replace its purchase, sale, payment, transfer, adjustment, accounting, or contact records.

Every mutating command must:

1. resolve `business_id`, acting user, location, and idempotency key;
2. authorize the exact action and location;
3. lock the affected device and stock rows in a deterministic order;
4. validate current state and expected version;
5. write POS aggregate stock and the device subledger in the same database transaction;
6. append a permanent domain event and outbox item;
7. return a stable result when the same idempotency key is retried.

No user may directly edit a device's ownership, custody, lifecycle, or stock state. Those values change only through commands below.

## 2. Device states and transitions

Suggested lifecycle states:

| State | Meaning | Common next states |
|---|---|---|
| `DRAFT` | Identity reserved but intake incomplete | `RECEIVED`, `VOIDED` |
| `RECEIVED` | Accepted into business custody | `DIAGNOSIS`, `IN_STOCK`, `RETURNED` |
| `DIAGNOSIS` | Under inspection | `IN_REPAIR`, `AWAITING_DECISION`, `IN_STOCK`, `RETURNED` |
| `AWAITING_DECISION` | Price, quote, or disposition decision pending | `IN_REPAIR`, `IN_STOCK`, `RETURNED`, `DISPOSED` |
| `IN_REPAIR` | Work authorized and active | `QC`, `UNREPAIRABLE` |
| `QC` | Final checks in progress | `IN_STOCK`, `READY_FOR_COLLECTION`, `IN_REPAIR` |
| `IN_STOCK` | Sellable business-owned unit at one location | `RESERVED`, `IN_TRANSFER`, `SOLD`, `IN_REPAIR` |
| `RESERVED` | Allocated to an order/customer | `IN_STOCK`, `SOLD` |
| `IN_TRANSFER` | Moving between locations | `IN_STOCK`, `TRANSFER_EXCEPTION` |
| `READY_FOR_COLLECTION` | Customer-owned device awaiting collection | `RETURNED`, `IN_REPAIR` |
| `SOLD` | Ownership transferred to buyer | `IN_REPAIR` for a later service visit |
| `RETURNED` | Customer-owned device left business custody | `RECEIVED` on a new intake |
| `UNREPAIRABLE` | Repair stopped after diagnosis/work | `RETURNED`, `DISPOSED`, `IN_STOCK` with explicit disposition |
| `DISPOSED` | Retired through an approved disposal path | none except controlled correction |
| `VOIDED` | Draft identity cancelled before stock participation | none |

Forbidden examples: `SOLD → IN_STOCK` without a return/trade-in transaction; `IN_TRANSFER → SOLD`; customer-owned `READY_FOR_COLLECTION → IN_STOCK` without acquisition; or any transition that creates two active custody locations.

## 3. New-stock or trade-stock receiving

### 3.1 Purchase-linked receiving

1. Receiver opens a posted or draft purchase and selects a tracked product variation.
2. System shows ordered, already serialized, received, and unresolved legacy quantities.
3. Receiver scans manufacturer serial/IMEI/service tag or records an approved “identifier unavailable” reason.
4. System checks normalized identifier uniqueness within the business without exposing another device to unauthorized users.
5. System allocates permanent `device_code`, creates the canonical device, business ownership period, location custody, purchase-line assignment, and initial cost entry.
6. Receiver prints or defers the SaverBro label.
7. Posting the receipt atomically increases POS aggregate quantity and creates one device movement per unit.
8. Reconciliation must equal: tracked on-hand devices plus declared legacy-unserialized balance equals POS aggregate on-hand.

Partial receipts and one-unit corrections are supported. A duplicate manufacturer identifier stops that unit, not the entire prepared batch; the receiver may resolve it before posting.

### 3.2 Trade-in intake

1. Staff finds or creates the customer contact.
2. Scan searches the canonical registry first. If the device already exists, reuse it; never create a replacement identity.
3. Record identifiers, declared ownership, condition evidence, accessories, and source customer.
4. Run diagnosis/grading and record offer version.
5. Customer acceptance captures approver, timestamp, terms version, and evidence.
6. Create the approved acquisition/payment record through the configured POS/accounting path; do not post acquisition value only to the Recommerce ledger.
7. Close the customer ownership period, open business ownership and custody, and move the device to `RECEIVED`/`DIAGNOSIS` atomically.
8. After refurbishment and QC, assign the sellable product variation and enter `IN_STOCK`.

Rejected trade-ins remain customer-owned and are returned. Personal evidence follows retention policy.

## 4. Scan and lookup

1. Input arrives from a keyboard-wedge scanner, camera decoder, pasted code, or direct HTTPS QR link.
2. Client normalizes whitespace and extracts a candidate token/code, then submits an authenticated exact-match request.
3. Server resolves in this order: SaverBro opaque token, human device code, repair-job token/code, then authorized manufacturer identifier.
4. Permission and business/location scope are evaluated before any descriptive result is returned.
5. Result includes the object, state/version, and only currently valid actions.
6. Repeated reads are harmless. Repeated writes require an idempotency key and expected version.

Ambiguous partial matches never auto-open a record. Unknown codes show a neutral result and may offer “start intake” only to authorized users. Revoked tokens do not reveal whether a device exists.

## 5. Internal refurbishment

1. Intake creates or reuses the device and opens an `INTERNAL_REFURBISHMENT` job.
2. Technician records structured diagnostics and evidence; system suggests no automatic grade in Alpha.
3. Authorized grader records grade, faults, and repair recommendation.
4. Manager approves work above configured cost thresholds.
5. Parts are reserved by location. Installation records serial/batch where applicable.
6. Posting an internal part usage creates a standard POS stock adjustment and a linked actual device cost entry in one coordinated transaction.
7. Labor and approved external-service costs post to the device cost ledger.
8. Technician completes actions and submits to QC; the technician cannot approve their own QC when segregation is configured.
9. QC pass closes the job, records final grade, establishes warranty policy, and changes the business-owned device to `IN_STOCK`.
10. QC failure returns the job to `IN_REPAIR` with reasons and a new event.

## 6. Customer repair service

### 6.1 Intake

1. Staff finds/creates the customer and scans the device.
2. Existing canonical devices are reused even if previously sold or repaired by SaverBro.
3. If new, create a customer-owned device without requiring a POS product/variation.
4. Capture identifiers, reported problem, visual condition, accessories, passcode handling choice, consent/terms version, preferred contact, and intake images.
5. Open custody at the receiving location and a `CUSTOMER_REPAIR` job in `RECEIVED`.
6. Print job/device label and customer receipt. The QR contains no customer or device details.

### 6.2 Diagnosis and quote

1. Assigned technician advances to `DIAGNOSIS` and records template observations, faults, evidence, and estimated parts/labor.
2. Quote service creates an immutable numbered version. Estimates do not enter actual cost ledger.
3. Job enters `AWAITING_APPROVAL`; no billable work proceeds beyond an explicitly authorized diagnostic allowance.
4. Approval captures quote version, decision, channel, actor/customer evidence, terms version, and timestamp.
5. Approved quote moves to `WAITING_PARTS` or `IN_REPAIR`; rejected quote records resolution `DECLINED` and moves to `READY` for collection/return; changed scope creates a new version and fresh approval.

### 6.3 Repair, QC, billing, collection

1. Parts are reserved, issued, installed, removed, or returned through explicit actions.
2. Customer repair parts remain `INSTALLED_PENDING_BILLING` until POS finalization; service items are non-stock POS lines.
3. Technician completes actions and submits for QC.
4. QC records template results and pass/fail. Failure reopens repair; pass moves to `READY`.
5. Billing projection creates/updates a linked Ultimate POS sell transaction with ordinary product/service lines. The POS transaction remains financial truth.
6. Final sale atomically consumes parts using normal POS stock/FIFO mapping and marks usages billed. Retry cannot double-consume stock.
7. Payment uses existing POS payment/accounting capabilities. Repair job stores only linkage and financial summary snapshots.
8. Authorized staff verifies collector, records handover, closes custody, and moves device to `RETURNED`; job becomes `CLOSED`.

Collection may occur with an approved outstanding balance only through a separately permissioned override and recorded reason.

## 7. Parts lifecycle

| Action | Stock effect | Rules |
|---|---|---|
| Reserve | none | quantity cannot exceed free available stock; expires/released explicitly |
| Issue to bench | optional custody movement only | not yet consumed |
| Install on customer job | none until billed | state `INSTALLED_PENDING_BILLING` |
| Bill customer part | POS sale decrement | link sale line and FIFO purchase mapping |
| Install on internal device | POS adjustment decrement | append actual device cost |
| Remove unused part | release/return | no cost if never consumed |
| Reverse consumed part | explicit POS return/adjustment | append reversal, never edit original cost |

Part substitutions require quote reapproval if price/scope rules demand it.

## 8. Sale of a tracked device

1. POS line identifies a tracked variation and quantity.
2. Staff scans exactly one eligible `IN_STOCK` device for each unit.
3. Preflight validates business, location, variation, sellability, reservation, version, and device count.
4. POS finalization locks selected devices and aggregate rows, posts sale and payment, decrements aggregate stock, writes purchase-sale mapping, creates device movements, ends business ownership, and opens customer ownership if known.
5. Post-commit output prints the permanent device code/QR and warranty reference on the invoice where configured.

Finalization is rejected when a tracked variation lacks device assignment. Cancelling/returning a sale uses an explicit inverse workflow and condition check; it never rewinds history.

## 9. Stock transfer

1. Sender selects tracked units by scan and creates transfer.
2. System validates source custody/location and marks units `IN_TRANSFER`; aggregate source decrement and transfer source transaction post together.
3. Receiver scans units. Missing, extra, or substituted devices create exceptions.
4. Completion posts destination purchase-transfer aggregate and each custody movement together, entering `IN_STOCK`.
5. Cancellation before dispatch restores source state. After dispatch, cancellation requires a return-transfer workflow.

The current core transfer status path needs the integration event/guard identified in the architecture. Tracked transfers must not use the unextended path.

## 10. Warranty return and repeat repair

1. Scan resolves the same canonical device and original sale/repair/warranty evidence.
2. System evaluates policy dates and coverage but an authorized user records the decision.
3. Open a new repair job linked to the originating job/sale; never reopen a closed job.
4. Covered and chargeable work remain explicitly separated in quote and POS lines.
5. Parts, costs, QC, custody, and closure follow the shared repair workflow.

## 11. Cancellation and correction

- Draft device before stock participation: void identity; retain tombstone/event.
- Incorrect identifier: authorized correction creates history, rechecks uniqueness, and may require approval.
- Posted receipt: reverse through POS purchase correction and inverse device movements.
- Posted part consumption: POS return/adjustment plus cost reversal.
- Closed repair: create corrective event or follow-up job; do not erase it.
- Sold device: use POS return/trade-in workflow and start a new ownership period.

## 12. Exception queues

Dedicated queues are required for:

- duplicate or conflicting identifiers;
- aggregate-versus-device stock mismatch;
- device at wrong/unknown location;
- incomplete purchase or sale assignments;
- transfer discrepancies;
- installed-but-unbilled parts;
- stale reservations;
- jobs blocked beyond service-level thresholds;
- outbox delivery failures;
- legacy-unserialized stock below zero or above declared baseline.

Resolution always records actor, reason, before/after values, and linked evidence. There is no “force fix” that bypasses the ledger.

## 13. Transaction boundaries

The following must be single local database transactions: receipt plus device creation/movements; tracked sale plus ownership change; transfer leg plus custody; internal part adjustment plus cost; customer part sale plus billed state; and job transition plus required evidence/event.

Printing, notifications, search indexing, analytics projections, and webhook delivery are post-commit outbox work. Their failure must not roll back a valid stock or financial posting.
