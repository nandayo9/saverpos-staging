# RCR-011 — Stock Count V1

## Boundary

Stock Count records an immutable physical observation against a branch and the
configured Recommerce variation cohort. UltimatePOS remains authoritative for
products, variations, aggregate quantity, transactions and accounting.
Recommerce remains authoritative for the exact Device, lifecycle, ownership,
custody and Device history. Count rows are evidence, never a second stock
ledger.

## V1 workflow

`DRAFT → IN_PROGRESS → REVIEW → AWAITING_APPROVAL → RECONCILED → CLOSED`

Starting creates immutable Device and generic-variation snapshot rows. Staff
scan permanent SaverBro QR URLs/tokens, Device codes, numeric Device IDs or an
authorized strong identifier; every successful Device scan is unique per
session. A scan can create an exception, but it never changes Device lifecycle,
custody or ownership.

V1 permits normal operations during counting but deliberately does not infer
their stock effect. Any post-snapshot tracked Device movement is visible and
blocks reconciliation. A fresh count is required until a future
movement-aware reconciliation policy is designed and tested.

## Reconciliation policy

- A negative **non-serialized** approved variance invokes
  `UltimatePosStockCountAdjustmentWriter`, which creates a native
  `stock_adjustment` transaction and calls UltimatePOS `ProductUtil`; it never
  updates `variation_location_details` directly.
- Positive variance is blocked: stock cannot be created without receiving,
  return, or identity provenance.
- Serialized discrepancies are blocked from automatic reconciliation. Their
  resolution note is evidence only; correcting lifecycle/custody must use the
  existing sale/return/transfer/repair domain flow.
- Every exception must be resolved with a structured reason and note before
  approval/reconciliation. Serialized exceptions require configurable approval;
  the generic cost threshold has no invented default.

## Exception matrix

| Exception | Aggregate stock | Device/custody/ownership |
| --- | --- | --- |
| Missing Device | No automatic mutation | No automatic mutation; use valid loss, return, transfer, or identity workflow |
| Unexpected Device | No automatic mutation | No automatic mutation |
| Wrong Location | No automatic mutation | No automatic transfer |
| Wrong State | No automatic mutation | No automatic lifecycle repair |
| Generic quantity variance | Approved negative variance only, through native UltimatePOS adjustment | Not applicable |
| Serialized aggregate conflict | No automatic mutation | No automatic mutation |

## Deliberate V1 limits

There are no count zones, independent recount rounds, automatic live-movement
adjustments, positive quantity adjustments, or generic Device state controls.
Those need separate domain decisions and evidence. Browser interaction is not
claimed: the available local runtime redirects unauthenticated requests and
the in-app browser could not reach a signed-in Stock Count screen.
