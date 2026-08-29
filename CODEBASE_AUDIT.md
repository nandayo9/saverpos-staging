# Ultimate POS 7.3 Codebase Audit for SaverBro Recommerce OS

Status: architecture reconnaissance only  
Audit date: 2026-08-27  
Scope: source present in this checkout; no production database or signed-in runtime was inspected

## Executive finding

Ultimate POS is a viable host for SaverBro Recommerce OS because its Laravel monolith already owns the business-critical ledgers SaverBro should preserve: products and variations, purchase and sale transactions, location-level quantity, FIFO/LIFO cost mapping, transfers, payments, contacts, roles, printing, and activity logging.

It is not presently a serialized-asset system. Its `enable_sr_no` option only exposes `transaction_sell_lines.sell_line_note`; it does not create a unique physical-unit record, enforce serial uniqueness, track unit ownership/location, or bind one device to one purchase/sale/transfer line. Lot tracking identifies a `purchase_lines` row, not one device. A new first-class device domain is therefore required, but it should be added as a module and coordinated with—not substituted for—the existing POS stock/accounting paths.

There is a release-blocking source gap: `config/modules.php` declares `Modules/` as the module source path and `modules_statuses.json` marks Repair and other modules enabled, but this checkout has no `Modules/` directory. `public/modules/repair` contains only zero-byte placeholders. Core code references `Modules\Repair\...`, proving an external Repair module was expected, but its entities, migrations, routes, workflows, and tests cannot be audited here. Recover and inspect the licensed module source before implementation.

## Evidence and confidence

- Confirmed by source: framework/dependencies, core schema, models, controller flows, stock algorithms, label/QR helpers, authorization framework, events, API surface, and tests present.
- Not confirmed: installed production schema, database engine/version, live module versions, production configuration, installed printer behavior, runtime permissions, or data quality.
- Runtime limitation: `php` is not installed on the inspection host, so Artisan and PHPUnit were not executed.
- Reproducibility limitation: `package.json` is absent although `resources/js` imports npm packages; bundled public assets exist, but a clean front-end rebuild is not demonstrated.

## 1. Framework and runtime

| Item | Finding | Source |
|---|---|---|
| Product version | Ultimate POS `7.3` | `config/author.php:19` |
| Framework | Laravel `9.52.4` locked; Composer constraint `^9.51` | `composer.lock:2163-2168`; `composer.json` |
| PHP requirement | `^8.0`; local PHP executable unavailable | `composer.json` |
| Database default | MySQL; example uses MySQL on port 3306 | `config/database.php:18,46-66`; `.env.example:20-25` |
| MySQL mode | `strict => false`; this increases the need for explicit validation and constraints in the new module | `config/database.php:55-60` |
| Queue | Default `sync`; database, Redis, SQS, and Beanstalkd are configured alternatives | `.env.example:30`; `config/queue.php:16-72` |
| Auth | Laravel session auth plus Passport API tokens | `app/User.php:11-20`; `routes/api.php:16-18` |
| UI | Blade-first Laravel UI with jQuery/Bootstrap-era components, AdminLTE styling, Tailwind utilities, and a minimal Vue 2 shell | `resources/views/layouts/app.blade.php:19-145`; `resources/js/app.js:8-36`; `resources/js/bootstrap.js:10-17` |

## 2. Module architecture

Ultimate POS uses `nwidart/laravel-modules` 9.0.6. The configured source location is `Modules/`, with module routes, entities, migrations, providers, views, assets, and tests generated under each module (`config/modules.php:17,63-131`).

The main extension mechanism is `ModuleUtil::getModuleData()`: it discovers installed modules, resolves `Modules\<Name>\Http\Controllers\DataController`, and calls named hooks (`app/Utils/ModuleUtil.php:56-111`). Core screens already ask modules for product form fragments, role permissions, sale hooks, invoice QR data, assets, and other extensions. Global module assets are collected via `ModuleAssetServiceProvider` (`app/Providers/ModuleAssetServiceProvider.php:16-84`).

Recommendation: implement SaverBro functionality in a new `Recommerce` module. Keep core edits limited to narrowly documented guard/hook points where existing callbacks/events cannot enforce a serialized-stock invariant.

### Missing module-source gate

Observed:

- `modules_statuses.json` says `Repair: true` and lists many other modules.
- `config/modules.php:74` expects `base_path('Modules')`.
- The directory is absent.
- `public/modules/repair/js/app.js` and `sass/app.scss` are zero bytes.
- Core code calls `Modules\Repair\Entities\RepairStatus` and `DeviceModel` (`app/Utils/TransactionUtil.php:1766-1843`).
- `.env.example:14` contains `SHOW_REPAIR_STATUS_LOGIN_SCREEN=true`.

