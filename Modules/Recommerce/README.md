# Recommerce module

This is an Ultimate POS capability, not a second application. It runs inside
the native POS admin shell and shares its business, locations, roles, contacts,
products, stock, sales, payments, and accounting. The operational write
boundary remains explicitly gated until the pilot evidence gates are cleared.

The integrated operator flow is: create and receive normal stock in
**Purchases**, then select **Receive Devices** on an eligible received
whole-unit line. The scan workspace displays expected, registered, and
remaining units and can be resumed in bounded batches. It creates Device
identity/custody evidence only; it never creates a second POS purchase or
changes aggregate stock, payments, or accounting. Newly registered Devices
start as `RECEIVED_PENDING_INSPECTION`, so they cannot be selected for POS sale
or transfer until the lifecycle process clears them. Customer Repairs is a
separate main POS workspace for customer-owned, non-stock Devices.

- `modules_statuses.json` keeps `Recommerce` disabled.
- `RECOMMERCE_ENABLED` defaults to `false`.
- `RECOMMERCE_WRITES_ENABLED` defaults to `false`.
- `Support/CohortPolicy.php` requires the module switch and every read cohort
  value for reads; write paths additionally require the write switch.
- `Support/AuthorizationGate.php` requires a catalogued permission in addition
  to the configured scope; all available routes remain unreachable while the
  module status or feature switch is off.
- `Support/Identity/DeviceCode.php` provides a stable, checkable human code
  derived from the persisted Device ID.
- `Support/Identity/OpaqueScanToken.php` issues 256-bit tokens and stores only
  an application-keyed hash; it does not persist or log raw tokens.
- `Support/Identity/ScanInput.php` accepts only exact Device codes or HTTPS QR
  paths on the explicitly configured resolver host.
- `Support/Identity/StrongIdentifierHasher.php` normalizes physical identifiers
  for keyed, business-scoped lookup without retaining a plaintext normalized
  value; the database guard is scoped by business and identifier type, matching
  the approved data model while preventing duplicates within one identifier
  namespace.
- `Support/LabelPayloadBuilder.php` prepares a safe, non-printing label payload
  without returning a token hash or persisting a raw token.
- `Services/LabelRenderer.php` converts the one-time issuance payload into a
  bounded standalone HTML label with installed Code128 and QR SVG geometry;
  the opaque QR URL is not printed as human-readable text. The print path runs
  this renderer inside token issuance, so a rendering failure rolls back the
  token and its timeline event for a safe retry; SVG output that echoes the
  opaque URL is rejected at the renderer boundary.
- `Services/TrackedReceivingService.php` defines the atomic receiving contract
  used by the guarded post endpoint, with idempotency, exact core-quantity
  assertion, same-transaction Device evidence creation, and a dated BUSINESS
  ownership period linked to the source purchase plus a LOCATION custody period
  linked to the receive movement; its command includes explicit received-
  purchase context rather than hidden request state.
- `Services/DeviceEventRecorder.php` writes the minimal immutable receive event
  timeline inside that same transaction, assigning each new event a durable
  UUID/version and recording label issuance/rotation without raw identifiers
  or token material. Each event also gets one pending tenant-scoped outbox
  message with the same safe metadata; no external dispatcher is enabled in
  this inactive scaffold.
- `Services/UltimatePosPurchaseWriter.php` is the source-reviewed adapter for
  the existing Ultimate POS transaction, purchase-line, stock-utility,
  payment-status, activity-log, and purchase-event primitives. It is not
  exposed directly by a route; `TrackedReceivingService` owns the transaction
  when the guarded receiving-post endpoint invokes it.
- `TrackedReceivingService::attachToExistingUltimatePosPurchase()` is the
  production receiving seam for normal Purchases: it locks the received
  purchase line, bounds the unassigned unit count, and creates Device evidence
  without writing aggregate stock a second time. The legacy special-purchase
  seam remains compatibility-only and is not offered as an operator start.
- `Services/StockReconciliationService.php` backs the protected read-only
  reconciliation endpoint, comparing core location quantity against tracked
  Devices plus approved persisted evidence. A `TRACKED_REQUIRED` serialization
  profile establishes zero legacy stock; a `LEGACY_MIXED` profile requires a
  location-scoped `recommerce_legacy_stock_balances` row. Missing evidence is
  `UNAVAILABLE`, profile/balance identifiers are returned as audit pointers,
  and the endpoint never accepts a caller-supplied balance. The same
  calculation blocks a new tracked receive when an existing scoped result is
  `MISMATCH` or `EXCEPTION`; idempotent completed-command replays are checked
  first and remain safe.
