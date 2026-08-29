# SaverBro Recommerce OS — Terra Architecture and Implementation Review

**Review date:** 2026-08-27  
**Scope:** architecture package and the supplied Ultimate POS 7.3 source checkout. No production database, web runtime, scanner, printer, or signed-in environment was accessed. This is an implementation review, not a claim of production state.

## 1. Executive assessment

The proposed direction is **technically sound with material Alpha simplification and one missing integration decision**. A Recommerce Laravel module is the correct boundary. Ultimate POS should remain the authority for catalogue, aggregate quantity, purchase/sale/transfer transactions, accounting, tax, invoices, payments, and FIFO/LIFO allocation. Recommerce should add the physical-unit registry, secure scan identity, custody, traceability, and a reconciled device subledger.

The design is strongest where it keeps a laptop distinct from a product variation and treats QR as an opaque resolver rather than as embedded device data. It is too broad where it builds generic eventing, generic command infrastructure, batch-print machinery, camera support, and scale controls before proving one physical receive-label-scan workflow.

The critical missing decision is the **purchase-receiving integration contract**. `PurchaseCreatedOrModified` is emitted inside the core purchase transaction, but it carries a transaction, not a prepared list of device identities. The current source has no purchase-form module hook comparable to the product-form hooks. Therefore the proposed event alone cannot safely turn an ordinary received purchase into exact devices. Alpha must choose and test one narrow entry point: either a Recommerce tracked-receiving screen/service that creates the core purchase using the existing utilities, or a small generic core extension that supplies a purchase form fragment and synchronous pre-commit callback. Do not assume this seam exists today.

**Repair ownership decision:** SaverBro will build and own the shared repair domain in `Modules/Recommerce`; it will not depend on recovering the vendor `Modules/Repair` source in order to create internal-refurbishment or customer-repair capability. The missing vendor module is a legacy-containment and cutover risk, not a design dependency. Do not create a second custom `Modules/Repair` clone merely to imitate unavailable vendor internals.

**Verdict:** proceed after a short readiness gate, then build a narrow Device + QR + received-purchase vertical slice. Do not build RC-007 and RC-008 as full platform abstractions first.

## 2. What is excellent and should remain unchanged

- **Modular monolith:** `Modules/Recommerce` preserves vendor upgradeability and keeps atomic POS/device changes in one database transaction. A microservice would make the first stock invariant harder, not safer.
- **Authority split:** the architecture correctly keeps `products`/`variations` as catalogue and aggregate inventory, and creates a first-class physical Device instead of one product variation per laptop.
- **Identity separation:** immutable staff-visible device code, opaque QR token, and manufacturer identifiers have different purposes. QR must never contain a database ID, IMEI, owner, location, cost, or repair details.
- **Physical-unit reconciliation:** `POS quantity = tracked on-hand devices + approved legacy untracked balance` is the right operating invariant for a serialized variation/location.
- **Ownership, custody, lifecycle, and stock participation are distinct concepts.** This prevents customer repair devices from becoming inventory simply because they are on the counter.
- **Reuse of core capabilities:** existing roles/location permissions, barcode/QR rendering, label geometry/PDF patterns, transactions, contacts, and normal service products should be reused rather than replaced.
- **Shared repair model:** one job engine with `INTERNAL_REFURBISHMENT` and `CUSTOMER_REPAIR` is the right long-term direction, provided its financial and ownership rules are mode-specific.

## 3. Source-verified corrections and limits

