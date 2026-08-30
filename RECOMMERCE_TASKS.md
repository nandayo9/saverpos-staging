# SaverBro Recommerce OS — Implementation Task Ledger

## How to use this ledger

Tasks are deliberately small and ordered by dependency. “Likely files/modules” are planning targets; exact paths must be confirmed after the provider-retirement audit and runnable baseline are complete. Completing a task means satisfying its acceptance criteria and tests, not merely committing code.

Risk levels: **Low**, **Medium**, **High**, **Critical**.

## Gate 0 and foundation

### RC-001 — Contain and retire the unavailable provider Repair integration

- **Objective:** Ensure the unavailable provider Repair module cannot become an accidental dependency of SaverBro's owned Repair domain.
- **Scope:** Inventory `Modules\\Repair` references, stale provider/cache metadata, status/configuration, legacy routes/fields/subtypes, and any deployment data that requires preservation; define the disable/containment boundary for the provider and record the owned boundary as `Modules/Recommerce`.
- **Likely files/modules affected:** `modules_statuses.json`, bootstrap/cache handling, core Repair references, `RECOMMERCE_ARCHITECTURE.md`, and a legacy containment decision record; no provider source recovery is required for new SaverBro Repair capability.
- **Dependencies:** Deployment/runtime information and, only if legacy Repair data exists, an approved read-only schema/data profile.
- **Acceptance Criteria:** Provider Repair is not a writable authority; stale provider/cache loading is prevented or explicitly controlled; core compatibility risks are listed; legacy data preservation/migration is separately dispositioned; no production data is changed.
- **Required Tests:** Static dependency trace; stale-provider/cache check; clean-load smoke with provider Repair disabled; route/permission/subtype inventory; legacy data-preservation check where applicable.
- **Out of Scope:** Recovering vendor source solely to build SaverBro Repair; deleting legacy tables/data; implementing the owned Repair domain.
- **Risk Level:** Critical.

### RC-002 — Establish the reproducible Ultimate POS baseline

**Status:** Certified 2026-08-30 on the deployment-matching platform (macOS, **PHP 8.2.33**, MySQL 8, Composer 2.10.2, Node 22) — the earlier baseline was only ever reproduced on PHP 8.4 in a cloud container and was explicitly recorded as "not platform certification for 8.2". Run against a clean `git archive HEAD` export so no uncommitted RC-041 code was involved. PASS: `composer check-platform-reqs` (php 8.2.33 + all 19 extensions satisfied); 327/327 migrations applied to a fresh disposable MySQL database with 0 pending (108 tables, 37 `recommerce_*`); PHPUnit 167 tests / 1056 assertions green with **zero** deprecations, notices, warnings, skipped, incomplete or risky tests; `php -l` clean across all 134 `Modules/Recommerce` files; `route:list` resolves 666 routes of which 51 are Recommerce; `config:cache` succeeds; frontend assets present and prebuilt (`public/css/app.css`, `vendor.css`, `js/app.js`, `js/vendor.js`, `mix-manifest.json`) so no asset build is required to deploy. **One known blocker recorded:** `php artisan route:cache` — and therefore `php artisan optimize` — fails with `LogicException: Unable to prepare route [logout] for serialization. Another route has already been assigned name [logout].` `routes/web.php` registers `Auth::routes()` at line 87 (POST `/logout` named `logout`) and a second `GET /logout` also named `logout` at line 538. This is **pre-existing stock Ultimate POS**, not SAVERPOS work: `git diff` shows `routes/web.php` is byte-identical to the root commit. It does not affect the current cPanel deployment, which runs `config:clear`/`view:clear` and never caches routes, but it blocks standard Laravel production route-cache optimisation and should be resolved before RC-045 performance work. Deferred deliberately rather than patched here: RC-002 puts stock POS code out of scope, and the fix requires choosing which of the two routes keeps the name (nothing calls `route('logout')`; the header links via `action([LoginController::class, 'logout'])`, which is itself ambiguous while two routes share the action). The disposable database was dropped after the run.

- **Objective:** Prove the unmodified source can run and be tested in an environment matching deployment.
- **Scope:** Record PHP, Composer, database, extensions, Node/assets, queue, scheduler, storage, and web-server requirements; install locked dependencies; run current tests/build/migrations/smoke checks.
- **Likely files/modules affected:** `composer.json`, `composer.lock`, `.env.example`, `config/**`, `phpunit.xml`, deployment documentation; no dependency upgrades.
- **Dependencies:** RC-001 for complete module load; sanitized disposable database.
- **Acceptance Criteria:** A clean setup runbook and exact pass/fail baseline with versions and known blockers exists.
- **Required Tests:** Composer platform check/audit; migrations on disposable DB; PHPUnit; route/application smoke; frontend asset availability.
- **Out of Scope:** Version upgrades and Recommerce code.
- **Risk Level:** Critical.

### RC-003 — Profile data, workflows, and site hardware

- **Objective:** Replace assumptions with quantified migration and operational inputs.
- **Scope:** Profile anonymized stock/history/serial notes/open transactions/Repair data; document business/location/category volumes, scanners, printers, browsers, concurrency, and deployment topology.
- **Likely files/modules affected:** Analysis scripts/read-only reports under an approved migration workspace; `RECOMMERCE_MIGRATION_PLAN.md` updates.
- **Dependencies:** RC-002; sanitized production-like database and site inventory.
- **Acceptance Criteria:** Signed discovery report states exact snapshot date, corpus, exclusions, duplicate/conflict counts, and hardware matrix.
- **Required Tests:** Query reconciliation to source totals; sampling of candidate identifier matches; hardware identification confirmation.
- **Out of Scope:** Data writes, backfill, or device creation.
- **Risk Level:** High.

### RC-004 — Scaffold the inactive Recommerce module

- **Objective:** Create the modular-monolith boundary with no POS behavior change.
- **Scope:** Module provider, routes, configuration, translation/view namespaces, feature flags, health page, and test namespace.
- **Likely files/modules affected:** `Modules/Recommerce/**`, `modules_statuses.json`, module registration/config.
- **Dependencies:** RC-001, RC-002, approved module convention.
- **Acceptance Criteria:** Module enables/disables cleanly; all write features default off; no core transaction behavior changes.
- **Required Tests:** Module boot, route authorization, feature-flag off, install/uninstall-safe smoke, ordinary POS regression.
- **Out of Scope:** Device/repair business features.
- **Risk Level:** Medium.

### RC-005 — Prove database migration and constraint conventions

