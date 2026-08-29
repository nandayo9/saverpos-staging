import fs from 'node:fs';
import path from 'node:path';

const checkout = path.resolve(process.argv[2] || '.');

function read(relativePath) {
    return fs.readFileSync(path.join(checkout, relativePath), 'utf8');
}

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

function parseScripts(markup, label) {
    const scripts = [...markup.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/gi)];
    assert(scripts.length > 0, `${label}: no script blocks found`);
    scripts.forEach((match, index) => {
        try {
            new Function(match[1]);
        } catch (error) {
            throw new Error(`${label}: script ${index + 1} failed to parse: ${error.message}`);
        }
    });
    return scripts.length;
}

const html = read('public/recommerce-demo.html');
const blade = read('Modules/Recommerce/Resources/views/receiving/index.blade.php');
const statuses = read('modules_statuses.json');
const config = read('Modules/Recommerce/Config/config.php');
const controller = read('Modules/Recommerce/Http/Controllers/ReceivingController.php');
const purchaseController = read('app/Http/Controllers/PurchaseController.php');
const reconciliationController = read('Modules/Recommerce/Http/Controllers/ReconciliationController.php');
const service = read('Modules/Recommerce/Services/TrackedReceivingService.php');
const reconciliationService = read('Modules/Recommerce/Services/StockReconciliationService.php');
const labelController = read('Modules/Recommerce/Http/Controllers/LabelController.php');
const tokenIssuanceService = read('Modules/Recommerce/Services/ScanTokenIssuanceService.php');
const scanController = read('Modules/Recommerce/Http/Controllers/ScanController.php');
const deviceController = read('Modules/Recommerce/Http/Controllers/DeviceController.php');
const eventRecorder = read('Modules/Recommerce/Services/DeviceEventRecorder.php');
const migration = read('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php');
const reconciliationMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000003_create_recommerce_reconciliation_tables.php');
const eventIdentityMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000004_harden_recommerce_event_identity.php');
const labelJobMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000005_create_recommerce_label_job_tables.php');
const ownershipMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000006_create_recommerce_ownership_periods.php');
const custodyMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000007_create_recommerce_custody_periods.php');
const reconciliationRunService = read('Modules/Recommerce/Services/ReconciliationRunService.php');
const labelView = read('Modules/Recommerce/Resources/views/labels/device.blade.php');
const deviceView = read('Modules/Recommerce/Resources/views/device/show.blade.php');
const routes = read('Modules/Recommerce/Routes/web.php');
const env = fs.existsSync(path.join(checkout, '.env')) ? read('.env') : '';
const repairMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000008_create_recommerce_repair_jobs.php');
const repairStateMachine = read('Modules/Recommerce/Support/RepairJobStateMachine.php');
const repairTransitionService = read('Modules/Recommerce/Services/RepairJobTransitionService.php');
const repairEntity = read('Modules/Recommerce/Entities/RepairJob.php');
const recommerceProvider = read('Modules/Recommerce/Providers/RecommerceServiceProvider.php');
const diagnosticsMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000009_create_recommerce_diagnostics.php');
const diagnosticsService = read('Modules/Recommerce/Services/DiagnosticTemplateService.php');
const diagnosticsVersion = read('Modules/Recommerce/Entities/DiagnosticTemplateVersion.php');
const repairController = read('Modules/Recommerce/Http/Controllers/RepairJobController.php');
const repairIntakeService = read('Modules/Recommerce/Services/RepairJobIntakeService.php');
const repairRoutes = read('Modules/Recommerce/Routes/web.php');
const repairIndexView = read('Modules/Recommerce/Resources/views/repair/index.blade.php');
const purchaseCreateView = read('resources/views/purchase/create.blade.php');
const purchaseIndexView = read('resources/views/purchase/index.blade.php');
const repairShowView = read('Modules/Recommerce/Resources/views/repair/show.blade.php');
const repairNewView = read('Modules/Recommerce/Resources/views/repair/new.blade.php');
const publicStatusView = read('Modules/Recommerce/Resources/views/repair/public-status.blade.php');
const repairEnhancementMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000012_enhance_customer_repair_intake.php');
const customerDeviceService = read('Modules/Recommerce/Services/CustomerRepairDeviceService.php');
const publicLookupService = read('Modules/Recommerce/Services/RepairPublicLookupService.php');
const certificationService = read('Modules/Recommerce/Services/DeviceCertificationService.php');
const certificationMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000014_create_recommerce_device_certifications.php');
const publicCertificationView = read('Modules/Recommerce/Resources/views/device/public-certification.blade.php');