- `Services/ReconciliationRunService.php` provides an explicit, separately
  permissioned evidence-recording path. It stores an immutable safe snapshot,
  SHA-256 result hash, and one open issue snapshot for `MISMATCH`, `EXCEPTION`,
  or `UNAVAILABLE` results; it never edits POS or tracked stock state.
- `Services/ScanTokenIssuanceService.php` issues or rotates one opaque token
  under print and separate rotation permission checks; safe label preparation
  and print rendering run inside the issuance transaction, so a failed builder
  or renderer cannot strand an active token; raw token output is one-time and
  not persisted.
- `Http/Controllers/LabelController.php` exposes the protected print-payload
  endpoint and a separate authenticated print-ready HTML endpoint; it creates
  no stock movement and marks responses `no-store`. Rendered single-label
  attempts retain a safe `LabelJob`/item record inside the issuance transaction;
  an explicit rotation creates a new audited print attempt for the same device.
- `Http/Controllers/DeviceController.php` and the device view provide a
  read-only, exact human-code lookup with neutral denial and location scope.
- `Http/Controllers/DeviceEventController.php` provides a protected,
  read-only, business/location/audit-permission-scoped timeline. It whitelists
  event metadata and never returns raw identifiers or token material.
- `Services/DeviceEventTimelineService.php` is the shared safe projection used
  by the JSON endpoint and Device Detail view, preventing the two surfaces from
  drifting into different event exposure rules. It requires a current-location
  scope marker on each event, excluding unscoped or historical out-of-location
  events from the operator timeline.
- `public/recommerce-demo.html` mirrors that boundary in the local Devices
  view with a synthetic safe event timeline; it performs no API or database
  writes.
- `Http/Controllers/ScanController.php` provides a read-only opaque-token
  resolver. Authorized staff are redirected to protected Device Detail; public
  scans are rendered only when an explicitly published customer-safe
  certification exists. Unknown, revoked, and non-certified Device tokens
  share the same no-store 404 response.
- `ReconciliationController.php` exposes authenticated GET
  `/recommerce/reconciliation/{variationId}` with a required `location_id`;
  approved balance evidence is read from the Alpha tables, the response is
  read-only and no-store, and caller overrides are rejected.
- The authenticated POST
  `/recommerce/reconciliation/{variationId}/runs` records the same scoped
  comparison as retained evidence using the separate
  `recommerce.stock.reconcile.record` permission; it is no-store and returns
  only safe snapshot/result metadata.
- The authenticated GET `/recommerce/devices/{deviceCode}/events` endpoint
  exposes the allowlisted immutable event timeline for authorized operators;
  it performs no mutation and returns no raw identifier or token material.
- The authenticated `POST /recommerce/scans/resolve` path accepts exact human
  codes or approved QR URLs and returns only a safe read result; it has no
  mutation action.
- `ReceivingController.php` exposes a bounded prepare step and a guarded post
  endpoint. Prepare only returns a post URL when the write switch, permission,
  and cohort gate all pass; the post endpoint delegates to the atomic service
  and reviewed Ultimate POS purchase adapter.
- `Resources/views/receiving/index.blade.php` provides the guarded receiving
  browser handoff. It can prepare safe hints with writes off and only exposes
  posting/label/scan/reconciliation actions when the explicit write gate and
  cohort permissions are present. Its post confirmation reports the exact core
  quantity delta, Device count, and source transaction returned by the service;
  the label action opens the authenticated print-ready HTML endpoint in a new
  tab without placing the opaque QR URL in the operator DOM, and identical
  keyboard-wedge scan reads are debounced for a short client window. The
  read-only reconciliation action stays separate from the guarded `Record
  evidence` action.
- The receiving controller maps only the dedicated
  `ReceivingInProgressException` to a retryable `409`, and maps the dedicated
  `ReceivingReconciliationBlockedException` to a stop-line `409`; unexpected
  runtime failures from the core purchase adapter are not mislabeled as
  duplicate work.