- **Objective:** Establish safe schema patterns for the deployed database engine and legacy Laravel application.
- **Scope:** Test UUID/ULID/bigint choice, tenant indexes, foreign keys, partial-active uniqueness alternative, JSON support, collation, online-index strategy, forward-only migration and feature disable.
- **Likely files/modules affected:** `Modules/Recommerce/Database/Migrations/**`, database compatibility tests/documentation.
- **Dependencies:** RC-002, RC-003, RC-004.
- **Acceptance Criteria:** Approved conventions cover every proposed table type and production-volume migration method.
- **Required Tests:** Forward migration on empty and production-like snapshots; constraint violation tests; lock-time measurement; feature-disable rehearsal.
- **Out of Scope:** Full domain schema.
- **Risk Level:** High.

### RC-006 — Implement permission catalog and tenant/location policies

**Status:** Re-verified 2026-08-29 by authorization sweep of all 51 Recommerce endpoints — acceptance criteria hold for committed code: every authenticated endpoint enforces permission + tenant/location scope through `AuthorizationGate`, and the two public token endpoints are opaque-token scoped, throttled, and minimal-disclosure. One role-editor label gap (`recommerce.warranty.manage`) was found and fixed in `DataController`. The only authorization outlier is the uncommitted, blocked RC-041 `LegacyRepairArchiveService`. A config/label parity test is still owed — see AI_HANDOFF.md.

- **Objective:** Ensure all later features inherit deny-by-default business and location isolation.
- **Scope:** Register granular permissions; role templates; policy/scope helpers; assignment and segregation-of-duty conventions.
- **Likely files/modules affected:** `Modules/Recommerce/Config/permissions.php`, providers, policies, middleware/scopes, role seeder/hook, tests.
- **Dependencies:** RC-004, current core role/location audit.
- **Acceptance Criteria:** No Recommerce endpoint or query is unscoped; permissions can be assigned without granting login/location implicitly.
- **Required Tests:** Cross-business, cross-location, missing-permission, role-template, direct URL, and background-context tests.
- **Out of Scope:** Production role assignment.
- **Risk Level:** Critical.

### RC-007 — Add permanent events and transactional outbox

- **Objective:** Provide durable device/job history and reliable post-commit integration.
- **Scope:** Event/outbox schema, append service, redaction policy, dispatcher, retry/dead-letter behavior, correlation/source metadata.
- **Likely files/modules affected:** `Modules/Recommerce/Database/Migrations/**`, `Entities/RecommerceEvent.php`, `Entities/OutboxMessage.php`, `Services/EventRecorder.php`, jobs/commands.
- **Dependencies:** RC-005, RC-006.
- **Acceptance Criteria:** Domain commit and event/outbox are atomic; dispatch only occurs after commit; permanent timeline is queryable and redacted.
- **Required Tests:** Commit/rollback, dispatcher crash/retry, duplicate delivery, dead letter, tenant scope, sensitive-field exclusion.
- **Out of Scope:** External messaging channels.
- **Risk Level:** High.

### RC-008 — Add idempotent command and versioning infrastructure

- **Objective:** Make retried and concurrent mutations safe before stock integration.
- **Scope:** Command receipt/outcome table, unique idempotency key, expected-version convention, lock-order helper, stale-response contract.
- **Likely files/modules affected:** `Modules/Recommerce/Database/Migrations/**`, `Services/CommandBus.php`, concurrency utilities, API/controller response layer.
- **Dependencies:** RC-005, RC-007.
- **Acceptance Criteria:** Same key returns same outcome; conflicting reuse is rejected; stale versions cannot overwrite newer state.
- **Required Tests:** Parallel duplicate command, rollback/retry, key collision, stale version, deadlock retry bounds.
- **Out of Scope:** Offline command queue.
- **Risk Level:** Critical.

### RC-009 — Create the canonical device schema

- **Objective:** Represent one physical device independently from catalog, ownership, custody, and repair jobs.
- **Scope:** Device table/model, lifecycle/version fields, optional product/variation for customer devices, business/location indexes, factories.
- **Likely files/modules affected:** `Modules/Recommerce/Database/Migrations/*devices*`, `Entities/Device.php`, factories/repositories/policies.
- **Dependencies:** RC-005, RC-006, approved data model.
- **Acceptance Criteria:** Customer device can exist without a product; business-owned sellable device validation requires correct variation/location; one canonical row persists across ownership changes.
- **Required Tests:** Schema constraints, tenant scope, customer/business-owned validation, lifecycle initialization, factory isolation.
- **Out of Scope:** Identifier, QR, and stock movement behavior.
- **Risk Level:** Critical.

### RC-010 — Implement concurrency-safe human code allocation

- **Objective:** Issue immutable, printable device/job codes without collision.
- **Scope:** Code format/check digit decision, allocator table/service, database uniqueness, retry behavior; do not depend on current unlocked reference count.
- **Likely files/modules affected:** `Modules/Recommerce/Database/Migrations/*sequences*`, `Services/Identity/CodeAllocator.php`, device/job creation services.
- **Dependencies:** RC-005, RC-009; approved prefix policy.
- **Acceptance Criteria:** Parallel allocation yields unique stable codes; voided codes are never reassigned; format is scanner/label safe.
- **Required Tests:** High-concurrency allocation, rollback/gap behavior, check-digit validation, business/global prefix collision.
- **Out of Scope:** Manufacturer identifiers and QR tokens.
- **Risk Level:** High.

## Device identity, receiving, labels, and scan

### RC-011 — Add identifiers, ownership periods, and custody

- **Objective:** Complete physical identity and history around the canonical device.
- **Scope:** Normalized identifier hashes/provenance/corrections; ownership periods; custody/location periods; active-period constraints.
- **Likely files/modules affected:** Recommerce migrations, `Entities/DeviceIdentifier.php`, `DeviceOwnershipPeriod.php`, `DeviceCustodyPeriod.php`, services/policies.
- **Dependencies:** RC-008, RC-009.
- **Acceptance Criteria:** Duplicate strong identifier is blocked within business; one active owner and custody exist at most; corrections append history.
- **Required Tests:** Normalization variants, duplicate races, active-period overlap, cross-business isolation, reveal permission/audit.
- **Out of Scope:** Automatic merge of ambiguous legacy data.
- **Risk Level:** Critical.

### RC-012 — Issue and resolve opaque QR tokens

- **Objective:** Provide safe permanent QR resolution without exposing record data.
- **Scope:** Cryptographic issuance, hash storage, revocation/rotation, public login redirect, authenticated authorization, log redaction/rate limits.
- **Likely files/modules affected:** token migration/entity/service, `Routes/web.php`, resolver controller/middleware, security configuration.
- **Dependencies:** RC-006 to RC-011; approved production domain later configurable.
- **Acceptance Criteria:** QR contains approved HTTPS URL only; raw token is not stored/logged; unauthorized and unknown results are indistinguishable.
- **Required Tests:** Entropy/uniqueness, hash resolution, rotation, enumeration/rate limit, login redirect, cross-tenant non-disclosure, malicious URL inputs.
- **Out of Scope:** Executing a business mutation from QR GET.
- **Risk Level:** Critical.

### RC-013 — Add serialization profiles and legacy balances

