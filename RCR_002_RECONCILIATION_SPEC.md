# RCR-002 — Read-Only Reconciliation Specification

**Status:** `PREPARED — NOT EXECUTED`  
**Prepared:** 2026-08-27  
**Purpose:** Define the checks to run against an approved sanitized Ultimate POS snapshot.

## Safety boundary

This is a query specification, not a production-access instruction. Execute only against an approved read-only or disposable sanitized database. Use bound parameters for all business, location, category, variation, and date values. Do not run write statements, migrations, seeders, repair commands, imports, or application actions as part of reconciliation.

Any legacy provider Repair schema must be discovered before a legacy Repair-specific query is treated as valid. SaverBro-owned Repair tables are queried only after their migrations and runtime baseline are proven. Each query must first confirm that its referenced table and column exist.

## 1. Required parameters

| Parameter | Meaning |
|---|---|
| `:business_id` | Approved business surrogate ID |
| `:location_id` | Approved Alpha location surrogate ID |
| `:category_id` | Approved category, if category scope is used |
| `:variation_ids` | Approved variation surrogate-ID set |
| `:as_of` | Snapshot timestamp, including timezone |
| `:from_date` | Start of reconciliation window |
| `:to_date` | End of reconciliation window |

No parameter value is available in the current source-only checkout.

## 2. Query execution order

### Q00 — Schema and snapshot preflight

Record database engine/version, schema revision, table count, migration history, timezone, collation, and read-only connection identity. Confirm that `products`, `variations`, `variation_location_details`, `transactions`, `purchase_lines`, `transaction_sell_lines`, and relevant transfer/adjustment tables exist before running dependent checks.

**Stop condition:** missing core table, unknown migration revision, or write-capable connection.

### Q01 — Approved business/location scope

Return only the approved business and location surrogate IDs, names, active flags, and parent relationships. Confirm that the selected location belongs to the selected business and that the category/variation scope belongs to the same business.

**Checks:** no cross-business IDs; no inactive location silently included; scope count recorded.

### Q02 — Product and variation identity extract

Extract the scoped product and variation records with:

- product and variation surrogate IDs;
- product name, SKU, variation sub-SKU, category, brand, and active/inactive state;
- barcode/identifier fields confirmed from the actual schema;
- unit and stock-enabled flag;
- source row timestamps.

Do not assume `sku`, `sub_sku`, or any barcode column is globally unique until duplicate checks prove the actual business/location rule.

### Q03 — Current source stock

Read `variation_location_details` for the approved `:business_id`, `:location_id`, and `:variation_ids`, returning `variation_id`, `location_id`, `qty_available`, and timestamps. Join to product/variation identity from Q02.

**Outputs:** one row per scoped variation, zero-quantity rows retained, missing rows reported separately, decimal precision preserved.

### Q04 — Movement reconciliation

Using the approved source status semantics, aggregate stock movements in the selected window by variation and location from purchase lines, sale lines, returns, adjustments, and transfers. Preserve transaction IDs, references, type, subtype, status, transaction date, and line quantity in an audit extract.

Use the following control equation only after status semantics are confirmed:

`calculated_qty = opening_qty + purchases - sales + sales_returns - purchase_returns + adjustments + transfers_in - transfers_out`

Compare `calculated_qty` with Q03 `qty_available`; never overwrite either value. Report variance, not a silent correction.

**Stop condition:** any movement class cannot be mapped to a source table/status or any transfer side is missing.

### Q05 — Open transactions and exception inventory

Count open or unresolved sales, purchases, purchase returns, sales returns, stock transfers, stock adjustments, and deposits using the deployment's actual status definitions. Include legacy Repair only if Q00 confirms that its schema exists; include SaverBro-owned Repair only after its own Alpha schema is deployed in the disposable environment.

For every count, retain the status list and as-of timestamp. A count without status definitions is `UNQUALIFIED`.

### Q06 — Identifier duplicate/conflict scan

Normalize identifiers using a documented, non-destructive rule and report:

1. duplicate SKU/sub-SKU values within the approved business;
2. duplicate barcode values within the approved business/location scope;
3. duplicate serial/IMEI values where the recovered schema contains them;
4. one physical identifier mapped to multiple variations/devices;
5. multiple identifiers on one record with conflicting normalized values;
6. missing identifiers on sellable or serialized stock.

Keep raw values in the restricted evidence store and use surrogate sample IDs in the report.

### Q07 — Repair dependency probe

First verify whether legacy provider Repair tables/data exist in the approved snapshot. If present, inventory their status values, device/job keys, transaction links, serial fields, deposits, attachments, and open/closed semantics. Separately inventory any deployed SaverBro-owned Repair tables. Compare Repair-linked stock movements with Q04 to identify double counting or unlinked balances.

**Current result:** not executable; provider source and live legacy Repair schema are absent from the checkout. No legacy schema is inferred from status/cache files.

### Q08 — Reconciliation output and sign-off

Produce:

- snapshot manifest and file hashes;
- scoped product/variation extract;
- current stock extract;
- movement extract;
- open-transaction extract;
- duplicate/conflict sample;
- Repair exception register;
- variance register with owner and disposition;
- reviewer sign-off and explicit approved exceptions.

## 3. Acceptance thresholds

RCR-002 cannot be marked accepted unless:

- snapshot date, timezone, schema revision, corpus, exclusions, and hashes are recorded;
- one approved Alpha location/category/variation scope is identified;
- current source stock is present for the full scope;
- all movement classes reconcile or have explicitly approved exceptions;
- duplicate/conflict counts and a representative sample are reviewed;
- open transactions and Repair exceptions are quantified;
- scanner terminator and printer/label checks are witnessed separately;
- the users, location, and operating window are approved.

Any unverified item remains `BLOCKED`, not `PASS`.

## 4. Current execution result

| Check | Result |
|---|---|
| Query execution | `NOT RUN` |
| Database connection | `NOT PROVIDED` |
| Production access | `NOT REQUESTED` |
| Sanitized snapshot | `MISSING` |
| Alpha scope | `MISSING` |
| Repair schema/source | `MISSING` |
| RCR-002 status | `BLOCKED` |

This specification adds no runtime behavior and does not alter application code, schema, data, permissions, module status, or assets.
