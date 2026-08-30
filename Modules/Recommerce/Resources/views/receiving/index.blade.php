@extends('layouts.app')

@section('title', 'Tracked receiving')

@section('content')
    @php
        $existingPurchaseLine = data_get($purchaseContext, 'selected_line');
        $existingPurchase = data_get($purchaseContext, 'purchase');
        $purchaseLines = collect(data_get($purchaseContext, 'lines', []));
        $receivingPostUrl = $postEnabled && (! $purchaseContext || $existingPurchaseLine)
            ? ($existingPurchaseLine ? route('recommerce.receiving.attach_purchase') : route('recommerce.receiving.post'))
            : null;
        $purchaseAttachment = $existingPurchaseLine ? [
            'transaction_id' => (int) $existingPurchase->id,
            'purchase_line_id' => (int) $existingPurchaseLine->id,
        ] : null;
        $maxUnits = $existingPurchaseLine
            ? min((int) $existingPurchaseLine->remaining_unit_count, (int) config('recommerce.receive_batch_limit', 50))
            : (int) config('recommerce.receive_batch_limit', 50);
    @endphp
    <section class="container" id="recommerce-receiving" data-csrf-token="{{ csrf_token() }}">
        <div class="row">
            <div class="col-md-9">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Operations overview</a></div>
                        <h3 class="box-title">{{ $existingPurchaseLine ? 'Serialise received purchase' : 'New serialised purchase' }}</h3>
                        <p class="text-muted" style="margin:6px 0 0">{{ $existingPurchaseLine ? 'Add Device identity to an existing POS purchase line. Stock, supplier, payment, and accounting remain unchanged.' : 'Create one controlled POS purchase and one Device evidence set per unit.' }}</p>
                    </div>
                    <div class="box-body">
                        @if ($postEnabled)
                            <div class="alert alert-warning" role="status">Pilot write gate is open for this cohort. Review the prepared impact before posting.</div>
                        @else
                            <div class="alert alert-info" role="status">Prepare-only mode. The write gate is closed; validation creates no purchase, Device, label, or stock record.</div>
                        @endif

                        @if ($purchaseContext)
                            @if ($purchaseLines->isEmpty())
                                <div class="alert alert-warning" role="status">This received POS purchase has no unassigned whole-unit lines in the approved serialised-device cohort.</div>
                            @else
                                <form method="get" action="{{ route('recommerce.receiving.index') }}" class="well well-sm">
                                    <input type="hidden" name="purchase_id" value="{{ $existingPurchase->id }}">
                                    <label for="purchase-line-id">POS purchase {{ $existingPurchase->ref_no ?: $existingPurchase->invoice_no ?: '#'.$existingPurchase->id }}</label>
                                    <div class="input-group">
                                        <select id="purchase-line-id" name="purchase_line_id" class="form-control">
                                            <option value="">Choose a line to serialise</option>
                                            @foreach ($purchaseLines as $line)
                                                <option value="{{ $line->id }}" @selected($existingPurchaseLine && (int) $existingPurchaseLine->id === (int) $line->id)>{{ $line->product_name }} · variation {{ $line->variation_id }} · {{ (int) $line->remaining_unit_count }} unassigned unit(s)</option>
                                            @endforeach
                                        </select>
                                        <span class="input-group-btn"><button type="submit" class="btn btn-default">Use purchase line</button></span>
                                    </div>
                                </form>
                            @endif
                        @endif

                        @if (! $purchaseContext || $existingPurchaseLine)
                        <div class="row">
                            <div class="col-sm-4">
                                <div class="form-group"><label for="receive-location">Location</label><input id="receive-location" class="form-control" value="{{ $locationId }}" readonly></div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group"><label for="receive-product">Product</label><input id="receive-product" class="form-control" value="{{ $variation->product->name }}" readonly></div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group"><label for="receive-variation">Variation ID</label><input id="receive-variation" class="form-control" value="{{ $variation->id }}" readonly></div>
                            </div>
                        </div>

                        @if (! $existingPurchaseLine)
                        <div class="row">
                            <div class="col-sm-4">
                                <div class="form-group"><label for="receive-supplier">Supplier contact ID</label><input id="receive-supplier" class="form-control" type="number" min="1" required></div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group"><label for="receive-date">Transaction date</label><input id="receive-date" class="form-control" type="date" value="{{ now()->format('Y-m-d') }}" required></div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group"><label for="receive-notes">Evidence note</label><input id="receive-notes" class="form-control" maxlength="2000"></div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-sm-4"><div class="form-group"><label for="receive-unit-cost">Unit purchase price</label><input id="receive-unit-cost" class="form-control" type="number" min="0" step="0.01" required></div></div>
                            <div class="col-sm-4"><div class="form-group"><label for="receive-unit-cost-tax">Unit price incl. tax</label><input id="receive-unit-cost-tax" class="form-control" type="number" min="0" step="0.01" required></div></div>
                            <div class="col-sm-4"><div class="form-group"><label for="receive-unit-tax">Unit item tax</label><input id="receive-unit-tax" class="form-control" type="number" min="0" step="0.01" value="0" required></div></div>
                        </div>
                        @else
                            <div class="alert alert-success" role="status">This action can add up to {{ $maxUnits }} Device record(s) in this batch to the selected POS purchase line. It does not create another purchase or change stock quantity.</div>
                        @endif

                        <div class="clearfix" style="margin:10px 0 8px"><strong>Physical identifiers</strong><span class="pull-right text-muted" id="unit-count">0 units</span></div>
                        <div id="unit-list" aria-live="polite"></div>
                        <button type="button" class="btn btn-default" id="add-unit">＋ Add unit</button>

                        <div class="well" style="margin-top:18px" aria-live="polite">
                            <strong id="preflight-title">Receiving preflight</strong>
                            <p class="text-muted" id="preflight-copy" style="margin:5px 0 0">Review the bounded command before any write can be attempted.</p>
                            <pre id="preflight-output" style="margin-top:12px;white-space:pre-wrap">No command prepared.</pre>
                        </div>

                        <div class="btn-toolbar" role="toolbar" aria-label="Receiving actions">
                            <button type="button" class="btn btn-primary" id="prepare-receipt">Validate and prepare</button>
                            <button type="button" class="btn btn-warning" id="post-receipt" disabled @if (! $postEnabled) title="Write gate is closed" @endif>Post receipt</button>
                        </div>
                        <div id="post-result" class="alert" style="display:none;margin-top:15px" role="status"></div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="box box-default">
                    <div class="box-header with-border"><h3 class="box-title">Slice status</h3></div>
                    <div class="box-body">
                        <p><span class="label label-success">Read</span> Prepare, scan, and reconcile responses are cache-safe.</p>
                        <p><span class="label label-warning">Write</span> Posting requires the explicit cohort and permission gate.</p>
                        <p><span class="label label-info">Identity</span> Raw identifiers are never echoed by preflight.</p>
                        <p class="text-muted">The label boundary returns only safe code, description, template, and QR payload fields.</p>
                    </div>
                </div>
                <div class="box box-default" id="device-results-box" style="display:none">
                    <div class="box-header with-border"><h3 class="box-title">Received Devices</h3></div>
                    <div class="box-body" id="device-results" aria-live="polite"></div>
                </div>
                <div class="box box-default" id="scan-box" style="display:none">
                    <div class="box-header with-border"><h3 class="box-title">Scan and reconcile</h3></div>
                    <div class="box-body">
                        <div class="form-group"><label for="scan-value">Device code or approved QR URL</label><input id="scan-value" class="form-control" autocomplete="off"></div>
                        <button type="button" class="btn btn-default" id="resolve-scan">Resolve scan</button>
                        <button type="button" class="btn btn-default" id="run-reconcile">Run reconciliation</button>
                        <button type="button" class="btn btn-default" id="record-reconcile" @disabled(! $reconciliationRecordEnabled) title="{{ $reconciliationRecordEnabled ? 'Retain this comparison as evidence' : 'Requires the reconciliation evidence permission and write switch' }}">Record evidence</button>
                        <div id="scan-result" class="alert" style="display:none;margin-top:12px" role="status"></div>
                        <div id="reconcile-result" class="alert" style="display:none;margin-top:12px" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        (function () {
            const root = document.getElementById('recommerce-receiving');
            const config = {
                businessId: @json($businessId),
                locationId: @json($locationId),
                productId: @json($variation->product_id),
                variationId: @json($variation->id),
                prepareUrl: @json(route('recommerce.receiving.prepare')),
                postUrl: @json($receivingPostUrl),
                purchaseAttachment: @json($purchaseAttachment),
                maxUnits: @json($maxUnits),
                labelBaseUrl: @json(url('/recommerce/devices')),
                scanUrl: @json(route('recommerce.scans.resolve')),
                reconcileUrl: @json(route('recommerce.reconciliation.show', ['variationId' => $variation->id])),
                recordReconcileUrl: @json($reconciliationRecordEnabled ? route('recommerce.reconciliation.runs.store', ['variationId' => $variation->id]) : null)
            };
            const state = { prepared: null, commandUuid: null, devices: [] };
            const unitList = document.getElementById('unit-list');

            function uuid() {
                if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (char) { const random = Math.random() * 16 | 0; const value = char === 'x' ? random : (random & 0x3 | 0x8); return value.toString(16); });
            }

            function setMessage(id, message, kind) {
                const target = document.getElementById(id);
                target.textContent = message;
                target.className = 'alert alert-' + kind;
                target.style.display = 'block';
            }

            function addUnit(type, value) {
                const row = document.createElement('div');
                row.className = 'row unit-row';
                row.style.marginBottom = '8px';
                row.innerHTML = '<div class="col-xs-3"><select class="form-control unit-type" aria-label="Identifier type"><option>SERIAL</option><option>ASSET_TAG</option><option>IMEI</option></select></div><div class="col-xs-7"><input class="form-control unit-value" maxlength="255" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-label="Identifier value"></div><div class="col-xs-2"><button type="button" class="btn btn-default remove-unit" aria-label="Remove unit">×</button></div>';
                row.querySelector('.unit-type').value = type || 'SERIAL';
                row.querySelector('.unit-value').value = value || '';
                row.querySelector('.remove-unit').addEventListener('click', function () { row.remove(); updateCount(); invalidatePrepared(); });
                row.querySelector('.unit-type').addEventListener('change', invalidatePrepared);
                row.querySelector('.unit-value').addEventListener('input', invalidatePrepared);
                unitList.appendChild(row);
                updateCount();
            }

            function updateCount() {
                const count = unitList.querySelectorAll('.unit-row').length;
                document.getElementById('unit-count').textContent = count + ' unit' + (count === 1 ? '' : 's');
                document.getElementById('add-unit').disabled = count >= config.maxUnits;
            }

            function units() {
                return Array.from(unitList.querySelectorAll('.unit-row')).map(function (row) {
                    const unitCost = document.getElementById('receive-unit-cost');
                    return { identifier_type: row.querySelector('.unit-type').value, identifier_value: row.querySelector('.unit-value').value, unit_acquisition_cost: unitCost ? unitCost.value : null };
                });
            }

            function preparePayload() {
                return { location_id: config.locationId, product_id: config.productId, variation_id: config.variationId, units: units() };
            }

            function postPayload() {
                if (config.purchaseAttachment) {
                    return {
                        business_id: config.businessId,
                        command_uuid: state.commandUuid || (state.commandUuid = uuid()),
                        location_id: config.locationId,
                        product_id: config.productId,
                        variation_id: config.variationId,
                        purchase_transaction_id: config.purchaseAttachment.transaction_id,
                        purchase_line_id: config.purchaseAttachment.purchase_line_id,
                        units: units()
                    };
                }
                return { business_id: config.businessId, command_uuid: state.commandUuid || (state.commandUuid = uuid()), location_id: config.locationId, product_id: config.productId, variation_id: config.variationId, purchase: { contact_id: document.getElementById('receive-supplier').value, transaction_date: document.getElementById('receive-date').value, unit_purchase_price: document.getElementById('receive-unit-cost').value, unit_purchase_price_inc_tax: document.getElementById('receive-unit-cost-tax').value, unit_item_tax: document.getElementById('receive-unit-tax').value, additional_notes: document.getElementById('receive-notes').value }, units: units() };
            }

            async function request(url, method, body) {
                const options = { method: method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, credentials: 'same-origin' };
                if (body) options.body = JSON.stringify(body);
                const response = await fetch(url, options);
                const data = await response.json().catch(function () { return {}; });
                if (!response.ok) throw new Error(data.message || 'The request was rejected.');
                return data;
            }

            async function requestPrintPreview(url) {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Accept': 'text/html', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                    credentials: 'same-origin'
                });
                const markup = await response.text();
                if (!response.ok) throw new Error('The label preview was rejected.');
                return markup;
            }

            let lastScan = { value: '', at: 0 };

            function shouldIgnoreDuplicateScan(value) {
                const normalized = value.trim();
                const now = Date.now();
                const duplicate = normalized !== ''
                    && normalized === lastScan.value
                    && now - lastScan.at < 750;
                lastScan = { value: normalized, at: now };
                return duplicate;
            }

            function invalidatePrepared() {
                if (!state.prepared) return;
                state.prepared = null;
                state.commandUuid = null;
                state.devices = [];
                document.getElementById('post-receipt').disabled = true;
                document.getElementById('preflight-title').textContent = 'Receiving preflight needs review';
                document.getElementById('preflight-copy').textContent = 'The unit draft changed. Prepare it again before posting.';
                document.getElementById('device-results').replaceChildren();
                document.getElementById('device-results-box').style.display = 'none';
                document.getElementById('scan-box').style.display = 'none';
                document.getElementById('scan-result').style.display = 'none';
                document.getElementById('reconcile-result').style.display = 'none';
                document.getElementById('post-result').style.display = 'none';
            }

            function renderDevices(devices) {
                const box = document.getElementById('device-results-box');
                const target = document.getElementById('device-results');
                target.replaceChildren();
                devices.forEach(function (device) {
                    const row = document.createElement('div');
                    row.className = 'well well-sm';
                    const code = document.createElement('strong');
                    code.textContent = device.device_code;
                    const copy = document.createElement('p');
                    copy.className = 'text-muted';
                    copy.textContent = 'Received · label not issued';
                    const labelButton = document.createElement('button');
                    labelButton.type = 'button';
                    labelButton.className = 'btn btn-default btn-xs';
                    labelButton.textContent = 'Open safe label preview';
                    labelButton.addEventListener('click', function () { issueLabel(device, row, copy, labelButton); });
                    row.append(code, copy, labelButton);
                    target.appendChild(row);
                });
                box.style.display = 'block';
                document.getElementById('scan-box').style.display = 'block';
                document.getElementById('scan-value').focus();
            }

            async function issueLabel(device, row, copy, button) {
                button.disabled = true;
                const previewWindow = window.open('', '_blank');
                if (previewWindow) previewWindow.opener = null;
                if (!previewWindow || previewWindow.closed) {
                    button.disabled = false;
                    copy.textContent = 'Print preview could not be opened. Label was not issued.';
                    return;
                }
                try {
                    const markup = await requestPrintPreview(config.labelBaseUrl + '/' + encodeURIComponent(device.device_id) + '/label/print');
                    if (previewWindow.closed) throw new Error('A print preview window was closed before rendering.');
                    previewWindow.document.open();
                    previewWindow.document.write(markup);
                    previewWindow.document.close();
                    copy.textContent = 'READY_TO_PRINT · raw token hidden from the operator view';
                    button.textContent = 'Label preview opened';
                    row.className = 'well well-sm';
                    document.getElementById('scan-value').value = device.device_code;
                    document.getElementById('scan-value').focus();
                } catch (error) {
                    if (previewWindow && !previewWindow.closed) previewWindow.close();
                    button.disabled = false;
                    copy.textContent = 'Label request rejected. Retry after checking the print gate.';
                }
            }

            document.getElementById('add-unit').addEventListener('click', function () { if (unitList.querySelectorAll('.unit-row').length < config.maxUnits) addUnit('SERIAL', ''); });
            document.getElementById('prepare-receipt').addEventListener('click', async function () {
                try {
                    const data = await request(config.prepareUrl, 'POST', preparePayload());
                    state.prepared = data;
                    document.getElementById('preflight-title').textContent = 'PREPARED_NO_WRITE';
                    document.getElementById('preflight-copy').textContent = 'Safe hints returned. Raw physical identifiers are not echoed.';
                    document.getElementById('preflight-output').textContent = JSON.stringify({ status: data.status, unit_count: data.unit_count, identifiers: data.identifiers, post_url_available: Boolean(data.post_url) }, null, 2);
                    document.getElementById('post-receipt').disabled = !config.postUrl;
                    const impact = config.purchaseAttachment
                        ? 'Prepared. The selected POS purchase will keep its existing stock quantity; ' + data.unit_count + ' Device record' + (data.unit_count === 1 ? '' : 's') + ' will be attached after posting.'
                        : 'Prepared. Core quantity will increase by ' + data.unit_count + ' and ' + data.unit_count + ' Device record' + (data.unit_count === 1 ? '' : 's') + ' will be created only after posting.';
                    setMessage('post-result', config.postUrl ? impact : 'Prepared in read-only mode. No write URL is available.', 'info');
                } catch (error) {
                    state.prepared = null;
                    document.getElementById('post-receipt').disabled = true;
                    setMessage('post-result', 'Preflight rejected. Check the required fields and identifier format.', 'warning');
                }
            });

            document.getElementById('post-receipt').addEventListener('click', async function () {
                if (!state.prepared || !config.postUrl) return;
                const button = document.getElementById('post-receipt');
                button.disabled = true;
                try {
                    const data = await request(config.postUrl, 'POST', postPayload());
                    state.devices = data.result && data.result.devices ? data.result.devices : [];
                    const result = data.result || {};
                    const unitCount = Number(result.unit_count || state.devices.length || 0);
                    const postedMessage = config.purchaseAttachment
                        ? 'POS purchase ' + (result.transaction_id || 'linked') + ' serialised. Core stock was unchanged; ' + unitCount + ' Device record' + (unitCount === 1 ? '' : 's') + ' attached.'
                        : 'Receipt posted. Core quantity +' + unitCount + '; ' + unitCount + ' Device record' + (unitCount === 1 ? '' : 's') + ' created; transaction ' + (result.transaction_id || 'linked') + '.';
                    setMessage('post-result', postedMessage, 'success');
                    renderDevices(state.devices);
                } catch (error) {
                    button.disabled = false;
                    setMessage('post-result', 'Receipt was not posted. Retry with the same prepared command only after checking the result.', 'warning');
                }
            });

            document.getElementById('resolve-scan').addEventListener('click', async function () {
                const value = document.getElementById('scan-value').value.trim();
                if (shouldIgnoreDuplicateScan(value)) {
                    setMessage('scan-result', 'Duplicate scan ignored. No mutation performed.', 'info');
                    return;
                }

                try {
                    const data = await request(config.scanUrl, 'POST', { value: value });
                    setMessage('scan-result', data.device_code + ' · ' + data.lifecycle_state + ' · ' + data.custody_kind + ' · no mutation performed', 'success');
                } catch (error) {
                    setMessage('scan-result', 'Scan could not be resolved in the authorized cohort.', 'warning');
                }
            });

            document.getElementById('scan-value').addEventListener('input', function () {
                lastScan = { value: '', at: 0 };
            });

            document.getElementById('scan-value').addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    document.getElementById('resolve-scan').click();
                }
            });

            document.getElementById('run-reconcile').addEventListener('click', async function () {
                try {
                    const data = await request(config.reconcileUrl + '?location_id=' + encodeURIComponent(config.locationId), 'GET');
                    setMessage('reconcile-result', data.status + ' · core ' + (data.core_quantity === null ? 'unavailable' : data.core_quantity) + ' · tracked ' + data.tracked_device_count + ' · no correction performed', data.status === 'PASS' ? 'success' : 'warning');
                } catch (error) {
                    setMessage('reconcile-result', 'Reconciliation is unavailable for this scope.', 'warning');
                }
            });

            document.getElementById('record-reconcile').addEventListener('click', async function () {
                if (!config.recordReconcileUrl) return;
                const button = this;
                button.disabled = true;
                try {
                    const data = await request(config.recordReconcileUrl, 'POST', { location_id: config.locationId });
                    setMessage('reconcile-result', data.status + ' · evidence retained · run ' + data.run_uuid + ' · no stock correction performed', 'success');
                } catch (error) {
                    setMessage('reconcile-result', 'Reconciliation evidence could not be recorded in this scope.', 'warning');
                } finally {
                    button.disabled = false;
                }
            });

            ['receive-supplier', 'receive-date', 'receive-notes', 'receive-unit-cost', 'receive-unit-cost-tax', 'receive-unit-tax'].forEach(function (id) {
                const field = document.getElementById(id);
                if (!field) return;
                field.addEventListener('input', invalidatePrepared);
                field.addEventListener('change', invalidatePrepared);
            });

            addUnit('SERIAL', '');
        }());
    </script>
@endsection