- **Objective:** Introduce tracked units without falsifying existing aggregate stock.
- **Scope:** `NOT_SERIALIZED`, `LEGACY_MIXED`, `TRACKED_REQUIRED`; per variation/location baseline, approval/evidence, conversion command.
- **Likely files/modules affected:** migrations/entities/services for serialization profiles/balances, policies, admin UI.
- **Dependencies:** RC-003, RC-008, RC-009.
- **Acceptance Criteria:** Profile activation validates equality; legacy balance cannot go negative; changes are versioned/approved.
- **Required Tests:** Negative/fractional/mismatch baseline, concurrent conversion, profile transitions, rollback/disable.
- **Out of Scope:** Automatic serial invention.
- **Risk Level:** Critical.

### RC-014 — Implement append-only device movements

- **Objective:** Create the physical-unit subledger that reconciles to POS aggregate stock.
- **Scope:** Movement types/source links, eligibility projection, one active location/state rules, inverse corrections.
- **Likely files/modules affected:** movement migration/entity, `Services/DeviceMovementService.php`, projections/repositories.
- **Dependencies:** RC-007 to RC-013.
- **Acceptance Criteria:** Every stock-participating state change has one source-linked movement; history is never overwritten.
- **Required Tests:** Movement ordering, duplicate source, correction reversal, state/location projection, tenant isolation.
- **Out of Scope:** Posting POS transactions.
- **Risk Level:** Critical.

### RC-015 — Coordinate purchase receiving with device creation

- **Objective:** Atomically receive tracked quantity and exact devices.
- **Scope:** Purchase-line assignments, prepared batch, locked POS quantity update via existing utility, ownership/custody/movements/events, idempotency.
- **Likely files/modules affected:** Recommerce receiving services/controllers; minimal generic purchase hook/guard if required; existing `app/Utils/ProductUtil.php` only through reviewed extension.
- **Dependencies:** RC-013, RC-014; core purchase regression baseline.
- **Acceptance Criteria:** Posted quantity equals created/assigned devices; duplicate unit can be resolved before posting; failures roll back both ledgers.
- **Required Tests:** Partial receipt, duplicate scan, concurrent receipt, retry after timeout, rollback fault injection, ordinary non-tracked purchase regression.
- **Out of Scope:** Historical purchase backfill.
- **Risk Level:** Critical.

### RC-016 — Build the tracked receiving screen

- **Objective:** Let receivers prepare, validate, and post batches efficiently with real scanners.
- **Scope:** Purchase line context, scan focus, prepared units, exceptions, impact confirmation, posted result.
- **Likely files/modules affected:** Recommerce receiving controllers/views/JS/CSS/routes/translations.
- **Dependencies:** RC-015.
- **Acceptance Criteria:** Complete rendered flow works for partial/large batch and preserves valid units when one fails; posting state is unambiguous.
- **Required Tests:** Browser role/location flows, keyboard-only, duplicate error, network retry, responsive layout, scanner hardware.
- **Out of Scope:** Trade-in intake.
- **Risk Level:** High.

### RC-017 — Render and print one safe device label

- **Objective:** Produce one unique Code128/QR label per device using proven POS print foundations.
- **Scope:** Label template/version, safe fields, existing barcode/PDF geometry reuse, authorized print/reprint event.
- **Likely files/modules affected:** Recommerce label service/controller/views; shared wrapper around installed barcode/PDF libraries; no replacement of core LabelsController.
- **Dependencies:** RC-010, RC-012.
- **Acceptance Criteria:** Label scans to exact device; no sensitive data; reprint preserves identity and is audited.
- **Required Tests:** HTML/PDF render, escaping, authorization, Code128 and QR decode, printer size/alignment, long description handling.
- **Out of Scope:** Batch pagination.
- **Risk Level:** High.

### RC-018 — Add immutable batch print manifests

- **Objective:** Print large receiving/job batches without duplicate or changing identities.
- **Scope:** Selected ordered manifest, unique token check, chunked rendering, print attempts/results, reprint reason.
- **Likely files/modules affected:** label-manifest migrations/entities/services/views/jobs.
- **Dependencies:** RC-016, RC-017.
- **Acceptance Criteria:** Same manifest reproduces same ordered identities; failure does not allocate new tokens/devices; large batch is bounded.
- **Required Tests:** Duplicate selection, interrupted render, chunk/page geometry, parallel reprint, authorization and sensitive-data scan.
- **Out of Scope:** Direct printer driver management.
- **Risk Level:** Medium.

### RC-019 — Build authenticated exact-match scan router

- **Objective:** Resolve scanners/pasted codes into safe contextual records/actions.
- **Scope:** Parser/normalizer, object resolver order, permission/location/state action resolver, duplicate-read behavior.
- **Likely files/modules affected:** Recommerce scan controller/service/routes/views/JS, rate-limit configuration.
- **Dependencies:** RC-006, RC-010 to RC-012.
- **Acceptance Criteria:** Exact device/job/token codes resolve; partial/ambiguous values do not; results contain only authorized actions; scan alone does not mutate.
- **Required Tests:** Parser attacks, whitespace/layout, unknown/revoked/unauthorized, cross-business, rapid duplicates, contextual action matrix.
- **Out of Scope:** Camera decoding.
- **Risk Level:** Critical.

### RC-020 — Build device registry and detail timeline

- **Objective:** Give authorized users one evidence-linked view of each physical device.
- **Scope:** Scoped filters, masked identifiers, overview, ownership/custody, stock sources, timeline, labels, repair placeholders.
- **Likely files/modules affected:** Recommerce device controllers/queries/resources/views/JS/routes.
- **Dependencies:** RC-011, RC-014, RC-019.
- **Acceptance Criteria:** Registry totals/source links are explainable; customer devices display without fake products; masks/locations follow permissions.
- **Required Tests:** Filter/pagination, tenant/location, identifier reveal audit, direct URL denial, timeline order, query-volume bounds.
- **Out of Scope:** Editable raw state fields.
- **Risk Level:** High.

### RC-021 — Deliver stock reconciliation and exception resolution

- **Objective:** Continuously prove POS aggregate equals tracked plus declared legacy quantity.
- **Scope:** On-demand/scheduled checks, severity queue, side-by-side evidence, controlled inverse/correction commands, daily report.
- **Likely files/modules affected:** reconciliation services/jobs/queries/controllers/views/events.
- **Dependencies:** RC-013 to RC-015, RC-020.
- **Acceptance Criteria:** All mismatch classes are detected; resolution cannot edit aggregate/device state directly; signed result is retained.
- **Required Tests:** Seeded mismatch classes, concurrent posting, large-volume performance, permission/dual approval, report totals.
- **Out of Scope:** Silent auto-fix.
- **Risk Level:** Critical.

### RC-022 — Add camera scan capability and browser matrix