- `scripts/recommerce-static-check.mjs` provides the dependency-free static
  verification command for the preview and receiving Blade handoff when the
  PHP runtime is unavailable or a fast pre-PHP check is useful.
- The Alpha identity migration and entities are present but are loaded only
  when the module is explicitly enabled; no migration is executed automatically.
- No production Recommerce route is active while the module is disabled. The
  source contains a native Ultimate POS Recommerce operations landing page,
  device registry, scan/entry, tracked receiving, reconciliation, repair
  workbench, customer-repair intake, and internal-refurbishment intake. Every
  route remains separately guarded by the module, cohort, location, and named
  permission checks.
- When the module is explicitly enabled, authenticated `GET /recommerce/health`
  reports the native POS sidebar integration and makes the cohort/permission
  write boundary visible to deployment checks; it does not mutate data.
- Authenticated module routes are under `/recommerce`; the public QR resolver
  uses `/s/d/<opaque-token>` and is read-only. An authorized staff scan opens
  the protected Device Detail view. An unauthenticated or non-staff scan can
  render only an explicitly published SaverBro certification: product name,
  masked serial suffix, grade, QC pass, battery health, purchase date, and
  warranty expiry. Custody, ownership, price/cost, margins, raw identifiers,
  events, and token material are never part of that public projection.
- `DeviceCertificationService` permits publication only for an already-sold
  Device under the dedicated `recommerce.device.certify` write permission. It
  does not create a sale or a second accounting ledger. Exact per-device sale
  price and gross-profit display remain blocked on an evidence-linked POS sell
  line assignment rather than being inferred or manually duplicated.
- `DeviceLifecycleService` projects exact, selected tracked devices onto the
  existing POS sell, sell-return, and completed stock-transfer transactions.
  It uses locked Device rows, append-only disposition/movement/event evidence,
  open-period closure, and unique active-device keys; it never creates core
  inventory or accounting rows. A final tracked sale without exactly one
  selected SaverBro code per unit is rejected. Status-only tracked transfer
  completion is rejected because it has no physical-device selection payload.
  Returned devices default to `RETURNED_PENDING_INSPECTION`, not available
  stock. See `RCR_008_DEVICE_LIFECYCLE_ASSESSMENT.md` and
  `RCR_008_TRACKED_DEVICE_PILOT_RUNBOOK.md` in the project root.
- The owned Repair vertical slice includes a dedicated `/recommerce/repair/new`
  counter intake, transactional customer-owned Device lookup/creation with
  `stock_participation=NONE`, persisted PASS/FAIL/NOT_APPLICABLE intake checks,
  an append-only state timeline, a printable repair record, and a separate
  rate-limited `/recommerce/repair/status/<job>/<opaque-token>` projection.
  The public projection excludes customer contact data, internal notes,
  financial data, diagnostics, and access credentials.

## POS integration and activation sequence

1. During a planned maintenance window, enable the `Recommerce` module status
   while keeping `RECOMMERCE_ENABLED=false`. Run the module migrations; the
   migrations also register the named Recommerce permissions when the Ultimate
   POS permissions table is present. Assign only the minimum required role
   permissions in the native Role editor. No Recommerce HTTP route is exposed
   by this preparation step.
2. Configure the one approved business, location, resolver host, and allowed
   variations (`RECOMMERCE_COHORT_VARIATION_IDS=303,404`). Confirm the
   dedicated cohort and ordinary Ultimate POS location permission both match
   the intended pilot.
3. Enable `Recommerce` and `RECOMMERCE_ENABLED=true` for read-only navigation
   first. Verify Operations overview, Device registry, Scan & Entry, Repair
   workbench, and Reconciliation in the signed-in POS shell.
4. Only after receiving, label printer/decoder, repair, internal stock
   adjustment, concurrency, and reconciliation evidence pass, set
   `RECOMMERCE_WRITES_ENABLED=true` for the approved cohort.

The dependency-free Recommerce static check covers the source and Blade
handoff. The current checkout has local PHP 8.3 and Node runtimes, and the
isolated Recommerce suite plus PHP lint have been run successfully; this is
local source/fixture evidence, not proof of a signed-in production POS shell,
Windows hardware, or release deployment. Do not enable it in a deployment
until RCR-001 through RCR-003 are proven and the module boot, authorization,
route, repair transaction, public-lookup privacy, and ordinary POS regression
tests pass in the target environment.
