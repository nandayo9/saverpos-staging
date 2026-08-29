# Recommerce Increment Control Record

**Date:** 2026-08-28
**Increment:** Inactive-by-default receive, label, scan, and reconciliation boundary with disposable runtime proof, receive serialization, persisted reconciliation evidence, and mismatch stop-line guard
**Status:** `PASSED DISPOSABLE LOCAL CHECKS — NOT PRODUCTION OR RELEASE READY`

**Continuation note (2026-08-28):** The persisted reconciliation-evidence
increment is source-reviewed and the local browser preview was rechecked. The
protected Device event timeline is now represented in both the API and the
permission-aware Device Detail view. PHP 8.3.33 syntax and PHPUnit checks were
rerun after the continuation patches; the current executable counts below are
evidence for this checkout, while production runtime and hardware gates remain
open.

The local label preview was subsequently rechecked through the prepared
receiving journey at `READY_TO_PRINT`; it now exposes both the opaque QR and
Code 128 visual surfaces without raw token text, and the production print
handoff severs the preview window opener before writing server-rendered markup.
If a browser blocks the preview window, the receiving screen now exits before
the print POST, so no token is issued for an unavailable operator surface.
The token service also rechecks the locked Device scope before committing, and
the disposable feature suite covers unauthorized scope drift without token
creation.

## Scope delivered

The following bounded slice is present under `Modules/Recommerce`:

- inactive module manifest and status entry;
- default-off module and write switches;
- explicit business/location/variation cohort policy;
- proposed Alpha permission catalog and permission/scope intersection;
- minimal Device, identifier, scan-token, ownership-period, custody-period, purchase-assignment, movement, immutable event, and idempotency schema migration with type-scoped hash-only identifier lookup storage;
- Device-related Eloquent entities with encrypted-at-rest raw identifier casting and hidden serialization;
- stable human Device code with check character;
- 256-bit opaque scan token issuance, keyed hashing, constant-time comparison,
  and false-return handling for malformed comparison inputs;
- exact human-code/approved-HTTPS-QR input parser;
- persisted serialization profiles and location-scoped legacy balances so a
  request cannot manufacture an approved reconciliation quantity;
- safe non-printing label payload builder with approved-host QR URL;
- bounded standalone print-ready label renderer using installed Code128 and QR
  SVG components, with the opaque QR URL encoded in geometry and omitted from
  human-readable label text;
- atomic tracked-receiving service requiring exact core scope and idempotency,
  with one Device evidence set per received unit, reachable only through the
  guarded post endpoint; receives for one business serialize before
  idempotency and identifier checks, and open a dated BUSINESS ownership period
  linked to the source purchase transaction plus a LOCATION custody period
  linked to the receive movement;
- source-reviewed Ultimate POS purchase-writer adapter that reuses the core
  transaction, purchase-line, stock, payment-status, activity, and event
  primitives, including stock-over-selling adjustment, without opening a
  nested transaction; it rejects missing business-session date context before
  creating a core transaction;
- protected authenticated read-only reconciliation endpoint backed by a service
  comparing core quantity, tracked Devices, and approved legacy balance without
  automatic correction;
- unexposed QR-token issuance/rotation service with row locking and one-time
  raw-token output;
- immutable Device timeline events for receive, label issuance, and token
  rotation, with safe source metadata only;
- protected read-only Device event timeline endpoint scoped by business,
  location, Device permission, and audit permission, with metadata allowlisting;
- protected label-payload endpoint with no stock mutation;
- protected authenticated print-ready HTML label endpoint with explicit
  issuance/rotation semantics and no-store/no-referrer responses;
- protected receiving prepare endpoint with masked identifier hints (at most
  the final two characters are revealed) and a
  separate guarded post endpoint;
- guarded cohort-bound receiving browser screen that prepares safely with
  writes off and hands posted Devices to label, scan, and reconciliation
  actions only when the write gate is open; its confirmation states the exact
  core quantity delta, Device count, and source transaction returned by the
  atomic result;
- read-only human-code Device Detail route;
- public QR resolver with authentication redirect and clean post-auth URL;
- authenticated read-only scan-resolution API;
- focused PHPUnit tests for identity, parser, identifier, label-payload,
  disposable receiving-transaction, label endpoint, scan resolver, token
  rotation, and read-only reconciliation behavior.

