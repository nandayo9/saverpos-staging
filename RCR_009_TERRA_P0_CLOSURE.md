# SAVERPOS Terra P0 Closure

## Disposable demo runtime

The demo is a fresh local MySQL database only. The builder rejects every
database name except `saverpos_demo_*`; `--fresh` drops and recreates only that
named demo database. It neither imports nor reads production data.

```bash
cd /Users/nandayo/Downloads/UltimatePOS-V7.3/UltimatePOS-CodeBase-V7.3
SAVERPOS_DEMO_DATABASE=saverpos_demo_p0 scripts/build-saverpos-demo-runtime.sh --fresh
SAVERPOS_DEMO_DATABASE=saverpos_demo_p0 scripts/serve-saverpos-demo-runtime.sh
```

Open `http://127.0.0.1:8010/login` and sign in with the fictional fixture
account `saverpos.demo` / `demo-pass`. The fixture creates business 1, Branch A
and Branch B, one tracked product/variation, a received purchase, and Device
`SB-DV-00000001-9` at Branch A. The serving script fixes the explicit local
Recommerce cohort to business 1, locations 1 and 2, variation 1.

The project migrations require MySQL-compatible DDL; a full SQLite migration
fails at the historical `ALTER ... MODIFY COLUMN` transaction migration. MySQL
is therefore the supported disposable fixture for this codebase.

## Transfer lifecycle contract

Pending and in-transit transfers reserve exact Devices in `IN_TRANSFER` /
`TRANSFER_PENDING`, retaining a durable assignment. Completion can use that
assignment without a new device payload, moves the exact device to destination
custody as `AVAILABLE` / `ON_HAND`, and is idempotent. Cancellation returns
only reserved Devices to source custody. A completed transfer may be cancelled
only through an append-only reversal; it rejects a reversal after a sale or any
other lifecycle change. Tracked transfer rows cannot be deleted.

Receiving evidence is recorded separately from custody: a receiver may submit
the observed codes and a note, producing `MISSING`, `EXTRA`, or `SUBSTITUTED`
exceptions. Unknown codes are stored as a SHA-256 hash with only a four-character
hint. An open exception blocks completion. A manager with the existing reverse-
disposition scope may resolve it with a required note; resolution records the
decision and never moves stock or changes Device state.

Core Ultimate POS remains the only aggregate-stock/accounting authority. The
controller reverses its original stock delta and purchase-sell mapping in the
same database transaction as the physical-device reversal. Any inconsistent
physical state raises an error and rolls back rather than inventing movement.

## Verification performed

* Fresh MySQL migration and seed passed on `saverpos_demo_p0`.
* Browser: fresh-fixture login, tracked receiving, pending and completed
  Branch A → Branch B transfer, Branch B POS sale attributed to Demo Customer,
  exact-device return, Branch B selection in the rendered reconciliation UI,
  and Device detail were exercised locally. Branch B reconciliation returned
  `PASS · core 1 · tracked 1 · legacy 0`; Device detail displayed the complete
  `RECEIVE_POSTED`, `TRANSFER_RESERVED`, `TRANSFER_COMPLETED`, `SALE_DISPOSED`,
  and `SALE_RETURN_RECORDED` timeline on the same permanent Device.
* Automated: `/opt/homebrew/bin/php vendor/bin/phpunit --no-coverage` passed (`145 tests, 966 assertions`), including exact partial returns and RC-037 receiving exceptions.
* Static: `/Users/nandayo/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node scripts/recommerce-static-check.mjs .` passed.
* RC-037 browser: a fresh pending transfer rendered its exact manifest; a receiver
  submitted an unknown code and evidence note, the page showed `MISSING` and
  `EXTRA` as `OPEN`, and the manager resolved the missing exception in the UI.

P0 status: **PASSED LOCALLY**. Partial returns are now defined and covered: the
requested return quantity must equal the number of selected exact devices, and
only that subset receives the inverse movement. Physical scanner/printer
validation remains out of scope for this browser-only run.

Browser entry was keyboard text into scanner-compatible fields; no physical
scanner, printer, labeler, or other hardware was used.
