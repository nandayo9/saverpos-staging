# SaverBro Recommerce OS — Migration and Cutover Plan

## 1. Migration posture

This is an additive, reversible-by-feature-flag migration. Ultimate POS remains operational authority for catalog, aggregate stock, purchases, sales, transfers, adjustments, payments, contacts, and accounting.

Do not rewrite historical POS transactions, retroactively invent unit identities, or enable tracked-device blocking until reconciled baselines and rollback procedures are proven.

## 2. Gate 0 — contain the provider integration and prove the source baseline

Blocking before Recommerce production enablement:

1. Prevent the unavailable provider `Modules/Repair` source/cache/routes from becoming an accidental dependency of `Modules/Recommerce`.
2. If legacy Repair data exists, preserve it through an approved read-only snapshot and separate migrate/archive/deep-link decision; provider source recovery is required only where needed for that legacy decision.
3. Restore a runnable PHP/Composer/database/Node toolchain matching the deployed environment.
4. Install dependencies from lock files in an isolated environment; record missing `package.json`/asset-source disposition.
5. Run migrations against a disposable copy, application smoke checks, dependency/security audit, and current tests.
6. Inventory production-specific customizations, cron/queue, storage, modules, database engine/version/collation, and deployment topology.

The current local package cannot provide runtime proof because PHP is unavailable and module sources are incomplete.

## 3. Data discovery

On a sanitized production database copy, profile by business and location:

- products/variations with `enable_sr_no`, lot tracking, SKUs, and likely serialized categories;
- `variation_location_details.qty_available`, negative/fractional quantities, and open transfers;
- purchase lines, sale lines/free-text serial notes, lot links, returns, adjustments, reservations, and stock history;
- duplicate/invalid manufacturer identifiers in any notes/custom fields;
- legacy provider Repair jobs/statuses/devices/warranties/payments/uploads if present in the approved snapshot;
- contact duplicates and customer device descriptions;
- current reference-count collisions and business prefixes;
- volumes, peak receiving/sale concurrency, printer/scanner/browser inventory.

Produce a signed data-discovery report. Free-text serial notes are hints, not automatically trusted device identities.

## 4. Schema rollout

### Phase A — foundation, inactive

- module tables, permissions, policies, events/outbox, idempotency commands;
- canonical devices, identifiers, ownership/custody, tokens, label manifests;
- serialization profiles and legacy-unserialized balance;
- jobs/diagnostics/parts/cost model;
- indexes and constraints created with production-safe strategy.

No core business path changes. Feature flags default off.

### Phase B — read-only/backfill

- generate candidate mappings and exception queues;
- expose authorized read-only registry/reconciliation views;
- import only reviewed identities;
- do not affect POS stock.

### Phase C — one-location Alpha write path

- one approved tracked category/location;
- receive new units through Recommerce;
- print/scan/reconcile vertical slice;
- prohibit undocumented alternate receipt paths for that scoped profile;
- daily reconciliation and rapid rollback flag.

### Phase D — sales/transfers/repair

Enable only after their complete path, tests, training, and reconciliation controls pass. Expand category/location in controlled cohorts, not all at once.

## 5. Legacy inventory strategy

For each business/location/variation choose and record one profile:

- `NOT_SERIALIZED`: ordinary POS aggregate only;
- `LEGACY_MIXED`: tracked devices plus an explicit nonnegative `legacy_unserialized_qty` must equal POS aggregate;
- `TRACKED_REQUIRED`: every on-hand unit has an eligible canonical device.

Initial equation:

`legacy_unserialized_qty = POS qty_available - verified tracked on-hand devices`

Block activation when the result is negative, fractional for unit-only goods, or cannot be explained. The baseline stores approver, evidence, as-of time, POS snapshot, and checksum.

Legacy units become tracked only through a controlled identify-and-convert command that reduces legacy balance and creates exactly one on-hand device without changing POS aggregate. Never mass-generate fake serials or placeholder identifiers.

## 6. Identity backfill and deduplication

1. Normalize candidate identifier type/value using versioned rules.
2. Group conflicts within business and classify exact duplicate, likely formatting duplicate, reused component/board, or insufficient evidence.
3. Match using strong provenance: purchase/sale line, physical scan, label/photo, or existing repair source.
4. Create candidate mappings in staging tables/reports.
5. Human reviewer approves each conflict resolution.
6. Import idempotently with immutable source references and event.
7. Keep ambiguous items as legacy-unserialized or customer-device candidates until physically verified.

Do not infer identity merely from product, customer, date, or identical free-text note.

## 7. Existing Repair module migration