## Safety controls

| Control | Result |
|---|---|
| Recommerce status | `false` |
| `RECOMMERCE_ENABLED` default | `false` |
| `RECOMMERCE_WRITES_ENABLED` default | `false` |
| Database migration executed | `No` |
| Stock mutation route | Guarded receiving post route exists in inactive source; unreachable while disabled |
| Repair table/source change | `None` |
| Core POS route/controller change | `None` |
| Raw QR token stored/logged by new code | `No` |
| Public QR route | Read-only resolver only |
| Authenticated scan API | Read-only result; `actions` is empty |

## Files added or changed

### Module source

- `Modules/Recommerce/module.json`
- `Modules/Recommerce/Config/config.php`
- `Modules/Recommerce/Providers/RecommerceServiceProvider.php`
- `Modules/Recommerce/Providers/RouteServiceProvider.php`
- `Modules/Recommerce/Routes/web.php`
- `Modules/Recommerce/Http/Controllers/DeviceController.php`
- `Modules/Recommerce/Http/Controllers/DeviceEventController.php`
- `Modules/Recommerce/Http/Controllers/ScanController.php`
- `Modules/Recommerce/Support/CohortPolicy.php`
- `Modules/Recommerce/Support/AuthorizationGate.php`
- `Modules/Recommerce/Support/Identity/DeviceCode.php`
- `Modules/Recommerce/Support/Identity/OpaqueScanToken.php`
- `Modules/Recommerce/Support/Identity/ScanInput.php`
- `Modules/Recommerce/Support/LabelPayloadBuilder.php`
- `Modules/Recommerce/Support/Identity/StrongIdentifierHasher.php`
- `Modules/Recommerce/Services/TrackedReceivingService.php`
- `Modules/Recommerce/Exceptions/ReceivingInProgressException.php`
- `Modules/Recommerce/Exceptions/ReceivingReconciliationBlockedException.php`
- `Modules/Recommerce/Services/DeviceEventRecorder.php`
- `Modules/Recommerce/Services/DeviceEventTimelineService.php`
- `Modules/Recommerce/Services/UltimatePosPurchaseWriter.php`
- `Modules/Recommerce/Services/StockReconciliationService.php`
- `Modules/Recommerce/Services/ReconciliationRunService.php`
- `Modules/Recommerce/Services/ScanTokenIssuanceService.php`
- `Modules/Recommerce/Services/LabelRenderer.php`
- `Modules/Recommerce/Http/Controllers/LabelController.php`
- `Modules/Recommerce/Http/Controllers/ReceivingController.php`
- `Modules/Recommerce/Http/Controllers/ReconciliationController.php`
- `Modules/Recommerce/Entities/*.php`
- `Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php`
- `Modules/Recommerce/Database/Migrations/2026_08_28_000003_create_recommerce_reconciliation_tables.php`
- `Modules/Recommerce/Database/Migrations/2026_08_28_000004_harden_recommerce_event_identity.php`
- `Modules/Recommerce/Database/Migrations/2026_08_28_000005_create_recommerce_label_job_tables.php`
- `Modules/Recommerce/Database/Migrations/2026_08_28_000006_create_recommerce_ownership_periods.php`
- `Modules/Recommerce/Database/Migrations/2026_08_28_000007_create_recommerce_custody_periods.php`
- `Modules/Recommerce/Resources/views/device/show.blade.php`
- `Modules/Recommerce/Resources/views/labels/device.blade.php`
- `Modules/Recommerce/Resources/views/receiving/index.blade.php`
- `scripts/recommerce-static-check.mjs`
- `Modules/Recommerce/README.md`
- `modules_statuses.json` (`Recommerce: false`)
- `tests/Unit/RecommerceIdentityTest.php`
- `tests/Unit/RecommerceBoundaryTest.php`
- `tests/Feature/RecommerceReceivingIntegrationTest.php` (disposable SQLite
  receiving contract proof)
- `public/recommerce-demo.html` (local-only UX simulation; no database writes)
  - The Devices view now renders a safe local event timeline using the same
    allowlisted operational fields as the protected API; raw identifiers and
  token material remain absent.
