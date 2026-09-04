{{--
    The cart owns the submitted `recommerce_device_codes` fields. This workbench is
    intentionally only the fast operator surface for those exact same fields; it
    does not create a second Device-selection contract.
--}}
<section id="pos_device_workbench" class="pos-device-workbench" hidden aria-labelledby="pos_device_workbench_title">
    <div class="pos-device-workbench__heading">
        <span class="pos-device-workbench__icon" aria-hidden="true"><i class="fas fa-barcode"></i></span>
        <div>
            <span class="pos-device-workbench__eyebrow">Serialized device</span>
            <h2 id="pos_device_workbench_title">Identify Device</h2>
            <p id="pos_device_workbench_product">Scan the exact physical unit for this sale line.</p>
        </div>
    </div>

    <div class="pos-device-workbench__progress" id="pos_device_workbench_progress" role="status">
        <strong>0 / 1 identified</strong>
        <span>Device required before payment</span>
    </div>

    <div class="pos-device-workbench__scan">
        <label for="pos_device_scan_input">Scan device</label>
        <div class="input-group">
            <span class="input-group-addon" aria-hidden="true"><i class="fas fa-qrcode"></i></span>
            <input type="text" id="pos_device_scan_input" class="form-control" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Scan QR, serial, IMEI or Device ID">
            <span class="input-group-btn">
                <button type="button" id="pos_device_scan_submit" class="btn btn-primary">Identify</button>
            </span>
        </div>
        <p id="pos_device_workbench_feedback" class="pos-device-workbench__feedback" aria-live="polite">Scanner ready. One physical Device is required for each unit.</p>
    </div>

    <div id="pos_device_workbench_devices" class="pos-device-workbench__devices" aria-live="polite"></div>

    <button type="button" id="pos_device_workbench_close" class="pos-device-workbench__close" title="Return to product search">
        <i class="fas fa-times" aria-hidden="true"></i><span>Done for now</span>
    </button>
</section>