Consequence: no claim about the existing Repair module's actual data model, transitions, quotations, parts, or permissions is possible. The architecture in the recommerce documents is the target design; implementation must first compare it to recovered Repair source and write an adopt/adapt/retire decision record.

## 3. Product and variation model

`products` is a business-scoped catalog record with type, unit, brand, category, stock flag, SKU, and barcode type (`database/migrations/2017_08_08_115903_create_products_table.php:16-46`). A product owns `product_variations`, while saleable `variations` hold `sub_sku`, purchase/sell defaults, and soft deletion (`database/migrations/2017_08_10_061146_create_product_variations_table.php:16-27`; `database/migrations/2017_08_10_061216_create_variations_table.php:16-35`).

The Eloquent graph confirms products have variations, purchase lines, locations, warranty, and media (`app/Product.php:59-61,115-141,188-218`). A `Variation` owns location detail rows and sale lines (`app/Variation.php:38-59`).

Architectural meaning:

- Product/variation remains the catalog and quantity/accounting identity.
- A physical device must reference a variation but must not become a new variation per unit.
- Customer-owned repair devices may have no product/variation until SaverBro acquires them.

## 4. Purchase and acquisition model

All purchases are `transactions` with `type='purchase'`; supplier is `contact_id`, branch is `location_id`, and purchase rows are `purchase_lines` (`app/Transaction.php:39-67`; `database/migrations/2017_08_19_054827_create_transactions_table.php:16-49`). Purchase lines carry product, variation, quantity, unit cost, tax, and—through later migrations—lot, manufacturing/expiry, returned/sold/adjusted quantities.

When a purchase reaches `received`, `ProductUtil::createOrUpdatePurchaseLines()` increases `variation_location_details.qty_available` (`app/Utils/ProductUtil.php:1207-1255`). Purchase creation, lines, payments, payment status, activity log, and `PurchaseCreatedOrModified` dispatch all occur in one database transaction (`app/Http/Controllers/PurchaseController.php:292-424`).

Existing purchase-level additional expense slots are limited fixed columns and are not a suitable permanent device cost ledger (`app/Http/Controllers/PurchaseController.php:369-387`). They can remain the invoice summary while recommerce allocates landed cost per device in its own append-only ledger.

## 5. Sales, service products, invoices, and payments

Sales use `transactions` with `type='sell'` and `transaction_sell_lines`. The sell transaction supports `sub_type`, allowing `repair` as an existing convention (`app/Transaction.php:9-12`; `database/migrations/2019_03_09_102425_add_sub_type_column_to_transactions_table.php`). Sale lines bind product/variation/quantity and can bind warranties (`app/TransactionSellLine.php:16-39,94-100`).

The POS finalization path:

1. Creates the sell transaction and sell lines.
2. Writes payments using the existing payment engine.
3. Decrements aggregate variation/location stock.
4. Maps sold quantity to purchase lines.
5. Invokes the module `after_sale_saved` hook inside the transaction.
6. Commits, then dispatches `SellCreatedOrModified` (`app/Http/Controllers/SellPosController.php:500-618`).

`TransactionPayment` belongs to a transaction, connects to accounts/cash-register behavior through events, and recalculates payment status on change (`app/TransactionPayment.php:21-39,78-119`; `app/Providers/EventServiceProvider.php:15-30`).

Recommendation: a customer repair job links to one Ultimate POS sell transaction. Parts use normal stock product lines; labour/diagnosis/outsourced charges use non-stock service products. Ultimate POS remains the only invoice, tax, cash-register, payment, and accounting path.

## 6. Inventory and costing logic

Aggregate on-hand stock is stored in `variation_location_details.qty_available`, keyed by product/variation/location. `ProductUtil::updateProductQuantity()` increments it and `decreaseProductQuantity()` decrements it (`app/Utils/ProductUtil.php:350-425`). These functions do not lock rows or know physical devices.

Cost-of-goods mapping uses `transaction_sell_lines_purchase_lines` to allocate sale or adjustment quantity against eligible received purchase lines (`database/migrations/2018_02_12_113640_create_transaction_sell_lines_purchase_lines_table.php:16-23`). `TransactionUtil::mapPurchaseSell()` selects purchase lines by business, location, product, variation, received status, optional lot, and FIFO/LIFO; it updates purchase-line quantity counters and inserts mappings (`app/Utils/TransactionUtil.php:3394-3594`).

Implications:

- Recommerce must not create a second quantity ledger.
- Device records provide the unit-level subledger; aggregate quantity remains Ultimate POS.
- Every serialized operation must update both inside one transaction and reconcile them.
- Existing stock helpers lack row locks, so the recommerce coordinator must lock device, variation/location, and relevant source rows before committing.