- `Modules/Recommerce/Resources/views/receiving/index.blade.php` is the
  guarded production receiving screen: prepare-only by default, with posting,
  label, scan, and reconciliation handoffs available only after the explicit
  write gate is open; the label action opens the authenticated print-ready HTML
  endpoint without placing the opaque QR URL in the operator DOM.

### Planning/control documents

- `RCR_002_PROVISIONAL_PROFILE.md`
- `RCR_002_EVIDENCE_INTAKE_TEMPLATE.md`
- `RCR_002_RECONCILIATION_SPEC.md`
- `RCR_002_HARDWARE_TEST_PROTOCOL.md`
- `RCR_003_RECEIVING_CONTRACT_DECISION.md`
- this control record

## Verification

### Passed static checks

- `modules_statuses.json` and `Modules/Recommerce/module.json` parse as valid JSON.
- `scripts/recommerce-static-check.mjs` passed with the bundled Node runtime,
  covering the static preview and receiving Blade script blocks, default-off
  markers, cache/referrer boundaries, type-scoped duplicate checks, masking,
  UUID/stale-evidence invalidation, and the narrowed 409 exception boundary.
- Recommerce status remains explicitly `false`.
- Composer already maps `Modules\\` to `Modules/`; no dependency manifest change was required.
- Route paths are separated correctly: authenticated module pages use `/recommerce/...`; the documented public QR path is `/s/d/<opaque-token>`.
- The schema includes separate serialization-profile, location-scoped legacy-balance, identity, identifier, token, assignment, movement, immutable event, and command-receipt tables with uniqueness/index controls; reconciliation approval metadata is required by the service before evidence is usable; identifier lookup uses a required business-and-type-scoped normalized hash (so SQL uniqueness cannot be bypassed with `NULL`) and no plaintext normalized-value column remains.
- The Device event timeline records actor and source-command/source-transaction provenance with safe operational metadata only; raw identifiers and token material are excluded.
- Each receive and label event appends one pending, tenant-scoped outbox message in the same transaction; outbox payloads contain only safe event metadata, and event/outbox rows roll back together when the surrounding command fails. No external dispatcher is enabled in this inactive scaffold.
- New receive/label events also carry a unique durable UUID and version; the UUID is included in the safe outbox/timeline projection so downstream consumers do not depend on auto-increment IDs.
- Each received business-owned Device now gets one open, dated ownership period linked to its purchase transaction inside the same transaction; the open-period key prevents a second active period while allowing closed history later.
- Each receive movement also opens one dated LOCATION custody period linked to that movement; its open-period key prevents two active custody locations for the same Device while preserving future transfer history.
- Label issuance and token rotation append `LABEL_TOKEN_ISSUED` or
  `LABEL_TOKEN_ROTATED` timeline events in the same transaction as token state
  changes; raw token material is excluded from both event metadata and API
  responses.
- The label payload exposes only safe description/code/QR/template fields; no raw-token, token-hash, or manufacturer-identifier field is returned.
- The receiving prepare and post paths use the same business-and-identifier-type hash namespace. The receiving post route delegates only to the atomic service and denies by default through the write switch, permission, cohort policy, core location permission, and same-business location/product/variation/supplier/tax checks; its command includes explicit received-purchase context and its core callback must return matching business, location, product, variation, and exact quantity before Device creation. Invalid normalized identifiers are converted to controlled command rejection before the core callback.
- The tracked receive transaction locks its business row before idempotency and existing-identifier checks; the normalized-hash unique key remains the final database guard. A bounded file-backed SQLite process race now proves one same-identifier commit and one rejection; production database lock/error semantics remain outstanding.
- The route provider registers no Recommerce routes while disabled; the disabled
  reconciliation URL returns an HTTP 404 in the disposable Laravel stack. When
  explicitly mapped with the module enabled, the receive prepare/post routes
  are authenticated POST routes, while a disabled write switch still denies
  the write cohort.
