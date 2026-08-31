@extends('layouts.app')

@section('title', 'Receive Stock')

@section('content')
@php
    $purchase = data_get($purchaseContext, 'purchase');
    $lines = collect(data_get($purchaseContext, 'lines', []));
    $selectedLine = data_get($purchaseContext, 'selected_line');
    $purchaseReference = $purchase ? ($purchase->ref_no ?: $purchase->invoice_no ?: '#'.$purchase->id) : null;
    $trackedLines = $lines->where('tracking_mode', 'SERIALIZED_DEVICE');
    $nextIncompleteLine = $trackedLines->first(fn ($line) => $line->remaining_count > 0 && (! $selectedLine || (int) $line->id !== (int) $selectedLine->id));
    $overallComplete = $purchase && $trackedLines->isNotEmpty() && (int) data_get($purchaseContext, 'remaining_count', 0) === 0;
    $inspectionRequired = $trackedLines->contains(fn ($line) => (bool) $line->inspection_required);
    $canViewInspection = (bool) ($canViewInspection ?? false);
    $deviceStatusLabels = [
        'RECEIVED_PENDING_INSPECTION' => 'Waiting for inspection',
        'INSPECTION_IN_PROGRESS' => 'Inspection in progress',
        'REFURBISHMENT_REQUIRED' => 'Action required',
        'AVAILABLE' => 'Ready for sale',
        'RESERVED' => 'Reserved',
        'SOLD' => 'Sold',
    ];