| Proposed/implicit assumption | What this checkout actually shows | Review consequence |
|---|---|---|
| Repair is definitely enabled and its data/workflow is known | `modules_statuses.json` sets `Repair: true`, but `Modules/Repair` does not exist. A cached `bootstrap/cache/repair_module.php` still names `Modules\\Repair\\Providers\\RepairServiceProvider`. `ModuleUtil::isModuleInstalled()` also requires a discovered module and a `system.repair_version` value, neither of which is available here. | The status/cache files are evidence of intended or stale configuration, not proof of a live installed Repair workflow. The cache must be cleared or regenerated only as part of the controlled baseline work. |
| Repair source is available somewhere in the checkout | There is no `Modules/Repair`; `public/modules/repair/js/app.js` and `sass/app.scss` are zero bytes. Core code references Repair controllers/entities. | The vendor source is unavailable. SaverBro's owned repair domain will live in `Modules/Recommerce`; recover vendor source only if a live deployment must migrate, preserve, or retire vendor Repair records/routes. |
| Repair tables/data exist in production | The dummy seeder writes `repair_device_models` and `repair_statuses`; the missing module would have owned migrations. | Seeder references are not production evidence. Profile a sanitized database before any migration/cutover claim. |
| Purchase event is a complete receiving extension seam | `PurchaseController::store()` opens a transaction, creates purchase lines, dispatches `PurchaseCreatedOrModified`, then commits. The event does not include scan/serial input and no purchase-form module callback was found. | Define a receiving seam before implementation; an after-the-fact listener is insufficient by itself. |
| Existing serial-number support can be extended into a registry | `enable_sr_no` exposes `transaction_sell_lines.sell_line_note`; it has no per-unit purchase identity, uniqueness, custody, or stock binding. | Do not build on this field. It remains invoice annotation only. |
| Existing labels can make per-device labels by repetition | The label controller repeats variation-level details by requested quantity. It does provide reusable geometry, browser print and mPDF patterns. | Reuse rendering patterns/libraries only; make a Recommerce label view fed by individual Device rows. |
| All transaction mutations have a usable module hook | Sale has `after_sale_saved` inside its transaction. Purchase exposes an event. Transfer status update has no equivalent dispatch path identified by the package. | Serialized transfers need a later, deliberately minimal core seam/guard. |
| A clean front-end build can be assumed | `composer.json` exists, but this checkout has no root `package.json`, lockfile, or yarn lock; PHP is also unavailable on this review host. | Camera-scanner dependencies and build claims are blocked until the reproducible baseline is recovered. |

## 4. Repair module blocker classification

### BLOCKER FOR REPAIR ONLY

The missing vendor source does **not** block implementation of SaverBro's owned repair module. The owned shared job domain will be part of `Modules/Recommerce`, use the canonical Device, and keep Ultimate POS as the accounting/invoice/stock authority.

It blocks only a **legacy Repair cutover**: disabling/replacing a live vendor Repair module, migrating its open jobs/data/files, or preserving its repair routes/receipts/customer status workflow. Core references prove that the application was designed to call `Modules\\Repair` for repair transactions, receipts, and redirects. Without a production data profile—and, where preservation is required, the matching vendor source—those actions could strand existing jobs, status history, attachments, quotes, permissions, or customer status pages.

The Device + QR + receiving pilot remains independent. The owned repair build may also proceed after that slice, provided the deployment baseline explicitly prevents the unavailable vendor provider/cache/routes from loading. It must not touch legacy `sub_type='repair'`, legacy repair tables, receipt fields, or feature flags until a separate cutover decision is approved.

## 5. Recommended canonical Device architecture

Keep one durable `recommerce_devices` row per physical laptop/device. It may outlive one or more ownership cycles. It must not be a product, variation, purchase line, sell line, or repair job.

| Concern | Canonical source | Alpha rule |
|---|---|---|
| Catalogue / aggregate stock | Ultimate POS `products`, `variations`, `variation_location_details` | A business-owned on-hand device references exactly one variation and one Ultimate POS location. Never create a variation per unit. |
| Physical identity | `recommerce_devices` + `recommerce_device_identifiers` | Device has immutable code; strong manufacturer identifiers use normalized comparison plus an immutable display/provenance record. Unknown/placeholder values are not identifiers. |
| Legal/economic ownership | dated device ownership periods | `BUSINESS` or a core `contacts` customer. A customer-owned device is never stock-participating. |
| Operational custody/location | current device custody projection plus append-only Device movements | Location is where the physical item is held, not who owns it. Keep customer return/collection custody distinct from branch stock. |
| POS evidence | purchase and sale assignment tables | At most one active device-to-sale assignment; a purchase assignment must not exceed the source received quantity. |
| Cost | append-only Recommerce cost entries, sourced from core transaction/line evidence | Not required to calculate Alpha receipt reconciliation; required before internal refurbishment profitability. |
| Repair | `recommerce_repair_jobs` later | A job references an existing Device; it does not become the Device identity. |