## 7. Locations and transfers

`business_locations` are tenant-scoped branches/warehouses (`database/migrations/2017_12_25_122822_create_business_locations_table.php:16-34`). Users can be granted all locations or explicit `location.<id>` permissions (`app/User.php:116-167`).

A stock transfer creates paired transactions:

- `sell_transfer` at origin;
- `purchase_transfer` at destination linked through `transfer_parent_id`.

When completed, the controller decrements origin aggregate stock, increments destination stock, adjusts overselling, and maps source cost (`app/Http/Controllers/StockTransferController.php:199-366`). Statuses are pending, in transit, and completed (`app/Http/Controllers/StockTransferController.php:184-190`).

The status-update path changes aggregate stock in a transaction but does not dispatch the stock-transfer event (`app/Http/Controllers/StockTransferController.php:896-957`). A minimal core hook/event addition is therefore required if tracked devices may pass through that route; otherwise direct use must be blocked for serialized variations.

## 8. Existing serial and lot tracking

### Serial/IMEI

The product flag `enable_sr_no` is added to products, while the actual value is stored in the generic `transaction_sell_lines.sell_line_note` text field (`database/migrations/2018_03_29_115502_add_changes_for_sr_number_in_products_and_sale_lines_table.php:15-21`). The POS renders that note field when serial mode is enabled (`resources/views/sale_pos/product_row.blade.php:17-18,55,95-100,196`; `public/js/pos.js:1964-2023`).

It provides invoice annotation, not serialized inventory. There is no serial table, no uniqueness constraint, no purchase-side serial identity, and no location/ownership lifecycle.

### Lots

Lots are stored on `purchase_lines.lot_number`; sale lines optionally point to a lot purchase line through `lot_no_line_id` (`app/TransactionSellLine.php:53-56`). POS search can match lot number and preselect that purchase line (`public/js/pos.js:330-332`). Lot tracking can help identify an acquisition batch, but a purchase line may represent many units and cannot be the canonical device.

## 9. Barcode, QR, scanning, and labels

Products declare one-dimensional barcode formats; the locked `milon/barcode` package is version 9.0.1. Existing label settings store paper dimensions, margins, rows, and sticker counts. `LabelsController` can build labels from product or purchase quantities and offers browser printing plus a vector mPDF path (`app/Http/Controllers/LabelsController.php:39-70,104-215,243-320`; `public/js/labels.js:44-86`).

Existing receipt QR codes use `DNS2D::getBarcodePNG(..., 'QRCODE')`. Their payload can concatenate invoice fields or an invoice URL (`app/Utils/TransactionUtil.php:1606-1677`; `resources/views/sale_pos/receipts/classic.blade.php:651-663`). This proves the rendering dependency is reusable.

The POS product box uses jQuery autocomplete against product name, SKU, sub-SKU, custom fields, and lot (`app/Utils/ProductUtil.php:1605-1744`; `public/js/pos.js:177-337`). Keyboard-emulation scanners can therefore scan a product SKU today, but there is no global scanner, camera decoder, device/repair resolver, opaque token, or duplicate-scan defense.

Recommendation: reuse label geometry, mPDF, browser print patterns, and barcode/QR libraries. Do not reuse the existing label endpoint/data loop as the device identity implementation because it repeats one variation detail by quantity and cannot emit distinct unit tokens.

## 10. Warranty

Core warranty is a reusable duration definition attached to products and sell lines (`database/migrations/2019_12_02_105025_create_warranties_table.php:16-33`; `app/TransactionSellLine.php:94-100`). It does not model a warranty claim, repair warranty event, covered device, entitlement consumption, or repeat ownership cycle.

Use it as a policy/duration source where useful, but add device/job warranty entitlements and claims in Recommerce.

## 11. Existing repair integration

What can be proven from core:

- `transactions.sub_type='repair'` is an intended extension point.
- Core receipts expect repair status, warranty, serial, defects, model, checklist, device category, and brand.
- Core product filters recognize `repair_model_id`.
- POS permissions allow `repair.create` when the subscription includes `repair_module`.
- Receipt rendering reaches into `Modules\Repair\Entities\RepairStatus` and `DeviceModel`.

What cannot be proven without `Modules/Repair`:

- actual repair tables and foreign keys;
- repair identifier generation;
- state machine and transition enforcement;
- quotes and approval evidence;
- parts reservation/consumption;
- technician queues;
- tests, policies, or security behavior.

The target architecture deliberately avoids depending on unknown module internals until the source-recovery gate is passed.

## 12. Contacts and ownership parties

`contacts` is the shared supplier/customer party table. Type can be supplier, customer, or both, and business-scoped dropdowns and access restrictions already exist (`app/Contact.php:39-103,199-264`). Use it for suppliers, repair customers, current customer owners, and invoice recipients. Do not create a parallel customer master.

