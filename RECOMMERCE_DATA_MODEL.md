# SaverBro Recommerce OS Data Model

Status: logical schema for review; names and types must be validated against the provider-containment baseline and production MySQL version

## 1. Conventions

- Tables use `recommerce_` prefix and live in `Modules/Recommerce/Database/Migrations`.
- Primary keys: unsigned bigint unless compatibility profiling requires the core integer size for direct foreign keys.
- All domain tables contain `business_id`; foreign keys to core rows are always revalidated for the same business.
- Monetary values: `decimal(22,4)` in business base currency, matching core conventions; retain source currency and exchange rate where applicable.
- Human codes are immutable after issuance and case-normalized.
- Mutable aggregates (`device`, `repair_job`, `quote`) contain `lock_version` for optimistic locking.
- Append-only rows are corrected with reversal/supersession links, not destructive edits.
- Use database checks where supported and duplicate validation in the application regardless; the production MySQL version is not yet known.
- Avoid Eloquent morphs for integrity-critical financial/device links; use explicit nullable FKs with an exactly-one-target invariant.

## 2. Core entity relationship

```text
products ── variations ── variation_location_details       contacts
   │            │                    │                         │
   └────────────┴──── recommerce_devices ── ownership_periods ┘
                              │
            ┌─────────────────┼──────────────────┐
            │                 │                  │
      device_identifiers  device_movements  repair_jobs
            │                                    │
       qr_tokens                       ┌──────────┼───────────┐
                                      │          │           │
                               diagnostics   actions   repair_quotes
                                      │          │           │
                                  observations  part_usage  quote_lines
                                                   │
                      transactions / purchase_lines / sell_lines
                                                   │
                         recommerce_cost_entries + recommerce_events
```

## 3. Device registry tables

### `recommerce_devices`

One row per physical device across its complete life.

| Column | Purpose |
|---|---|
| `id` | Internal FK only |
| `business_id` | Tenant boundary |
| `device_uuid` | Immutable UUID/ULID for internal correlation/export |
| `device_code` | Human ID, e.g. `SB-LAP-000182` |
| `category_code` | Label prefix/category snapshot such as `LAP`; not the product category FK |
| `ownership_kind` | `BUSINESS` or `CUSTOMER` |
| `current_owner_contact_id` | Required for customer-owned; nullable for business-owned |
| `custody_kind` | `SUPPLIER`, `LOCATION`, `IN_TRANSIT`, `CUSTOMER`, `LOST`, `UNKNOWN` |
| `current_location_id` | Required when custody is at a SaverBro location |
| `product_id`, `variation_id` | Required for business-owned stock participation; nullable during customer repair |
| `lifecycle_state` | Current device workflow summary |
| `stock_participation` | `NONE`, `ON_HAND`, `RESERVED`, `IN_TRANSFER`; sold/retired are `NONE` |
| `grade_id` | Current grade assignment, nullable |
| `specifications_json` | Versioned flexible device attributes, non-secret |
| `manufacturer_serial_display` | Optional display cache; authoritative identifiers are in child table |
| `acquired_at`, `sold_at`, `retired_at` | Useful lifecycle timestamps; history remains event-based |
| `lock_version` | Optimistic concurrency |
| `created_by`, `updated_by`, timestamps | Operator provenance |

Indexes/constraints:

- unique `(business_id, device_code)`;
- unique `device_uuid` globally;
- indexes on `(business_id, variation_id, current_location_id, stock_participation)` and `(business_id, lifecycle_state)`;
- FK product/variation/location/contact/user;
- variation must belong to product;
- customer ownership implies no stock participation;
- on-hand business device implies variation and current location;
- do not soft-delete devices; retire them.

### `recommerce_device_identifiers`

Supports manufacturer serial, service tag, IMEI, asset tag, MAC where justified, and legacy aliases.

| Column | Purpose |
|---|---|
| `device_id`, `business_id` | Parent and tenant |
| `identifier_type` | `MANUFACTURER_SERIAL`, `IMEI`, `SERVICE_TAG`, `LEGACY_SERIAL`, etc. |
| `raw_value_encrypted` | Optional encrypted exact display value for sensitive identifier classes |
| `normalized_value` | Uppercase/trim/punctuation-normalized exact matching value |
| `normalized_hash` | HMAC/SHA-256 lookup where raw value should not be indexed |
| `issuer` | Manufacturer/network/source context |
| `is_verified`, `verified_at`, `verified_by` | Duplicate-control evidence |
| timestamps | Provenance |

