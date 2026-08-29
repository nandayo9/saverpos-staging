# RCR-002 — Provisional Local/Source Profile

**Profile date:** 2026-08-27  
**Status:** `PROVISIONAL ONLY — NOT RCR-002 ACCEPTANCE`  
**Checkout:** `/Users/nandayo/Downloads/UltimatePOS-V7.3/UltimatePOS-CodeBase-V7.3`

## Purpose and boundary

This document records the most useful RCR-002 profile that can be produced by bypassing the evidence gate in a bounded way: static inspection of the supplied Ultimate POS source checkout. It is not a production, Alpha-site, database, or hardware profile. No production system, signed-in account, application runtime, database, scanner, printer, or browser session was accessed.

The profile is intentionally non-authoritative. It does not authorize a pilot, data migration, schema change, Recommerce implementation, or release claim.

## 1. Decision

**RCR-002 remains `BLOCKED`.**

The source checkout does not contain the evidence needed to select one real Alpha location, variation/category, and current-stock baseline. The only identifiable business records are dummy-seeder intent, not approved production data. No current stock reconciliation, open-transaction count, identifier sample, or hardware confirmation can be calculated from this checkout.

## 2. Corpus and evidence qualification

| Field | Provisional result |
|---|---|
| Corpus | Supplied Ultimate POS source checkout and its included static assets/configuration |
| Snapshot date | Not available; profile inspection date is 2026-08-27 only |
| Geography/site | Not available; no Alpha location or site inventory supplied |
| Production database | Not supplied and not accessed |
| Synthetic/demo data | `database/seeders/DummyBusinessSeeder.php` contains dummy businesses, products, transactions, Repair models, and Repair statuses; excluded from operational totals |
| Exclusions | No production quantities, serials, open transactions, users, devices, scanners, printers, browsers, network topology, or deployment facts |
| Evidence class | Static source observation only; no observed production result |

## 3. Candidate cohort decision

No defensible Alpha cohort can be selected from the source package.

The dummy seed file is not a substitute for an approved cohort. In particular, the presence of demo businesses or seeded Repair rows does not prove that the corresponding tables exist in deployment, that the records are current, or that any location is approved for a pilot. RCR-002 therefore has no selected location, category, variation, or stock quantity.

## 4. Stock-control surface found in source

The following are the static stock-affecting paths that a later approved profile should reconcile. Their presence is not runtime proof.

| Stock event surface | Source evidence | Profile implication |
|---|---|---|
| Purchases | `routes/web.php:226-232`; `PurchaseController::store` around `:292`; update around `:645` | Purchase receipts/status changes may affect aggregate stock and cost |
| Opening stock import | `routes/web.php:395-396`; `ImportOpeningStockController::store` around `:70`; quantity update around `:268` | Opening balances require an explicit source snapshot and reconciliation |
| Sales returns | `routes/web.php:399-407`; `SellReturnController::store` around `:370`; stock update around `:542` | Return transactions must be included in net stock movement |
| Stock adjustments | `routes/web.php:367-369`; `StockAdjustmentController::store` around `:175`; update around `:322` | Manual adjustments and their approval/status must be counted |
| Stock transfers | `routes/web.php:384-386`; `StockTransferController` quantity updates around `:328`, `:335`, `:516`, `:524`, `:840`, and `:847` | Location-to-location movements require both sides of reconciliation |
| Purchase returns | `routes/web.php:425-431` | Supplier returns can change available stock and must be included |
| Product quantity service | `app/Utils/ProductUtil.php:350`, `:399`, and `:1207` | Later integration must use verified core quantity conventions, not a parallel stock authority |

## 5. Identifier and duplicate profile

**Not calculable from the checkout.** No production product/variation extract, serial-number extract, duplicate sample, or approved identifier mapping was supplied.

Static observations relevant to later discovery:

- Core Repair-related transaction fields include `repair_serial_no`, `repair_device_id`, and `repair_model_id` in `app/Utils/TransactionUtil.php:1766-1843`, but the Repair source and live schema are absent.
- The checkout contains no `Modules/Repair` source tree and no Recommerce source tree.
- No production barcode/QR scan sample or identifier collision report is present.
- A later evidence pack must distinguish product SKU/barcode, serial number, IMEI or other manufacturer identifier, and any existing human reference; these cannot be inferred from dummy seed intent.

## 6. Open-transaction and Repair profile

**Not calculable from the checkout.** There is no live database, migration history for the missing Repair module, or approved export of open sales, purchases, transfers, returns, Repair jobs, deposits, attachments, or customer-device records.

The source does show that Repair is coupled to core POS receipt paths through `transactions.sub_type = 'repair'`, Repair-specific fields, permissions, redirects, and view checks. The missing module source prevents determining the actual table structure, status model, open-job semantics, or data volume.

## 7. Hardware and browser profile

**No hardware profile supplied or observed.** Static source search found printer-related core files, but no approved Alpha inventory for:

- scanner model, connection mode, keyboard-wedge behavior, or scan terminator;
- label/receipt printer model, paper size, DPI, driver, network path, or quiet-zone constraints;
- browser name/version, workstation OS, display size, or supported kiosk mode;
- concurrent operators, network latency, offline requirements, or deployment topology.

The required scanner and printer acceptance checks therefore remain unrun.

## 8. What this provisional bypass permits

This bypass permits only planning and evidence-request preparation:

- map the core stock-control surfaces listed above;
- prepare a read-only reconciliation query/workbook against a future sanitized snapshot;
- prepare a hardware interview/checklist;
- keep Recommerce outside core stock authority and outside any legacy provider Repair surface; the SaverBro-owned Repair domain remains subject to its own design and runtime gates.

It does not permit production reads, production writes, authenticated portal access, data backfill, device creation, migration, or pilot approval.

## 9. Evidence still required to clear RCR-002

The minimum missing pack is:

1. Approved read-only or sanitized production snapshot with snapshot timestamp, database engine/version, schema/migration history, and business/location identifiers.
2. One named Alpha location, one category/variation scope, current on-hand quantity, and source-of-truth reconciliation total.
3. Identifier sample covering duplicates/conflicts and the mapping between SKU/barcode, serial/IMEI, and any existing device reference.
4. Open transaction and Repair-job counts, including deposits, transfers, returns, and unresolved exceptions.
5. Site hardware/browser matrix and witnessed scan terminator and printer-label checks.
6. Approval identifying the users and location in scope; this is operational evidence, not implied by a source checkout or role definition.

## 10. Final status

| Gate | Status |
|---|---|
| RCR-001 source/runtime baseline | `BLOCKED` as documented in `RCR_001_BASELINE_REPORT.md` |
| RCR-002 source-only provisional profile | `COMPLETE AS NON-AUTHORITATIVE ANALYSIS` |
| RCR-002 accepted Alpha profile | `BLOCKED` |
| Recommerce production feature implementation | `NOT STARTED` |
| Repair modification or migration | `NOT STARTED` |

No application business logic, dependencies, routes, migrations, database, module status, or assets were modified for this provisional bypass.