Retain a simple current-state projection on Device for fast scans, but make Device movement/event rows the evidence. The projection must be updated inside the same transaction; it is not an independent ledger. In Alpha, avoid a large free-form lifecycle state machine. Use only states necessary to route `ON_HAND` received devices safely, plus explicit `SOLD`/`RETIRED` placeholders. Add diagnostic/QC states with the repair slice.

## 6. Recommended QR, barcode, print, and scan architecture

1. Create a permanent human code at Device creation, for example `SB-DV-<database-id>-<check>`. Use the inserted Device primary key plus a unique `(business_id, device_code)` constraint. Gaps after rollback are acceptable and safer than a new generic allocator. This replaces RC-010's separate sequence service for Alpha.
2. Issue a random 128-bit-or-greater token and store only a keyed hash, status, target Device, issuance metadata, and replacement relation. Print `https://approved-host/r/<token>` as the QR payload. The QR label remains permanent as a label; token rotation revokes it and requires a reprint.
3. Print Code128 for the staff device code and QR for phone/native-camera opening. Reuse the existing `milon/barcode`, QR helper, label dimensions, and browser/PDF patterns through a Recommerce template; do not modify `LabelsController` or overload product-label rows.
4. The public QR GET is a safe login/deep-link only: it returns no model, owner, location, job, or existence disclosure. After authentication it resolves under business/location policy and opens Device Detail. A scan never changes stock or state.
5. Alpha supports USB and Bluetooth keyboard-wedge scanners through one focused input handler, and manual entry through the same exact-match resolver. This requires no new browser library. Browser camera scanning is **needed soon**, but only after the missing asset build metadata, HTTPS deployment, dependency review, and actual phone/browser testing are complete.

## 7. Recommended POS integration boundaries

**Ultimate POS remains authoritative:** purchases, purchase lines, product/variation/location quantity, sales, sale lines, transfers, adjustments, invoices, tax, payments, cash registers, contacts, FIFO/LIFO mapping, and accounting.

**Recommerce owns:** Device identity, QR token/label issuance, device-to-core-line assignments, device custody/movement evidence, serialization profile, reconciliation snapshots, and later repair workflow/cost visibility.

For every tracked receipt, create or update the core purchase quantity and the Device assignments/movement/event in **one database transaction**. On any device validation or constraint failure, roll back the core purchase change. At the end, assert for the scoped variation/location:

`core qty_available = tracked on-hand device count + approved legacy balance`

Until tracked sale, transfer, and adjustment support exists, a tracked variation must be constrained to the approved receiving pilot path. Use the existing `after_sale_saved` hook to reject unassigned tracked sales before commit, and do not enable the profile where stock transfer/adjustment routes can be used without a documented temporary block. No silent divergence is acceptable.

The smallest acceptable core modification, only if a module-only receiving wizard cannot reuse the core flow, is a **generic, synchronous purchase extension contract**: a form fragment hook plus a pre-commit callback supplied with the Purchase transaction and validated module input. It must run inside the existing transaction, throw on invariant failure, make no Recommerce-specific decisions in core, and be covered by ordinary purchase regression tests. Do not patch `ProductUtil`, `TransactionUtil`, or stock arithmetic to carry device data.

## 8. Recommended repair architecture

Build one shared **SaverBro-owned** repair engine in `Modules/Recommerce` after the Device + QR slice. Both modes share Device, jobs, assignments, diagnosis, actions, parts usage, QC, evidence, and timeline. A later legacy-cutover record decides whether any vendor Repair records are read-only, mapped, migrated, or retained outside the owned module; it must not change the owned model's source-of-truth boundary.

| Internal refurbishment | Customer repair |
|---|---|
| Device is business-owned and is/will be stock-participating. | Device is customer-owned and never stock-participating. |
| Parts consumption posts through a controlled core stock adjustment and then writes a device cost entry from the linked source. | Parts and labour are normal Ultimate POS sale/service lines; revenue, tax, payment and invoice live only in Ultimate POS. |
| Outcome is QC then return to eligible on-hand inventory. | Outcome is QC, final billing/collection, then return to customer custody. |

Require mutual-exclusion rules: a customer-repair Device cannot be assigned a product/variation as inventory because it is in the workshop; an internal job cannot create customer-repair billing; only one authoritative writable repair job system may govern an existing Repair record. Keep cost/revenue ledger links, not a competing accounting ledger.

