# RC-038 Trade-in acquisition decision record

**Status:** Implemented and committed locally on 2026-08-31; not pushed or
deployed. Browser-proven through accepted native purchase on an isolated MySQL
fixture. The required-test list below is now closed. Browser payment proof
remains outstanding, and **reversal cannot be browser-proven at all: it has no
route, controller action, or UI** — `TradeInService::recordReversal` is
reachable only from code (see "Known V1 limitations").

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

## Implemented V1 contract

- A versioned, deterministic `TradeInPricingService` records all rule inputs,
  reserves, calculation version, recommendation levels, inspection, and manual
  market evidence in an immutable valuation snapshot. It deliberately does not
  encode a historic fixed 30/20/10 formula.
- A valuation references an existing customer-owned Device, an explicitly
  selected supplier-capable or `both` UltimatePOS contact, and an existing
  authorised UltimatePOS product/variation. It never creates catalogue items
  or changes a contact type.
- `TradeInService::accept()` invokes `UltimatePosPurchaseWriter` once with one
  unit and the accepted acquisition price. It creates no payment, so native
  UltimatePOS settlement can remain due and later use ordinary payment flows.
- The service appends `recommerce_device_acquisitions`, closes customer
  periods, opens business/location periods, records a physical movement and
  lifecycle evidence, and sets the Device to `AVAILABLE` / `ON_HAND` only
  after the native receipt returns in the same transaction.
- Rejection returns customer custody without a purchase. Reversal first
  requires a matching native `purchase_return`, preserves the acquisition row,
  and refuses once the device leaves its acquired on-hand state.
- New permissions are deny-by-default: `view`, `manage`, `approve`,
  `override_economic_ceiling`, `accept`, and `reverse`. Registration does not
  silently grant them to existing roles.

## Evidence as of 2026-08-31

- `RecommerceTradeInAcquisitionTest`: **14 tests / 118 assertions**, covering rule
  versioning, immutable valuation/evidence snapshots, approval/override,
  contact-role rejection, rollback, repeat acquisition after reversal,
  idempotent one-unit acceptance, reject custody, and native-return-gated reversal.
- Full suite: **359 tests / 1,783 assertions**; Recommerce static check and Blade
  view cache passed.
- An isolated `saverpos_demo_rc038` clone migrated both RC-038 migrations on
  MySQL. In the authenticated browser, the fictional `SB-DV-TRADEIN-001`
  Device was valued from two manual market-evidence points, correctly required
  approval for a MYR 950 offer above its MYR 931 negotiation ceiling, then was
  approved and accepted. The browser-created native purchase is `received` /
  `due`, has one mapped purchase line at quantity one, has no payment row, and
  links the exact Device as `BUSINESS` / `LOCATION` / `AVAILABLE` / `ON_HAND`.
  No page console errors occurred. Browser payment and reversal were not run.

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

## Required-test closure (2026-08-31)

The list above is now satisfied. Five tests were added to close it, each
mutation-checked against a deliberately broken service:

| Requirement | Test | Mutation that proves it |
| --- | --- | --- |
| Concurrent accept/reject leaves one outcome | `test_a_reject_after_acceptance_is_refused_and_leaves_the_device_acquired` | Adding `ACCEPTED` to `reject()`'s allowed statuses turns it red |
| Concurrent accept/reject leaves one outcome | `test_an_acceptance_after_rejection_is_refused_and_posts_no_purchase` | Adding `REJECTED`/`ACCEPTED` to `accept()`'s allowed statuses turns it red |
| Stale-offer retries leave one outcome | `test_a_stale_retry_under_a_new_command_uuid_cannot_post_a_second_purchase` | Same status-gate mutation; command-UUID idempotency alone cannot catch a retry under a fresh key |
| Device reuse from a previous sale/repair; no duplicate identity | `test_a_device_with_prior_history_is_reused_and_its_record_is_preserved` | Deleting rather than closing prior periods in `closeOpenPeriods()` turns it red |
| Native purchase/line/payment/acquisition/ownership/custody/movement/event reconcile exactly | `test_acceptance_reconciles_every_native_and_recommerce_artifact` | Pointing the acquisition at `purchase_line_id + 1` turns it red |

Two notes on how that closure is scoped, so nobody over-reads it:

- **Concurrency is asserted through the status gate, not through two live
  connections.** The suite runs on in-memory SQLite, which offers no second
  connection to race. Both `accept()` and `reject()` take
  `lockForUpdate()` on the valuation and then re-check its status inside the
  transaction; the tests prove the status gate, which is what makes the lock
  decisive. A true concurrent-writer test needs the MySQL fixture.
- **Duplicate strong identifiers are prevented structurally, not by a rejection
  path.** `TradeInService` never creates a `Device` and never writes a
  `DeviceIdentifier` — it resolves an existing customer-owned device and locks
  it. The reuse test asserts identifier rows are untouched by acceptance.

The first version of the reuse test asserted only that *no open* prior period
remained, which passed just as happily when the rows were deleted. The mutation
exposed it; it now asserts the prior ownership and custody rows still exist and
carry `ends_at`.

## Known V1 limitations

- **Reversal has no user interface.** `TradeInService::recordReversal` is
  implemented, gated on `recommerce.tradein.reverse`, requires a matching native
  `purchase_return`, and is covered by tests — but there is no route, no
  controller action, and no view that calls it. An operator cannot reverse an
  accepted trade-in through the application. This is why the browser reversal
  proof is not merely outstanding but currently impossible. Building it needs a
  decision on how the native purchase-return reference is supplied and confirmed;
  it is not assumed here.
- Settlement remains a separate native purchase payment, unexercised in a
  browser.
- Every route that does exist has a view caller, which was checked rather than
  assumed.

## Resolved V1 decisions and deferred work

1. An accepted trade-in is a native UltimatePOS purchase; ownership transfers
   when that purchase posts, even if it is still due.
2. Settlement is deferred to native UltimatePOS payment flows. Store credit is
   out of scope.
3. Operators must explicitly select an existing supplier-capable or `both`
   contact; promotion/creation is outside this command.
4. A mapped product/variation is mandatory. Catalog creation and automatic
   mapping are out of scope.
5. Machine learning, opaque AI prices, marketplace scraping, auto-catalogue,
   and live price feeds are deferred. The V1 rule engine is intentionally
   deterministic and explainable.
