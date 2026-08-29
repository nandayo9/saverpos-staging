# RCR-008 Tracked Device Pilot Runbook

## Purpose and boundary

Use this procedure for one approved branch and one approved `TRACKED_REQUIRED` variation. Ultimate POS remains the only stock, sale, return, payment, and accounting ledger. Recommerce records the corresponding permanent physical-device history.

## Pre-flight

1. Create an isolated database from a supported Ultimate POS installation or a versioned disposable fixture. The checked-in `.env` points to `/private/tmp/saverbro_recommerce_demo.sqlite`; it must be created and migrated before browser use. PHPUnit uses separate in-memory SQLite and is not a login fixture.
2. Enable the module and writes only for the approved business, branch, and variation. Assign `recommerce.device.sell`, `recommerce.device.transfer`, `recommerce.device.return`, `recommerce.device.reverse_disposition`, and the existing read/receiving permissions to the pilot role.
3. Record a signed physical baseline. Core location quantity and tracked-device count must reconcile before operations begin.
4. Confirm a keyboard-wedge scanner enters the printed `SB-DV-...` device code and the browser print dialog reaches the pilot label printer. No scanner or printer driver changes are part of this release.

## Operating procedure

1. Receive each tracked unit once, capture its identifier, and print its label. Resolve/scan the code and verify the Device record.
2. Complete inspection/repair before making a device `AVAILABLE`; do not sell a `RECEIVED`, repair, or quarantine device.
3. For a transfer, enter one device code per unit. Complete the transfer through the edit/create flow. Do not use status-only completion for tracked stock: it is intentionally blocked because it cannot prove the physical selection.
4. At POS, scan or type one code per tracked sale unit. A final sale with missing, duplicate, wrong-branch, wrong-variation, or unavailable devices must fail as one transaction.
5. For a return, scan the device’s original code. The default result is `RETURNED_PENDING_INSPECTION`; it is not available for resale until inspection sets a valid next state.
6. Void/delete and return-delete actions must run through the native core action. Recommerce writes an immutable reversal; operators must not alter device state directly.
7. Run reconciliation at opening, close, and after each discrepancy. Stop tracked receiving and investigate any mismatch; never create/delete Devices to force a pass.

## Discrepancy escalation and rollback

- Stop affected tracked variation operations, record the Device code, core transaction reference, expected/actual location, and last scan.
- Preserve the core transaction and Recommerce events; escalate with the reconciliation run ID. Correct through an audited reversal/inspection workflow only.
- To roll back the pilot, first reconcile and close/void all outstanding tracked operations, then set `RECOMMERCE_WRITES_ENABLED=false`. Keep reads enabled until evidence is exported. Disable the module only after a final reconciliation is retained.

## Acceptance record

Capture the date, operator, branch, variation, device codes, core purchase/sale/transfer/return references, label-printer result, scanner result, reconciliation result, and approver for every pilot day. Production deployment is not authorized by this runbook.