- **Objective:** Offer secure camera scanning while retaining scanner/manual paths.
- **Scope:** capability detection, BarcodeDetector, locally bundled vetted fallback, camera lifecycle, HTTPS/permission UX.
- **Likely files/modules affected:** Recommerce scan JS/assets/views, dependency manifest after asset-source recovery, CSP.
- **Dependencies:** RC-002 asset decision, RC-019.
- **Acceptance Criteria:** Supported browsers scan approved symbologies; unsupported/denied camera falls back cleanly; frames are not uploaded.
- **Required Tests:** Actual mobile/desktop browsers, permission denial/revoke, rear camera, repeated decode, CSP/dependency audit, accessibility.
- **Out of Scope:** Offline mutation and native app.
- **Risk Level:** High.

## Shared repair and costs

### RC-023 — Create versioned diagnostic and grading templates

- **Objective:** Make diagnosis/grade repeatable and historically defensible.
- **Scope:** Template/version/check/range schema, publish/retire workflow, category applicability, immutable use snapshots.
- **Likely files/modules affected:** Recommerce diagnostic migrations/entities/services/admin views/policies.
- **Dependencies:** RC-005 to RC-009; approved operational rubrics.
- **Acceptance Criteria:** Published version cannot change; submitted diagnostic retains exact template; grade override records reason.
- **Required Tests:** Versioning, applicability, units/ranges, retirement, permission, submitted snapshot immutability.
- **Out of Scope:** AI diagnosis or automatic final grade.
- **Risk Level:** Medium.

### RC-024 — Implement shared repair job schema and state machine

- **Objective:** Support internal refurbishment and customer repair on one controlled engine.
- **Scope:** Jobs, types, states/transitions, prerequisites, assignments, accessories, source links, events, optimistic locking.
- **Likely files/modules affected:** Recommerce repair migrations/entities/state services/policies/tests.
- **Dependencies:** RC-001 provider-containment decision, RC-007 to RC-011, RC-023.
- **Acceptance Criteria:** Both job types follow shared core with policy differences; invalid direct transitions are impossible; closed job is immutable.
- **Required Tests:** Allowed/forbidden transition matrix, concurrent transition, prerequisites, cross-location assignment, repeat linked job.
- **Out of Scope:** Quote, parts, invoice, and UI.
- **Risk Level:** Critical.

### RC-025 — Build repair intake, job detail, and technician workbench

- **Objective:** Provide role-specific browser workflows on the state engine.
- **Scope:** internal intake, queues/assignment, diagnostic entry, faults/actions/evidence, state buttons, timelines.
- **Likely files/modules affected:** Recommerce repair controllers/requests/views/JS/routes/file service.
- **Dependencies:** RC-020, RC-023, RC-024, secure file controls.
- **Acceptance Criteria:** Counter/technician/manager see only needed data/actions; submissions are versioned; no status dropdown bypass.
- **Required Tests:** Rendered role flows, file validation/download auth, stale version, keyboard/tablet, missing prerequisite errors.
- **Out of Scope:** Customer quote/billing and production notification.
- **Risk Level:** High.

### RC-026 — Add part reservations and usage states

- **Objective:** Track requested/issued/installed/returned parts without bypassing POS stock.
- **Scope:** reservation, issue, installation, removal, disposition, customer/internal path, expiry and location rules.
- **Likely files/modules affected:** Recommerce parts migrations/entities/services/views; read integration with core products/variations/location stock.
- **Dependencies:** RC-008, RC-024.
- **Acceptance Criteria:** Free available quantity considers reservations; one usage cannot follow both customer and internal consumption paths; history is explicit.
- **Required Tests:** Concurrent reserve, expiry/release, substitute, install/remove, wrong location, authorization.
- **Out of Scope:** Direct writes to `qty_available`.
- **Risk Level:** Critical.

### RC-027 — Post internal part consumption and device costs

- **Objective:** Consume refurbishment parts through POS adjustment and append actual unit cost once.
- **Scope:** stock-adjustment integration, usage/source link, device-target cost entries, labor/external cost, reversals.
- **Likely files/modules affected:** Recommerce cost migrations/entities/services; minimal adjustment hook/coordinator; existing adjustment utilities by extension only.
- **Dependencies:** RC-014, RC-024, RC-026.
- **Acceptance Criteria:** One action posts one POS decrement, one usage result, one device cost; failure rolls all back; correction is a reversal.
- **Required Tests:** Concurrency/idempotency, FIFO/cost source link, rollback injection, reversal, accounting/stock regression.
- **Out of Scope:** Redesign of official inventory valuation.
- **Risk Level:** Critical.

### RC-028 — Implement QC, final grade, and internal stock release

- **Objective:** Prevent unfinished devices entering sellable stock.
- **Scope:** versioned QC templates/results, separation of duties, failure/rework, warranty evidence, final classification and `IN_STOCK` movement.
- **Likely files/modules affected:** Recommerce QC migrations/entities/services/views/policies.
- **Dependencies:** RC-013, RC-023 to RC-027.
- **Acceptance Criteria:** Release requires passed/approved waiver, resolved parts/cost prerequisites, business ownership, variation, location, label, and reconciliation.
- **Required Tests:** QC pass/fail/rework, self-approval denial, missing variation/label, stale result, reconciliation.
- **Out of Scope:** Customer collection.
- **Risk Level:** Critical.

## Sale, customer repair, transfers, and trade-in

### RC-029 — Add tracked-device assignment to POS sale lines

- **Objective:** Require exact eligible devices before checkout finalization.
- **Scope:** assignment schema/service and POS panel for tracked variations; scan validation for count/location/state/variation/reservation.
- **Likely files/modules affected:** Recommerce sale-assignment migration/services/views/JS; minimal POS view/module hook.
- **Dependencies:** RC-013, RC-019, RC-028.
- **Acceptance Criteria:** Assigned count equals tracked line quantity and no device is assigned twice; non-tracked UI remains unchanged.
- **Required Tests:** Wrong state/location/variation, duplicate assignment, quantity edit, multiple lines, role/browser regression.
- **Out of Scope:** Sale posting.
- **Risk Level:** Critical.

### RC-030 — Coordinate tracked sale, ownership, and device movement

- **Objective:** Atomically finalize POS sale and exact physical-unit disposition.
- **Scope:** preflight hook, locks, sale-line assignments, existing aggregate/FIFO posting, movement, ownership transition, events.
- **Likely files/modules affected:** Recommerce sale coordinator/listener; narrowly reviewed core `SellPosController`/module hook contract and `TransactionUtil` integration.
- **Dependencies:** RC-008, RC-014, RC-029.
- **Acceptance Criteria:** Missing assignment blocks tracked sale; finalized sale decrements quantity and sells each assigned device once; exception rolls back POS.
- **Required Tests:** Parallel sale of same device, retry/timeouts, hook exception rollback, multi-line payment, ordinary sale regression, post-commit event.
- **Out of Scope:** Replacing POS tax/payment/accounting.
- **Risk Level:** Critical.

