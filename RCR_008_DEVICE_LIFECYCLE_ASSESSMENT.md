# RCR-008 Device Lifecycle Technical Assessment

**Assessment date:** 2026-08-29  
**Scope:** tracked Recommerce cohort only; no change to Ultimate POS inventory or accounting authority.

## Verified implementation state

- `TrackedReceivingService` creates one `recommerce_devices` record per received unit, with purchase assignment, ownership/custody period, movement, immutable event, command idempotency, and row-level locking.
- `StockReconciliationService` compares `variation_location_details.qty_available` with tracked devices and approved legacy evidence. It correctly reports an exception while devices are marked `IN_TRANSFER`.
- `SellPosController` creates or updates the core sell transaction and sell lines inside a database transaction, then adjusts core stock and purchase-to-sell mapping before its commit. It has no existing physical-device attribution.
- `StockTransferController` creates paired `sell_transfer` / `purchase_transfer` transactions and changes aggregate quantity inside one database transaction. It has no device selection or movement bridge.
- `SellReturnController` creates and deletes core sell returns inside database transactions. It has no device-aware return bridge.
- `DeviceCertificationService` requires `sold_at`, but normal sales do not set it; this is a real lifecycle defect.

## Controlled integration points

1. **Sell store/update/delete:** invoke the Recommerce lifecycle service after core sell lines exist, but before the outer controller transaction commits. Final tracked sale lines require selected permanent device codes. Updates synchronise active dispositions; draft/cancel/delete reverses them without deleting history.
2. **Sell return store/delete:** invoke the service after `addSellReturn` has persisted the return transaction and lines, still within its transaction. A selected exact device must be attributable to the original sale line. The device moves into inspection-required return custody; deletion reverses that return disposition.
3. **Stock transfer store/update-status/delete:** invoke the service only for completed/final core transfers, after paired core transactions/lines exist and before commit. It moves exact devices between locations and leaves append-only movement evidence. Pending transfers do not move device custody.

## What remains untouched

- Core `variation_location_details`, sales, purchases, payments, tax, cash, accounting, FIFO/LIFO mapping, and warranty rules remain Ultimate POS authority.
- Recommerce stores only device identity, selection/disposition history, custody/ownership history, and a derived economics projection.
- Non-cohort and aggregate-only variations retain current Ultimate POS behaviour.

## Transaction, locking, and idempotency design

The module service runs within the pre-existing controller database transaction. It locks the sale/transfer/return row and each selected Device row, validates business, location, variation, lifecycle, and stock participation from persisted records, then writes unique append-only disposition rows, movement/event evidence, and period transitions. `recommerce_stock_commands` is used for command idempotency where an external/request identifier is available; database uniqueness prevents a device from having more than one active disposition of a given kind. Invalid selection throws before the surrounding transaction commits, rolling back the core transaction as well.

For a finalized tracked sale, no selected device count mismatch is permitted. There is no silent legacy bypass: a variation is subject to selection only when it has an approved Recommerce serialization profile and falls in the explicit enabled cohort.

## Known runtime condition before this change

The checked-in `.env` selects `/private/tmp/saverbro_recommerce_demo.sqlite`, which does not currently exist. PHPUnit is reproducible because `phpunit.xml` uses in-memory SQLite; the browser/runtime fixture needs an explicit disposable bootstrap path before it can be claimed operational.