- The reconciliation route is an authenticated, rate-limited GET endpoint with numeric variation/location validation, generic cache-safe request rejection, cohort and permission checks through the service, out-of-scope 404 behavior, generic no-store rejection for service validation failures, `no-store`/`no-referrer` response headers, and no correction actions or writes in its response path; its enabled route is exercised through the local Laravel HTTP stack in disposable tests. Scan and receiving JSON responses use the same no-store/no-referrer boundary.
- Reconciliation evidence is a separate authenticated POST route with a distinct write permission. It records the scoped comparison as an immutable run snapshot with a SHA-256 result hash and creates one open issue snapshot for non-`PASS` results; it never edits POS, Device, or aggregate stock state. The disposable integration test covers a retained `PASS` snapshot followed by a blocking mismatch issue, while the existing GET route remains read-only.
- The Ultimate POS purchase-writer adapter is container-bindable and only reachable through the guarded post endpoint; it uses the existing core purchase utilities and event path, requires the business-session date format, and its real class now passes a disposable authenticated HTTP receive test with legacy utility seams mocked. Production adapter/runtime verification remains outstanding.
- Only the dedicated in-progress receiving exception maps to a `409` retry response; unexpected runtime failures from the core purchase writer are not mislabeled as duplicate work.
- `TrackedReceivingService::executeWithUltimatePosPurchase()` is the sole production seam for invoking that adapter; no existing POS controller calls it while the module is disabled.
- The reconciliation service and protected endpoint report `PASS`, `MISMATCH`, `EXCEPTION`, or `UNAVAILABLE`; they exclude in-transfer Devices from on-hand count, perform no writes, never treat a missing core row as zero, and expose only persisted profile/balance evidence pointers. Caller-supplied legacy balances are rejected.
- The production receiving handoff keeps the read-only reconciliation button separate from the guarded `Record evidence` action; the latter is disabled unless the explicit reconciliation-record permission and write cohort both pass.
- New tracked receiving is stop-lined when the current scoped reconciliation is `MISMATCH` or `EXCEPTION`; the guard runs after idempotency replay/conflict checks, so a completed command can still replay its original result without creating a second write. The dedicated `ReceivingReconciliationBlockedException` maps to a generic no-store `409`, while the core writer is not called and no new command receipt is created.
- The unauthenticated public QR resolver now redirects to a neutral `/login`
  path before any token lookup, so valid-format known, revoked, and unknown
  tokens share the same unauthenticated response without carrying the opaque
  token in the redirect target; the token remains absent from the post-auth
  redirect response.
- Token issuance requires print permission and the write/cohort boundary; rotation additionally requires its dedicated permission, allows one active token per Device, and records rotation without storing raw token material.
- The label endpoint is POST-only, rate-limited, authenticated, and returns a no-store safe payload; physical printer/decoder proof remains unrun.
- Rendered label attempts retain one safe `recommerce_label_jobs` row and item inside the token issuance transaction. A reprint requires explicit rotation, preserves the same Device identity, records a new token/job item, and renderer failure leaves neither job nor token behind; raw token material is absent from job request metadata.
- The authenticated label print path renders installed Code128 and QR SVG components into a standalone print-ready view, includes only safe device description/code/template text, excludes the opaque URL from human-readable markup, and preserves no-store/no-referrer headers. The real printer, stock geometry, and decoder proof remain unrun.
- Label rendering now runs inside the scan-token issuance transaction; a renderer failure rolls back the token and label timeline event, preserving a retryable state without requiring rotation. This behavior is covered by the disposable feature suite.
- The bounded renderer also rejects SVG output that echoes the opaque QR URL, keeping the no-human-readable-token invariant at the renderer boundary as well as in the Blade view.
- The JSON label-payload path also builds its safe payload inside the scan-token issuance transaction; a payload-builder failure rolls back the token and label timeline event and permits an immediate retry without rotation.
- The scanned Device detail route is scoped by business/location/read permission
  and marks its rendered response `no-store`/`no-referrer`; full app-layout
  rendering remains a production-runtime gate because the disposable fixture
  does not contain Ultimate POS permission/notification tables.
- The Device event timeline route is read-only, requires both Device-view and
  audit permissions within the same location/variation cohort, returns only
  allowlisted operational metadata through the shared timeline projection. Each
  emitted timeline event carries a location scope, and events without the
  current Device location are excluded before projection; responses are marked
  `no-store`/`no-referrer`.
- The local Devices preview renders receive, label, scan, and reservation
  events as safe operator context; it is synthetic UI state and is not runtime
  or production evidence.
- The local Receiving preview now applies the same identifier-type namespace as
  the production preflight: normalized values duplicate only within the same
  identifier type, while different types remain independently valid.