## 9. Core code boundaries

Do not broadly modify:

- `app/Utils/ProductUtil.php`
- `app/Utils/TransactionUtil.php`
- `app/Http/Controllers/SellPosController.php`
- `app/Http/Controllers/PurchaseController.php`
- `app/Http/Controllers/StockTransferController.php`
- `app/Http/Controllers/StockAdjustmentController.php`
- `app/Transaction.php`, purchase/sell models, FIFO/LIFO mapping tables, and core accounting/payment paths
- existing product label implementation in `LabelsController`

Use the module's routes, provider, policies, event listeners, views, services, and tables. Where the core has an explicit extension point, use it. Any core change needs a one-purpose contract, an inline rationale, a feature flag, a regression test of the ordinary flow, and an upgrade note. The probable future exceptions are a purchase integration contract and a transfer-status guard/event; neither is justified in the first QR-only implementation task.

## 10. Infrastructure classification

| Component | Classification | Concrete Alpha failure prevented / decision |
|---|---|---|
| Unique database constraints: Device code, active QR hash, strong identifier, active sale/purchase assignment | **MUST HAVE BEFORE ALPHA** | Duplicate identity or one unit linked twice. Validation alone races. |
| One narrow transactional receiving service, DB transaction, `FOR UPDATE` where it changes stock/device state, deterministic batch ordering | **MUST HAVE BEFORE ALPHA** | Partial POS/Device posting or two concurrent receipts claiming one identity. |
| Per-mutation idempotency receipt for tracked receive (key, request hash, stored outcome) | **MUST HAVE BEFORE ALPHA** | Browser/scanner retry creates a second receipt/device. This is a small table/service, not a generic command bus. |
| Immutable Device event/timeline row written in the same transaction | **MUST HAVE BEFORE ALPHA** | Cannot explain identity/receipt/label/custody changes. Existing activity log has generic semantics and retention cleanup. |
| Feature flag, approved variation/location cohort, disable/rollback procedure, reconciliation query | **MUST HAVE BEFORE ALPHA** | Uncontrolled exposure or undetected POS/device divergence. |
| Explicit business/location authorization on resolver, detail, receive, and print | **MUST HAVE BEFORE ALPHA** | Cross-branch disclosure/operation. |
| Opaque QR, hash-at-rest, exact resolver, safe public response, Code128 label | **MUST HAVE BEFORE ALPHA** | QR resolves wrong device or leaks device/customer data. |
| Optimistic `lock_version` on mutable Device | **NEEDED SOON** | Helpful for concurrent corrections/state updates; add with the first editable Device state, not as framework-wide infrastructure. |
| Full append-only cost ledger and detailed movement model | **NEEDED SOON** | Needed before refurbishment parts/costs and transfers; Alpha receive needs one receipt movement/evidence only. |
| Label print attempt/audit record | **NEEDED SOON** | Supports reprints and operational traceability; one simple issuance/reprint event is enough for the first label. |
| Camera scan fallback | **NEEDED SOON** | Operationally useful, but keyboard scanners/manual entry prove the workflow without unrecovered asset dependencies. |
| Transactional outbox for notifications/PDFs | **NEEDED SOON** | Needed when a failed external side effect must be retried. Do not create it to print an in-browser label or save a local Device event. |
| Generic command bus, generic command/version framework, generic scan router | **DEFER UNTIL SCALE / FUTURE PHASE** | Alpha has one receive command and one Device resolver. Extract only after two or more real flows share stable behavior. |
| Separate UUID/ULID abstraction | **DEFER UNTIL SCALE / FUTURE PHASE** | Use a Device bigint PK and a random immutable `device_uuid` column if channel-neutral correlation is needed. No abstraction layer is required. |
| Dead-letter management UI, generic notification adapters, queue topology | **DEFER UNTIL SCALE / FUTURE PHASE** | No external asynchronous side effect is in the first slice. |
| Generic state engine, configurable dual approval, generic workflow designer | **DEFER UNTIL SCALE / FUTURE PHASE** | Start with explicit service methods and policy rules; introduce approvals only for a defined financial/control risk. |
| Offline queue/native app/device telemetry/AI diagnostics | **DEFER UNTIL SCALE / FUTURE PHASE** | They introduce a different trust, conflict, privacy, and support model. |

