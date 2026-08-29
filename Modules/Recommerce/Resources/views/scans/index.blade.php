@extends('layouts.app')

@section('title', 'Recommerce scan and entry')

@section('content')
    <section class="container" id="recommerce-scan-entry" data-csrf-token="{{ csrf_token() }}">
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Operations overview</a></div>
                        <h3 class="box-title">Scan &amp; Entry</h3>
                        <p class="text-muted" style="margin:6px 0 0">Resolve a Device code or approved QR URL in the current business and authorized location scope.</p>
                    </div>
                    <div class="box-body">
                        <div class="alert alert-info" role="status">
                            Scan resolution is read-only. It does not receive stock, change a repair, or expose raw identifiers.
                        </div>

                        <div class="form-group">
                            <label for="recommerce-scan-value">Device code or approved QR URL</label>
                            <input id="recommerce-scan-value" class="form-control input-lg" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus>
                        </div>
                        <button type="button" class="btn btn-primary" id="recommerce-resolve-scan">Resolve scan</button>
                        <button type="button" class="btn btn-default" id="recommerce-clear-scan">Clear</button>

                        <div id="recommerce-scan-result" class="alert" style="display:none;margin-top:18px" role="status" aria-live="polite"></div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="box box-default">
                    <div class="box-header with-border"><h3 class="box-title">Continue workflow</h3></div>
                    <div class="box-body">
                        @if ($canReceive)
                            <a class="btn btn-default btn-block" href="{{ route('recommerce.receiving.index') }}">Tracked receiving</a>
                        @endif
                        @if ($canRepair)
                            <a class="btn btn-default btn-block" href="{{ route('recommerce.repair.index') }}">Repair intake</a>
                        @endif
                        <a class="btn btn-default btn-block" href="{{ route('recommerce.devices.index') }}">Device registry</a>
                        <a class="btn btn-default btn-block" href="{{ action([\App\Http\Controllers\SellPosController::class, 'create']) }}">Return to POS sale</a>
                    </div>
                </div>
                <div class="box box-default">
                    <div class="box-header with-border"><h3 class="box-title">Access boundary</h3></div>
                    <div class="box-body">
                        <p class="text-muted" style="margin-bottom:0">Results are limited to this business, the user’s location access, the active Recommerce cohort, and the assigned permission.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        (function () {
            const root = document.getElementById('recommerce-scan-entry');
            const input = document.getElementById('recommerce-scan-value');
            const result = document.getElementById('recommerce-scan-result');
            const resolveButton = document.getElementById('recommerce-resolve-scan');
            const clearButton = document.getElementById('recommerce-clear-scan');
            const scanUrl = @json(route('recommerce.scans.resolve'));

            function showResult(message, kind) {
                result.textContent = message;
                result.className = 'alert alert-' + kind;
                result.style.display = 'block';
            }

            function renderDevice(device) {
                result.innerHTML = '';
                result.className = 'alert alert-success';
                result.style.display = 'block';

                const title = document.createElement('strong');
                title.textContent = 'Device resolved: ' + device.device_code;
                result.appendChild(title);

                const details = document.createElement('div');
                details.style.marginTop = '8px';
                details.textContent = 'Lifecycle: ' + device.lifecycle_state + ' · Custody: ' + device.custody_kind;
                result.appendChild(details);

                const open = document.createElement('a');
                open.className = 'btn btn-success btn-sm';
                open.style.marginTop = '12px';
                open.href = @json(url('/recommerce/devices')) + '/' + encodeURIComponent(device.device_code);
                open.textContent = 'Open device record';
                result.appendChild(document.createElement('br'));
                result.appendChild(open);
            }

            resolveButton.addEventListener('click', async function () {
                const value = input.value.trim();
                if (!value) {
                    showResult('Enter a Device code or approved QR URL first.', 'warning');
                    input.focus();
                    return;
                }

                resolveButton.disabled = true;
                showResult('Resolving scan…', 'info');

                try {
                    const response = await fetch(scanUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': root.dataset.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({ value: value })
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        throw new Error(payload.message || 'Scan could not be resolved in the authorized cohort.');
                    }
                    renderDevice(payload);
                } catch (error) {
                    showResult(error.message || 'Scan could not be resolved in the authorized cohort.', 'warning');
                } finally {
                    resolveButton.disabled = false;
                }
            });

            clearButton.addEventListener('click', function () {
                input.value = '';
                result.style.display = 'none';
                result.textContent = '';
                input.focus();
            });

            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    resolveButton.click();
                }
            });
        })();
    </script>
@endsection