- The rendered receiving handoff and local preview now ignore an identical
  scan read within a short debounce window, preserving the first result and
  avoiding duplicate resolution requests; the local browser check displayed
  the explicit `Duplicate scan ignored` feedback.
- The authenticated Device Detail Blade view uses the same shared timeline
  projection and only queries events when the separate audit permission is
  present; without that permission it returns a neutral unavailable message.
  The detail view also presents the eager-loaded ownership and custody period
  history, including source purchase/movement references, without exposing raw
  identifiers or token material.
- Receiving preparation is bounded, cohort/permission/location checked, duplicate-aware, and only returns a post URL when the write gate passes. Both prepare and post reject malformed request payloads with generic `422` no-store/no-referrer responses; the separate post endpoint delegates to the atomic service, and no write route is reachable while the module or write switch is off.
- The production receiving handoff invalidates prepared evidence on any draft or purchase-context edit, resets the idempotency UUID for the revised command, clears stale Device/scan/reconciliation panels, and preserves the UUID only for a true retry of the unchanged command. The static preview masks identifier hints to a two-character suffix and applies type-scoped duplicate checks.

### Passed executable checks

- PHP syntax lint passed for all Recommerce source and focused test files using
  the locally installed PHP 8.3.33 binary.
- The standalone Recommerce Alpha migration passed on disposable in-memory
  SQLite.
- The identifier schema contract was tightened so `normalized_hash` is
  non-null; the standalone migration continued to pass after this change.
- `tests/Unit/RecommerceIdentityTest.php` passed: 13 tests and 41 assertions;
  raw control characters are rejected before whitespace trimming, including a
  trailing NUL, and raw identifier values are encrypted at rest and hidden
  from serialization.
- `tests/Unit/RecommerceBoundaryTest.php` passed: 10 tests and 44 assertions;
  default-off route registration, write-switch, cohort, permission, business,
  core-callback, and malformed-identifier intersections remain deny-by-default.
- Full `tests/Unit` suite passed: 24 tests and 91 assertions. Existing PHP 8.3
  compatibility deprecation warnings remain non-failing.
- `tests/Feature/RecommerceReceivingIntegrationTest.php` passed in the current
  rerun: 51 tests and 472 assertions against a disposable SQLite schema using the real Recommerce
  entities, services, and label/scan controller seams. It proves exact
  unit-to-Device evidence creation, completed-command replay without a second
  core callback, normalized duplicate-identifier rejection before the
  callback, conflicting idempotency-key rejection, transaction rollback when
  the core quantity does not match, core purchase-adapter utility sequencing
  and missing-date-context rejection, receiving controller forwarding of
  business-scoped commands and generic no-store rejection behavior,
  receiving prepare responses with masked identifiers and no write URL while
  writes are off, controlled duplicate/malformed-input rejection,
  safe label response headers/payload, active-versus-replaced QR resolution,