## 13. Roles and permissions

The system uses Spatie Permission 5.5. Roles are scoped by `business_id` and named with `#<business_id>`. Admin receives a gate-wide allow for ordinary abilities (`app/Providers/AuthServiceProvider.php:24-41`). Module permissions are injected into the standard role create/edit screens through `DataController::user_permissions` (`app/Http/Controllers/RoleController.php:87-104,199-223`).

Recommerce must add granular abilities and always combine them with business and location predicates. A permission such as `recommerce.device.view` is not sufficient if the device is at a location the user cannot access.

## 14. Events, queues, and audit logging

Core defines domain-shaped events for purchases, sales, stock adjustments, and transfers, but `EventServiceProvider` explicitly registers only payment/account listeners and disables discovery (`app/Providers/EventServiceProvider.php:15-51`). Modules may register their own listeners.

Spatie Activitylog 4.7.3 is installed. `Util::activityLog()` logs a subject, action, changes, actor, and business (`app/Utils/Util.php:1459-1505`). The configuration deletes records older than 365 days when cleanup runs (`config/activitylog.php:8-20`). It is therefore useful for operator audit but unsuitable as the permanent device lifecycle ledger.

Default queue execution is synchronous and `after_commit` is false for configured asynchronous drivers (`config/queue.php:16,37-72`). Recommerce should write critical device/job events synchronously in the same transaction and use an outbox for optional notifications/PDF generation.

## 15. API

Core `routes/api.php` exposes only authenticated `/api/user` in this checkout (`routes/api.php:16-18`). Passport is installed, but no core device/repair API exists. The Alpha scanner can use authenticated web JSON endpoints with CSRF. A versioned Passport API can be added later for the mobile application without changing QR identity.

## 16. Migration and testing conventions

Core migrations are chronological Laravel migrations, mostly anonymous in newer files and class-based in some package-derived files. Module generation is configured to keep module migrations under `Modules/<Name>/Database/Migrations` (`config/modules.php:103-119`).

PHPUnit 9.5 is locked. The test suite contains only the default one unit and one feature example; the testing database settings are commented out (`tests/Unit/ExampleTest.php`; `tests/Feature/ExampleTest.php`; `phpunit.xml:7-29`). No meaningful stock, permission, repair, or barcode regression suite is present.

## 17. Suitability assessment

Suitable with controlled extension: **yes**.

Strengths to preserve:

- mature transaction/payment/contact/location foundation;
- existing stock and cost mapping;
- database transactions around major workflows;
- module callback architecture;
- role/permission integration;
- reusable label, barcode, QR, PDF, and receipt tools.

Constraints to design around:

- quantity-led inventory with no per-unit identity;
- stock helpers lack explicit row locking;
- current serial support is free text;
- activity log is not permanent domain history;
- module source and front-end build metadata are incomplete;
- test coverage is essentially absent;
- some integration events run after commit or are missing from status paths.

## 18. Mandatory pre-implementation gates

1. Recover the licensed `Modules/` source, especially `Modules/Repair`, and document compatibility/overlap.
2. Restore a supported PHP 8 runtime and run dependency, migration, and PHPUnit baselines.
3. Recover or reconstruct the exact front-end build manifest/package lock before adding camera-scanner dependencies.
4. Obtain a sanitized production schema/data profile: MySQL version, counts by variation/location, duplicate manufacturer serial candidates, open purchases/transfers, and active Repair records.
5. Decide the cutover policy for existing serialized stock and whether old Repair jobs must migrate.
6. Back up and rehearse all schema/data changes on a production-like copy.

## 19. Core files to avoid or touch minimally

Avoid broad edits to:

- `app/Utils/ProductUtil.php` — central aggregate stock mutations and reporting.
- `app/Utils/TransactionUtil.php` — sales, payments, cost mapping, receipts, and reports.
- `app/Http/Controllers/SellPosController.php` — highly coupled POS finalization.
- `app/Http/Controllers/PurchaseController.php` — purchase/payment/stock receipt orchestration.
- `app/Http/Controllers/StockTransferController.php` — paired transfer transactions and costing.
- `app/Http/Controllers/StockAdjustmentController.php` — aggregate reductions and cost mapping.
- `app/Transaction.php`, `app/PurchaseLine.php`, `app/TransactionSellLine.php` — shared core models.
- core transaction, purchase-line, sell-line, and variation-location migrations — extend with new migrations; never rewrite history.

Allowed minimal changes must be explicitly justified integration seams: guarded module callbacks/events, device identifiers posted with POS lines, and a stock-operation context that lets Recommerce validate atomic unit assignments. The preferred implementation home is `Modules/Recommerce`.