### RC-031 — Implement tracked sale return and cancellation

- **Objective:** Reverse commercial/stock effects without erasing device history.
- **Scope:** validate returned unit/condition, POS return/cancellation links, inverse movement, ownership/custody/disposition, warranty implications.
- **Likely files/modules affected:** Recommerce return services/views/policies; reviewed core return hooks.
- **Dependencies:** RC-030.
- **Acceptance Criteria:** Original sale remains; device enters correct inspected/quarantine state; aggregate and subledger reconcile.
- **Required Tests:** Full/partial return, wrong device, duplicate return, damaged disposition, concurrent transfer/repair, POS regression.
- **Out of Scope:** Trade-in pricing.
- **Risk Level:** Critical.

### RC-032 — Add customer repair intake and privacy controls

**Status:** Implemented locally. Customer selection was repaired 2026-08-30:
the intake page seeds its customer select with the first 200 contacts by name
and its search box filtered only those options in the browser, so a business
with more than 200 customers could not select — or even find — anyone sorted
after the 200th, despite `GET /recommerce/repair/customers` already existing to
search the whole book by name, contact reference, or mobile. That endpoint had
**no caller anywhere in the application and no test coverage**. The search box
now calls it (debounced, 2-character minimum, stale responses discarded) and
both render paths keep the chosen customer even when they rebuild the list.

- **Objective:** Accept customer-owned devices without fake catalog stock.
- **Scope:** contact link, registry reuse/create, custody, reported issue, condition/evidence, accessories, consent/terms, secret-handling decision, receipt/label.
- **Likely files/modules affected:** Recommerce customer-intake services/controllers/views, repair/device entities, file/privacy policies.
- **Dependencies:** RC-017, RC-024, RC-025; approved privacy/secret policy.
- **Acceptance Criteria:** Customer device may lack variation; existing device is reused; safe receipt/label excludes personal/sensitive fields.
- **Required Tests:** Duplicate identity, contact/location permission, file access, no-passcode default, terms version, printed output.
- **Out of Scope:** Customer portal or automated messaging.
- **Risk Level:** Critical.

### RC-033 — Implement quote versions and approval evidence

- **Objective:** Prevent repair scope from changing after customer approval.
- **Scope:** immutable quote versions/lines/tax assumptions/expiry, send record, decision/evidence, work gate, revised quote.
- **Likely files/modules affected:** Recommerce quote migrations/entities/services/views/policies/outbox events.
- **Dependencies:** RC-024 to RC-026, RC-032.
- **Acceptance Criteria:** Sent version is immutable; approval names exact version; scope increase blocks work until reapproved.
- **Required Tests:** Version mutation denial, expired/declined/revised approval, concurrent decisions, permission/evidence, totals snapshot.
- **Out of Scope:** Claiming cryptographic e-signature.
- **Risk Level:** High.

### RC-034 — Bill customer repair parts/services through POS

- **Objective:** Use POS as financial truth while linking exact repair work and parts.
- **Scope:** projected invoice lines, service products, installed-pending-billing parts, sale creation/finalization link, billed state and reversals.
- **Likely files/modules affected:** Recommerce billing coordinator/services/views; approved POS transaction subtype/module metadata hook.
- **Dependencies:** RC-026, RC-030, RC-033.
- **Acceptance Criteria:** Part stock decrements exactly once at final POS sale; service lines do not affect stock; invoice/tax/payment remain POS authority.
- **Required Tests:** Draft update, price/tax/discount, parallel finalize, retry, sale return, installed-unbilled exception, ordinary POS regression.
- **Out of Scope:** New payment gateway or accounting ledger.
- **Risk Level:** Critical.

### RC-035 — Complete customer QC, payment policy, and collection

**Status:** Implemented locally. The repeat-visit surface was repaired
2026-08-30: the **Repeat visit** button was unreachable in every state and
would have failed three ways if reached. It rendered only inside the collection
block, which requires `READY`; it carried `disabled` whenever the job was not
`CLOSED`, which is always true inside that block; and it posted an empty
`command_uuid` that the submit handler strips before sending, so the route
would have rejected the request as missing. `RepairCollectionService::
startRepeat()` accepts only a `CLOSED` customer repair and authorizes it with
`recommerce.repair.intake`, so the button is now gated on exactly that, its
dead `disabled` attribute is gone, and the browser supplies the v4
`command_uuid` the deduplication depends on. Until this, the only working path
to a repeat visit was the one `WarrantyClaimService` creates internally.

- **Objective:** Close service custody only after technical and commercial prerequisites.
- **Scope:** customer QC, ready state, POS balance summary, authorized outstanding-balance override, collector evidence, custody close, warranty record.
- **Likely files/modules affected:** Recommerce QC/collection services/controllers/views/policies/events.
- **Dependencies:** RC-028 concepts, RC-032 to RC-034.
- **Acceptance Criteria:** Closed job has QC outcome, resolved parts, financial policy result, and custody handover; repeat visit creates new linked job.
- **Required Tests:** Paid/unpaid/override, unauthorized collection, QC failure, missing part billing, concurrent close, repeat repair.
- **Out of Scope:** Customer identity-verification service.
- **Risk Level:** High.

### RC-036 — Add generic tracked-transfer core integration

- **Objective:** Close the current transfer status hook gap without embedding Recommerce rules in core.
- **Scope:** generic preflight/event at create/status completion/cancel, exception rollback, stable source line correlation.
- **Likely files/modules affected:** narrowly scoped `app/Http/Controllers/StockTransferController.php` or generic domain service/events; Recommerce listener/guard; provider/tests.
- **Dependencies:** RC-002 regression baseline, RC-008, RC-014.
- **Acceptance Criteria:** Module may atomically guard/record tracked transfer legs; disabled module leaves existing behavior unchanged.
- **Required Tests:** Create/complete/cancel hooks, exception rollback, event timing, non-tracked transfer regression, listener absence.
- **Out of Scope:** Rewriting transfer accounting.
- **Risk Level:** Critical.

### RC-037 — Build tracked transfer manifests and receiving exceptions

**Status:** Implemented locally: receiving evidence and completion gate are covered; courier tracking and return-transfer workflow remain out of scope.

- **Objective:** Move exact units between locations with sender/receiver proof.
- **Scope:** scan manifest, dispatch/in-transit, receiving scan, missing/extra/substitute queue, completion/cancellation/return transfer.
- **Likely files/modules affected:** Recommerce transfer migrations/entities/services/views/policies.
- **Dependencies:** RC-019, RC-021, RC-036.
- **Acceptance Criteria:** Device and aggregate transfer legs reconcile; inaccurate manifest cannot complete; device cannot sell while in transit.
- **Required Tests:** Partial/mismatch receive, concurrent sale, duplicate scan, cancellation stages, cross-location permission, POS regression.
- **Out of Scope:** Courier tracking.
- **Risk Level:** Critical.