RC-007 should therefore become **"add minimal immutable Device event writer"** before Alpha. Its outbox, retries, and dead-letter mechanics defer. RC-008 should become **"make tracked receiving idempotent and transaction-safe"** before Alpha. A generic command bus and broad optimistic-version infrastructure defer.

## 11. Major technical risks and missing dependencies

1. **Purchase seam not chosen:** this is the only architecture gap that can prevent an atomic first received-laptop workflow. Resolve it before coding the receiving service.
2. **Missing installed-module source:** all enabled module directories are absent, not only Repair. Establish a matching runnable source set and module versions before deployment testing.
3. **No production facts:** MySQL version/collation, real Repair tables, open transactions, serial duplicates, location quantities, custom code, jobs/queue, printer/scanner models and browser constraints remain unverified.
4. **Unprotected alternative stock paths:** sale, transfer, adjustment, returns, imports, and possibly other modules can mutate a tracked variation unless excluded or guarded. Scope Alpha to a controlled cohort and prove the block.
5. **Current core stock helpers lack this unit-level invariant:** Recommerce must lock/check its own rows and use existing core paths, not write `variation_location_details` directly.
6. **Hardware truth:** generated QR/Code128 is not enough. Prove decoded results from the exact printer stock, label size, USB/Bluetooth scanner, phone/browser, and user role.
7. **Legacy stock:** do not mass-create fake devices. Start with a new clean variation/location or record a signed legacy baseline; convert known units one at a time.
8. **Legacy Repair containment:** no vendor-repair data migration, route takeover, or `transactions.sub_type='repair'` integration is safe until the deployed state and open-record/data-file scope are profiled. This does not block the owned Recommerce repair domain.

## 12. Revised operational milestones

| Slice | Outcome | Entry/exit boundary |
|---|---|---|
| 0 — Release readiness | Proven source/runtime/module inventory, production-like profile, receiving seam decision, rollback plan | No business feature yet. |
| A — Safe Recommerce foundation | Inactive module, feature flag, scoped permissions, migration conventions, tests | Module can be disabled with no POS behaviour change. |
| B — Device + QR | Create one Device, permanent code/QR, print one label, wedge/manual scan, Device Detail | No inventory mutation from scanning. |
| C — Tracked receiving and reconciliation | Receive one approved variation/location into core stock and Devices atomically; print batch only when needed; reconcile | This completes the first operational vertical slice. |
| D — Diagnostics | Scan Device, capture structured diagnostics/battery/faults | Device remains owned/custodied correctly. |
| E — Internal refurbishment | Repair job, technician, parts, costs | Parts affect core stock once; cost is evidence-linked. |
| F — Grade, QC, ready to sell | QC, grade and sellable release | No manual bypass of Device state. |
| G — Serialized POS sale | Scan/assign Device at sale and enforce preflight | One core sale equals one Device sale movement. |
| H — Transfers | Dispatch/receive exact Device manifests between branches | Aggregate transfer and Device custody agree. |
| I — Customer repair | Customer-owned Device, quote, billing, collection | Never becomes stock inventory. |
| J — Warranty / trade-in | Entitlement/claim and ownership transition | Existing Device history is reused. |

## 13. Revised first 15 implementation tasks

### RCR-001 — Establish the release baseline and contain legacy Repair

- **Objective:** prove the unmodified runtime, inventory installed module/cache/provider state, and isolate any unavailable vendor Repair dependency from SaverBro's owned Recommerce module.
- **Dependencies:** licensed packages, disposable sanitized database, deployment/runtime information.
- **Acceptance criteria:** module/cache/provider inventory is recorded; unavailable vendor Repair provider cannot be accidentally loaded; SaverBro-owned Repair boundary is documented as `Modules/Recommerce`; PHP/Composer/database/front-end baseline has exact pass/fail evidence; legacy data gaps are recorded.
- **Required tests:** clean boot/migration/route smoke; dependency audit; installed-versus-enabled module comparison; stale Repair-provider cache check; legacy repair route/table inventory if present.
- **Risk:** Critical — prevents a stale vendor provider/cache from breaking boot or confusing the source of truth.

### RCR-002 — Profile the approved Alpha cohort, hardware, and stock paths