Unique `(business_id, identifier_type, normalized_hash)`. Unknown/placeholder identifiers such as `N/A`, `UNKNOWN`, or all zeroes must be stored as null/notes, never as identifiers. Duplicate detection is tenant-wide; a manager merge/review workflow resolves suspected duplicates rather than bypassing uniqueness.

### `recommerce_device_ownership_periods`

| Column | Purpose |
|---|---|
| `device_id`, `business_id` | Device and tenant |
| `owner_kind` | `BUSINESS` or `CONTACT` |
| `contact_id` | Required for `CONTACT` |
| `starts_at`, `ends_at` | Non-overlapping interval |
| `acquisition_transaction_id`, `sale_transaction_id` | POS evidence where relevant |
| `reason` | purchase, sale, trade-in, correction |
| `recorded_by` | Operator |

Only one open period per device. Ownership change locks the device and current period.

### `recommerce_device_attribute_snapshots`

Optional but recommended for significant immutable snapshots (intake, post-repair, listing, sale): `device_id`, `snapshot_type`, `schema_version`, `attributes_json`, `captured_at`, `captured_by`, `source_repair_job_id`.

## 4. QR/token and label tables

### `recommerce_scan_tokens`

| Column | Purpose |
|---|---|
| `business_id` | Tenant |
| `subject_type` | `DEVICE` or `REPAIR_JOB` only in Alpha |
| `device_id`, `repair_job_id` | Exactly one target |
| `token_hash` | SHA-256/HMAC of random 128+ bit token; unique |
| `token_hint` | Last few characters for support, never sufficient to resolve |
| `status` | `ACTIVE`, `REVOKED`, `REPLACED` |
| `issued_at`, `revoked_at`, `replaced_by_id` | Lifecycle |
| `issued_by`, `reason` | Audit |

Only one active primary token per subject. Plain token is returned once at issue/print time; it is not stored.

### `recommerce_label_jobs`

Tracks single and batch rendering/printing attempts: `business_id`, `job_uuid`, `label_type`, `format` (`HTML`/`PDF`), `template_version`, `requested_by`, `status`, `item_count`, `request_json`, `output_path`, `expires_at`, `error_code`, timestamps.

### `recommerce_label_job_items`

`label_job_id`, target device/job, token issuance/version, ordinal, `status`, and error. Unique per job and target. A retry creates a new attempt while preserving issuance evidence.

## 5. Serialization and stock-link tables

### `recommerce_serialization_profiles`

One row per variation: `business_id`, `product_id`, `variation_id`, `mode` (`NOT_SERIALIZED`, `LEGACY_MIXED`, `TRACKED_REQUIRED`), `effective_at`, `configured_by`, `version`, timestamps. Unique `(business_id, variation_id)`.

### `recommerce_legacy_stock_balances`

Temporary cutover exception: `profile_id`, `location_id`, `legacy_unserialized_qty`, `last_reconciled_at`, `reason`. Must reach zero before `TRACKED_REQUIRED`.

### `recommerce_device_purchase_assignments`

Binds one device to acquisition evidence: `device_id`, `transaction_id`, `purchase_line_id`, `unit_ordinal`, `unit_acquisition_cost`, `landed_allocation`, `assigned_at`, `assigned_by`. Unique `device_id`; unique `(purchase_line_id, unit_ordinal)`. The count per purchase line cannot exceed received quantity.

### `recommerce_device_sale_assignments`

`device_id`, `transaction_id`, `sell_line_id`, `assigned_at`, `assigned_by`, `reversed_at`, `return_transaction_id`. An active unique device assignment prevents double sale. For tracked lines, active assignment count equals line quantity.

### `recommerce_device_movements`

Append-only custody/stock movement:

- device/business;
- `movement_type`: receive, relocate, transfer dispatch, transfer receipt, sale, sale return, trade-in, warranty intake, customer return, write-off, correction;
- `from_custody_kind/location_id`, `to_custody_kind/location_id`;
- linked core transaction, purchase/sell/adjustment line, transfer parent;
- command/correlation key;
- occurred/recorded time and actor;
- reversal link and reason.

Unique source/idempotency link prevents duplicate movements.

### `recommerce_stock_commands`

Command receipt/idempotency table: `business_id`, `command_uuid`, `command_type`, request hash, actor, status, result JSON, started/completed time. Unique `(business_id, command_uuid)`. Reusing a UUID with a different request hash is rejected.