### RC-038 — Implement trade-in offer and ownership acquisition

- **Objective:** Acquire a customer device while preserving its identity/history and financial source.
- **Scope:** lookup/intake, condition/diagnosis, versioned offer/approval, configured POS/accounting acquisition link, ownership/custody transition, reject/return.
- **Likely files/modules affected:** Recommerce trade-in migrations/entities/services/views/policies; approved POS/accounting integration adapter.
- **Dependencies:** RC-023, RC-032, management decision on acquisition accounting.
- **Acceptance Criteria:** Existing device reused; accepted offer links booked source; rejected device is returned; estimates never appear as actual cost.
- **Required Tests:** Previous sale/repair reuse, duplicate identifiers, approval threshold, accept/reject concurrency, accounting/stock reconciliation.
- **Out of Scope:** Automated pricing model.
- **Risk Level:** Critical.

### RC-039 — Add warranty coverage and claim jobs

**Status:** Implemented locally: coverage source, versioned policy evidence, claim lines, repeat-job creation, and the repair-record claim UI are covered; a rendered browser smoke and the production-policy review remain pending.

The UI was the real gap behind "UI smoke pending": the claim service and `POST
/recommerce/repair/{jobCode}/warranty/claim` shipped with **no caller** — no
view referenced the route and no screen listed a claim, so the feature was
unreachable from the application. The repair record now carries a Warranty
claims card that lists each claim (number, coverage status, decision reason,
policy, cover end, claim lines) and links a repeat job back to the claim that
created it, plus a claim form gated on `recommerce.warranty.manage` at the
job's location and restricted to customer repairs. A latent defect was fixed
with it: `WarrantyClaim` cast none of its datetime columns, so a claim re-read
from the database returned raw strings and `coverage_end_at->format()` would
have fatalled on any page listing one — the in-memory model held Carbon, which
is why every existing test passed. Six tests added, each mutation-checked.

- **Objective:** Preserve policy evidence and handle repeat service without reopening history.
- **Scope:** service coverage instance, source sale/job, coverage decision, claim link, covered/chargeable line separation.
- **Likely files/modules affected:** Recommerce warranty migrations/entities/services/views; read integration with core warranties/sale lines.
- **Dependencies:** RC-031, RC-035.
- **Acceptance Criteria:** Claim is a new linked job; policy/version/dates and decision reason are retained; original invoice/job unchanged.
- **Required Tests:** In/out of coverage, partial coverage, repeat claim, permission, POS billing separation.
- **Out of Scope:** Insurer/OEM claim API.
- **Risk Level:** Medium.

## Migration, operations, and release

### RC-040 — Build reviewed legacy identity/backfill tooling

- **Objective:** Import only evidence-supported historical devices and establish mixed-stock baselines.
- **Scope:** candidate staging, normalization/conflict report, source provenance, reviewer approval, idempotent import, legacy balance setup.
- **Likely files/modules affected:** Recommerce console commands/migration services/staging tables/reports; no direct historical rewrite.
- **Dependencies:** RC-003, RC-011, RC-013, RC-021.
- **Acceptance Criteria:** Ambiguous candidates remain unresolved; imported totals/provenance reconcile; rerun creates no duplicates.
- **Required Tests:** Representative snapshot migration, conflicts, retry/interruption, rollback by feature disable, totals/checksum.
- **Out of Scope:** Fake identifier generation.
- **Risk Level:** Critical.

### RC-041 — Migrate or archive existing Repair records

**Status:** BLOCKED — do not land. Uncommitted/untracked archive code exists in the working tree (`recommerce_repair_archives` migrations, `RepairArchive`, `LegacyRepairArchiveService`, `LegacyRepairArchiveController`, `recommerce.repair.archive` permission, `POST /recommerce/repair/legacy-archive`, `RecommerceLegacyRepairArchiveTest`). It must not be committed as written: (a) `RCR_001_BASELINE_REPORT.md` §5 records the Repair disposition as UNAVAILABLE/INSUFFICIENT EVIDENCE and forbids implementing any Repair route, migration, or permission under RCR-001; (b) no `Modules/Repair` source exists in this checkout (`modules_statuses.json` has `"Repair": false`), so the archive captures only the POS `transactions` row and cannot meet the status/financial/attachment acceptance criteria; (c) `LegacyRepairArchiveService::assertArchiveAccess()` never calls `AuthorizationGate`/`$user->can()`, so any authenticated cohort user could run the archive — and the existing permission test cannot detect this. See AI_HANDOFF.md "Incoming-agent verification (2026-08-29)".

- **Objective:** Prevent dual writable repair authorities and preserve historical access.
- **Scope:** Implement the RC-001 disposition: mapped import with source keys, or authorized read-only deep link/archive; attachments/permissions included.
- **Likely files/modules affected:** Recommerce migration commands/adapters/views, storage mapping, and only approved legacy read-only adapters if legacy records exist; no new provider Repair source is required.
- **Dependencies:** RC-001, RC-024, RC-032 to RC-035.
- **Acceptance Criteria:** Every in-scope historical job is accounted for; new writes have one authority; reconciliation and user navigation pass.
- **Required Tests:** Full representative migration, status/financial/attachment sampling, permissions, rerun, rollback/dual-write prevention.
- **Out of Scope:** Inventing missing historical facts.
- **Risk Level:** Critical.

### RC-042 — Harden files, exports, retention, and secret handling

- **Objective:** Close privacy/security controls beyond ordinary route authorization.
- **Scope:** private file delivery/scanning, export masking/formula defense, retention jobs/legal holds, approved temporary-secret encryption/expiry or explicit no-storage path.
- **Likely files/modules affected:** Recommerce file/export/retention services, storage config, policies, commands/jobs.
- **Dependencies:** Approved privacy retention and secret policy; RC-006, RC-025, RC-032.
- **Acceptance Criteria:** No predictable public evidence files; export/reveal is audited; expired data is disposed according to policy; secrets never enter logs/events.
- **Required Tests:** MIME spoof/malware quarantine, path traversal, direct URL denial, CSV injection, retention/legal hold, key/secret expiry.
- **Out of Scope:** Defining legal policy for SaverBro.
- **Risk Level:** Critical.

### RC-043 — Add safe notification adapters and exception handling

- **Objective:** Deliver repair updates without coupling job transactions to external channels.
- **Scope:** outbox templates, minimal payload, channel adapter interface, retries/dead letter/manual-contact evidence, preference/consent.
- **Likely files/modules affected:** Recommerce notification services/templates/jobs/views; configured channel adapters later.
- **Dependencies:** RC-007, RC-033, RC-035; channel decision.
- **Acceptance Criteria:** Job commit succeeds independently; delivery state is visible; messages contain safe references only.
- **Required Tests:** Adapter failure/retry, duplicate delivery key, template escaping, preference/consent, sensitive-data inspection.
- **Out of Scope:** Selecting/purchasing messaging provider.
- **Risk Level:** High.