-  the scoped read-only reconciliation controller response with no correction
  actions, persisted-profile and persisted-legacy-balance evidence,
  missing-evidence `UNAVAILABLE` behavior, caller-balance rejection,
  out-of-scope 404 and generic no-store rejection behavior, and
  PASS/MISMATCH/EXCEPTION/UNAVAILABLE reconciliation states without
  reconciliation writes. A separately permissioned reconciliation-evidence
  POST also retained a PASS snapshot and then a blocking mismatch issue
  without changing stock. The enabled reconciliation route also passed through
  the local Laravel HTTP stack with authentication, route binding, session
  encryption, and safe response-header assertions; the guarded receiving POST
  route was likewise exercised over HTTP with a container-isolated service
  seam; the enabled label-payload and scan-resolution routes were also
  exercised over authenticated HTTP with safe-payload and no-store assertions.
  The approved HTTPS QR scan path and unauthenticated public resolver redirect
  were additionally exercised over HTTP without exposing raw token material.
  The disabled Recommerce reconciliation URL also returned HTTP 404 over the
  same disposable Laravel stack; the disabled receiving POST URL also returned
  HTTP 404, and protected reconciliation/scan routes returned HTTP 401 when
  unauthenticated. A bounded file-backed SQLite process race for two different
  commands carrying the same normalized identifier produced exactly one
  committed receive and one rejection, leaving one Device, identifier, and
  StockCommand.
  A guarded authenticated receiving POST also traversed the real Recommerce
  service and adapter classes, persisted a Recommerce-sourced core transaction,
  purchase line, StockCommand, and Device evidence in one disposable test.
  The authenticated receiving prepare route was also exercised over HTTP,
  returning masked identifiers with no post URL while writes were off and
  exposing the post URL only after the write gate was explicitly enabled.
  One separate end-to-end disposable HTTP journey also completed receive,
  label issuance, human-code scan resolution, and PASS reconciliation in order,
  leaving one Device, identifier, and active scan token.
  A post-receive label failure also returned a generic retryable no-store
  response without creating a scan token or duplicating the Device.
  Invalid reconciliation query input also returned a generic no-store/no-referrer
  422 without a validation-detail payload.
  Invalid receiving prepare and post payloads also returned generic
  no-store/no-referrer 422 responses without validation-detail payloads over
  authenticated HTTP.
  Each committed receive also wrote one immutable `RECEIVE_POSTED` Device event
  with source provenance; replay, rollback, and the bounded same-identifier
  race did not duplicate or retain event rows, and event metadata contained no
  raw identifier value.
  Initial label issuance and token rotation also wrote distinct immutable
  timeline events; failed label configuration left only the receive event, and
  rotation event metadata contained no raw token.
  Each committed receive also opens one dated LOCATION custody period linked to
  its receive movement; rollback and bounded same-identifier race checks leave
  no orphaned or duplicate open custody evidence.
- Complete configured PHPUnit suite passed: 76 tests and 564 assertions.
- The complete configured PHPUnit suite also passed on PHP 8.5.9 with the
  existing dependency deprecation warnings; PHP 8.3.33 remains the primary
  local verification runtime.
- Laravel boot/version check passed: 9.52.4.
- `artisan module:list` confirmed `Recommerce` is disabled.
- `composer validate --no-check-publish` passed with only pre-existing
  warnings about exact dependency constraints.
