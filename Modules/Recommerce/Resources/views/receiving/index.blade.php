@extends('layouts.app')

@section('title', 'Receive Devices')

@section('content')
@php
    $purchase = data_get($purchaseContext, 'purchase');
    $lines = collect(data_get($purchaseContext, 'lines', []));
    $selectedLine = data_get($purchaseContext, 'selected_line');
    $purchaseReference = $purchase ? ($purchase->ref_no ?: $purchase->invoice_no ?: '#'.$purchase->id) : null;
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
            <div class="box-header with-border"><h1 id="device-receiving-title" class="box-title">Receive Devices</h1></div>
            <div class="box-body">
                <p class="text-muted">Start from a received supplier purchase. SaverPOS will identify the product lines that need individual Device registration.</p>
                <a class="btn btn-primary" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}">Open Purchases</a>
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}">Back to Purchases</a></div>
                        <h1 id="device-receiving-title" class="box-title">Receive Devices</h1>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-sm-4"><strong>Purchase</strong><br>{{ $purchaseReference }}</div>
                            <div class="col-sm-4"><strong>Supplier</strong><br>{{ $purchase->supplier_business_name ?: $purchase->supplier_name ?: 'Not recorded' }}</div>
                            <div class="col-sm-4"><strong>Branch</strong><br>{{ $purchase->location_name ?: 'Branch '.$locationId }}<br><small class="text-muted">Received {{ \Carbon\Carbon::parse($purchase->transaction_date)->format('d M Y') }}</small></div>
                        </div>
                        <div class="sb-receiving-progress" aria-label="Overall Device receiving progress"><span style="width: {{ data_get($purchaseContext, 'expected_count', 0) > 0 ? min(100, round(data_get($purchaseContext, 'registered_count', 0) / data_get($purchaseContext, 'expected_count', 1) * 100)) : 0 }}%"></span></div>
                        <div class="row text-center" style="margin-top:12px"><div class="col-xs-3"><strong>{{ data_get($purchaseContext, 'expected_count', 0) }}</strong><br><small>Expected</small></div><div class="col-xs-3"><strong id="registered-count">{{ data_get($purchaseContext, 'registered_count', 0) }}</strong><br><small>Registered</small></div><div class="col-xs-3"><strong id="remaining-count">{{ data_get($purchaseContext, 'remaining_count', 0) }}</strong><br><small>Remaining</small></div><div class="col-xs-3"><strong>{{ data_get($purchaseContext, 'inspection_cleared_count', 0) }}</strong><br><small>Inspection cleared</small></div></div>
                    </div>
                </div>

                @if ($selectedLine)
                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h2 class="box-title">{{ $selectedLine->product_name }}@if($selectedLine->variation_name) <small>{{ $selectedLine->variation_name }}</small>@endif</h2>
                            <p id="selected-line-progress" class="text-muted" style="margin:6px 0 0">{{ $selectedLine->registered_count }} registered · {{ $selectedLine->remaining_count }} remaining</p>
                        </div>
                        <div class="box-body">
                            @if (! $selectedLine->is_whole_unit)
                                <div class="alert alert-warning">This purchase quantity must be corrected to a whole number before individual Devices can be received.</div>
                            @elseif ($selectedLine->remaining_count === 0)
                                <div class="alert alert-success">All Devices for this purchase line are registered.</div>
                            @elseif (! $postEnabled)
                                <div class="alert alert-info">You can review this purchase line, but Device registration is not available for your current access.</div>
                            @else
                                <label for="scan-identifier">Scan serial / IMEI</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-btn"><select id="identifier-type" class="form-control" aria-label="Identifier type"><option value="SERIAL">Serial</option><option value="IMEI">IMEI</option><option value="ASSET_TAG">Asset tag</option></select></span>
                                    <input id="scan-identifier" class="form-control sb-scanner-input" maxlength="255" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Scan or enter identifier, then press Enter">
                                </div>
                                <p class="help-block">Scan → validate → accepted. Valid scans remain staged while an exception is resolved; the scanner returns to focus after every result.</p>

                                <div id="scan-message" class="alert" style="display:none" role="status" aria-live="polite"></div>
                                <div id="scan-exceptions" aria-live="polite"></div>
                                <p id="batch-summary" class="text-muted">0 valid · 0 blocked</p>
                                <div id="staged-units" aria-live="polite"></div>
                                <div id="empty-staged" class="text-muted" style="padding:12px 0">No Devices staged yet.</div>
                                <div class="btn-toolbar" style="margin-top:16px">
                                    <button id="register-devices" class="btn btn-primary" type="button" disabled>Register Devices</button>
                                    <button id="clear-staged" class="btn btn-default" type="button" disabled>Clear staged scans</button>
                                </div>
                                <div id="recent-labels" style="margin-top:12px"></div>
                            @endif
                        </div>
                    </div>
                @elseif ($lines->where('tracking_mode', 'SERIALIZED_DEVICE')->isEmpty())
                    <div class="box box-default"><div class="box-body"><p class="text-muted">This purchase contains bulk stock only. No individual Device registration is required.</p></div></div>
                @else
                    <div class="box box-default"><div class="box-body"><p class="text-muted">Choose a serialized product line from the list to continue receiving.</p></div></div>
                @endif

                @if ($selectedLine)
                    <div class="box box-default">
                        <div class="box-header with-border"><h3 class="box-title">Registered Devices</h3></div>
                        <div class="box-body table-responsive">
                            <table class="table table-hover"><thead><tr><th>#</th><th>Device ID</th><th>Identifier</th><th>Unit cost</th><th>Status</th><th></th></tr></thead><tbody id="registered-device-list">
                                @forelse ($registeredDevices as $device)
                                    <tr><td>{{ $device->unit_ordinal }}</td><td><strong>{{ $device->device_code }}</strong></td><td>Protected identifier recorded</td><td>{{ $device->unit_acquisition_cost === null ? '—' : 'RM '.number_format((float) $device->unit_acquisition_cost, 2) }}</td><td>{{ str_replace('_', ' ', $device->lifecycle_state) }}</td><td><form method="post" action="{{ route('recommerce.devices.label.print', $device->device_id) }}" target="_blank"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button class="btn btn-default btn-xs" type="submit">Print label</button></form></td></tr>
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
                                    <div class="sb-receiving-progress"><span style="width: {{ $percent }}%"></span></div>
                                    <span id="line-progress-{{ $line->id }}">{{ $line->registered_count }} / {{ $line->expected_count }}</span>
                                    @if ($line->remaining_count > 0)
                                        <span class="text-muted"> · {{ $line->remaining_count }} remaining</span>
                                    @else
                                        <span class="text-success"> · Complete</span>
                                    @endif
                                    @if($line->registered_count > 0)
                                        <br><small class="text-muted">{{ $line->inspection_cleared_count }} ready · {{ $line->inspection_open_count }} awaiting inspection
                                        @if($line->inspection_failed_count)
                                            · {{ $line->inspection_failed_count }} action required
                                        @endif
                                        </small>
                                    @endif
                                    <br><a class="btn btn-default btn-xs" style="margin-top:7px" href="{{ route('recommerce.receiving.index', ['purchase_id' => $purchase->id, 'purchase_line_id' => $line->id]) }}">{{ $line->remaining_count > 0 ? ($line->registered_count ? 'Continue receiving' : 'Receive Devices') : 'View Devices' }}</a>
                                </div>
                            @else
                                <div class="sb-receiving-line"><strong>{{ $line->product_name }}</strong><br><span class="text-muted">Bulk stock · no individual Devices required</span></div>
                            @endif
                        @endforeach
                    </div>
                </div>
                <div class="box box-default"><div class="box-body"><a class="btn btn-default btn-block" href="{{ route('recommerce.inspection.index') }}">Open Inspection Queue</a><a class="btn btn-default btn-block" href="{{ route('recommerce.devices.index') }}">Open Device Registry</a><a class="btn btn-default btn-block" href="{{ route('recommerce.reconciliation.index') }}">Stock Check</a></div></div>
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
    const config = {
        attachUrl: @json(route('recommerce.receiving.attach_purchase')),
        prepareUrl: @json(route('recommerce.receiving.prepare')),
        purchaseId: @json((int) $purchase->id), purchaseLineId: @json((int) $selectedLine->id),
        locationId: @json((int) $locationId), productId: @json((int) $selectedLine->product_id), variationId: @json((int) $selectedLine->variation_id),
        max: @json(min((int) $selectedLine->remaining_count, (int) config('recommerce.receive_batch_limit', 50))), remaining: @json((int) $selectedLine->remaining_count), registered: @json((int) $selectedLine->registered_count), expected: @json((int) $selectedLine->expected_count),
        defaultCost: @json($selectedLine->default_unit_acquisition_cost), canOverrideCost: @json($canOverrideCost)
    };
    let staged = []; let blocked = [];
    const uuid = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
    const normalise = value => value.trim().toUpperCase().replace(/[\s_-]+/g, '');
    const notify = (text, kind) => { message.textContent = text; message.className = 'alert alert-' + kind; message.style.display = 'block'; };
    const resetMessage = () => { message.style.display = 'none'; };
    const money = value => value === null || value === '' ? '—' : 'RM ' + Number(value).toFixed(2);
    function render() {
        stagedTarget.replaceChildren(); emptyState.style.display = staged.length ? 'none' : 'block';
        batchSummary.textContent = staged.length + ' valid · ' + blocked.length + ' blocked';
        exceptions.replaceChildren();
        blocked.forEach((exception, index) => { const card = document.createElement('div'); card.className = 'alert alert-warning'; const text = document.createElement('span'); text.textContent = exception.message; card.append(text); if (exception.device_url) { const link = document.createElement('a'); link.href = exception.device_url; link.className = 'btn btn-default btn-xs pull-right'; link.textContent = 'View Device'; card.append(link); } const dismiss = document.createElement('button'); dismiss.type = 'button'; dismiss.className = 'btn btn-link btn-xs'; dismiss.textContent = 'Remove scan'; dismiss.addEventListener('click', () => { blocked.splice(index, 1); render(); scanner.focus(); }); card.append(dismiss); exceptions.append(card); });
        staged.forEach((unit, index) => {
            const row = document.createElement('div'); row.className = 'sb-staged-unit';
            const identity = document.createElement('strong'); identity.textContent = unit.type + ' · ' + unit.mask;
            const details = document.createElement('span'); details.className = 'text-muted'; details.textContent = 'Valid · ready to register';
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-default btn-xs'; remove.textContent = 'Remove'; remove.addEventListener('click', () => { staged.splice(index, 1); render(); scanner.focus(); });
            row.append(identity, details);
            if (config.canOverrideCost) { const cost = document.createElement('input'); cost.type = 'number'; cost.min = '0'; cost.step = '0.01'; cost.className = 'form-control input-sm'; cost.style.width = '110px'; cost.value = unit.cost === null ? '' : unit.cost; cost.setAttribute('aria-label', 'Unit acquisition cost'); cost.addEventListener('input', () => { unit.cost = cost.value === '' ? null : cost.value; }); const reason = document.createElement('select'); reason.className = 'form-control input-sm'; reason.style.width = '170px'; reason.setAttribute('aria-label', 'Cost override reason'); [['','Default purchase cost'],['SUPPLIER_UNIT_PRICING','Supplier unit pricing'],['BUNDLE_ALLOCATION','Bundle allocation'],['INVOICE_CORRECTION','Invoice correction'],['MANAGEMENT_ADJUSTMENT','Management adjustment'],['OTHER','Other']].forEach(([value,label]) => { const option = document.createElement('option'); option.value=value; option.textContent=label; option.selected=unit.costReason===value; reason.append(option); }); reason.addEventListener('change', () => { unit.costReason = reason.value; }); row.append(cost, reason); }
            else { const cost = document.createElement('span'); cost.className = 'text-muted'; cost.textContent = money(unit.cost); row.append(cost); }
            const observation = document.createElement('select'); observation.className = 'form-control input-sm'; observation.style.width = '170px'; observation.setAttribute('aria-label', 'Optional intake observation'); [['','No intake issue'],['DAMAGED_PACKAGING','Damaged packaging'],['VISIBLE_PHYSICAL_DAMAGE','Visible damage'],['PRODUCT_MISMATCH','Product mismatch'],['MISSING_CHARGER','Missing charger'],['UNREADABLE_IDENTIFIER','Unreadable identifier'],['SUPPLIER_DISCREPANCY','Supplier discrepancy'],['OTHER','Other issue']].forEach(([value,label]) => { const option=document.createElement('option'); option.value=value; option.textContent=label; option.selected=unit.observationType===value; observation.append(option); }); observation.addEventListener('change', () => { unit.observationType=observation.value; }); row.append(observation);
            row.append(remove); stagedTarget.append(row);
        });
        registerButton.disabled = staged.length === 0; clearButton.disabled = staged.length === 0;
        registerButton.textContent = staged.length ? 'Register ' + staged.length + ' Device' + (staged.length === 1 ? '' : 's') : 'Register Devices';
    }
    async function stageCurrent() {
        const value = scanner.value.trim(); const key = normalise(value); resetMessage();
        if (!key) { notify('Scan or enter a serial, IMEI, or asset tag first.', 'warning'); return; }
        if (staged.length >= Math.min(config.max, config.remaining)) { notify('This purchase line already has the maximum number of Devices ready for this batch.', 'warning'); return; }
        if (staged.some(unit => unit.type === type.value && unit.key === key)) { notify('This identifier is already in the current batch.', 'warning'); scanner.select(); return; }
        scanner.disabled = true;
        try { await request(config.prepareUrl, { location_id: config.locationId, product_id: config.productId, variation_id: config.variationId, units: [{ identifier_type: type.value, identifier_value: value }] }); const mask = key.length <= 4 ? '••••' : '••••' + key.slice(-4); staged.push({ type: type.value, value, key, mask, cost: config.defaultCost, costReason: '', observationType: '' }); scanner.value = ''; notify('Accepted. Scan the next Device.', 'success'); }
        catch (error) { blocked.push({ message: error.message || 'This scan needs attention.', device_url: error.device_url || null }); notify('Scan blocked. Valid staged Devices are unchanged.', 'warning'); }
        finally { scanner.disabled = false; render(); scanner.focus(); }
    }
    async function request(url, body) {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, body: JSON.stringify(body) });
        const data = await response.json().catch(() => ({})); if (!response.ok) { const error = new Error(data.message || 'This Device could not be registered.'); error.device_url = data.exception && data.exception.device_url; throw error; } return data;
    }
    function payload(commandUuid) { return { command_uuid: commandUuid, location_id: config.locationId, product_id: config.productId, variation_id: config.variationId, purchase_transaction_id: config.purchaseId, purchase_line_id: config.purchaseLineId, units: staged.map(unit => ({ identifier_type: unit.type, identifier_value: unit.value, unit_acquisition_cost: unit.cost, cost_override_reason_code: unit.costReason, intake_observations: unit.observationType ? [{ type: unit.observationType }] : [] })) }; }
    scanner.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); stageCurrent(); } });
    clearButton.addEventListener('click', () => { staged = []; resetMessage(); render(); scanner.focus(); });
    registerButton.addEventListener('click', async () => {
        if (!staged.length) return; registerButton.disabled = true; const commandUuid = uuid();
        try {
            const data = await request(config.attachUrl, payload(commandUuid)); const devices = (data.result && data.result.devices) || [];
            const registered = document.getElementById('registered-count'); const remaining = document.getElementById('remaining-count'); config.registered += devices.length; config.remaining = Math.max(0, config.remaining - devices.length); if (registered) registered.textContent = Number(registered.textContent || 0) + devices.length; if (remaining) remaining.textContent = config.remaining; const selectedProgress = document.getElementById('selected-line-progress'); if (selectedProgress) selectedProgress.textContent = config.registered + ' registered · ' + config.remaining + ' remaining'; const lineProgress = document.getElementById('line-progress-' + config.purchaseLineId); if (lineProgress) lineProgress.textContent = config.registered + ' / ' + config.expected; const emptyRegistered = document.getElementById('no-registered-devices'); if (emptyRegistered) emptyRegistered.remove(); const registeredList = document.getElementById('registered-device-list'); devices.forEach(device => { const row = document.createElement('tr'); [String(device.unit_ordinal), device.device_code, 'Protected identifier recorded', money(config.defaultCost), 'RECEIVED PENDING INSPECTION'].forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); }); const action = document.createElement('td'); const link = document.createElement('a'); link.className = 'btn btn-default btn-xs'; link.href = root.dataset.labelPrintPrefix.replace(/\/$/, '') + '/' + device.device_code; link.textContent = 'Open Device'; action.append(link); row.append(action); registeredList.append(row); }); recentLabels.replaceChildren(); if (devices.length) { const heading = document.createElement('strong'); heading.textContent = 'Print Device Labels'; recentLabels.append(heading); devices.forEach(device => { const form = document.createElement('form'); form.method = 'post'; form.action = root.dataset.labelPrintPrefix.replace(/\/$/, '') + '/' + device.device_id + '/label/print'; form.target = '_blank'; form.style.display = 'inline-block'; form.style.margin = '5px 5px 0 0'; const token = document.createElement('input'); token.type = 'hidden'; token.name = '_token'; token.value = root.dataset.csrfToken; const button = document.createElement('button'); button.type = 'submit'; button.className = 'btn btn-default btn-sm'; button.textContent = 'Print ' + device.device_code; form.append(token, button); recentLabels.append(form); }); } notify(devices.length + ' Device' + (devices.length === 1 ? '' : 's') + ' registered and sent to inspection.', 'success'); staged = []; render(); scanner.focus();
        } catch (error) { notify(error.message || 'This batch could not be registered. Check the identifiers and try again.', 'warning'); registerButton.disabled = false; scanner.focus(); }
    });
    render(); scanner.focus();
})();
</script>
@endif
@endsection