- **Objective:** choose one location and one variation/category with quantified current stock, legacy balance, and real scanner/printer/browser constraints.
- **Dependencies:** RCR-001; approved read-only production snapshot/site access.
- **Acceptance criteria:** dated profile reconciles source quantities; known identifiers/duplicates, open transactions, alternative stock paths, scanner/printer models, and named pilot users are documented.
- **Required tests:** read-only quantity reconciliation; duplicate sample review; scanner keyboard terminator test; printer size/quiet-zone test.
- **Risk:** High — a generic design can fail on real labels, data, and workarounds.

### RCR-003 — Decide and prove the atomic tracked-receiving integration contract

- **Objective:** select the narrowest source-reviewed path that takes validated unit input and commits core purchase quantity plus Devices together.
- **Dependencies:** RCR-001, RCR-002.
- **Acceptance criteria:** documented sequence/lock order/error propagation; chosen route is either a module-owned receiving service or a minimal generic purchase hook; ordinary purchase path has no changed behaviour when Recommerce is off.
- **Required tests:** forced Device validation failure rolls back core purchase; forced core failure rolls back Device rows; event/callback timing test; ordinary purchase regression.
- **Risk:** Critical — an event after data loss or an uncontrolled second workflow creates irreconcilable stock.

### RCR-004 — Scaffold an inactive Recommerce module and cohort switch

- **Objective:** create the module boundary, routes, migrations namespace, configuration, feature flag, and disable procedure.
- **Dependencies:** RCR-001, RCR-003.
- **Acceptance criteria:** module boots/enables/disables; all writes off by default; only the approved business/location/variation can be selected when enabled; no core POS change.
- **Required tests:** module boot; auth route denial; flag-off behaviour; install/disable smoke; ordinary POS smoke.
- **Risk:** Medium — an uncontrolled flag can expose unfinished stock paths.

### RCR-005 — Add Alpha authorization and serialization-policy guardrails

- **Objective:** implement deny-by-default business/location policies plus an explicit `TRACKED_PILOT` profile that blocks unapproved writes.
- **Dependencies:** RCR-002, RCR-004.
- **Acceptance criteria:** receive/scan/detail/print enforce permission, business, and location; unsupported sale/transfer/adjustment path is rejected for pilot variation before commit; policy remains inactive outside the cohort.
- **Required tests:** cross-business/location/direct-URL denial; unassigned tracked sale rollback; flag-off/non-serialized purchase and sale regression.
- **Risk:** Critical — stock can otherwise diverge through normal core screens.

### RCR-006 — Add the minimal Device identity schema

- **Objective:** create Device, identifier, QR-token, receipt-assignment, receipt-movement, and operation-receipt tables with only Alpha constraints.
- **Dependencies:** RCR-001, RCR-004, RCR-005.
- **Acceptance criteria:** one Device has immutable code, optional strong identifier, business/location/custody, variation link when stock-participating, active QR hash, and source-linked receipt assignment; customer ownership cannot be stock-participating.
- **Required tests:** migration forward/rollback plan; FK/unique violations; tenant isolation; product/variation/location invariant; placeholder-identifier rejection.
- **Risk:** Critical — poor schema constraints cannot be repaired safely after physical labels exist.

### RCR-007 — Implement safe Device-code and QR issuance

- **Objective:** issue Device code from the created row, random opaque QR token, and immutable issuance evidence without a generic ID framework.
- **Dependencies:** RCR-006.
- **Acceptance criteria:** code is unique/non-reused and scanner-safe; QR token is cryptographically random, stored hashed, resolves exactly once to its Device, and raw token is not logged/stored after issuance.
- **Required tests:** concurrent creation; rollback/gap behaviour; code check validation; token uniqueness/hash lookup; cross-business/unknown token non-disclosure.
- **Risk:** Critical — wrong or reusable labels corrupt the physical registry.

### RCR-008 — Build Device detail and exact keyboard/manual scan resolution

- **Objective:** resolve Code128 text, QR token URL, and manual code to one authorized Device Detail view without mutation.
- **Dependencies:** RCR-005, RCR-007.
- **Acceptance criteria:** USB/Bluetooth keyboard wedge and manual input accept exact codes with terminator/focus handling; public QR redirects safely to login; unauthorized/unknown scan does not disclose existence/location; scan performs no stock change.
- **Required tests:** parser length/control-character attacks; focus/rapid repeats; direct URL; cross-branch denial; authorized device-detail browser journey.
- **Risk:** High — scanner ergonomics and privacy fail together if this is only unit-tested.

