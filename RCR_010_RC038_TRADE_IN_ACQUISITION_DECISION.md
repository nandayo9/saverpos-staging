# RC-038 Trade-in acquisition decision record

**Status:** Architecture drafted; implementation not started. The accounting
decision remains open with management. This record is source-reviewed design
evidence, not a runtime or release claim.

**Date:** 2026-08-31

## Decision boundary

UltimatePOS remains the authority for the financial acquisition, stock
quantity, supplier/customer balances, payments, and accounting callbacks.
Recommerce owns the physical device identity, identifier history, ownership and
custody periods, lifecycle state, and the link that explains which financial
source acquired the device.

Trade-in must not create a Recommerce-only purchase, payment, balance, or stock
ledger. An offer is an estimate until an authorised acceptance posts an
authoritative POS transaction.

## Source evidence

- `Modules/Recommerce/Services/UltimatePosPurchaseWriter.php` creates a native
  received `purchase` transaction, a native `purchase_lines` row, adjusts stock
  through `ProductUtil`, recalculates payment status, writes activity, and
  dispatches `PurchaseCreatedOrModified`. It deliberately creates no payment
  row (lines 77-137).
- `Modules/Recommerce/Services/TrackedReceivingService.php` creates the
  Device, business ownership period, purchase assignment, custody period,
  movement, and permanent receive event only after the core receipt is inside
  the same database transaction (lines 259-353).
- `app/Http/Controllers/TransactionPaymentController.php` is the native
  purchase-payment path. It creates `TransactionPayment`, emits
  `TransactionPaymentAdded`, updates payment status, and lets the account
  listener create the account transaction when an account is supplied.
- `recommerce_device_ownership_periods.acquisition_transaction_id` is nullable
  and append-only by convention, so each ownership acquisition can retain its
  own native transaction reference.
- `recommerce_device_purchase_assignments` has a unique `device_id`. The row
  currently describes the first received purchase layer and cannot safely be
  reused for a later trade-in without destroying the original acquisition
  history.
- `CustomerRepairDeviceService` and `RepairCollectionService` already provide
  customer-owned device lookup and audited location/customer custody changes.
  Trade-in should reuse those invariants rather than directly mutating device
  state.

## Recommended V1 financial seam

1. Intake/lookup resolves the existing canonical Device by device code or a
   strong identifier, locks it, and verifies that it is customer-owned by the
   presenting contact. A new Device is not created for a matching identity.
2. Diagnosis and offer revisions remain Recommerce records. Each revision
   stores the observed condition, proposed amount, currency, approver, and
   approval evidence. No revision is an actual cost or stock event.
3. Acceptance requires an approved offer, an authorised location, a product
   and variation suitable for stock, and a supplier-capable UltimatePOS
   counterparty. The implementation must never silently change a customer
   contact to a supplier. The default safe policy is an explicitly selected
   supplier/both contact, with the original customer contact retained on the
   trade-in record.
4. In one transaction, call a reviewed purchase adapter modelled on
   `UltimatePosPurchaseWriter` with quantity one and the accepted offer amount
   as the unit acquisition price. The native purchase is the only stock and
   accounting mutation. Its source/ref/note should carry the trade-in command
   and offer identifiers without putting sensitive device data in free text.
5. After the native receipt succeeds, append a new Recommerce acquisition
   record (schema still required), close the customer ownership/custody
   periods, open business ownership at the receiving location, append an
   acquisition movement/event, and set the device to `AVAILABLE` / `ON_HAND`.
   The new record links the POS transaction and purchase line; the old sale,
   repair, identifiers, and prior ownership periods remain unchanged.
6. Settlement is a separate native purchase payment operation unless
   management explicitly approves an atomic payment adapter. Cash/bank/store
   credit must use an existing UltimatePOS payment/account mechanism; no
   Recommerce balance is permitted. A purchase may remain `due` when the
   business owes the seller, while ownership is already recorded as acquired.
7. Rejection or expiry creates no purchase, payment, stock, cost, or
   ownership change. The existing customer-owned device is returned through an
   audited custody transition and the offer is closed with the rejection
   reason.

## Required schema seam before implementation

Add an append-only `recommerce_device_acquisitions` (name provisional) table,
one row per accepted acquisition, with at least:

- business, device, seller/customer contact, location;
- accepted offer/revision and immutable amount/currency evidence;
- native transaction and purchase-line IDs;
- acquisition kind (`INITIAL_PURCHASE`, `TRADE_IN`), accepted/settled times,
  actor, command UUID, and reversal reference;
- unique business/command idempotency and unique source transaction/line
  constraints appropriate to the approved retry model.

Do not repurpose `DevicePurchaseAssignment` for this. It remains the original
tracked-receipt assignment; the new acquisition record is the history needed
for repeat sale → trade-in → resale cycles. If exact per-layer cost is needed
for profit, the acquisition record (or a dedicated append-only cost entry) must
be the source-linked layer; an offer estimate must never populate it before
acceptance.

## Reversal and reconciliation contract

- A posted native purchase is reversed only through the ordinary UltimatePOS
  purchase-return/void authority. Recommerce then appends an inverse
  acquisition/ownership/custody movement; it never deletes the original rows.
- A trade-in cannot be rejected after acceptance has posted. It requires an
  explicit reversal path and must refuse reversal after a subsequent sale,
  transfer, repair custody change, or other dependent lifecycle event unless a
  reviewed unwind sequence exists.
- Reconciliation must compare the native purchase quantity and purchase-line
  mapping to exactly one active on-hand Device, and report missing, duplicate,
  due/paid, or reversed links as blocking evidence. It must never auto-balance
  stock or invent a cost.

## Required tests before RC-038 can be marked implemented

- Reuse the Device from a previous sale and/or repair; no duplicate identity.
- Duplicate strong identifiers and duplicate command UUIDs are rejected or
  replay the original result.
- Approval threshold, revision immutability, and separation of estimate from
  actual acquisition cost.
- Concurrent accept/reject and stale-offer retries leave one outcome.
- Native purchase, purchase line, payment, stock, acquisition record,
  ownership period, custody period, movement, and event reconcile exactly.
- Native purchase failure rolls back every Recommerce write; later native
  reversal leaves the complete prior history intact.

## Management decisions still required

1. Is the accepted trade-in booked as a native purchase payable to the seller,
   and which payment/account methods are allowed in V1?
2. Must settlement be immediate/atomic, or may the native purchase remain due?
3. May a customer contact be promoted to `both`, or must operators select/create
   a separate supplier-capable contact?
4. Which product/variation catalog mapping is approved when a customer device
   has no existing POS variation?

Until these decisions are approved, RC-038 remains **blocked for implementation**
but its source-reviewed acquisition seam is documented.