- Local UX preview served at `http://127.0.0.1:8002/recommerce-demo.html` and
  exercised in the in-app browser: receiving add/prepare, typed identifier
  capture through the SERIAL, ASSET_TAG, and IMEI selector, blank-input
  blocking, case-insensitive duplicate-identifier
  blocking, backend-normalized duplicate aliases, identifier type/value
  validation with matching browser max-length limits, safe text insertion,
  numeric and non-negative unit-cost preflight,
  visible 50-unit batch counting and over-limit blocking,
  identifier-field autocomplete/spellcheck opt-out,
  Add unit disabling at the 50-unit cap with automatic re-enablement after
  removal,
  field-level `aria-invalid` feedback and first-problem focus behavior,
  masked identifier-hint payload inspection with raw values absent from the
  preflight JSON, gated safe-payload copy, and reviewed-draft invalidation
  after context edits, simulated
  `READY_TO_PRINT` label
  issuance with hidden raw token material, human-code and approved-QR scan
  resolution (with QR input redacted after acceptance), DOM-node-safe scan
  result rendering, invalid-scan blocking,
  scanner-style Enter actions for identifier add and scan resolution,
  label-device changes invalidating issued-label evidence, and scan edits
  invalidating a prior resolution before reconciliation,
  label issuance disabled until a batch is prepared and re-disabled after a
  prepared-draft edit,
  Labels now displays the correct Step 2 of 4 label to match the Overview
  progress model,
  Scan center and Reconciliation now expose matching Step 3 and Step 4 context;
  all four labels were verified alongside the completed guided journey,
  and direct hash links now reopen their requested view after a refresh,
  direct read-only Scan and Reconciliation access no longer claims prior
  workflow steps are complete, while the full first-slice journey still marks
  all four steps done,
  read-only reconciliation across `PASS`, `MISMATCH`, `EXCEPTION`, and
  `UNAVAILABLE`, result filtering, and decision-context inspection. This is
  simulation evidence only; it does not prove the disabled production module,
  printer/decoder path, or database write path. Device Registry search,
  including case-insensitive exact Device-code matching, and safe detail
  inspection were also exercised; identifier evidence and operational history
  remain protected in the UI, and Device Detail now renders a synthetic safe
  timeline with receive, label, and scan context. The refreshed browser view
  showed three events with no sensitive values. Device/Reconciliation result
  controls now expose contextual accessible names. Audit Trail command-type filtering and
  evidence-boundary inspection were exercised; changing Reconciliation,
  Device, or Audit filters now clears stale detail context. Protected identifiers, raw scan
  tokens, and secrets remain excluded. Primary dynamic status, result, summary,
  empty-state, and detail surfaces now expose polite live-region semantics and
  were verified
  without disturbing the prepared-label gate. Control State was exercised and shows
  module disabled, writes disabled, disposable local runtime, and the evidence
  required before production enablement. The guided browser journey was also
  completed end to end: receive, label, scan, reconciliation, then overview;
  all four first-slice steps reached the completed state. The local preview
  reset control was exercised and returned the workflow to its initial state
  for repeatable testing. The label flow now exposes a print action after
  `READY_TO_PRINT`; its print stylesheet isolates the label surface while
  omitting the safety note and application chrome. A valid-but-unmatched
  Device code was also tested and correctly remained blocked from the next
  workflow step. Device Registry also now exposes a clear empty-search state;
  protected identifiers remain explicitly non-searchable. Screen deep links
  were exercised (`#reconcile` and `#controls`); navigation preserves the
  selected view, updates the browser tab title, and Reset Preview clears the
  hash and returns to overview.
  Receiving Clear draft was also exercised after preparation; it now removes
  unit rows, hides the Labels handoff, restores the Step 1 progress state, and
  blocks validation until purchase context is entered again. The reset was
  also verified after completing the full first slice: stale label issuance,
  scan resolution, and reconciliation results are cleared before a new draft
  can begin.
  Editing Receiving context after preparation was also verified to invalidate
  the prepared handoff and clear dependent evidence, requiring a fresh
  preflight before the slice can continue.
  Adding a unit after preparation follows the same invalidation path and was
  verified to retain the new row while disabling the stale handoff.
  The initial preview state was verified to start at Overview; opening
  Receiving then advances the first-slice progress after a batch is prepared
  and enables the Labels handoff. Active navigation was also verified to expose
  `aria-current="page"` for the selected view and remove it from inactive
  views; the default Overview markup now exposes that state on first render,
  and a fresh `#controls` load was verified to select Controls correctly.

### Not run

- Full route listing, full application migrations, database constraints, production receiving write-path
  and production reconciliation-endpoint integration, authenticated production-module browser journeys, real scanner,
  printer, QR/Code128 decoding, production database concurrency semantics, authorization runtime tests, and
  a true narrow-viewport browser pass. Mobile layout rules are present, but the
  current in-app browser stayed at a 1052px CSS viewport during this check.
- The current rerun includes the continuation tests plus the additional
  identifier-scope and out-of-location-history assertions, the narrowed
  receiving 409 boundary, the reconciliation generic-rejection boundary, and
  the strengthened route assertions. It covers persisted reconciliation evidence,
  token-safe unauthenticated QR redirect, no-store/no-referrer response
  coverage, the `web`/CSRF route group, and malformed-UTF-8 idempotency
  rejection, short-identifier masking, and the protected event-timeline
  endpoint, plus type-scoped identifier uniqueness and out-of-location history
  exclusion. PHP 8.3.33 syntax and PHPUnit reruns are now current evidence for
  this checkout.
- The guarded receiving Blade screen was source-reviewed and its embedded
  JavaScript parsed after replacing Blade-only values with test placeholders;
  its controller response contract is covered by a focused 10-assertion test,
  but the screen has not yet run through the full Laravel renderer or an
  authenticated production-module browser journey because the disposable
  fixture does not contain the app-layout permission/session tables.

The full route-list command is currently blocked by a pre-existing missing
`PaymentAccountController` reference outside Recommerce. PHP 8.3.33 and
Composer are available locally; the UX preview uses a disposable local static
runtime, while no production database, scanner, printer, or production system
was accessed.

## Release decision

`NOT RELEASE READY`.

This increment is source code prepared behind a disabled boundary. Before enabling it, recover the RCR-001 runtime/module evidence, complete RCR-002 Alpha data and hardware profiling, prove RCR-003's transaction contract, run the migration on a disposable production-like database, and execute the listed browser/security/runtime tests.