### RCR-009 — Render and prove one Device QR/Code128 label

- **Objective:** print a single safe label from Device data using a Recommerce view and the proven existing print geometry/PDF tooling.
- **Dependencies:** RCR-007, RCR-008; RCR-002 hardware profile.
- **Acceptance criteria:** label contains only Device code, Code128, QR, safe model text/template version; print/reprint is authorized and records an issuance event; actual scanned label opens the exact Device.
- **Required tests:** HTML/PDF escaping and geometry; QR/Code128 decode; real printer/scanner/browser proof; long-text handling; reprint audit.
- **Risk:** High — a visually correct but unreadable/wrong label is operationally worse than no label.

### RCR-010 — Implement the narrow idempotent tracked-receive transaction

- **Objective:** receive one approved purchase line into Ultimate POS and create its exact Device(s), receipt assignments, custody, movement, and immutable Device events in the same transaction.
- **Dependencies:** RCR-003, RCR-005 to RCR-009.
- **Acceptance criteria:** repeated operation key returns the original outcome; duplicate manufacturer identity rejects and leaves neither partial Device nor POS quantity; all postconditions are asserted before commit.
- **Required tests:** retry/time-out; parallel receive; duplicate identifier; fault injection before/after core mutation; row-lock/deadlock retry bound; ordinary purchase regression.
- **Risk:** Critical — this is the financial/inventory integrity boundary.

### RCR-011 — Build the real tracked-receiving browser flow

- **Objective:** let an authorized receiver scan/type unit identifiers, prepare a small batch, confirm impact, post RCR-010, and receive Device links/print actions.
- **Dependencies:** RCR-010.
- **Acceptance criteria:** valid prepared units remain visible if one unit fails; user sees unambiguous posted/retry result; batch is bounded; non-serialized core receiving remains unchanged.
- **Required tests:** receiver-role browser journey; keyboard-only/scanner flow; validation/failure/retry; location denial; actual laptop proof.
- **Risk:** High — an unsafe workaround around the service will defeat the invariant.

### RCR-012 — Implement scoped reconciliation and stop-the-line exception

- **Objective:** show core aggregate, tracked Device count, approved legacy balance, and difference for the pilot variation/location; block further tracked receive on mismatch.
- **Dependencies:** RCR-010, RCR-011.
- **Acceptance criteria:** reconciliation runs after every receipt and on demand; mismatch is immutable evidence and no automatic balancing occurs; successful result is attributable to source transaction and Devices.
- **Required tests:** seeded mismatch classes; concurrent post/reconcile; permissions; report total; failure path with no silent repair.
- **Risk:** Critical — it is the evidence that Recommerce remains a subledger, not a competing stock ledger.

### RCR-013 — Rehearse disable, rollback, and ordinary-POS regression

- **Objective:** prove the pilot can be halted without deleting traceability or breaking ordinary Ultimate POS workflows.
- **Dependencies:** RCR-004 to RCR-012.
- **Acceptance criteria:** feature disable blocks new Recommerce receives/scans but preserves readable evidence; pilot cohort is reconciled; normal purchases/sales work in a non-pilot variation; rollback responsibilities are named.
- **Required tests:** disable at safe boundary; post-disable access; ordinary POS purchase/sale/receipt smoke; backup/restore rehearsal on disposable copy.
- **Risk:** High — production cannot adopt a new stock control without a credible stop path.

### RCR-014 — Add camera scan capability after the asset baseline is recovered

- **Objective:** add a browser-camera option without weakening the already working scanner/manual route.
- **Dependencies:** RCR-001 front-end evidence, RCR-008, HTTPS pilot environment.
- **Acceptance criteria:** supported phone/browser scans QR; permission denial/unsupported browser falls back; frames are not uploaded; result uses the exact existing resolver.
- **Required tests:** real Android/iOS/browser matrix; rear-camera/permission revoke; CSP/dependency audit; accessibility and fallback proof.
- **Risk:** High — browser support and privacy cannot be inferred from a desktop build.

