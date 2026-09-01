@extends('layouts.app')

@section('title', 'Find Device')

@section('content')
    <style>
        #recommerce-scan-entry .scan-camera { width:100%; max-width:520px; background:var(--sb-surface, #0f172a); border-radius:8px; display:none; }
        #recommerce-scan-entry .scan-camera.is-open { display:block; }
        @media (max-width:767px) { #recommerce-scan-entry { padding-left:10px; padding-right:10px; } #recommerce-scan-entry .btn { margin-bottom:6px; } }
    </style>
    <section class="container" id="recommerce-scan-entry" data-csrf-token="{{ csrf_token() }}">
        <div class="row">
            <div class="col-md-8">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Device Overview</a></div>
                        <h3 class="box-title">Find Device</h3>
                        <p class="text-muted" style="margin:6px 0 0">Scan a SAVERBRO QR or Code128 label to open its Device. Manufacturer serial, IMEI, and asset-tag lookup remain available for recovery.</p>
                    </div>
                    <div class="box-body">
                        <div class="alert alert-info" role="status">
                            This search is read-only. To receive new supplier stock, start from Purchases.
                        </div>

                        <div class="form-group">
                            <label for="recommerce-scan-value">SAVERBRO Device ID, QR URL, serial, IMEI, or asset tag</label>
                            <input id="recommerce-scan-value" class="form-control input-lg" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus>
                        </div>
                        <button type="button" class="btn btn-primary" id="recommerce-resolve-scan">Resolve scan</button>
                        <button type="button" class="btn btn-default" id="recommerce-open-camera">Scan Device</button>
                        <button type="button" class="btn btn-default" id="recommerce-clear-scan">Clear</button>
                        <div style="margin-top:14px"><video id="recommerce-scan-camera" class="scan-camera" autoplay playsinline muted aria-label="Device QR camera preview"></video></div>
                        <p id="recommerce-camera-message" class="text-muted" aria-live="polite" style="margin-top:8px"></p>

                        <div id="recommerce-scan-result" class="alert" style="display:none;margin-top:18px" role="status" aria-live="polite"></div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="box box-default">
                    <div class="box-header with-border"><h3 class="box-title">Continue workflow</h3></div>
                    <div class="box-body">
                        @if ($canReceive)
                            <a class="btn btn-primary btn-block" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}">Receive stock from Purchases</a>
                        @endif
                        @if ($canRepair)
                            <a class="btn btn-default btn-block" href="{{ route('recommerce.repair.index') }}">Repair intake</a>
                        @endif
                        <a class="btn btn-default btn-block" href="{{ route('recommerce.devices.index') }}">Device Registry</a>
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

    {{-- jsQR is the compatibility decoder for Safari and Chrome builds without BarcodeDetector. --}}
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <script>
        (function () {
            const root = document.getElementById('recommerce-scan-entry');
            const input = document.getElementById('recommerce-scan-value');
            const result = document.getElementById('recommerce-scan-result');
            const resolveButton = document.getElementById('recommerce-resolve-scan');
            const clearButton = document.getElementById('recommerce-clear-scan');
            const cameraButton = document.getElementById('recommerce-open-camera');
            const camera = document.getElementById('recommerce-scan-camera');
            const cameraMessage = document.getElementById('recommerce-camera-message');
            const scanUrl = @json(route('recommerce.scans.resolve'));
            let stream = null;
            let scanning = false;
            let fallbackFrame = null;
            let fallbackCanvas = null;
            let fallbackContext = null;

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
                const statusLabels = { RECEIVED_PENDING_INSPECTION: 'Waiting for inspection', INSPECTION_IN_PROGRESS: 'Inspection in progress', REFURBISHMENT_REQUIRED: 'Action required', AVAILABLE: 'Ready for sale', RESERVED: 'Reserved', SOLD: 'Sold' };
                const holderLabels = { CUSTOMER: 'Customer', IN_TRANSIT: 'In transit', EXTERNAL_PROVIDER: 'External provider' };
                const holder = device.custody_kind === 'LOCATION' ? (device.location_name || 'Branch not recorded') : (holderLabels[device.custody_kind] || device.custody_kind);
                details.textContent = (device.product ? device.product + ' · ' : '') + 'Status: ' + (statusLabels[device.lifecycle_state] || device.lifecycle_state) + ' · Current holder: ' + holder;
                result.appendChild(details);

                if (device.transfer) {
                    const transfer = document.createElement('div');
                    transfer.style.marginTop = '6px';
                    const transferState = device.transfer.state === 'IN_TRANSIT' ? 'In transit' : 'Received — awaiting transfer completion';
                    transfer.textContent = 'Transfer: ' + transferState + ' · ' + device.transfer.from_location + ' → ' + device.transfer.to_location + ' · ' + device.transfer.reference;
                    result.appendChild(transfer);
                }

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

            function stopCamera(message) {
                scanning = false;
                if (stream) stream.getTracks().forEach(track => track.stop());
                stream = null;
                if (fallbackFrame) cancelAnimationFrame(fallbackFrame);
                fallbackFrame = null;
                camera.srcObject = null;
                camera.classList.remove('is-open');
                cameraButton.textContent = 'Scan Device';
                if (message) cameraMessage.textContent = message;
            }

            async function detectFrame(detector) {
                if (!scanning || !stream) return;
                try {
                    const codes = await detector.detect(camera);
                    if (codes.length && codes[0].rawValue) {
                        input.value = codes[0].rawValue;
                        stopCamera('Device scan captured. Resolving…');
                        resolveButton.click();
                        return;
                    }
                } catch (_) { /* a frame can be unavailable while starting */ }
                if (scanning) requestAnimationFrame(() => detectFrame(detector));
            }

            function detectFrameWithFallback() {
                if (!scanning || !stream) return;

                if (camera.readyState >= 2 && camera.videoWidth && camera.videoHeight) {
                    const maxWidth = 960;
                    const scale = Math.min(1, maxWidth / camera.videoWidth);
                    const width = Math.max(1, Math.round(camera.videoWidth * scale));
                    const height = Math.max(1, Math.round(camera.videoHeight * scale));
                    if (fallbackCanvas.width !== width || fallbackCanvas.height !== height) {
                        fallbackCanvas.width = width;
                        fallbackCanvas.height = height;
                    }
                    fallbackContext.drawImage(camera, 0, 0, width, height);
                    const decoded = window.jsQR(
                        fallbackContext.getImageData(0, 0, width, height).data,
                        width,
                        height,
                        { inversionAttempts: 'attemptBoth' }
                    );
                    if (decoded && decoded.data) {
                        input.value = decoded.data;
                        stopCamera('Device scan captured. Resolving…');
                        resolveButton.click();
                        return;
                    }
                }

                if (scanning) fallbackFrame = requestAnimationFrame(detectFrameWithFallback);
            }

            cameraButton.addEventListener('click', async function () {
                if (scanning) { stopCamera('Camera scan cancelled.'); return; }
                if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    cameraMessage.textContent = 'Camera scanning requires HTTPS (or localhost) and browser camera permission. Use the lookup field or a barcode scanner instead.';
                    return;
                }
                const nativeQrAvailable = 'BarcodeDetector' in window;
                const fallbackQrAvailable = typeof window.jsQR === 'function';
                if (!nativeQrAvailable && !fallbackQrAvailable) {
                    cameraMessage.textContent = 'QR camera decoding is unavailable in this browser. Use the lookup field or a barcode scanner instead.';
                    return;
                }
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                    camera.srcObject = stream;
                    await camera.play();
                    scanning = true;
                    camera.classList.add('is-open');
                    cameraButton.textContent = 'Cancel Camera';
                    cameraMessage.textContent = 'Point the rear camera at a SAVERBRO QR label.';
                    if (nativeQrAvailable) {
                        const detector = new BarcodeDetector({ formats: ['qr_code'] });
                        detectFrame(detector);
                    } else {
                        fallbackCanvas = document.createElement('canvas');
                        fallbackContext = fallbackCanvas.getContext('2d', { willReadFrequently: true });
                        if (!fallbackContext) throw new Error('QR camera decoder could not prepare a video frame.');
                        detectFrameWithFallback();
                    }
                } catch (_) {
                    stopCamera('Camera access was unavailable or denied. Check permission and HTTPS, then try again.');
                }
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
