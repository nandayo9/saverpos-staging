<div class="sb-ti-panel">
    <div class="sb-ti-panel-head"><div><h2>Walk-In device</h2><p>One estimate, computed on the spot. Creates a real Quick Quote - subject to full inspection before acceptance.</p></div></div>
    <div class="sb-ti-panel-body">
        <form method="post" action="{{ route('recommerce.tradeins.walk_in.store') }}" id="walk-in-form">
            @php($wiOld = session()->hasOldInput())
            @csrf<input type="hidden" name="command_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

            <div class="row">
                <div class="col-sm-4 form-group">
                    <label for="wi-category">Device</label>
                    <select class="form-control" id="wi-category" name="category_code" data-walk-in-category required>
                        @foreach(['PHONE' => 'Phone', 'TABLET' => 'Tablet', 'LAPTOP' => 'Laptop'] as $value => $label)
                        <option value="{{ $value }}" @selected($value === old('category_code', 'PHONE'))>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="row" style="margin-top:14px">
                <div class="col-sm-6 form-group">
                    <label for="wi-brand">Brand</label>
                    <input class="form-control" list="wi-brand-list" id="wi-brand" name="brand" value="{{ old('brand') }}" required maxlength="100">
                    <datalist id="wi-brand-list" data-walk-in-brand-list></datalist>
                </div>
                <div class="col-sm-6 form-group">
                    <label for="wi-model">Model</label>
                    <input class="form-control" id="wi-model" name="model" value="{{ old('model') }}" required maxlength="160">
                </div>
            </div>

            <div class="row" data-walk-in-section="PHONE,TABLET">
                <div class="col-sm-4 form-group">
                    <label for="wi-brand-family">Brand family</label>
                    <select class="form-control" id="wi-brand-family" name="brand_family">
                        <option value="APPLE" @selected(old('brand_family') === 'APPLE')>Apple</option>
                        <option value="ANDROID" @selected(old('brand_family') === 'ANDROID')>Android (non-foldable)</option>
                        <option value="ANDROID_FOLDABLE" @selected(old('brand_family') === 'ANDROID_FOLDABLE') data-walk-in-category-only="PHONE">Android (foldable)</option>
                    </select>
                </div>
                <div class="col-sm-4 form-group">
                    <label for="wi-identifier-mobile">IMEI</label>
                    <input class="form-control" id="wi-identifier-mobile" name="identifier" value="{{ old('identifier') }}" maxlength="80" required>
                    <p class="help-block">Needed to verify the estimate before acceptance.</p>
                </div>
            </div>
            <div class="row" data-walk-in-section="LAPTOP" hidden>
                <div class="col-sm-4 form-group">
                    <label for="wi-identifier-laptop">Serial number</label>
                    <input class="form-control" id="wi-identifier-laptop" name="identifier" value="{{ old('identifier') }}" maxlength="80">
                </div>
            </div>

            <div class="row">
                <div class="col-sm-4 form-group">
                    <label for="wi-ram">RAM</label>
                    <select class="form-control" id="wi-ram" name="ram" required>
                        @foreach($walkInOptions['ram'] ?? [] as $value)<option value="{{ $value }}" @selected(old('ram') === $value)>{{ $value }}</option>@endforeach
                    </select>
                </div>
                <div class="col-sm-4 form-group">
                    <label for="wi-storage-type">Storage</label>
                    <select class="form-control" id="wi-storage-type" name="storage_type" required>
                        @foreach($walkInOptions['storage_type'] ?? [] as $value)<option value="{{ $value }}" @selected(old('storage_type') === $value)>{{ $value }}</option>@endforeach
                    </select>
                </div>
                <div class="col-sm-4 form-group">
                    <label for="wi-storage-size">Storage size</label>
                    <select class="form-control" id="wi-storage-size" name="storage_size" required>
                        @foreach($walkInOptions['storage_size'] ?? [] as $value)<option value="{{ $value }}" @selected(old('storage_size') === $value)>{{ $value }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label for="wi-cpu">CPU</label>
                    <input class="form-control" id="wi-cpu" name="cpu" value="{{ old('cpu') }}" maxlength="160">
                </div>
                <div class="col-sm-6 form-group" data-walk-in-section="LAPTOP" hidden>
                    <label for="wi-gpu">GPU (if available)</label>
                    <input class="form-control" id="wi-gpu" name="gpu" value="{{ old('gpu') }}" maxlength="160">
                </div>
            </div>

            <h3 style="margin-top:18px">Condition</h3>

            <div data-walk-in-section="PHONE,TABLET">
                <div class="row">
                    <div class="col-sm-4 form-group">
                        <label for="wi-screen">Screen condition</label>
                        <select class="form-control" id="wi-screen" name="condition[screen_condition]">
                            <option value="FLAWLESS" @selected(old('condition.screen_condition') === 'FLAWLESS')>Flawless</option>
                            <option value="MINOR" @selected(old('condition.screen_condition') === 'MINOR')>Minor</option>
                            <option value="MODERATE" @selected(old('condition.screen_condition') === 'MODERATE')>Moderate</option>
                            <option value="SEVERE" @selected(old('condition.screen_condition') === 'SEVERE')>Severe</option>
                        </select>
                    </div>
                    <div class="col-sm-4 form-group">
                        <label for="wi-body">Body condition</label>
                        <select class="form-control" id="wi-body" name="condition[body_condition]">
                            <option value="FLAWLESS" @selected(old('condition.body_condition') === 'FLAWLESS')>Flawless</option>
                            <option value="MINOR" @selected(old('condition.body_condition') === 'MINOR')>Minor</option>
                            <option value="MODERATE" @selected(old('condition.body_condition') === 'MODERATE')>Moderate</option>
                            <option value="SEVERE" @selected(old('condition.body_condition') === 'SEVERE')>Severe</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[biometrics_ok]" value="1" @checked($wiOld ? old('condition.biometrics_ok') : true)> Fingerprint / Face ID OK</label></div>
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[core_functions_ok]" value="1" @checked($wiOld ? old('condition.core_functions_ok') : true)> Speaker / mic / buttons / Wi-Fi / Bluetooth OK</label></div>
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[camera_ok]" value="1" @checked($wiOld ? old('condition.camera_ok') : true)> Camera OK</label></div>
                </div>
                <div class="row">
                    <div class="col-sm-4 form-group">
                        <label><input type="checkbox" id="wi-extra-issue" name="condition[extra_issue]" value="1" @checked($wiOld ? old('condition.extra_issue') : false)> Extra issue</label>
                        <select class="form-control" id="wi-extra-severity" name="condition[extra_issue_severity]" disabled style="margin-top:8px;display:none">
                            <option value="MINOR" @selected(old('condition.extra_issue_severity') === 'MINOR')>Minor</option>
                            <option value="MODERATE" @selected(old('condition.extra_issue_severity') === 'MODERATE')>Moderate</option>
                            <option value="SEVERE" @selected(old('condition.extra_issue_severity') === 'SEVERE')>Severe</option>
                        </select>
                    </div>
                </div>
            </div>

            <div data-walk-in-section="LAPTOP" hidden>
                <div class="row">
                    <div class="col-sm-4 form-group">
                        <label for="wi-lcd">LCD condition</label>
                        <select class="form-control" id="wi-lcd" name="condition[lcd_condition]">
                            <option value="FLAWLESS" @selected(old('condition.lcd_condition') === 'FLAWLESS')>Flawless</option>
                            <option value="MINOR_SCRATCHES" @selected(old('condition.lcd_condition') === 'MINOR_SCRATCHES')>2-3 minor scratches</option>
                            <option value="HEAVY_SCRATCH" @selected(old('condition.lcd_condition') === 'HEAVY_SCRATCH')>Heavy scratch</option>
                            <option value="CRACKED" @selected(old('condition.lcd_condition') === 'CRACKED')>Cracked</option>
                            <option value="NOT_WORKING" @selected(old('condition.lcd_condition') === 'NOT_WORKING')>Not working (reject)</option>
                        </select>
                    </div>
                    <div class="col-sm-4 form-group">
                        <label for="wi-laptop-body">Body condition</label>
                        <select class="form-control" id="wi-laptop-body" name="condition[body_condition]">
                            <option value="FLAWLESS" @selected(old('condition.body_condition') === 'FLAWLESS')>Flawless</option>
                            <option value="SCRATCHES" @selected(old('condition.body_condition') === 'SCRATCHES')>Scratches</option>
                            <option value="DENT_BENT_CORNER" @selected(old('condition.body_condition') === 'DENT_BENT_CORNER')>Dent / bent corner</option>
                            <option value="CRACKED" @selected(old('condition.body_condition') === 'CRACKED')>Cracked</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[input_devices_ok]" value="1" @checked($wiOld ? old('condition.input_devices_ok') : true)> Keyboard / buttons / Touch Bar / trackpad OK</label></div>
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[camera_ok]" value="1" @checked($wiOld ? old('condition.camera_ok') : true)> Camera OK</label></div>
                </div>
                <div class="row">
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[battery_bloated_or_pop_out]" value="1" @checked($wiOld ? old('condition.battery_bloated_or_pop_out') : false)> Bloated battery / pop-out (reject)</label></div>
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[jailbroken_or_rooted]" value="1" @checked($wiOld ? old('condition.jailbroken_or_rooted') : false)> Jailbroken / rooted (reject)</label></div>
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[liquid_damage]" value="1" @checked($wiOld ? old('condition.liquid_damage') : false)> Liquid damage</label></div>
                    <div class="col-sm-3 form-group"><label><input type="checkbox" name="condition[power_on_fault]" value="1" @checked($wiOld ? old('condition.power_on_fault') : false)> Cannot power on</label></div>
                </div>
            </div>

            <h3 style="margin-top:18px">Warranty &amp; price</h3>
            <div class="row">
                <div class="col-sm-4 form-group">
                    <label for="wi-warranty">Warranty status</label>
                    <select class="form-control" id="wi-warranty" name="warranty_status">
                        <option value="BRANDED" @selected(old('warranty_status') === 'BRANDED')>Branded warranty remaining</option>
                        <option value="OTHER_STORE" @selected(old('warranty_status') === 'OTHER_STORE')>Other-store warranty</option>
                        <option value="EXPIRED" @selected(old('warranty_status') === 'EXPIRED')>Expired / none</option>
                    </select>
                </div>
                <div class="col-sm-4 form-group">
                    <label for="wi-appearance-severity">Appearance</label>
                    <select class="form-control" id="wi-appearance-severity" name="appearance_severity">
                        <option value="MINOR" @selected(old('appearance_severity') === 'MINOR')>Minimal wear</option>
                        <option value="MODERATE" @selected(old('appearance_severity') === 'MODERATE')>Moderate wear</option>
                        <option value="SEVERE" @selected(old('appearance_severity') === 'SEVERE')>Heavy wear</option>
                    </select>
                </div>
                @php($wiPriceError = data_get(session('status'), 'field') === 'market_price' ? data_get(session('status'), 'msg') : null)
                <div class="col-sm-4 form-group{{ $wiPriceError ? ' has-warning' : '' }}">
                    <label for="wi-market-price">Market price (RM)</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="wi-market-price" name="market_price" value="{{ old('market_price') }}" data-lookup-url="{{ route('recommerce.tradeins.walk_in.market_price') }}" @if($wiPriceError) autofocus @endif>
                    <p class="help-block" id="wi-market-price-help" aria-live="polite">{{ $wiPriceError }}</p>
                </div>
            </div>

            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-dw-btn-lg tw-text-white">Get estimated price</button>
        </form>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('walk-in-form');
    if (!form) return;
    var brandOptions = @json($walkInOptions['brands'] ?? []);

    function apply() {
        var category = form.querySelector('[data-walk-in-category]').value || 'PHONE';
        document.querySelectorAll('[data-walk-in-section]').forEach(function (section) {
            var shown = section.getAttribute('data-walk-in-section').split(',').indexOf(category) !== -1;
            section.hidden = !shown;
            section.querySelectorAll('input, select').forEach(function (field) { field.disabled = !shown; });
        });
        document.querySelectorAll('[data-walk-in-category-only]').forEach(function (option) {
            option.hidden = option.getAttribute('data-walk-in-category-only') !== category;
        });
        var list = document.querySelector('[data-walk-in-brand-list]');
        if (list) {
            list.innerHTML = '';
            (brandOptions[category] || []).forEach(function (brand) {
                var option = document.createElement('option');
                option.value = brand;
                list.appendChild(option);
            });
        }
        applyExtraIssue();
    }

    function applyExtraIssue() {
        var checkbox = document.getElementById('wi-extra-issue');
        var severity = document.getElementById('wi-extra-severity');
        if (!checkbox || !severity) return;
        var shown = checkbox.checked && !checkbox.disabled;
        severity.style.display = shown ? '' : 'none';
        severity.disabled = !shown;
    }

    form.querySelectorAll('[data-walk-in-category]').forEach(function (input) {
        input.addEventListener('change', apply);
    });
    var extraIssueCheckbox = document.getElementById('wi-extra-issue');
    if (extraIssueCheckbox) extraIssueCheckbox.addEventListener('change', applyExtraIssue);
    apply();

    // Market price auto-fill: once brand and model are entered, look up the
    // brand-new price and fill the field, unless staff typed their own.
    var priceInput = document.getElementById('wi-market-price');
    var priceHelp = document.getElementById('wi-market-price-help');
    var priceTyped = priceInput.value !== '';
    var lastLookupKey = null;
    var lookupTimer = null;
    var lookupRequest = null;

    priceInput.addEventListener('input', function () { priceTyped = priceInput.value !== ''; });

    function lookUpPrice() {
        var category = form.querySelector('[data-walk-in-category]').value;
        var brand = document.getElementById('wi-brand').value.trim();
        var model = document.getElementById('wi-model').value.trim();
        var key = [category, brand, model].join('|').toLowerCase();
        if (!brand || !model || key === lastLookupKey) return;
        lastLookupKey = key;

        if (lookupRequest) lookupRequest.abort();
        lookupRequest = new AbortController();
        priceHelp.textContent = 'Looking up the brand-new price for ' + brand + ' ' + model + ' on Lazada and Shopee...';
        var params = new URLSearchParams({category_code: category, brand: brand, model: model});
        fetch(priceInput.getAttribute('data-lookup-url') + '?' + params, {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            signal: lookupRequest.signal
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }).then(function (data) {
            if (!data.found) {
                priceHelp.textContent = data.message;
                return;
            }
            if (!priceTyped) priceInput.value = Number(data.amount).toFixed(2);
            priceHelp.textContent = '';
        }).catch(function (error) {
            if (error.name === 'AbortError') return;
            lastLookupKey = null;
            priceHelp.textContent = 'Price lookup failed. Enter the market price manually.';
        });
    }

    function scheduleLookup() {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(lookUpPrice, 1000);
    }
    ['wi-brand', 'wi-model'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', scheduleLookup);
        document.getElementById(id).addEventListener('change', lookUpPrice);
    });
    form.querySelector('[data-walk-in-category]').addEventListener('change', scheduleLookup);
})();
</script>