### RCR-015 — Implement SaverBro-owned shared repair foundation

- **Objective:** create the single authoritative shared Repair job foundation in Recommerce for internal refurbishment and customer repair.
- **Dependencies:** RCR-001 containment evidence; RCR-006 Device schema; approved operational policies.
- **Acceptance criteria:** Recommerce owns the jobs, state transitions, custody, and repair evidence; internal/customer ownership and POS posting rules are enforced; the unavailable vendor module is neither loaded nor writable for this scope.
- **Required tests:** customer-owned non-inventory rule; internal-job stock/cost contract tests; role policy tests; job-state transition/retry tests; legacy-route/provider exclusion test.
- **Risk:** Critical — repair work has material custody, privacy, inventory, and financial consequences even though it no longer depends on vendor source recovery.

## 14. Final recommendation

### IMPLEMENT FIRST

**RCR-001 — Establish the release baseline and contain legacy Repair.** This is discovery/verification, not a feature build. It prevents an unavailable vendor Repair provider/cache from becoming an accidental runtime dependency and establishes the owned Recommerce boundary.

### THEN

1. **RCR-002 — Profile the approved Alpha cohort, hardware, and stock paths.**
2. **RCR-003 — Decide and prove the atomic tracked-receiving integration contract.**
3. **RCR-004 — Scaffold an inactive Recommerce module and cohort switch.**
4. **RCR-005 — Add Alpha authorization and serialization-policy guardrails.**

The first feature-code tasks then start at RCR-006 and build Device identity, QR, scan, label, and receiving in the operational order above.

### FIRST WORKING VERTICAL SLICE

At the end of RCR-013, an authorized receiver at one approved branch can receive one approved laptop variation through the chosen atomic path; create a permanent SaverBro Device code and opaque QR; print and attach one QR/Code128 label; scan it with a USB/Bluetooth keyboard scanner or enter it manually; open the exact, authorized Device Detail; see its branch, custody/status, source receipt and label evidence; and see a post-receipt reconciliation proving the Ultimate POS location quantity equals the tracked Device count plus any approved legacy balance. A bad identifier, cross-branch scan, retry, or partial failure cannot silently create a duplicate or leave POS and Device state out of step. Ordinary non-serialized POS workflows remain available, and the Recommerce pilot can be disabled without deleting history.

### DO NOT BUILD YET

- full transactional outbox, dead-letter UI, notification adapters, and asynchronous PDF pipeline;
- generic command bus, generic scan router, generic optimistic-version framework, or standalone ULID/UUID abstraction;
- batch label manifests beyond a bounded receiving batch;
- camera scanner until the front-end asset/HTTPS/browser matrix is proven;
- generic state engine, configurable dual approvals, trade-in, warranty, offline/native apps, AI diagnosis, telemetry, or component genealogy;
- any vendor Repair migration, route takeover, or legacy `sub_type='repair'` integration until its deployed state and open records are profiled. The SaverBro-owned Repair domain itself is scheduled at RCR-015.

### BLOCKERS

1. Matching runnable source/runtime baseline and legacy Repair containment (RCR-001).
2. A tested atomic tracked-receiving integration contract (RCR-003).
3. Approved pilot cohort with real stock/profile and hardware facts (RCR-002).
4. Before live pilot receipt: tested feature guard/rollback and reconciliation evidence (RCR-005 and RCR-012/RCR-013).

## Source evidence reviewed

- `CODEBASE_AUDIT.md`
- `RECOMMERCE_ARCHITECTURE.md`
- `RECOMMERCE_DATA_MODEL.md`
- `RECOMMERCE_QR_SCAN_ARCHITECTURE.md`
- `RECOMMERCE_MIGRATION_PLAN.md`
- `RECOMMERCE_ROADMAP.md`
- `RECOMMERCE_SECURITY_AND_PERMISSIONS.md`
- `RECOMMERCE_TASKS.md`
- `config/modules.php`, `modules_statuses.json`, `app/Utils/ModuleUtil.php`
- `app/Http/Controllers/PurchaseController.php`, `SellPosController.php`, `StockTransferController.php`, and `LabelsController.php`
- `app/Utils/ProductUtil.php`, `TransactionUtil.php`, core events, migrations, and `database/seeders/DummyBusinessSeeder.php`