@endphp
<style>
    .sb-device-receiving { max-width: 1180px; margin: 0 auto; }
    .sb-device-receiving .box { border-radius: 10px; }
    .sb-receiving-progress { height: 9px; margin: 8px 0; background: rgba(148,163,184,.2); border-radius: 999px; overflow: hidden; }
    .sb-receiving-progress > span { display:block; height:100%; background: var(--sb-success, #22c55e); }
    .sb-receiving-line { border-left: 3px solid var(--sb-border, #475569); padding: 11px 12px; margin-bottom: 9px; background: rgba(15,23,42,.03); }
    .sb-receiving-line.is-active { border-left-color: var(--sb-primary, #3b82f6); background: rgba(59,130,246,.08); }
    .sb-scanner-input { height: 54px; font-size: 19px; letter-spacing: .03em; }
    .sb-staged-unit { display:flex; gap:8px; align-items:center; padding:8px 0; border-bottom:1px solid rgba(148,163,184,.18); }
    .sb-staged-unit:last-child { border-bottom:0; }
    @media (max-width: 767px) { .sb-staged-unit { align-items:stretch; flex-wrap:wrap; } .sb-staged-unit .form-control { min-width:0; } }
</style>

<section class="container-fluid sb-device-receiving" id="device-receiving" data-csrf-token="{{ csrf_token() }}" data-label-print-prefix="{{ url('/recommerce/devices') }}" aria-labelledby="device-receiving-title">
    @if (! $purchase)
        <div class="box box-primary">
            <div class="box-header with-border"><h1 id="device-receiving-title" class="box-title">Receive Stock</h1></div>
            <div class="box-body">
                <p class="text-muted">Start from a received supplier purchase. SAVERPOS will identify the product lines that need physical device identification.</p>
                <a class="btn btn-primary" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}">Open Purchases</a>
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}">Back to Purchases</a></div>
                        <h1 id="device-receiving-title" class="box-title">Receive Stock</h1>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-sm-4"><strong>Purchase</strong><br>{{ $purchaseReference }}</div>
                            <div class="col-sm-4"><strong>Supplier</strong><br>{{ $purchase->supplier_business_name ?: $purchase->supplier_name ?: 'Not recorded' }}</div>
                            <div class="col-sm-4"><strong>Branch</strong><br>{{ $purchase->location_name ?: 'Branch '.$locationId }}<br><small class="text-muted">Received {{ \Carbon\Carbon::parse($purchase->transaction_date)->format('d M Y') }}</small></div>
                        </div>
                        <div class="sb-receiving-progress" aria-label="Overall Device registration progress"><span id="overall-progress-bar" style="width: {{ data_get($purchaseContext, 'expected_count', 0) > 0 ? min(100, round(data_get($purchaseContext, 'registered_count', 0) / data_get($purchaseContext, 'expected_count', 1) * 100)) : 0 }}%"></span></div>
                        <div class="row text-center" style="margin-top:12px"><div class="col-xs-3"><strong>{{ data_get($purchaseContext, 'expected_count', 0) }}</strong><br><small>Tracked</small></div><div class="col-xs-3"><strong id="registered-count">{{ data_get($purchaseContext, 'registered_count', 0) }}</strong><br><small>Registered</small></div><div class="col-xs-3"><strong id="remaining-count">{{ data_get($purchaseContext, 'remaining_count', 0) }}</strong><br><small>Remaining</small></div><div class="col-xs-3"><strong id="ready-count">{{ data_get($purchaseContext, 'inspection_cleared_count', 0) }}</strong><br><small>Ready</small></div></div>
                        @if (data_get($purchaseContext, 'registered_count', 0) > 0)
                            <p id="label-progress" class="text-muted" style="margin:10px 0 0">
                                Labels {{ data_get($purchaseContext, 'label_confirmed_count', 0) }} / {{ data_get($purchaseContext, 'registered_count', 0) }} attached
                                @if (data_get($purchaseContext, 'label_remaining_count', 0))
                                    · <strong>{{ data_get($purchaseContext, 'label_remaining_count', 0) }} still need a label</strong>
                                @endif
                            </p>
                        @endif
                    </div>
                </div>

                <div id="receiving-complete" class="box box-success" @if (! $overallComplete) style="display:none" @endif>
                    <div class="box-header with-border"><h2 class="box-title"><i class="fa fa-check-circle" aria-hidden="true"></i> Receiving Complete</h2></div>
                    <div class="box-body">
                        <p><strong id="completion-count">{{ data_get($purchaseContext, 'registered_count', 0) }} / {{ data_get($purchaseContext, 'expected_count', 0) }}</strong> tracked devices registered.</p>
                        @if (data_get($purchaseContext, 'label_remaining_count', 0))
                            <p class="text-warning"><strong>{{ data_get($purchaseContext, 'label_remaining_count', 0) }} registered Device{{ data_get($purchaseContext, 'label_remaining_count', 0) === 1 ? '' : 's' }} still need{{ data_get($purchaseContext, 'label_remaining_count', 0) === 1 ? 's' : '' }} a label.</strong> Registration remains complete; recover labels from the Device Registry.</p>
                        @endif
                        <p class="text-success">✓ Purchase quantity reconciled<br>✓ Device identities registered<br>✓ {{ $inspectionRequired ? 'Devices waiting for inspection' : 'Device intake policy completed' }}</p>
                        @if($inspectionRequired)<p><strong id="inspection-waiting-count">{{ (int) data_get($purchaseContext, 'inspection_open_count', 0) }}</strong> <span id="inspection-waiting-grammar">{{ (int) data_get($purchaseContext, 'inspection_open_count', 0) === 1 ? 'device is' : 'devices are' }}</span> now waiting for inspection.</p>@endif
                        @if($canViewInspection)<a class="btn btn-primary" href="{{ route('recommerce.inspection.index') }}">Open Inspection Queue</a>@elseif($inspectionRequired)<p class="text-muted">Ask a supervisor with inspection access to continue.</p>@endif
                        <a class="btn btn-default" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}">Return to purchases</a>
                        <a class="btn btn-default" href="{{ route('recommerce.devices.index') }}">View devices</a>
                    </div>
                </div>

                @if ($selectedLine)
                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h2 class="box-title">{{ $selectedLine->product_name }}@if($selectedLine->variation_name) <small>{{ $selectedLine->variation_name }}</small>@endif</h2>
                            <p id="selected-line-progress" class="text-muted" style="margin:6px 0 0">{{ $selectedLine->registered_count }} / {{ $selectedLine->expected_count }} registered · {{ $selectedLine->remaining_count }} remaining</p>
                        </div>
                        <div class="box-body">
                            @if (! $selectedLine->is_whole_unit)
                                <div class="alert alert-warning">This purchase quantity must be corrected to a whole number before individual devices can be identified.</div>
                            @elseif ($selectedLine->remaining_count === 0)
                                <div class="alert alert-success">Device identification is complete for this product line.</div>
                                @if ($nextIncompleteLine)
                                    <a class="btn btn-primary" href="{{ route('recommerce.receiving.index', ['purchase_id' => $purchase->id, 'purchase_line_id' => $nextIncompleteLine->id]) }}">Receive next product · {{ $nextIncompleteLine->remaining_count }} remaining</a>
                                @endif
                            @elseif (! $postEnabled)
                                <div class="alert alert-info">You can review this purchase line, but device identification is not available for your current access.</div>
                            @else
                                <label for="scan-identifier">Manufacturer Serial / Service Tag</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-btn"><select id="identifier-type" class="form-control" aria-label="Identifier type"><option value="SERIAL">Serial</option><option value="IMEI">IMEI</option><option value="ASSET_TAG">Asset tag</option></select></span>
                                    <input id="scan-identifier" class="form-control sb-scanner-input" maxlength="255" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Type serial or service tag, then press Enter">
                                </div>
                                <p class="help-block">Enter one physical Device at a time. SAVERPOS checks duplicates before registration, then prepares its permanent SAVERBRO identity and label.</p>

                                <div id="scan-message" class="alert" style="display:none" role="status" aria-live="polite"></div>
                                <div id="scan-exceptions" aria-live="polite"></div>
                                <p id="batch-summary" class="text-muted">0 valid · 0 blocked</p>
                                <div id="staged-units" aria-live="polite"></div>
                                <div id="empty-staged" class="text-muted" style="padding:12px 0">No devices staged yet.</div>
                                <div class="btn-toolbar" style="margin-top:16px">
                                    <button id="register-devices" class="btn btn-primary" type="button" disabled>Register &amp; Print Label</button>
                                    <button id="clear-staged" class="btn btn-default" type="button" disabled>Clear entry</button>
                                </div>
                                <div id="recent-labels" style="margin-top:12px"></div>
                            @endif
                        </div>
                    </div>
                @elseif ($lines->where('tracking_mode', 'SERIALIZED_DEVICE')->isEmpty())
                    <div class="box box-default"><div class="box-body"><p class="text-muted">This purchase contains ordinary stock only. No individual device identification is required.</p></div></div>
                @else
                    <div class="box box-default"><div class="box-body"><p class="text-muted">Choose a tracked product line from the list to continue receiving.</p></div></div>
                @endif

                @if ($selectedLine)
                    <div class="box box-default">
                        <div class="box-header with-border"><h3 class="box-title">Registered Devices</h3></div>
                        <div class="box-body table-responsive">
                            <table class="table table-hover"><thead><tr><th>#</th><th>Device ID</th><th>Identifier</th><th>Unit cost</th><th>Status</th><th></th></tr></thead><tbody id="registered-device-list">
                                @forelse ($registeredDevices as $device)
                                    <tr><td>{{ $device->unit_ordinal }}</td><td><strong>{{ $device->device_code }}</strong></td><td>Protected identifier recorded</td><td>{{ $device->unit_acquisition_cost === null ? '—' : 'RM '.number_format((float) $device->unit_acquisition_cost, 2) }}</td><td>{{ $deviceStatusLabels[$device->lifecycle_state] ?? ucwords(strtolower(str_replace('_', ' ', $device->lifecycle_state))) }}</td><td><form method="post" action="{{ route('recommerce.devices.label.print', $device->device_id) }}" target="_blank"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button class="btn btn-default btn-xs" type="submit">Print label</button></form></td></tr>
                                @empty
                                    <tr id="no-registered-devices"><td colspan="6" class="text-muted">No Devices have been registered for this purchase line.</td></tr>
                                @endforelse
                            </tbody></table>
                        </div>
                    </div>
                @endif
            </div>

            <div class="col-md-4">
                <div class="box box-default">
                    <div class="box-header with-border"><h3 class="box-title">Purchase lines</h3></div>
                    <div class="box-body">
                        @foreach ($lines as $line)
                            @if ($line->tracking_mode === 'SERIALIZED_DEVICE')
                                @php $active = $selectedLine && (int) $selectedLine->id === (int) $line->id; $percent = $line->expected_count > 0 ? min(100, round($line->registered_count / $line->expected_count * 100)) : 0; @endphp
                                <div class="sb-receiving-line {{ $active ? 'is-active' : '' }}">
                                    <strong>{{ $line->product_name }}</strong>@if($line->variation_name)<br><small>{{ $line->variation_name }}</small>@endif
                                    <div class="sb-receiving-progress"><span id="line-progress-bar-{{ $line->id }}" style="width: {{ $percent }}%"></span></div>
                                    <span id="line-progress-{{ $line->id }}">{{ $line->registered_count }} / {{ $line->expected_count }}</span>
                                    @if ($line->remaining_count > 0)
                                        <span id="line-remaining-{{ $line->id }}" class="text-muted"> · {{ $line->remaining_count }} remaining</span>
                                    @else
                                        <span class="text-success"> · Complete</span>
                                    @endif
                                    <br><small id="line-inspection-{{ $line->id }}" class="text-muted" @if($line->registered_count < 1) style="display:none" @endif>{{ $line->inspection_cleared_count }} ready · {{ $line->inspection_open_count }} awaiting inspection
                                        @if($line->inspection_failed_count)
                                            · {{ $line->inspection_failed_count }} action required
                                        @endif
                                    </small>
                                    <br><a id="line-action-{{ $line->id }}" class="btn btn-default btn-xs" style="margin-top:7px" href="{{ route('recommerce.receiving.index', ['purchase_id' => $purchase->id, 'purchase_line_id' => $line->id]) }}">{{ $line->remaining_count > 0 ? ($line->registered_count ? 'Continue receiving' : 'Scan devices') : 'View devices' }}</a>
                                </div>
                            @else
                                <div class="sb-receiving-line"><strong>{{ $line->product_name }}</strong><br><span class="text-muted">Received · ordinary stock · no device identification required</span></div>
                            @endif
                        @endforeach
                    </div>
                </div>
                <div class="box box-default"><div class="box-body">@if($canViewInspection)<a class="btn btn-default btn-block" href="{{ route('recommerce.inspection.index') }}">Open Inspection Queue</a>@endif<a class="btn btn-default btn-block" href="{{ route('recommerce.devices.index') }}">Open Device Registry</a><a class="btn btn-default btn-block" href="{{ route('recommerce.reconciliation.index') }}">Stock Check</a></div></div>
            </div>
        </div>
    @endif
</section>

@if ($purchase && $selectedLine && $postEnabled && $selectedLine->remaining_count > 0)
<script>
(() => {
    const root = document.getElementById('device-receiving');
    const scanner = document.getElementById('scan-identifier');
    const type = document.getElementById('identifier-type');
    const stagedTarget = document.getElementById('staged-units');
    const emptyState = document.getElementById('empty-staged');
    const registerButton = document.getElementById('register-devices');
    const clearButton = document.getElementById('clear-staged');
    const message = document.getElementById('scan-message');
    const exceptions = document.getElementById('scan-exceptions');
    const batchSummary = document.getElementById('batch-summary');
    const recentLabels = document.getElementById('recent-labels');
    const receivingComplete = document.getElementById('receiving-complete');
    const overallProgressBar = document.getElementById('overall-progress-bar');
    const config = {
        attachUrl: @json(route('recommerce.receiving.attach_purchase')),
        prepareUrl: @json(route('recommerce.receiving.prepare')),
        purchaseId: @json((int) $purchase->id), purchaseLineId: @json((int) $selectedLine->id),
        locationId: @json((int) $locationId), productId: @json((int) $selectedLine->product_id), variationId: @json((int) $selectedLine->variation_id),
        // One Device per identity/label cycle is the default. The API still
        // preserves its bounded batch contract for controlled integrations.
        max: 1, remaining: @json((int) $selectedLine->remaining_count), registered: @json((int) $selectedLine->registered_count), expected: @json((int) $selectedLine->expected_count),
        overallRegistered: @json((int) data_get($purchaseContext, 'registered_count', 0)), overallRemaining: @json((int) data_get($purchaseContext, 'remaining_count', 0)), overallExpected: @json((int) data_get($purchaseContext, 'expected_count', 0)), overallReady: @json((int) data_get($purchaseContext, 'inspection_cleared_count', 0)), overallAwaitingInspection: @json((int) data_get($purchaseContext, 'inspection_open_count', 0)),
        lineReady: @json((int) $selectedLine->inspection_cleared_count), lineAwaitingInspection: @json((int) $selectedLine->inspection_open_count), lineActionRequired: @json((int) $selectedLine->inspection_failed_count),
        inspectionRequired: @json((bool) $selectedLine->inspection_required), nextLineUrl: @json($nextIncompleteLine ? route('recommerce.receiving.index', ['purchase_id' => $purchase->id, 'purchase_line_id' => $nextIncompleteLine->id]) : null),
        defaultCost: @json($selectedLine->default_unit_acquisition_cost), canOverrideCost: @json($canOverrideCost),
        labelConfirmPrefix: @json(url('/recommerce/devices'))
    };
    let staged = []; let blocked = [];
    const uuid = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
    const normalise = value => value.trim().toUpperCase().replace(/[\s_-]+/g, '');
    const notify = (text, kind) => { message.textContent = text; message.className = 'alert alert-' + kind; message.style.display = 'block'; };
    const resetMessage = () => { message.style.display = 'none'; };
    const money = value => value === null || value === '' ? '—' : 'RM ' + Number(value).toFixed(2);
    const deviceStatusLabel = state => ({ RECEIVED_PENDING_INSPECTION: 'Waiting for inspection', INSPECTION_IN_PROGRESS: 'Inspection in progress', REFURBISHMENT_REQUIRED: 'Action required', AVAILABLE: 'Ready for sale', RESERVED: 'Reserved', SOLD: 'Sold' }[state] || String(state || 'Status unavailable').toLowerCase().replace(/_/g, ' ').replace(/^./, value => value.toUpperCase()));
    function render() {
        stagedTarget.replaceChildren(); emptyState.style.display = staged.length ? 'none' : 'block';
        batchSummary.textContent = staged.length + ' valid · ' + blocked.length + ' blocked';
        exceptions.replaceChildren();
        blocked.forEach((exception, index) => { const card = document.createElement('div'); card.className = 'alert alert-warning'; const text = document.createElement('span'); text.textContent = exception.message; card.append(text); if (exception.device_url) { const link = document.createElement('a'); link.href = exception.device_url; link.className = 'btn btn-default btn-xs pull-right'; link.textContent = 'View Device'; card.append(link); } const dismiss = document.createElement('button'); dismiss.type = 'button'; dismiss.className = 'btn btn-link btn-xs'; dismiss.textContent = 'Remove scan'; dismiss.addEventListener('click', () => { blocked.splice(index, 1); render(); scanner.focus(); }); card.append(dismiss); exceptions.append(card); });
        staged.forEach((unit, index) => {
            const row = document.createElement('div'); row.className = 'sb-staged-unit';
            const identity = document.createElement('strong'); identity.textContent = unit.type + ' · ' + unit.mask;
            const details = document.createElement('span'); details.className = 'text-muted'; details.textContent = 'Valid · ready to identify';
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-default btn-xs'; remove.textContent = 'Remove'; remove.addEventListener('click', () => { staged.splice(index, 1); render(); scanner.focus(); });
            row.append(identity, details);
            if (config.canOverrideCost) { const cost = document.createElement('input'); cost.type = 'number'; cost.min = '0'; cost.step = '0.01'; cost.className = 'form-control input-sm'; cost.style.width = '110px'; cost.value = unit.cost === null ? '' : unit.cost; cost.setAttribute('aria-label', 'Unit acquisition cost'); cost.addEventListener('input', () => { unit.cost = cost.value === '' ? null : cost.value; }); const reason = document.createElement('select'); reason.className = 'form-control input-sm'; reason.style.width = '170px'; reason.setAttribute('aria-label', 'Cost override reason'); [['','Default purchase cost'],['SUPPLIER_UNIT_PRICING','Supplier unit pricing'],['BUNDLE_ALLOCATION','Bundle allocation'],['INVOICE_CORRECTION','Invoice correction'],['MANAGEMENT_ADJUSTMENT','Management adjustment'],['OTHER','Other']].forEach(([value,label]) => { const option = document.createElement('option'); option.value=value; option.textContent=label; option.selected=unit.costReason===value; reason.append(option); }); reason.addEventListener('change', () => { unit.costReason = reason.value; }); row.append(cost, reason); }
            else { const cost = document.createElement('span'); cost.className = 'text-muted'; cost.textContent = money(unit.cost); row.append(cost); }
            const observation = document.createElement('select'); observation.className = 'form-control input-sm'; observation.style.width = '170px'; observation.setAttribute('aria-label', 'Optional intake observation'); [['','No intake issue'],['DAMAGED_PACKAGING','Damaged packaging'],['VISIBLE_PHYSICAL_DAMAGE','Visible damage'],['PRODUCT_MISMATCH','Product mismatch'],['MISSING_CHARGER','Missing charger'],['UNREADABLE_IDENTIFIER','Unreadable identifier'],['SUPPLIER_DISCREPANCY','Supplier discrepancy'],['OTHER','Other issue']].forEach(([value,label]) => { const option=document.createElement('option'); option.value=value; option.textContent=label; option.selected=unit.observationType===value; observation.append(option); }); observation.addEventListener('change', () => { unit.observationType=observation.value; }); row.append(observation);
            row.append(remove); stagedTarget.append(row);
        });
        registerButton.disabled = staged.length === 0; clearButton.disabled = staged.length === 0;
        registerButton.textContent = staged.length ? 'Register & Print Label' : 'Register & Print Label';
    }
    async function stageCurrent() {
        const value = scanner.value.trim(); const key = normalise(value); resetMessage();
        if (!key) { notify('Scan or enter a serial, IMEI, or asset tag first.', 'warning'); return; }
        if (staged.some(unit => unit.type === type.value && unit.key === key)) { notify('This identifier is already in the current batch.', 'warning'); scanner.select(); return; }
        if (staged.length >= Math.min(config.max, config.remaining)) { notify('This purchase line already has the maximum number of devices ready for this batch.', 'warning'); return; }
        scanner.disabled = true;
        try { await request(config.prepareUrl, { location_id: config.locationId, product_id: config.productId, variation_id: config.variationId, units: [{ identifier_type: type.value, identifier_value: value }] }); const mask = key.length <= 4 ? '••••' : '••••' + key.slice(-4); staged.push({ type: type.value, value, key, mask, cost: config.defaultCost, costReason: '', observationType: '' }); scanner.value = ''; notify('Serial accepted. Register this exact Device and print its SAVERBRO label.', 'success'); }
        catch (error) { blocked.push({ message: error.message || 'This scan needs attention.', device_url: error.device_url || null }); notify('Scan blocked. Valid staged devices are unchanged.', 'warning'); }
        finally { scanner.disabled = false; render(); scanner.focus(); }
    }
    async function request(url, body) {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, body: JSON.stringify(body) });
        const data = await response.json().catch(() => ({})); if (!response.ok) { const error = new Error(data.message || 'This device could not be identified.'); error.device_url = data.exception && data.exception.device_url; throw error; } return data;
    }
    async function openLabelView(device, preview = null) {
        const response = await fetch(root.dataset.labelPrintPrefix.replace(/\/$/, '') + '/' + device.device_id + '/label/print', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'text/html', 'X-CSRF-TOKEN': root.dataset.csrfToken } });
        const html = await response.text();
        if (!response.ok) throw new Error('Device registered, but the label preview could not be opened. Use Print Later from the Device Registry.');
        preview = preview || window.open('', 'saverbro-device-label-' + device.device_id);
        if (!preview) throw new Error('Device registered — label not printed. Your browser blocked the label preview; use Print Later from the Device Registry.');
        preview.document.open(); preview.document.write(html); preview.document.close(); preview.focus();
        return true;
    }
    function payload(commandUuid) { return { command_uuid: commandUuid, location_id: config.locationId, product_id: config.productId, variation_id: config.variationId, purchase_transaction_id: config.purchaseId, purchase_line_id: config.purchaseLineId, units: staged.map(unit => ({ identifier_type: unit.type, identifier_value: unit.value, unit_acquisition_cost: unit.cost, cost_override_reason_code: unit.costReason, intake_observations: unit.observationType ? [{ type: unit.observationType }] : [] })) }; }
    scanner.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); stageCurrent(); } });
    clearButton.addEventListener('click', () => { staged = []; resetMessage(); render(); scanner.focus(); });
    registerButton.addEventListener('click', async () => {
        if (!staged.length) return; registerButton.disabled = true; const commandUuid = uuid(); const labelPreview = window.open('', 'saverbro-device-label');
        try {
            const data = await request(config.attachUrl, payload(commandUuid)); const devices = (data.result && data.result.devices) || [];
            const registered = document.getElementById('registered-count'); const remaining = document.getElementById('remaining-count'); config.registered += devices.length; config.remaining = Math.max(0, config.remaining - devices.length); config.overallRegistered += devices.length; config.overallRemaining = Math.max(0, config.overallRemaining - devices.length); if (registered) registered.textContent = config.overallRegistered; if (remaining) remaining.textContent = config.overallRemaining; if (overallProgressBar) overallProgressBar.style.width = (config.overallExpected ? Math.min(100, Math.round(config.overallRegistered / config.overallExpected * 100)) : 0) + '%'; const selectedProgress = document.getElementById('selected-line-progress'); if (selectedProgress) selectedProgress.textContent = config.registered + ' / ' + config.expected + ' registered · ' + config.remaining + ' remaining'; const lineProgress = document.getElementById('line-progress-' + config.purchaseLineId); if (lineProgress) lineProgress.textContent = config.registered + ' / ' + config.expected; const lineRemaining = document.getElementById('line-remaining-' + config.purchaseLineId); if (lineRemaining) lineRemaining.textContent = config.remaining ? ' · ' + config.remaining + ' remaining' : ' · Complete'; const emptyRegistered = document.getElementById('no-registered-devices'); if (emptyRegistered) emptyRegistered.remove(); const registeredList = document.getElementById('registered-device-list'); devices.forEach(device => { const row = document.createElement('tr'); [String(device.unit_ordinal), device.device_code, 'Protected identifier recorded', money(config.defaultCost), deviceStatusLabel(device.lifecycle_state || (config.inspectionRequired ? 'RECEIVED_PENDING_INSPECTION' : 'AVAILABLE'))].forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); }); const action = document.createElement('td'); const link = document.createElement('a'); link.className = 'btn btn-default btn-xs'; link.href = root.dataset.labelPrintPrefix.replace(/\/$/, '') + '/' + device.device_code; link.textContent = 'Open device'; action.append(link); row.append(action); registeredList.append(row); }); recentLabels.replaceChildren(); if (devices.length) { const device = devices[0]; const heading = document.createElement('strong'); heading.textContent = 'Device Registered — ' + device.device_code; recentLabels.append(heading, document.createElement('br')); const note = document.createElement('span'); note.className = 'text-muted'; note.textContent = 'Waiting for inspection. Attach the SAVERBRO label to this exact device before operational use.'; recentLabels.append(note, document.createElement('br')); const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'btn btn-primary btn-sm'; retry.textContent = 'Print SAVERBRO Label'; retry.style.marginTop = '8px'; const confirm = document.createElement('button'); confirm.type = 'button'; confirm.className = 'btn btn-success btn-sm'; confirm.textContent = 'Label Attached'; confirm.style.margin = '8px 0 0 6px'; confirm.disabled = true; confirm.addEventListener('click', async () => { try { await request(config.labelConfirmPrefix.replace(/\/$/, '') + '/' + device.device_id + '/label/confirm', {}); confirm.textContent = 'Label attached'; confirm.disabled = true; notify('Label attachment recorded. The Device remains waiting for inspection.', 'success'); } catch (error) { notify(error.message || 'Label attachment could not be confirmed.', 'warning'); } }); retry.addEventListener('click', async () => { try { await openLabelView(device); retry.textContent = 'Label print view opened'; retry.disabled = true; confirm.disabled = false; } catch (error) { notify(error.message, 'warning'); } }); recentLabels.append(retry, confirm); try { await openLabelView(device, labelPreview); retry.textContent = 'Label print view opened'; retry.disabled = true; confirm.disabled = false; notify('Device Registered — Label print view opened. Confirm Label Attached only after attaching it to this exact device.', 'success'); } catch (error) { notify(error.message, 'warning'); } } staged = []; render(); if (config.overallRemaining === 0) { const count = document.getElementById('completion-count'); if (count) count.textContent = config.overallRegistered + ' / ' + config.overallExpected; receivingComplete.style.display = 'block'; receivingComplete.scrollIntoView({ behavior: 'smooth', block: 'start' }); } else if (config.remaining === 0 && config.nextLineUrl) { const next = document.createElement('a'); next.className = 'btn btn-primary btn-sm'; next.href = config.nextLineUrl; next.textContent = 'Receive next product'; recentLabels.append(document.createTextNode(' '), next); } else { scanner.focus(); }
            const lineProgressBar = document.getElementById('line-progress-bar-' + config.purchaseLineId); if (lineProgressBar) lineProgressBar.style.width = (config.expected ? Math.min(100, Math.round(config.registered / config.expected * 100)) : 0) + '%';
            const lineAction = document.getElementById('line-action-' + config.purchaseLineId); if (lineAction && config.remaining === 0) lineAction.textContent = 'View devices';
            const availableAdded = devices.filter(device => String(device.lifecycle_state || '') === 'AVAILABLE').length;
            const awaitingAdded = devices.filter(device => ['RECEIVED_PENDING_INSPECTION', 'INSPECTION_IN_PROGRESS'].includes(String(device.lifecycle_state || ''))).length;
            config.overallReady += availableAdded; config.overallAwaitingInspection += awaitingAdded; config.lineReady += availableAdded; config.lineAwaitingInspection += awaitingAdded;
            const ready = document.getElementById('ready-count'); if (ready) ready.textContent = config.overallReady;
            const lineInspection = document.getElementById('line-inspection-' + config.purchaseLineId); if (lineInspection) { lineInspection.textContent = config.lineReady + ' ready · ' + config.lineAwaitingInspection + ' awaiting inspection' + (config.lineActionRequired ? ' · ' + config.lineActionRequired + ' action required' : ''); lineInspection.style.display = ''; }
            const inspectionWaitingCount = document.getElementById('inspection-waiting-count'); if (inspectionWaitingCount) inspectionWaitingCount.textContent = config.overallAwaitingInspection;
            const inspectionWaitingGrammar = document.getElementById('inspection-waiting-grammar'); if (inspectionWaitingGrammar) inspectionWaitingGrammar.textContent = config.overallAwaitingInspection === 1 ? 'device is' : 'devices are';
        } catch (error) { notify(error.message || 'This batch could not be identified. Check the identifiers and try again.', 'warning'); registerButton.disabled = false; scanner.focus(); }
    });
    render(); scanner.focus();
})();
</script>
@endif
@endsection