## 6. Repair core tables

### `recommerce_repair_jobs`

| Column | Purpose |
|---|---|
| `business_id`, `job_uuid`, `job_code` | Tenant and permanent identity, e.g. `RPR-2026-000182` |
| `job_type` | `INTERNAL_REFURBISHMENT` or `CUSTOMER_REPAIR` |
| `device_id` | Required canonical device |
| `customer_contact_id` | Required for customer repair |
| `intake_location_id` | Receiving branch |
| `current_location_id` | Current custody location |
| `status` | Repair state machine state |
| `resolution_code` | Nullable outcome: `COMPLETED`, `CANCELLED`, `DECLINED`, or `UNREPAIRABLE` |
| `priority` | Configurable small set |
| `reported_problem` | Customer/staff statement |
| `intake_condition_json` | Structured condition snapshot |
| `access_required` | Boolean; never store a device password here |
| `assigned_technician_id` | Current assignee cache |
| `expected_at`, `received_at`, `ready_at`, `closed_at` | Workflow dates |
| `sale_transaction_id` | Customer invoice/sale, nullable until billing |
| `parent_job_id` | Warranty/rework lineage |
| `lock_version` | Stale-update control |
| `created_by`, `updated_by`, timestamps | Audit |

Unique `(business_id, job_code)` and `job_uuid`. At most one active repair job of a conflicting type per device unless a manager records an override reason.

### `recommerce_repair_state_transitions`

Append-only: job, from/to state, reason code/text, actor, occurred time, command key, metadata. Unique command key; application validates allowed transition and preconditions.

### `recommerce_repair_assignments`

Assignment history: job, technician, assigned/unassigned timestamps, assigned_by, reason. The job's current technician is a cache of the open assignment.

### `recommerce_repair_access_secrets`

Optional only if SaverBro chooses managed credential capture. Contains job, encrypted secret payload, one-time reveal limits, expiry, created/viewed/deleted audit, and key version. Default Alpha policy is **do not store**; use customer-present unlock, temporary code written by customer and immediately destroyed, or a customer-managed reset workflow.

### `recommerce_repair_accessories`

Job, accessory type, quantity, description, condition, received/returned flags/times, actor. This prevents a free-text list from losing collection accountability.

## 7. Diagnostics and grading

### `recommerce_diagnostic_templates`

Business-scoped, versioned template metadata: device category, name, version, status, schema JSON, created/published by. Published versions are immutable.

### `recommerce_diagnostic_sessions`

Job/device, template/version snapshot, stage (`INTAKE`, `DIAGNOSIS`, `POST_REPAIR_QC`, `GRADING`), status, technician, start/completion, notes.

### `recommerce_diagnostic_observations`

One row per check:

- session, stable `check_key`, label snapshot, group/order;
- result (`PASS`, `FAIL`, `NOT_TESTED`, `NOT_APPLICABLE`, `INFO`);
- `value_type`, typed numeric/text/boolean values, unit;
- structured `value_json` for bounded complex values;
- fault severity, notes, evidence media reference;
- timestamps and actor.

Battery fields are checks such as `battery.design_capacity_mwh`, `battery.full_charge_capacity_mwh`, `battery.health_percent`, and `battery.cycle_count`, not nullable columns on the device table. Frequently queried values may be projected/cache columns after evidence shows a need.

### `recommerce_grading_schemes`, `recommerce_grades`, `recommerce_grade_assignments`

Schemes and criteria are business/category scoped and versioned. An assignment records device, scheme/version, grade, scoring snapshot, diagnostics source, grader, time, override reason, and superseded assignment. The current device `grade_id` is a cache.

## 8. Repair work, parts, and quotes

### `recommerce_faults`

Job/session, fault code, component, severity, description, detected/resolved times, resolution action, actor.

### `recommerce_repair_actions`

Job, action type (`PART_REPLACEMENT`, `LABOUR`, `CLEANING`, `SOFTWARE`, `OUTSOURCED`, `OTHER`), title, description, status, technician/vendor contact, planned/started/completed times, labour minutes, QC required, source fault.

### `recommerce_part_usages`

Job/action, product/variation/location, requested/reserved/installed/billed/returned/written-off quantities, status, unit estimated cost, source sale line or stock-adjustment line, reservation timestamps, installed by/at, idempotency key. Do not duplicate product names/prices except immutable snapshots needed for audit.