This plan is conditional on the legacy-data profile, not on provider source recovery for new SaverBro capability. If legacy Repair records exist, the required mapping document must compare:

- repair/job identity and status history;
- device model/serial representation;
- customer/contact linkage;
- invoice/subtype/payment linkage;
- parts and labor representation;
- warranty, documents/uploads, notes, technicians, and permissions.

Preferred outcomes in order:

1. migrate losslessly into shared Recommerce model with source keys;
2. keep historical jobs read-only with deep links while new jobs use Recommerce;
3. archive/export only if licensing/technical limits make runtime retention unsafe.

Never run both modules as writable authorities for the same repair job/device.

## 8. POS integration deployment

Introduce core changes narrowly behind module-enabled and serialization-profile checks:

- generic preflight/guard before tracked purchase/sale/adjustment/transfer posting;
- reliable after-commit events where available;
- transfer status integration currently missing;
- stable per-line correlation/device assignment input;
- exception propagation that rolls back the POS transaction.

Deploy guards inactive first and log “would block” results. Compare false positives before enforcement.

## 9. Cutover runbook

For each cohort:

1. Announce scope, freeze profile/config changes, and verify backup/restore point.
2. Clear or account for open purchases, sales, transfers, adjustments, and repair work affecting cohort.
3. Capture aggregate stock and source-record snapshot.
4. Complete physical count and verified-device scan.
5. Approve legacy balance and exception disposition.
6. Activate feature flags/profile at a recorded time.
7. Run one receive-label-scan and, when enabled, one sale/transfer/repair proof with assigned roles.
8. Reconcile immediately and at end of day.
9. Monitor errors, idempotency retries, outbox, latency, and alternate-path attempts.
10. Approve continuation or invoke rollback criteria.

## 10. Rollback

Feature rollback disables new Recommerce commands and returns scoped variations to a safe `LEGACY_MIXED` operational mode only after reconciling posted POS effects. It does not delete device, movement, job, cost, or event history.

Rollback criteria include unresolved stock mismatch, duplicate device sale/transfer, unacceptable checkout/receiving failures, authorization leak, or unavailable label/scan path without safe manual operation.

Data rollback rules:

- unposted drafts may be voided;
- posted financial/stock records use explicit POS reversals;
- schema stays in place until retention/export and compatibility are resolved;
- database restore is disaster recovery, not normal feature rollback, because it may erase valid POS activity.

## 11. Reconciliation during migration

Automated checks per variation/location:

- POS aggregate = eligible tracked on-hand + legacy-unserialized balance;
- one active custody/location per device;
- business-owned sellable device has catalog variation and on-hand movement;
- sold device links one finalized sale assignment;
- transfer manifest/device states match POS transfer legs;
- internal consumed parts link adjustment and cost;
- customer billed parts link finalized sale line;
- no installed-pending-billing parts on closed job;
- outbox and projection checkpoints are current.

Daily signed exception reports are required through Alpha; severity-one mismatches stop cohort expansion.

## 12. Testing layers

- migration tests from representative anonymized database snapshots;
- schema forward migration and downgrade/feature-disable rehearsal;
- unit/property tests for normalization and state machines;
- transaction/concurrency tests against the deployed database engine;
- cross-business/location permission tests;
- full browser role flows;
- real scanner, camera, label printer, PDF, and damaged-label tests;
- POS purchase/sale/return/adjustment/transfer/payment regression;
- volume/performance and lock-contention tests;
- backup restore and disaster recovery exercise.

HTTP success or migration completion is not acceptance; rendered UI, persisted records, stock equality, and role denial must be observed.

## 13. Operational readiness

Before Alpha:

- named product owner, inventory owner, repair owner, security owner, and rollback commander;
- trained role-specific users and quick-reference procedures;
- supported hardware/browser list and spare labels/scanner contingency;
- monitoring/alerting, queue worker, scheduler, backups, key management, and log retention;
- support escalation and incident playbooks;
- privacy notices/retention approved;
- known limitations and manual fallback published;
- acceptance evidence stored per milestone.

## 14. Acceptance evidence per cohort

Capture exact date/time, build/commit, module/source hashes, database version, feature flags, business/location/category scope, user roles, devices/quantities, scanner/printer/browser models, test results, reconciliation before/after, screenshots or recordings, exceptions, and named approval.

## 15. Explicit non-actions

- Do not access or change a production instance during architecture work.
- Do not enable the currently absent Repair module by assumption.
- Do not drop/rename core tables or mass-edit historical transactions.
- Do not treat free-text serial fields as a reliable registry.
- Do not turn on tracked enforcement for unreconciled stock.
- Do not delete historical evidence during rollback.