const bladeForParsing = blade
    .replace(/@json\([^\n]*\)/g, 'null')
    .replace(/@csrf/g, 'TOKEN');

const htmlScriptCount = parseScripts(html, 'static preview');
const bladeScriptCount = parseScripts(bladeForParsing, 'receiving Blade');
const repairNewScriptCount = parseScripts(repairNewView.replace(/\{\{[\s\S]*?\}\}/g, 'null'), 'repair intake Blade');
const repairShowScriptCount = parseScripts(repairShowView.replace(/\{\{[\s\S]*?\}\}/g, 'null'), 'repair detail Blade');

const localRecommerceTestActivation = /APP_ENV\s*=\s*local/.test(env)
    && /RECOMMERCE_ENABLED\s*=\s*true/.test(env);
assert(
    /"Recommerce"\s*:\s*false/.test(statuses) || localRecommerceTestActivation,
    'Recommerce may only be enabled with an explicit local test activation'
);
assert(/"Repair"\s*:\s*false/.test(statuses), 'Provider Repair must remain disabled in modules_statuses.json');
assert(repairMigration.includes('recommerce_repair_jobs'), 'owned Repair job migration must be present');
assert(repairMigration.includes("recommerce_repair_job_code_unique"), 'owned Repair job code must be unique per business');
assert(repairStateMachine.includes('INTERNAL_REFURBISHMENT'), 'owned Repair state machine must support internal refurbishment');
assert(repairStateMachine.includes('CUSTOMER_REPAIR'), 'owned Repair state machine must support customer repair');
assert(repairStateMachine.includes('STATE_CLOSED => []'), 'closed owned Repair jobs must have no outgoing transition');
assert(repairTransitionService.includes('lockForUpdate'), 'owned Repair transitions must lock the job row');
assert(repairTransitionService.includes('expectedLockVersion'), 'owned Repair transitions must support stale-form protection');
assert(repairEntity.includes("protected $table = 'recommerce_repair_jobs'"), 'owned Repair entity must use the Recommerce table');
assert(!repairMigration.includes('Modules\\\\Repair'), 'owned Repair migration must not reference provider Repair');
assert(recommerceProvider.includes('RepairJobTransitionService::class'), 'owned Repair transition service must be container-bound');
assert(diagnosticsMigration.includes('recommerce_diagnostic_template_versions'), 'diagnostic version migration must be present');
assert(diagnosticsMigration.includes('recommerce_diagnostic_sessions'), 'diagnostic session migration must be present');
assert(diagnosticsMigration.includes('recommerce_diagnostic_observations'), 'diagnostic observation migration must be present');
assert(diagnosticsService.includes("status', 'PUBLISHED'"), 'diagnostic service must require published versions');
assert(diagnosticsService.includes('template_snapshot_json'), 'diagnostic sessions must retain a template snapshot');
assert(diagnosticsService.includes('All required diagnostic checks must be completed'), 'diagnostic submission must enforce required checks');
assert(diagnosticsService.includes('lockForUpdate'), 'diagnostic publish and submit paths must lock rows');
assert(diagnosticsService.includes('unknown check'), 'diagnostic submission must reject unknown checks');
assert(diagnosticsService.includes('Numeric diagnostic checks require a numeric value'), 'diagnostic submission must validate numeric checks');
assert(diagnosticsService.includes('A submitted diagnostic requires a grade'), 'diagnostic submission must require a grade');
assert(diagnosticsService.includes('$session->template_snapshot_json'), 'diagnostic snapshot must be assigned through the model boundary');
assert(diagnosticsVersion.includes('function snapshot'), 'diagnostic versions must expose an immutable snapshot projection');
assert(recommerceProvider.includes('DiagnosticTemplateService::class'), 'diagnostic service must be container-bound');
assert(repairIntakeService.includes('command_uuid'), 'Repair intake must require an idempotency command UUID');
assert(repairIntakeService.includes('command_hash') && repairIntakeService.includes('JSON_THROW_ON_ERROR'), 'Repair intake must reject conflicting or malformed idempotency payloads');
assert(repairIntakeService.includes("DB::table('business')"), 'Repair intake must serialize command claims per business');
assert(repairIntakeService.includes('lockForUpdate'), 'Repair intake must lock the Device row');
assert(repairIntakeService.includes('TYPE_CUSTOMER_REPAIR'), 'Repair intake must handle customer-owned jobs');
assert(repairIntakeService.includes('TYPE_INTERNAL_REFURBISHMENT'), 'Repair intake must handle internal jobs');
assert(repairEnhancementMigration.includes('recommerce_repair_checklist_items'), 'repair intake checklist persistence must be migrated');
assert(repairEnhancementMigration.includes('recommerce_repair_lookup_tokens'), 'repair public lookup token storage must be migrated');
assert(repairEnhancementMigration.includes('recommerce_repair_state_transitions'), 'repair state timeline persistence must be migrated');
assert(customerDeviceService.includes('stock_participation' ) && customerDeviceService.includes("'NONE'"), 'customer-owned repair devices must not participate in POS stock');
assert(customerDeviceService.includes('lockForUpdate') && customerDeviceService.includes('OwnershipPeriod'), 'customer device creation must be transactional and historized');
assert(customerDeviceService.includes('raw_value_encrypted') && customerDeviceService.includes('normalized_hash'), 'device identifiers must be encrypted and keyed-hash backed');
assert(customerDeviceService.includes('Access credentials are not accepted'), 'customer device service must reject credential capture');
assert(publicLookupService.includes('random') || publicLookupService.includes('issue()'), 'public lookup must issue opaque random tokens');
assert(publicLookupService.includes('token_hash'), 'public lookup must resolve by hashed token');
assert(repairRoutes.includes('/repair/status/{jobCode}/{token}') && repairRoutes.includes('throttle:30,1'), 'public repair lookup must be rate limited and opaque');
assert(repairNewView.includes('New Customer Repair') && repairNewView.includes('customer-owned'), 'dedicated customer repair intake view must be present');
assert(repairNewView.includes('checklist') && repairNewView.includes('access_status'), 'intake view must capture checklist and access status');
assert(publicStatusView.includes('internal notes') && publicStatusView.includes('payment'), 'public status must document privacy exclusions');
assert(repairController.includes("'publicJob' => $publicJob"), 'public repair status must receive a sanitized view record');
assert(repairController.includes("recommerce.repair.view"), 'Repair controller must enforce the view permission');
assert(repairController.includes("recommerce.repair.transition"), 'Repair controller must enforce the transition permission');
assert(repairController.includes('RepairJobStateMachine::allowedTransitions'), 'Repair detail must expose only state-machine transitions');
assert(repairController.includes("->header('Cache-Control', 'no-store')"), 'Repair controller must set no-store');
assert(repairRoutes.includes("/repair/{jobCode}/transition"), 'Repair transition route must be explicit');
assert(repairRoutes.includes("/repair/intake"), 'Repair intake route must be explicit');
assert(repairIndexView.includes('Customer Repairs') && repairIndexView.includes('Repair workbench'), 'Customer and internal repair workspaces must remain clearly separated');
assert(repairRoutes.includes('/customer-repairs') && repairController.includes('customerIndex'), 'Customer Repairs must have its own explicit workspace route');
assert(purchaseCreateView.includes('Stock entry') && purchaseCreateView.includes('Receive serialised purchase'), 'purchase create must explain the stock-to-device receiving handoff');
assert(repairShowView.includes('expected_lock_version'), 'Repair workbench must submit stale-form protection');
assert(recommerceProvider.includes('RepairJobIntakeService::class'), 'Repair intake service must be container-bound');
const partsMigration = read('Modules/Recommerce/Database/Migrations/2026_08_28_000010_create_recommerce_repair_parts.php');
const partsService = read('Modules/Recommerce/Services/RepairPartService.php');
assert(partsMigration.includes('recommerce_repair_part_reservations'), 'parts reservation migration must be present');
assert(partsMigration.includes('recommerce_repair_part_usages'), 'parts usage migration must be present');
assert(partsService.includes('variation_location_details'), 'parts service must read core stock availability');
assert(partsService.includes('qty_available'), 'parts service must use the core aggregate quantity');
assert(partsService.includes('INSTALLED_PENDING_BILLING'), 'parts service must retain installed pending billing state');
assert(partsService.includes('sourceType'), 'parts service must require a source type when resolving usage');
assert(partsService.includes('expires_at'), 'parts service must enforce reservation expiry');
assert(partsService.includes("$job->state === 'CLOSED'"), 'parts service must block closed Repair jobs');
assert(partsService.includes('consumeInternal'), 'parts service must expose the internal consumption boundary');
assert(partsService.includes('PART_ACTUAL'), 'internal consumption must append actual part cost');
assert(partsService.includes('stockAdjustmentWriter->write'), 'internal consumption must call the POS adjustment writer');
assert(!partsService.includes("qty_available'") && !partsService.includes('qty_available"'), 'parts service must not write core aggregate quantity');
assert(recommerceProvider.includes('RepairPartService::class'), 'parts service must be container-bound');
assert(/'enabled'\s*=>\s*env\('RECOMMERCE_ENABLED',\s*false\)/.test(config), 'Recommerce enabled default must remain false');
assert(/'writes_enabled'\s*=>\s*env\('RECOMMERCE_WRITES_ENABLED',\s*false\)/.test(config), 'Recommerce writes default must remain false');
assert(controller.includes("->header('Cache-Control', 'no-store')"), 'receiving controller must set no-store');
assert(controller.includes("->header('Referrer-Policy', 'no-referrer')"), 'receiving controller must set no-referrer');
assert(controller.includes('ReceivingInProgressException'), 'receiving controller must narrow the 409 exception boundary');
assert(controller.includes('ReceivingReconciliationBlockedException'), 'receiving controller must expose the reconciliation stop-line boundary');
assert(routes.includes('/receiving/attach-purchase') && controller.includes('attachPurchase'), 'received POS purchases must have an explicit device-attachment route');
assert(service.includes('attachToExistingUltimatePosPurchase') && service.includes("'core_stock_changed' => false"), 'existing POS purchase serialisation must not post stock twice');
assert(purchaseController.includes('Serialise devices') && purchaseIndexView.includes('One stock workflow.'), 'the Purchase workspace must expose the serialisation handoff');
assert(reconciliationService.includes('assertTrackedReceiveMayProceed'), 'reconciliation service must guard new tracked receives after a mismatch');
assert(service.includes('assertTrackedReceiveMayProceed'), 'tracked receiving must invoke the reconciliation stop-line guard');
assert(labelController.includes('LabelRenderer'), 'label controller must use the bounded renderer');
assert(labelController.includes('issueAndRender'), 'label print path must render before token issuance commits');
assert(labelController.includes('issueAndPrepare'), 'label payload path must prepare before token issuance commits');
assert(tokenIssuanceService.includes('assertIssuanceScope'), 'token issuance must re-check the locked Device scope');
assert(tokenIssuanceService.includes('issueAndPrepare'), 'token issuance must expose the atomic preparation boundary');
assert(migration.includes("recommerce_outbox_messages"), 'Alpha migration must include the transactional outbox table');
assert(eventRecorder.includes('appendOutbox'), 'device event recording must append an outbox message');
assert(eventRecorder.includes("'status' => 'PENDING'"), 'outbox messages must begin pending');
assert(eventRecorder.includes("'event_uuid' => (string) Str::uuid()"), 'new device events must receive a durable UUID');
assert(eventIdentityMigration.includes('recommerce_device_event_uuid_unique'), 'event UUID uniqueness migration must be present');
assert(labelJobMigration.includes('recommerce_label_jobs'), 'label job migration must be present');
assert(labelJobMigration.includes('recommerce_label_job_items'), 'label job item migration must be present');
assert(labelController.includes("'label_job_uuid' => $job->job_uuid"), 'rendered label issuance must retain print-job identity');
assert(labelController.includes("'scan_token_id' => $issued['token_id']"), 'print-job items must link the issued token');
assert(ownershipMigration.includes('recommerce_device_ownership_periods'), 'ownership-period migration must be present');
assert(ownershipMigration.includes('recommerce_ownership_open_unique'), 'ownership periods must enforce one open period key');
assert(service.includes('OwnershipPeriod::create'), 'tracked receiving must create business ownership evidence');
assert(custodyMigration.includes('recommerce_device_custody_periods'), 'custody-period migration must be present');
assert(custodyMigration.includes('recommerce_custody_open_unique'), 'custody periods must enforce one open period key');
assert(service.includes('CustodyPeriod::create'), 'tracked receiving must create custody evidence');
assert(deviceController.includes("'ownershipPeriods'"), 'device detail must load ownership-period evidence');
assert(deviceController.includes("'custodyPeriods'"), 'device detail must load custody-period evidence');
assert(deviceView.includes('Ownership periods'), 'device detail must present ownership-period evidence');
assert(deviceView.includes('Custody periods'), 'device detail must present custody-period evidence');
assert(deviceView.includes('Protected identifiers and raw token material'), 'device detail must state timeline redaction');
assert(reconciliationMigration.includes('recommerce_reconciliation_runs'), 'reconciliation runs migration must be present');
assert(reconciliationMigration.includes('recommerce_reconciliation_issues'), 'reconciliation issues migration must be present');
assert(reconciliationRunService.includes("'result_hash' => $resultHash"), 'reconciliation runs must retain an integrity hash');
assert(reconciliationRunService.includes("'status' => 'OPEN'"), 'reconciliation issues must begin open');
const publicResolver = scanController.slice(scanController.indexOf('public function device('));
assert(publicResolver.includes("'device.certification'"), 'public QR resolver must load only the published certification relation');
assert(publicResolver.includes('publicProfile($device)'), 'public QR resolver must use the customer-safe profile');
assert(publicResolver.includes("'X-Robots-Tag', 'noindex, nofollow, noarchive'"), 'public QR certification must discourage indexing');
assert(certificationMigration.includes('recommerce_device_certifications'), 'device certification migration must be present');
assert(certificationMigration.includes('rc_device_certification_device_unique'), 'each Device must have at most one public certification record');
assert(certificationService.includes("'recommerce.device.certify'"), 'certification publication must require its own permission');
assert(certificationService.includes('empty($device->sold_at)'), 'public certification must require a sold Device');
assert(certificationService.includes('Only QC-passed devices'), 'public certification must require QC evidence');
assert(!publicCertificationView.includes('device_code'), 'customer certification must not disclose the internal Device code');
assert(!publicCertificationView.includes('cost'), 'customer certification must not disclose financial data');
assert(routes.includes("/devices/{deviceId}/label/print"), 'label print route must remain explicit');
assert(routes.includes("/reconciliation/{variationId}/runs"), 'reconciliation evidence route must remain explicit');
assert(labelView.includes("{!! $rendered['qr_svg'] !!}"), 'label view must include the QR SVG');
assert(labelView.includes("{!! $rendered['code128_svg'] !!}"), 'label view must include the Code128 SVG');
assert(!labelView.includes("$label['qr_url']"), 'label view must not print the opaque QR URL as text');
assert(html.includes('class="barcode-placeholder" aria-label="Code 128 preview"'), 'static preview must show a Code 128 surface');
assert(reconciliationController.includes('catch (InvalidArgumentException|LogicException'), 'reconciliation controller must hide service validation detail');
assert(service.includes("$unit['identifier_type'].'|'.$identifierHash"), 'tracked receiving must use type-scoped identifier keys');
assert(html.includes('function identifierHint(value)'), 'static preview must define a safe identifier hint helper');
assert(html.includes('identifier_hint: identifierHint(unit.value)'), 'static preview must use the safe identifier hint helper');
assert(html.includes("inputs[0].value.trim().toUpperCase() === identifierType"), 'static add-unit duplicate check must include identifier type');
assert(html.includes('`${unit.type}|${normalizeIdentifierValue(unit.value)}`'), 'static preflight duplicate check must include identifier type');
assert(blade.includes('state.commandUuid = null;'), 'production receiving must reset UUID after a prepared draft edit');
assert(blade.includes("document.getElementById('device-results-box').style.display = 'none';"), 'production receiving must clear stale Device results after a draft edit');
assert(blade.includes("'/label/print'"), 'production receiving must hand label actions to the print-ready endpoint');
assert(blade.includes("'Accept': 'text/html'"), 'production receiving print action must request HTML safely');
assert(blade.includes('previewWindow.opener = null'), 'production receiving print preview must sever the opener reference');
assert(blade.includes('Print preview could not be opened. Label was not issued.'), 'production receiving must fail before issuance when the preview window is blocked');
assert(blade.includes('shouldIgnoreDuplicateScan'), 'production receiving must debounce identical scanner reads');
assert(blade.includes('recordReconcileUrl'), 'production receiving must expose the guarded reconciliation evidence URL');
assert(blade.includes('evidence retained'), 'production receiving must explain retained reconciliation evidence');
assert(html.includes('shouldIgnoreDuplicateScan'), 'static preview must debounce identical scanner reads');

const normalizeSource = html.match(/function normalizeIdentifierValue\(value\) \{[^}]*\}/)?.[0];
const hintSource = html.match(/function identifierHint\(value\) \{[^}]*\}/)?.[0];
assert(normalizeSource && hintSource, 'static preview masking functions must be extractable');
const identifierHint = new Function(`${normalizeSource}\n${hintSource}\nreturn identifierHint;`)();
const hintCases = [
    ['EMO001', '****01'],
    ['SN-DEMO-001', '******01'],
    ['A', '*'],
    ['AB', '**'],
];
hintCases.forEach(([value, expected]) => assert(identifierHint(value) === expected, `identifier hint mismatch for ${value}`));

console.log(`recommerce-static-check: passed (${htmlScriptCount} static script block, ${bladeScriptCount} Blade script block, ${hintCases.length} masking cases)`);
