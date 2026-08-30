@extends('layouts.app')

@section('title', 'New customer repair')

@section('content')
<style>
    .sb-repair-page { max-width: 1140px; margin: 0 auto; }
    .sb-repair-hero { display:flex; justify-content:space-between; align-items:center; gap:20px; margin-bottom:18px; }
    .sb-repair-hero h1 { margin:2px 0 5px; font-size:24px; font-weight:700; color:var(--sb-text,#172033); }
    .sb-repair-muted { color:var(--sb-muted,#58657a); font-size:13px; }
    .sb-repair-card { background:var(--sb-surface-raised,#fff); border:1px solid var(--sb-border,#e2e8f0); border-radius:10px; box-shadow:0 6px 16px rgba(0,0,0,.28); margin-bottom:14px; overflow:hidden; }
    .sb-repair-card h2 { font-size:14px; font-weight:800; letter-spacing:.02em; margin:0; padding:13px 18px; border-bottom:1px solid var(--sb-border,#eaeef5); color:var(--sb-text,#0f172a); }
    .sb-repair-card .card-body { padding:16px 18px; }
    .sb-repair-card .card-lead { margin:0 0 14px; font-size:13px; color:var(--sb-muted,#58657a); }
    .sb-repair-card .form-control { min-height:38px; border-radius:6px; border-color:var(--sb-border-strong,#d7dce4); }
    .sb-repair-card textarea.form-control { min-height:82px; }
    .sb-repair-card label { font-weight:600; margin-bottom:5px; display:block; }
    .sb-repair-card label .sb-required { color:var(--sb-danger,#dc2626); margin-left:3px; }
    .sb-checklist { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
    .sb-check { border:1px solid var(--sb-border,#e2e8f0); border-radius:7px; padding:9px 11px; background:var(--sb-surface,#fbfdff); }
    .sb-check label { font-weight:700; margin-bottom:6px; display:block; }
    .sb-check .form-control { min-height:32px; }
    .sb-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .sb-status { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 10px; font-size:11px; font-weight:800; text-transform:uppercase; }
    .sb-status-info { background:#1e3a8a; color:#bfdbfe; }
    .sb-search { border:1px solid var(--sb-border,#dbe3ea); border-radius:7px; padding:11px 12px; background:var(--sb-surface,#f9fbfd); margin-bottom:15px; }
    .sb-search .form-control { min-height:34px; }
    .sb-search .search-help { margin:8px 0 0; font-size:12px; color:var(--sb-muted,#64748b); }
    @media (max-width: 767px) {
        .sb-repair-hero { align-items:flex-start; flex-direction:column; }
        .sb-repair-card h2 { padding:12px 15px; }
        .sb-repair-card .card-body { padding:14px 15px; }
        .sb-checklist { grid-template-columns:1fr; }
        .sb-actions { align-items:stretch; flex-direction:column; }
        .sb-actions .btn { width:100%; }
    }
</style>

<section class="container-fluid sb-repair-page" aria-labelledby="new-repair-title">
    <div class="sb-repair-hero">
        <div>
            <p class="sb-repair-muted" style="margin:0 0 5px">Customer Repair · Location {{ $locationId }}</p>
            <h1 id="new-repair-title">New Customer Repair</h1>
            <p class="sb-repair-muted" style="margin:0">Capture the handoff once; the repair code appears after the record saves.</p>
<div class="sb-repair-help">Start with the customer, then find the device and describe the handoff.</div>
        </div>
        <div class="sb-actions">
            <span class="sb-status sb-status-info"><span aria-hidden="true">●</span> Customer-owned · no POS stock</span>
            <a class="btn btn-default" href="{{ route('recommerce.repair.index') }}">Back to Repair</a>
        </div>
    </div>

    <div id="repair-intake" data-csrf-token="{{ csrf_token() }}" data-intake-url="{{ route('recommerce.repair.intake') }}" data-device-search-url="{{ route('recommerce.repair.devices.search') }}" data-customer-search-url="{{ route('recommerce.repair.customers') }}">
        <div id="repair-intake-result" class="alert" style="display:none" role="alert" aria-live="polite"></div>
        <form id="repair-intake-form" novalidate>
            <div class="row">
                <div class="col-md-7">
                    <div class="sb-repair-card">
                        <h2>Customer and device</h2>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="repair-customer">Customer <span class="sb-required">*</span></label>
                                <label class="sr-only" for="repair-customer-search">Search customers by name, contact reference, or mobile</label>
                                <input id="repair-customer-search" class="form-control" style="margin-bottom:7px" autocomplete="off" placeholder="Search by name, reference, or mobile" aria-describedby="repair-customer-help">
                                <div class="input-group">
                                    <select id="repair-customer" class="form-control" required aria-describedby="repair-customer-help">
                                        <option value="">Select a customer</option>
                                        @foreach ($customers as $customer)
                                            <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->contact_id ? ' · '.$customer->contact_id : '' }}{{ $customer->mobile ? ' · '.$customer->mobile : '' }}</option>
                                        @endforeach
                                    </select>
                                    <span class="input-group-btn"><button type="button" class="btn btn-default btn-modal" data-href="{{ action([\App\Http\Controllers\ContactController::class, 'create'], ['type' => 'customer']) }}" data-container=".contact_modal">Quick create</button></span>
                                </div>
                                <span id="repair-customer-help" class="help-block">Search by name, contact reference, or mobile. If the customer is new, use <strong>Quick create</strong>, then search for them here.</span>
                            </div>

                            <div class="sb-search">
                                <div class="row">
                                    <div class="col-sm-4">
                                        <label for="repair-identifier-type">Identifier type</label>
                                        <select id="repair-identifier-type" class="form-control">
                                            <option value="SERIAL">Serial / service tag</option>
                                            <option value="IMEI">IMEI</option>
                                            <option value="DEVICE_CODE">SAVERPOS device code</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-5">
                                        <label for="repair-identifier-value">Identifier value</label>
                                        <input id="repair-identifier-value" class="form-control" maxlength="255" autocomplete="off" placeholder="Serial, IMEI, or SAVERPOS code">
                                    </div>
                                    <div class="col-sm-3" style="padding-top:25px">
                                        <button type="button" id="repair-device-search" class="btn btn-primary btn-block">Find device</button>
                                    </div>
                                </div>
                                <p id="repair-device-result" class="help-block search-help" style="margin:9px 0 0" role="status" aria-live="polite">Exact lookup. Scoped to this customer and branch. If no match is found, the form creates a new device.</p>
                                <input type="hidden" id="repair-device-id">
                            </div>

                            <div class="row">
                                <div class="col-sm-4 form-group">
                                    <label for="repair-category">Device category <span class="sb-required">*</span></label>
                                    <select id="repair-category" class="form-control" required>
                                        <option value="">Select a category</option><option value="PHONE">Phone</option><option value="TABLET">Tablet</option><option value="LAPTOP">Laptop</option><option value="DESKTOP">Desktop</option><option value="CONSOLE">Game console</option><option value="OTHER">Other</option>
                                    </select>
                                </div>
                                <div class="col-sm-4 form-group"><label for="repair-brand">Brand <span class="sb-required">*</span></label><input id="repair-brand" class="form-control" maxlength="120" required></div>
                                <div class="col-sm-4 form-group"><label for="repair-model">Model <span class="sb-required">*</span></label><input id="repair-model" class="form-control" maxlength="160" required></div>
                            </div>
                        </div>
                    </div>

                    <div class="sb-repair-card">
                        <h2>Fault and handoff</h2>
                        <div class="card-body">
                            <div class="form-group"><label for="repair-fault">Fault description <span class="sb-required">*</span></label><textarea id="repair-fault" class="form-control" maxlength="10000" required placeholder="What does the customer report? Include symptoms and when it started."></textarea></div>
                            <div class="form-group"><label for="repair-condition">Cosmetic condition <span class="sb-required">*</span></label><textarea id="repair-condition" class="form-control" maxlength="10000" required placeholder="Record scratches, cracks, dents, liquid indicators, and other visible condition."></textarea></div>
                            <div class="row">
                                <div class="col-sm-6 form-group"><label for="repair-access">Device access status <span class="sb-required">*</span></label><select id="repair-access" class="form-control" required><option value="NO_LOCK">No lock configured</option><option value="CUSTOMER_WILL_UNLOCK">Customer will unlock when needed</option><option value="EXTERNAL_ACCESS_SUPPLIED">Access details supplied externally</option></select><span class="help-block">Never enter a password, PIN, pattern, or credential here.</span></div>
                                <div class="col-sm-12 form-group"><label for="repair-update">Customer-facing update</label><input id="repair-update" class="form-control" maxlength="1000" value="Device received. We will update you after diagnosis."></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="sb-repair-card">
                        <h2>Work plan</h2>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-sm-6 form-group"><label for="repair-due">Due date</label><input id="repair-due" type="date" class="form-control"></div>
                                <div class="col-sm-6 form-group"><label for="repair-priority">Priority <span class="sb-required">*</span></label><select id="repair-priority" class="form-control" required><option value="NORMAL">Normal</option><option value="LOW">Low</option><option value="HIGH">High</option><option value="URGENT">Urgent</option></select></div>
                            </div>
                            <div class="form-group"><label for="repair-technician">Assigned technician</label><select id="repair-technician" class="form-control"><option value="">Assign later</option>@foreach ($technicians as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                            <div class="row">
                                <div class="col-sm-6 form-group"><label for="repair-quote">Estimated quote (RM)</label><input id="repair-quote" type="number" min="0" step="0.01" class="form-control" inputmode="decimal" placeholder="Optional"></div>
                                <div class="col-sm-6 form-group"><label for="repair-warranty-days">Warranty days</label><input id="repair-warranty-days" type="number" min="0" max="3650" class="form-control" inputmode="numeric" placeholder="Optional"></div>
                            </div>
                            <div class="form-group"><label for="repair-warranty-terms">Warranty information</label><textarea id="repair-warranty-terms" class="form-control" maxlength="2000" rows="3" placeholder="Coverage, exclusions, or agreed terms"></textarea></div>
                        </div>
                    </div>

                    <div class="sb-repair-card">
                        <h2>Pre-repair checklist</h2>
                        <div class="card-body">
                            <p class="card-lead">Select an outcome for every check. Add short notes for exceptions or visible evidence.</p>
                            <div class="sb-checklist">
                                @foreach ($checklist as $check)
                                    <div class="sb-check" data-check-key="{{ $check['key'] }}" data-check-label="{{ $check['label'] }}">
                                        <label for="check-{{ $check['key'] }}">{{ $check['label'] }} <span class="sb-required">*</span></label>
                                        <select id="check-{{ $check['key'] }}" class="form-control checklist-outcome" required><option value="">Choose</option><option value="PASS">PASS</option><option value="FAIL">FAIL</option><option value="NOT_APPLICABLE">NOT APPLICABLE</option></select>
                                        <input class="form-control checklist-note" style="margin-top:7px" maxlength="1000" placeholder="Optional note" aria-label="{{ $check['label'] }} note">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="sb-actions" style="justify-content:flex-end; margin-bottom:24px">
                        <a class="btn btn-default" href="{{ route('recommerce.repair.index') }}">Return to workbench</a>
                        <button id="repair-submit" class="btn btn-primary btn-lg" type="submit">Create customer repair</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>

<div class="modal fade contact_modal" tabindex="-1" role="dialog" aria-labelledby="repair-quick-create-title"></div>

<script>
(function () {
    const root = document.getElementById('repair-intake');
    const form = document.getElementById('repair-intake-form');
    const result = document.getElementById('repair-intake-result');
    const customer = document.getElementById('repair-customer');
    const customerSearch = document.getElementById('repair-customer-search');
    const deviceResult = document.getElementById('repair-device-result');
    const deviceId = document.getElementById('repair-device-id');
    const identifierType = document.getElementById('repair-identifier-type');
    const identifierValue = document.getElementById('repair-identifier-value');
    const csrf = root.dataset.csrfToken;
    const uuid = function () { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { const r = Math.random() * 16 | 0; const v = c === 'x' ? r : (r & 3 | 8); return v.toString(16); }); };
    const message = function (text, kind) { result.textContent = text; result.className = 'alert alert-' + kind; result.style.display = 'block'; result.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); };
    const field = function (id) { return document.getElementById(id).value.trim(); };

    // The select is seeded with the first 200 customers by name only, so a
    // client-side filter over those options cannot find anyone past the 200th.
    // Search goes to the server; the current selection is always kept so a
    // chosen customer never disappears out from under the form.
    const customerHelp = document.getElementById('repair-customer-help');
    const seededOptions = Array.prototype.slice.call(customer.options);
    let customerSearchToken = 0;

    const renderCustomers = function (rows) {
        const selectedValue = customer.value;
        const selectedText = customer.selectedIndex > 0 ? customer.options[customer.selectedIndex].text : '';
        customer.innerHTML = '';
        customer.appendChild(new Option('Select a customer', ''));
        let selectionPresent = false;
        rows.forEach(function (row) {
            const label = row.name + (row.reference ? ' · ' + row.reference : '') + (row.mobile ? ' · ' + row.mobile : '');
            customer.appendChild(new Option(label, String(row.id)));
            if (String(row.id) === selectedValue) { selectionPresent = true; }
        });
        if (selectedValue && !selectionPresent) { customer.appendChild(new Option(selectedText, selectedValue)); }
        customer.value = selectedValue;
    };

    const restoreSeededCustomers = function () {
        const selectedValue = customer.value;
        const selectedText = customer.selectedIndex > 0 ? customer.options[customer.selectedIndex].text : '';
        customer.innerHTML = '';
        let selectionPresent = false;
        seededOptions.forEach(function (option) {
            customer.appendChild(option.cloneNode(true));
            if (option.value === selectedValue) { selectionPresent = true; }
        });
        // A customer found by search may sit past the seeded 200; clearing the
        // box must not silently drop them from the form.
        if (selectedValue && !selectionPresent) { customer.appendChild(new Option(selectedText, selectedValue)); }
        customer.value = selectedValue;
    };

    customerSearch.addEventListener('input', function () {
        const term = this.value.trim();
        const token = ++customerSearchToken;
        if (term.length < 2) {
            restoreSeededCustomers();
            customerHelp.textContent = 'Type at least 2 characters to search every customer by name, contact reference, or mobile.';
            return;
        }
        customerHelp.textContent = 'Searching customers…';
        window.setTimeout(async function () {
            if (token !== customerSearchToken) { return; }
            try {
                const url = new URL(root.dataset.customerSearchUrl, window.location.origin);
                url.searchParams.set('q', term);
                const response = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) { throw new Error('Customer search is unavailable.'); }
                const data = await response.json();
                if (token !== customerSearchToken) { return; }
                const rows = data.data || [];
                renderCustomers(rows);
                customerHelp.textContent = rows.length
                    ? rows.length + ' matching customer(s). If the customer is new, use Quick create and then refresh this page.'
                    : 'No customer matches that search. Use Quick create and then refresh this page.';
            } catch (error) {
                if (token !== customerSearchToken) { return; }
                customerHelp.textContent = 'Customer search is unavailable. Showing the first 200 customers by name.';
                restoreSeededCustomers();
            }
        }, 250);
    });

    document.getElementById('repair-device-search').addEventListener('click', async function () {
        if (!customer.value || identifierValue.value.trim().length < 2) { deviceResult.textContent = 'Select a customer and enter at least 2 identifier characters before searching.'; return; }
        this.disabled = true; deviceResult.textContent = 'Searching this customer’s devices…';
        try {
            const url = new URL(root.dataset.deviceSearchUrl, window.location.origin);
            url.searchParams.set('contact_id', customer.value); url.searchParams.set('identifier_type', identifierType.value); url.searchParams.set('q', identifierValue.value.trim());
            const response = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            const data = await response.json(); if (!response.ok) throw new Error('Device search is unavailable.');
            if (!data.data || !data.data.length) { deviceId.value = ''; deviceResult.textContent = 'No matching customer-owned device. The submitted details will create a new device record.'; return; }
            const device = data.data[0]; deviceId.value = device.id;
            document.getElementById('repair-category').value = device.category_code || document.getElementById('repair-category').value;
            document.getElementById('repair-brand').value = device.brand || document.getElementById('repair-brand').value;
            document.getElementById('repair-model').value = device.model || document.getElementById('repair-model').value;
            deviceResult.textContent = 'Existing device selected: ' + device.device_code + '. Intake will reuse this identity.';
        } catch (error) { deviceResult.textContent = error.message; } finally { this.disabled = false; }
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        result.style.display = 'none';
        const checklist = Array.prototype.map.call(document.querySelectorAll('.sb-check'), function (row) { return { check_key: row.dataset.checkKey, label: row.dataset.checkLabel, outcome: row.querySelector('.checklist-outcome').value, notes: row.querySelector('.checklist-note').value.trim() }; });
        const invalid = checklist.some(function (item) { return !item.outcome; });
        if (!customer.value || !field('repair-category') || !field('repair-brand') || !field('repair-model') || !field('repair-fault') || !field('repair-condition') || invalid) { message('Complete the customer, device, fault, condition, and every checklist outcome before submitting.', 'warning'); return; }
        const button = document.getElementById('repair-submit'); button.disabled = true;
        const quote = field('repair-quote'); const warrantyDays = field('repair-warranty-days');
        const payload = {
            command_uuid: uuid(), location_id: {{ (int) $locationId }}, device_id: deviceId.value ? Number(deviceId.value) : null, job_type: 'CUSTOMER_REPAIR', contact_id: Number(customer.value), priority: field('repair-priority'), assigned_to: field('repair-technician') ? Number(field('repair-technician')) : null,
            identifier_type: identifierValue.value.trim() ? identifierType.value : null, identifier_value: identifierValue.value.trim() || null, category_code: field('repair-category'), brand: field('repair-brand'), model: field('repair-model'), reported_fault: field('repair-fault'), cosmetic_condition: field('repair-condition'), due_at: field('repair-due') || null, estimated_quote_amount: quote || null, access_status: field('repair-access'), customer_facing_update: field('repair-update') || null, warranty_json: { days: warrantyDays ? Number(warrantyDays) : null, terms: field('repair-warranty-terms') || null }, intake_snapshot_json: { source: 'customer_repair_counter' }, checklist: checklist
        };
        try {
            const response = await fetch(root.dataset.intakeUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, credentials: 'same-origin', body: JSON.stringify(payload) });
            const raw = await response.text();
            let data = {};
            try { data = raw ? JSON.parse(raw) : {}; } catch (parseError) { throw new Error('The server returned an unreadable intake response. No repair code was confirmed.'); }
            if (!response.ok) { if (data.errors) Object.keys(data.errors).forEach(function (key) { const base = key.split('.')[0]; const element = document.getElementById('repair-' + base.replace('_', '-')); if (element) element.setAttribute('aria-invalid', 'true'); }); throw new Error(data.message || 'Please review the intake fields.'); }
            const job = data.job || (data.data && data.data.job);
            if (!job || !job.job_code) throw new Error('The intake was not confirmed with a repair code. Check Repair before retrying.');
            message('Repair ' + job.job_code + ' created in RECEIVED.', 'success');
            const detail = document.createElement('a'); detail.className = 'btn btn-success'; detail.href = '{{ url('/recommerce/repair') }}/' + encodeURIComponent(job.job_code); detail.textContent = 'Open repair detail'; result.appendChild(document.createTextNode(' ')); result.appendChild(detail);
            if (job.lookup_url) { const tracking = document.createElement('a'); tracking.className = 'btn btn-default'; tracking.href = job.lookup_url; tracking.target = '_blank'; tracking.rel = 'noopener'; tracking.textContent = 'Open customer tracking'; result.appendChild(document.createTextNode(' ')); result.appendChild(tracking); }
            form.reset(); deviceId.value = '';
        } catch (error) { message(error.message || 'Please review the intake fields.', 'warning'); button.disabled = false; }
    });
}());
</script>
@endsection