### RC-044 — Deliver evidence-linked operational reporting

- **Objective:** Provide controllable operational measures without duplicating accounting reports.
- **Scope:** queue/turnaround/QC/parts/reconciliation/unit-cost projections, scope/date/as-of labels, export permissions.
- **Likely files/modules affected:** Recommerce projection jobs/queries/controllers/views/exports.
- **Dependencies:** RC-007, relevant workflow milestones, approved definitions.
- **Acceptance Criteria:** Every metric has definition/source/as-of/scope and reconciles to records; costs/revenue are clearly distinguished.
- **Required Tests:** Fixture calculations, tenant/location, late-event rebuild, export masking, performance.
- **Out of Scope:** Forecasting, AI, or replacement of POS accounting reports.
- **Risk Level:** Medium.

### RC-045 — Run performance, recovery, and security readiness gates

- **Objective:** Prove the system survives production-like load, failures, and adversarial access.
- **Scope:** lock/latency/load testing, dependency and application security review, queue/monitoring, backup restore, token/key incident, accessibility and browser matrix.
- **Likely files/modules affected:** Test suites, monitoring/deployment configuration, runbooks; fixes discovered are separate scoped tasks.
- **Dependencies:** All Alpha-scope features, production-like environment.
- **Acceptance Criteria:** Approved service objectives and security checklist pass; restore/disable/incident exercises have timestamped evidence and owners.
- **Required Tests:** Peak receive/sale/scan concurrency, deadlock/fault injection, cross-tenant penetration cases, restore, queue outage, hardware/browser/accessibility.
- **Out of Scope:** Claiming public release from local results.
- **Risk Level:** Critical.

### RC-046 — Execute one controlled Alpha cohort

- **Objective:** Validate the selected vertical slices with actual people, devices, roles, and hardware before expansion.
- **Scope:** One approved business/location/category cohort; training; baseline physical count; feature activation; runtime evidence; daily reconciliation; rollback decision.
- **Likely files/modules affected:** Configuration/feature flags, operational records, acceptance evidence, issue ledger; production code only through separately reviewed fixes.
- **Dependencies:** RC-001 to the chosen milestone scope, RC-045, named owners/approvals.
- **Acceptance Criteria:** Cutover runbook completes; zero unresolved severity-one mismatch/security issue; acceptance or rollback is signed with exact date/build/scope.
- **Required Tests:** Live role workflows, actual scanner/printer/browser, receive-label-scan-reconcile, selected later slices, backup/disable readiness.
- **Out of Scope:** Multi-site rollout or general release.
- **Risk Level:** Critical.

## Immediate implementation order

## Operational verification status

**Blade compilation guarded across the module (2026-08-30).**
Recommerce views were only ever asserted as source strings, which cannot catch
a template that throws when it runs. The repair record is now rendered for real
in tests, and that immediately exposed five Blade directives written directly
after a word character (`@endif@if`, `claim@elseif`) — Blade leaves those
uncompiled, so three pre-existing ones leaked literal `@else…@endif` into the
page and two added on 2026-08-30 unbalanced the leftovers into a PHP parse
error on every repair record. All 17 module views now compile with no
leftovers, and `RecommerceBladeCompilesTest` fails if that regresses. This is
local test evidence, not release evidence.

**Operations screens rendered in tests (2026-08-30).**
The device registry, operations dashboard, reconciliation index, and transfer
exceptions screens now render in tests, with assertions that their permission
gating hides what a role may not see and that their degraded states (missing
product, missing device row, single configured location) render rather than
throw. Rendered coverage is 8 of 17 module views; the rest remain
compilation-only. Local test evidence, not release evidence.

**Unauthenticated documents rendered in tests (2026-08-30).**
The public certification page, the public repair status page, and the print
label are now rendered rather than asserted as source, each checked against the
disclosure limits its own copy promises: the masked serial with the row dropped
when nothing is safe to show, an escaped `customer_facing_update` (operator free
text displayed to a customer over an unauthenticated link), and a label whose
scan target is never printed as text. 13 of 17 module views remain
compilation-only. Local test evidence, not release evidence.

**UI/UX audit of all 13 in-app screens (2026-08-30).**
Every in-app Recommerce screen was rendered from the real Blade against the
real CSS cascade and audited at 375, 768 and 1280 px: composited contrast per
text style, accessible names, discernible control text, light surfaces on the
dark ground, and horizontal overflow. 39 page-renders, all clean after two
fixes. First, stock Bootstrap components the dark pass never covered measured
1.86-2.04:1 (`.btn-warning`, `.btn-info`, `.btn-danger`, `.label-warning`,
`.label-info`, `.label-default`, `.alert-success`) and `.help-block` sat at
3.37:1; all now darkened in the shared stylesheet with measured replacements.
Second, 17 form controls used a placeholder as their accessible name, including
four in `device/show` whose visible labels carried no `for`. Guarded by
`RecommerceDarkStockComponentsTest` and `RecommerceFormLabellingTest`. This
covers responsive layout and contrast for RC-016's acceptance; POS chrome and
interaction remain unverified. Local test evidence, not release evidence.

**Quick create on repair intake was broken (2026-08-30).**
The intake screen linked to `ContactController@create` with `target="_blank"`,
but that endpoint returns `contact/create.blade.php`, which starts at
`<div class="modal-dialog">` and extends no layout. The new tab showed a bare
unstyled fragment with no validation, no select2 and no way back, so customer
quick-create could not work at all. Rewired to the app's own `btn-modal` +
`.contact_modal` idiom, with the container in the initial markup because
`public/js/app.js:525` binds it directly at page load. The help text no longer
says to refresh the page: the customer search added earlier queries the server,
so a newly created customer is findable immediately. Two related findings left
open: the local demo fixture contains 0 repair jobs, and `.env` points at a
non-existent sqlite file that only affects `artisan`, not the served app.

**Dark conversion rendered and measured (2026-08-30).**
The repair record and repair queue were rendered from the real Blade against
the real stylesheet and measured with `getComputedStyle`: 33 distinct text
styles, none below the AA 4.5:1 floor, worst 5.94:1. One real defect surfaced
and was fixed: the checklist emits `outcome-not-applicable` while only
`.outcome-na` was styled, so an N/A row inherited the card's brightest colour
and outranked PASS and FAIL on the dark surface. Pre-existing, but harmless on
the old white card and wrong on the new one. The full authenticated chrome and
`repair/new`, `parts/show`, `diagnostics/show` remain unrendered.