### `recommerce_repair_quotes`

Job, sequential version, status (`DRAFT`, `ISSUED`, `APPROVED`, `DECLINED`, `SUPERSEDED`, `EXPIRED`), totals/tax snapshot, issued/valid dates, created/issued by, supersedes quote. Unique `(job_id, version)`; issued versions immutable.

### `recommerce_repair_quote_lines`

Quote, line type, linked product/variation or service product, description snapshot, quantity, unit price, estimated unit cost, tax/discount snapshot, recommended/optional flag, order.

### `recommerce_repair_approvals`

Quote, decision, evidence method (`IN_PERSON_SIGNATURE`, `VERBAL_RECORDED`, `PHONE_RECORDED`, `LINK`, `PORTAL`, `OTHER`), customer/recording staff, timestamp, evidence reference/hash, IP/user agent only when relevant, notes. Approval is never overwritten. Any quoted commercial change creates a new version and requires new approval.

### `recommerce_qc_sessions`

Job, diagnostic template/version, independent checker (policy dependent), result, start/completion, failure reason, and linked diagnostic session. A failed QC returns the job to repair with a transition.

### `recommerce_repair_warranty_entitlements`

Original job/device/customer, coverage start/end, policy/warranty reference, covered scope JSON, status. A claim creates a new linked repair job; it does not rewrite the closed original job.

## 9. Cost and profitability

### `recommerce_cost_entries`

| Column | Purpose |
|---|---|
| `business_id` | Tenant |
| `device_id`, `repair_job_id` | Exactly one target |
| `category` | Acquisition, logistics, part, labour, outsourced, other, adjustment |
| `status` | `ESTIMATED`, `POSTED`, `REVERSED` |
| `amount`, `currency_id`, `exchange_rate`, `base_amount` | Financial value |
| `quantity`, `unit_cost` | Optional trace |
| `cost_method` | Actual purchase layer, standard labour rate, supplier invoice, manual |
| source core transaction/line/payment or repair action/part usage | Provenance |
| `source_key` | Idempotency |
| `reversal_of_id` | Compensating entry |
| `occurred_at`, `posted_at`, `created_by` | Timing/audit |

Unique `(business_id, source_key)`. Profit views use only `POSTED` entries net of reversals. Estimated values remain available for quote variance analysis.

## 10. Timeline, audit, and integration

### `recommerce_events`

Permanent append-only event: business, event UUID/type/version, device/job, actor, location, occurred/recorded time, correlation UUID, causation UUID, command UUID, payload JSON, optional previous-event hash. Unique event UUID and source/command key.

### `recommerce_outbox_messages`

Event reference, topic, payload JSON, status, attempts, available/processed time, last error. Optional PDFs, emails, future WhatsApp/portal/mobile events consume this table after commit.

### `recommerce_reconciliation_runs` and `recommerce_reconciliation_issues`

Run metadata and immutable issue snapshots: scope, variation/location/device, POS quantity, tracked count, legacy count, difference, severity, detected/resolved times, resolution evidence. Resolution never silently changes stock.

## 11. State constraints

Minimum database/application invariants:

1. Device code and active QR resolve to exactly one subject.
2. Manufacturer/service identifier is unique per tenant/type when present.
3. One active ownership period and one current custody state per device.
4. Customer-owned device has no stock participation.
5. Tracked on-hand device has one variation and one location.
6. One active sale assignment per device.
7. One movement command cannot post twice.
8. Repair job type matches device ownership at job intake.
9. Customer job has customer contact; internal job does not require one.
10. Issued quote is immutable; one currently approved commercial version.
11. Installed/billed/returned quantities never exceed requested/reserved bounds.
12. A part usage has exactly one inventory posting strategy/source.
13. Cost entry targets exactly one device/job and source key is unique.
14. Reversal references an earlier non-reversed row and has opposite amount.
15. Tracked device count reconciles to POS quantity plus approved legacy balance.

## 12. Identifier normalization

- Device/job codes: trim, uppercase, remove surrounding whitespace; preserve internal hyphens.
- Manufacturer serial/service tag: Unicode normalize, trim, uppercase, remove separators only according to identifier type; never apply a transformation that can collapse legitimately different serials without a raw-value review.
- QR URLs: canonical host/path, HTTPS, no query tracking parameters in printed payload.
- Scanner input: cap length, reject control characters except scanner terminator, and never run partial/fuzzy matching for mutation.