**Recommerce screens brought onto the dark palette (2026-08-30).**
The earlier presentation pass covered stock POS surfaces; the shared stylesheet
has no rules for the module's own classes, so the Recommerce screens still
painted white cards with near-black type inside the dark chrome. `repair/show`,
`repair/index`, `repair/new`, `dashboard/index` and the shared status-tone
partial now take their colours from the `--sb-*` tokens, with each fallback set
to the value the rule had before, so a missing stylesheet degrades to the old
light design instead of white-on-white. Status tones were re-derived for a dark
ground with measured contrast (7.28-8.40:1 text-on-pill, all above AAA) and keep
a light print variant; `--sb-faint` was measured at 3.70:1 and dropped for type.
The three standalone documents stay light on purpose — the print label goes on
white stock. `RecommerceDarkPaletteTest` guards the rule. Source and test
evidence only; not rendered in a browser.

**Presentation UI follow-up — PASSED locally (2026-08-30).**
The shared dark stylesheet now covers the remaining light utility surfaces,
Highcharts chart backgrounds, DataTables loading state, date widgets,
breadcrumbs, and legacy alerts. In-app browser checks rendered the Dashboard,
Recommerce device registry, and POS register screens without changing any
workflow state. This is local source/runtime evidence only and is not deployed
or release evidence.

**Live staging smoke — core flow passed; demo-fixture repair implemented locally (2026-08-30).**
The fictional authenticated estate passed receive → tracked A→B transfer →
Branch B POS sale → exact-device return → Branch B reconciliation. Credit Sale
completed the original isolated sale. This does not advance RC-045 or RC-046
and is not production, hardware, or release evidence.

**Staging Cash smoke and complete flow — PASSED (2026-08-30).**
After correcting the existing demo business currency through the visible Business
Settings UI, the authenticated staging flow passed: tracked receipt transaction
8 created `SB-DV-00000019-1`; transfer `CASH-SMOKE-TRANSFER-20260830` completed
from Branch A to Branch B; Cash sale `INV-0002` posted for RM 1,200.00; the
exact device was returned; and Branch B reconciliation reported
`PASS · core 2 · tracked 2 · legacy 0`. This is live fictional-flow evidence,
not proof that the current local branch has been deployed, and it does not
advance RC-045 or RC-046.

**Publish completed; served deployment still unverified (2026-08-30).**
The reviewed branch was pushed successfully and GitHub Actions run `33300110323`
completed successfully for `17266ca`. A read-only served-asset check still found
the live dark stylesheet at hash `3c2ab4f7…` versus `798306ec…` in the local
published checkout. The cPanel **Update from Remote → Deploy HEAD** step remains
required before claiming that the current branch is deployed or repeating the
smoke as deployment evidence.

**Automatic pull fix attempted; cPanel update still blocked (2026-08-30).**
The workflow was corrected to call `VersionControl/update` for the `staging`
branch before `VersionControlDeployment/create`, with UAPI error validation.
The first run for `61fbc7f` failed in that new cPanel request with process exit
code 5, before the deployment command ran. The live stylesheet fingerprint is
still the old `3c2ab4f7…`; use cPanel **Update from Remote → Deploy HEAD**
manually and verify the served asset before claiming the dark UI is live.

The deployment target also needs confirmation: the cPanel inspection recorded
the domain root as `saverpos-staging-repo/public`, while the deployment script
defaults to the sibling `saverpos-staging/public`. No path change is being
invented until the operator confirms which directory serves the domain.

**GitHub-to-cPanel automation repair (2026-08-30).**
The workflow now pulls the `staging` branch through `VersionControl/update`
before `VersionControlDeployment/create`, retries transient HTTP/network
failures, and reports sanitized UAPI errors. The deployment script now uses
the managed checkout in place when its server-only `.env` is there, while
retaining the sibling-live-directory mode as an explicit override/fallback.
This is source evidence only until a successful run and served CSS fingerprint
confirm the live result.

The first automated run for this repair reached the cPanel job but failed during
`VersionControl/update` with exit code 1, before deployment. This requires a
one-time cPanel correction of the repository path/token/clean-tree setup; it is
not a reason to reopen cPanel for every future GitHub push.

**Payment-account repair now reaches the already-seeded estate (2026-08-30).**
The earlier record said the deployed estate only had to rerun the expansion
seeder before the Cash smoke. That was wrong: the complete POS
`default_payment_accounts` shape was added to `SaverposDemoRuntimeSeeder`,
which runs only against a *fresh* database, while the deployed estate is
repaired by `SaverposDemoExpansionSeeder`, which never touched the column.
The expansion seeder now fills in the payment types a demo branch is missing,
leaving anything already configured alone, and both seeders share one
`SaverposDemoRuntimeSeeder::demoPaymentAccounts()` shape so they cannot drift
again. Measured on a disposable MySQL fixture whose branches were reset to the
deployed estate's state: `Util::payment_types()` returned `[]` for both
branches beforehand — the register offered no payment method at all, so this
was never Cash-specific — and all twelve types including `cash` afterwards,
with a rerun leaving `updated_at` unchanged. The disposable database was
dropped after the run.

**Staging Cash smoke attempted and still unconfirmed (2026-08-30).** The fix
was pushed with approval, but the authenticated smoke on `pos.kkcctv.com.my`
shows both branches still storing an empty payment map, so the seeder has not
run against that database and the deployment did not take effect. No sale was
created. The stock-POS mechanism behind the symptom is now pinned exactly:
`public/js/pos.js:3037` guards the payment-account map but not the requested
key, so a partial map throws `TypeError: Cannot read properties of undefined
(reading 'account')` and the Cash button dies silently. That missing guard is
pre-existing stock code, out of RC-002 scope, and needs its own decision. The
Cash smoke stays unverified until the deployment failure is fixed.

**Staging CD does not deploy pushed commits (2026-08-30).** Diagnosed and
proved rather than inferred: `public/css/saverbro-dark-pos.css` served from
`pos.kkcctv.com.my` is byte-identical (sha256 `3c2ab4f7...`, 25482 bytes) to
its state at `03d49f2`, so the site runs a checkout from before `e69b8dd` and
the last four commits have never been deployed. The workflow calls only
cPanel's `VersionControlDeployment/create`, which deploys the **server**
checkout's HEAD; it never performs the **Update from Remote** step that
`ICORE_CPANEL_STAGING.md` documents as the required first half, so every push
redeploys stale code and reports success. `curl --fail` also cannot see cPanel
UAPI errors, which are returned as HTTP 200 with an `errors` array. Deploying
currently requires the manual cPanel steps. Fixing the pipeline is an
outward-facing change to a credentialed deploy path and is not authorized by
this record.

The first ten implementation tasks are `RC-001` through `RC-010`. They deliberately resolve source/runtime/data uncertainty and establish security, transactions, audit, canonical device identity, and safe code allocation before any UI or stock mutation is built.

No task in this ledger is authorized for implementation by this architecture package alone. Each task should be taken through normal review and approval, and the first production task must not begin until SaverBro chooses to proceed.
