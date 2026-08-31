@extends('layouts.app')

@section('title', 'Trade-in acquisition')

@section('content')
@php
    $money = static fn ($value) => number_format((float) $value, 2);
    $statuses = ['READY_TO_ACCEPT' => 'success', 'PENDING_APPROVAL' => 'warning', 'APPROVED' => 'info', 'ACCEPTED' => 'primary', 'REJECTED' => 'default', 'REVERSED' => 'danger'];
    $cosmeticChecks = ['screen_condition' => 'Screen surface', 'body_condition' => 'Top cover / body', 'palm_rest_condition' => 'Palm rest', 'keyboard_condition' => 'Keyboard surface', 'hinges_condition' => 'Hinges'];
    $checks = ['display' => 'Display output', 'keyboard' => 'Keyboard input', 'trackpad' => 'Trackpad', 'wifi' => 'Wi-Fi', 'bluetooth' => 'Bluetooth', 'webcam' => 'Webcam', 'microphone' => 'Microphone', 'speakers' => 'Speakers', 'usb_ports' => 'USB ports', 'hdmi_output' => 'HDMI output', 'charging' => 'Charging', 'power_on' => 'Power on', 'storage_health' => 'Storage health'];
    $photoPurposes = ['FRONT_OPEN' => 'Front / open device', 'KEYBOARD_PALMREST' => 'Keyboard / palm rest', 'POWERED_SCREEN' => 'Powered-on screen', 'BOTTOM_REAR' => 'Bottom / rear', 'SERIAL_LABEL' => 'Serial / device label', 'DEFECT_DAMAGE' => 'Defect / damage'];
@endphp
<section class="container" id="recommerce-trade-ins">
    <div class="row">
        @foreach ([['Today’s valuations', $metrics['today'], 'info'], ['Accepted', $metrics['accepted'], 'success'], ['Pending approval', $metrics['pending'], 'warning'], ['Rejected', $metrics['rejected'], 'default'], ['Conversion rate', $metrics['conversion'] === null ? '—' : $metrics['conversion'].'%', 'info'], ['Acquisition spend', 'RM '.$money($metrics['spend']), 'primary']] as [$label, $value, $tone])
            <div class="col-xs-6 col-md"><div class="small-box bg-{{ $tone }}"><div class="inner"><h3>{{ $value }}</h3><p>{{ $label }}</p></div></div></div>
        @endforeach
    </div>

    @if (session('status'))<div class="alert alert-{{ data_get(session('status'), 'success') ? 'success' : 'warning' }}" role="status">{{ data_get(session('status'), 'msg') }}</div>@endif
    @if ($variations->isEmpty())<div class="alert alert-warning">No approved catalogue match exists in this branch cohort. Trade-in cannot invent a product or bypass the cohort.</div>@endif

    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Operations overview</a></div>
            <h3 class="box-title">New device acquisition</h3>
            <p class="text-muted" style="margin:6px 0 0">Seller → Device Passport → inspection → evidence → offer → approval → native acquisition → QC.</p>
        </div>
        <form method="post" action="{{ route('recommerce.tradeins.store') }}" enctype="multipart/form-data" autocomplete="off" id="tradein-wizard" novalidate>
            @csrf
            <input type="hidden" name="command_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="box-body">
                <ol class="nav nav-pills nav-justified" aria-label="Acquisition steps" style="margin-bottom:18px"><li class="active"><a href="#tradein-step-1">1 Type & seller</a></li><li><a href="#tradein-step-2">2 Device</a></li><li><a href="#tradein-step-3">3 Inspection</a></li><li><a href="#tradein-step-4">4 Evidence & offer</a></li><li><a href="#tradein-step-5">5 Declaration</a></li></ol>

                <fieldset id="tradein-step-1" class="tradein-step"><legend>1. Acquisition type and seller</legend>
                    <div class="row"><div class="col-md-4"><div class="form-group"><label>Acquisition type</label><select class="form-control" name="acquisition_type" aria-label="Acquisition type"><option value="SELL_TO_SAVERBRO">Sell to SaverBro</option><option value="TRADE_IN">Trade-in against a future sale</option><option value="BUSINESS_OR_BULK_ACQUISITION">Business / bulk (single-device V2)</option></select></div></div><div class="col-md-4"><div class="form-group"><label for="tradein-customer">Existing seller / customer</label><select id="tradein-customer" class="form-control" name="customer_contact_id"><option value="">Create a new seller below</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)old('customer_contact_id') === (string)$customer->id)>{{ $customer->name }}{{ $customer->mobile ? ' · '.$customer->mobile : '' }}</option>@endforeach</select></div></div><div class="col-md-4"><div class="form-group"><label for="seller-phone">Seller phone</label><input id="seller-phone" class="form-control" name="seller_phone" maxlength="80" value="{{ old('seller_phone') }}" placeholder="Required for a new seller/payee"></div></div></div>
                    <div class="row"><div class="col-md-6"><div class="form-group"><label for="seller-name">New seller name</label><input id="seller-name" class="form-control" name="seller_name" maxlength="255" value="{{ old('seller_name') }}" placeholder="Only if not selecting an existing customer"></div></div><div class="col-md-6"><div class="form-group"><label for="seller-id-ref">Identification reference</label><input id="seller-id-ref" class="form-control" name="seller_identity_reference" maxlength="255" value="{{ old('seller_identity_reference') }}" autocomplete="off"><p class="help-block">Stored encrypted; never displayed in the acquisition list.</p></div></div></div>
                </fieldset>

                <fieldset id="tradein-step-2" class="tradein-step"><legend>2. Device Passport and catalogue match</legend>
                    <div class="row"><div class="col-md-4"><div class="form-group"><label for="tradein-device">Existing Device Passport</label><select id="tradein-device" class="form-control" name="device_id"><option value="">Create or match by serial/IMEI below</option>@foreach($devices as $device)<option value="{{ $device->id }}">{{ $device->device_code }} · {{ $device->brand }} {{ $device->model }}</option>@endforeach</select></div></div><div class="col-md-4"><div class="form-group"><label for="identifier-type">Match identifier</label><select id="identifier-type" class="form-control" name="identifier_type"><option value="SERIAL">Serial number</option><option value="IMEI">IMEI</option><option value="DEVICE_CODE">SaverBro Device ID</option></select></div></div><div class="col-md-4"><div class="form-group"><label for="identifier-value">Serial / IMEI / Device ID</label><input id="identifier-value" class="form-control" name="identifier_value" maxlength="160" value="{{ old('identifier_value') }}" placeholder="Required for a new Device Passport"></div></div></div>
                    <div class="row"><div class="col-md-4"><div class="form-group"><label for="laptop-brand">Brand</label><input id="laptop-brand" class="form-control" name="laptop_brand" maxlength="100" value="{{ old('laptop_brand') }}" placeholder="HP, Lenovo, Dell…"></div></div><div class="col-md-4"><div class="form-group"><label for="laptop-model">Model</label><input id="laptop-model" class="form-control" name="laptop_model" maxlength="160" value="{{ old('laptop_model') }}" placeholder="ProBook 440 G10"></div></div><div class="col-md-4"><div class="form-group"><label for="tradein-variation">Confirmed catalogue product / variation</label><select id="tradein-variation" class="form-control" name="variation_id" required><option value="">Choose existing match</option>@foreach($variations as $variation)<option value="{{ $variation->id }}">{{ $variation->product->name }} · {{ $variation->name ?: 'Default' }}</option>@endforeach</select><div id="catalogue-suggestions" class="help-block" aria-live="polite">Enter brand/model/specification to suggest an existing catalogue match. Staff must confirm it; no catalogue record is created automatically.</div></div></div>
                    <input type="hidden" name="category_code" value="LAPTOP">
                </fieldset>

                <fieldset id="tradein-step-3" class="tradein-step"><legend>3. Laptop inspection</legend>
                    <div class="well well-sm"><strong>Device specification</strong><p class="help-block" style="margin:4px 0 12px">Record only what helps identify and price this laptop.</p><div class="row">@foreach(['cpu'=>'CPU','ram'=>'RAM','storage'=>'Storage','gpu'=>'GPU','display_size'=>'Screen size','operating_system'=>'Operating system'] as $key=>$label)<div class="col-xs-6 col-md-4"><div class="form-group"><label>{{ $label }}</label><input class="form-control" name="laptop_{{ $key }}" value="{{ old('laptop_'.$key) }}" maxlength="160" aria-label="{{ $label }}"></div></div>@endforeach</div></div>
                    <div class="well well-sm" data-tradein-group="cosmetic"><div class="clearfix"><div class="pull-left"><strong>Exterior condition</strong><p class="help-block" style="margin:4px 0 12px">Set the overall grade, then record exceptions only.</p></div><div class="pull-right" style="margin-bottom:8px"><button class="btn btn-default btn-sm" type="button" data-tradein-set="GOOD" data-tradein-group-target="cosmetic">Mark exterior good</button></div></div><div class="row"><div class="col-xs-6 col-md-3"><div class="form-group"><label>Overall cosmetic grade</label><select class="form-control" name="cosmetic_grade" aria-label="Overall cosmetic grade" required><option value="">Choose grade</option>@foreach(['A','B','C','D'] as $grade)<option value="{{ $grade }}">{{ $grade }}</option>@endforeach</select></div></div>@foreach($cosmeticChecks as $name=>$label)<div class="col-xs-6 col-md-3"><div class="form-group"><label>{{ $label }}</label><select class="form-control" name="{{ $name }}" aria-label="{{ $label }} condition"><option value="">Not recorded</option><option value="GOOD">Good</option><option value="FAIR">Fair</option><option value="POOR">Poor</option><option value="DAMAGED">Damaged</option></select></div></div>@endforeach</div></div>
                    <div class="well well-sm"><strong>Battery</strong><p class="help-block" style="margin:4px 0 12px">Leave health or cycle fields empty if they cannot be verified.</p><div class="row"><div class="col-md-3"><div class="form-group"><label>Health %</label><input class="form-control" name="battery_health_percent" type="number" min="0" max="100" step="0.1" aria-label="Battery health percent"></div></div><div class="col-md-3"><div class="form-group"><label>Cycle count</label><input class="form-control" name="battery_cycle_count" type="number" min="0" aria-label="Battery cycle count"></div></div><div class="col-md-3"><div class="form-group"><label>Replacement needed</label><select class="form-control" name="battery_replacement_needed" aria-label="Battery replacement needed"><option value="NO">No</option><option value="YES">Yes</option><option value="CONDITIONAL">Conditional</option></select></div></div><div class="col-md-3"><div class="form-group"><label>Replacement estimate (MYR)</label><input class="form-control" name="battery_replacement_estimate_amount" type="number" min="0" step="0.01" value="0" aria-label="Battery replacement estimate"></div></div></div></div>
                    <div class="well well-sm" data-tradein-group="functional"><div class="clearfix"><div class="pull-left"><strong>Functional checks</strong><p class="help-block" style="margin:4px 0 12px">Start with the quick action, then change only failed or conditional tests.</p></div><div class="pull-right" style="margin-bottom:8px"><button class="btn btn-success btn-sm" type="button" data-tradein-set="PASS" data-tradein-group-target="functional">Mark tested items pass</button> <button class="btn btn-default btn-sm" type="button" data-tradein-set="NOT_TESTED" data-tradein-group-target="functional">Clear tests</button></div></div><div class="row">@foreach($checks as $key=>$label)<div class="col-xs-6 col-md-3"><div class="form-group"><label>{{ $label }}</label><select class="form-control" name="{{ $key }}_outcome" aria-label="{{ $label }} outcome"><option value="NOT_TESTED">Not tested</option><option value="PASS">Pass</option><option value="FAIL">Fail</option><option value="CONDITIONAL">Conditional</option></select></div></div>@endforeach</div></div>
                    <div class="row"><div class="col-md-6"><div class="well well-sm"><strong>Risk / lock status</strong><p class="help-block" style="margin:4px 0 8px">Select only a known concern.</p>@foreach(['BIOS_PASSWORD'=>'BIOS password','MDM_MANAGED'=>'MDM/company management','ACCOUNT_LOCK'=>'Account lock','COMPANY_ASSET_TAG'=>'Company asset tag','SUSPICIOUS_SERIAL'=>'Suspicious serial','OWNERSHIP_CONCERN'=>'Ownership concern'] as $value=>$label)<label class="checkbox-inline"><input type="checkbox" name="risk_flags[]" value="{{ $value }}"> {{ $label }}</label>@endforeach</div></div><div class="col-md-6"><div class="well well-sm"><strong>Accessories</strong><p class="help-block" style="margin:4px 0 8px">Record accessories included with this device.</p><label class="checkbox-inline"><input type="checkbox" name="charger_included" value="1"> Charger included</label><label class="checkbox-inline"><input type="checkbox" name="box_included" value="1"> Box</label><input class="form-control" style="margin-top:8px" name="charger_type" placeholder="Charger type / originality" aria-label="Charger type or originality"></div></div></div>
                    <details class="well well-sm" style="margin-bottom:0"><summary><strong>Photo evidence</strong> <span class="text-muted">(optional)</span></summary><p class="help-block" style="margin-top:8px">Add only the photos needed to support condition, serial, or damage evidence. Photos stay on the Device Passport.</p><div class="row">@foreach($photoPurposes as $value=>$label)<div class="col-xs-6 col-md-4"><label>{{ $label }}<input class="form-control" type="file" name="photos[]" accept="image/*"><input type="hidden" name="photo_purpose[]" value="{{ $value }}"></label></div>@endforeach</div></details>
                </fieldset>

                <fieldset id="tradein-step-4" class="tradein-step"><legend>4. Market evidence, recommendation, and first offer</legend>
                    <div class="row">@foreach([1=>'External asking evidence',2=>'Competitor / other evidence'] as $number=>$label)<div class="col-md-6"><div class="well well-sm"><strong>{{ $label }}</strong><div class="form-group"><label>Source and model/spec comparison</label><input class="form-control" name="market_evidence_{{ $number }}_source" maxlength="320" aria-label="{{ $label }} source" required></div><div class="form-group"><label>Asking/reference price (MYR)</label><input class="form-control" name="market_evidence_{{ $number }}_amount" type="number" min="0" step="0.01" aria-label="{{ $label }} price" required></div><div class="form-group"><label>URL (optional)</label><input class="form-control" name="market_evidence_{{ $number }}_url" type="url" maxlength="1000" aria-label="{{ $label }} URL"></div></div></div>@endforeach</div>
                    <div class="row">@foreach(['market_reference_amount'=>'Market reference','expected_resale_amount'=>'Expected selling price','expected_refurbishment_amount'=>'Estimated refurbishment cost','staff_proposed_amount'=>'Opening offer','customer_requested_amount'=>'Customer expected price'] as $name=>$label)<div class="col-xs-6 col-md"><div class="form-group"><label>{{ $label }} (MYR)</label><input class="form-control" name="{{ $name }}" type="number" min="0" step="0.01" aria-label="{{ $label }} amount" @if($name !== 'customer_requested_amount') required @endif value="{{ $name === 'expected_refurbishment_amount' ? 0 : '' }}"></div></div>@endforeach</div>
                    <div class="alert alert-info"><strong>Offer ladder:</strong> the system saves opening offer, recommended buy, maximum without approval, and economic ceiling from the active rule. Approval is required for a ceiling or personal-authority exception.</div>
                </fieldset>

                <fieldset id="tradein-step-5" class="tradein-step"><legend>5. Seller declaration and record valuation</legend>
                    <div class="well"><p>{{ $sellerDeclarationText }}</p><label><input type="checkbox" name="seller_declaration_accepted" value="1" required> Seller acknowledged this declaration.</label></div>
                    <button class="btn btn-primary btn-lg" type="submit" @disabled($variations->isEmpty())>Generate SAVERPOS recommendation</button>
                </fieldset>
            </div>
        </form>
    </div>

    <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Operational acquisition queue</h3></div><div class="box-body table-responsive"><table class="table table-striped table-bordered"><thead><tr><th>Reference / seller</th><th>Device</th><th>Economics</th><th>Negotiation</th><th>State / action</th></tr></thead><tbody>
    @forelse($valuations as $valuation)
        @php($inspection = $valuation->laptopInspection)
        <tr><td><strong>{{ $valuation->valuation_uuid }}</strong><br><small>{{ $valuation->created_at }} · {{ $valuation->acquisition_type }}</small><br>{{ optional($valuation->customer)->name ?: 'Seller record' }}<br><small>{{ optional($valuation->createdBy)->first_name ?: 'Staff' }} · {{ optional($valuation->device)->device_code }}</small></td><td><strong>{{ optional($inspection)->brand ?: optional($valuation->device)->brand }} {{ optional($inspection)->model ?: optional($valuation->device)->model }}</strong><br><small>{{ optional($inspection)->cpu }} · {{ optional($inspection)->ram }} · {{ optional($inspection)->storage }}<br>Grade {{ optional($inspection)->cosmetic_grade ?: data_get($valuation->inspection_json,'cosmetic_grade') }}</small></td><td><small>Expected sale: RM {{ $money($valuation->expected_resale_amount) }}<br>Opening: RM {{ $money($valuation->opening_offer_amount) }}<br>Recommended: RM {{ $money($valuation->target_acquisition_amount) }}<br>Max without approval: RM {{ $money($valuation->authority_limit_amount ?? $valuation->negotiation_ceiling_amount) }}<br>Economic ceiling: RM {{ $money($valuation->economic_ceiling_amount) }}<br>Required contribution: RM {{ $money(data_get($valuation->pricing_snapshot_json, 'components.required_contribution_amount', 0)) }}<br>Latest offer: <strong>RM {{ $money($valuation->staff_proposed_amount) }}</strong></small></td><td><small>{{ $valuation->negotiationEvents->count() }} events</small>@if(in_array($valuation->status,['READY_TO_ACCEPT','PENDING_APPROVAL','APPROVED'],true) && $canManage)<form method="post" action="{{ route('recommerce.tradeins.negotiation',$valuation->id) }}" class="form-inline" style="margin-top:6px">@csrf<select class="input-sm" name="event_type" aria-label="Negotiation event type"><option value="STAFF_OFFER">Staff offer</option><option value="CUSTOMER_COUNTER">Customer counter</option></select> <input class="input-sm" name="amount" type="number" min="0" step="0.01" required aria-label="Negotiation amount"> <button class="btn btn-default btn-xs">Add</button></form>@endif</td><td style="min-width:240px"><span class="label label-{{ $statuses[$valuation->status] ?? 'default' }}">{{ str_replace('_',' ',$valuation->status) }}</span><br><small>{{ $valuation->staff_proposed_amount <= $valuation->opening_offer_amount ? 'Excellent buy' : ($valuation->staff_proposed_amount <= $valuation->target_acquisition_amount ? 'Healthy buy' : ($valuation->staff_proposed_amount <= $valuation->negotiation_ceiling_amount ? 'Acceptable buy' : 'Approval or economic override required')) }}</small>@if($valuation->authority_approval_required)<br><small class="text-warning">Authority approval required above RM {{ $money($valuation->authority_limit_amount) }}</small>@endif
        @if($valuation->status === 'PENDING_APPROVAL' && $canApprove)<form method="post" action="{{ route('recommerce.tradeins.approve',$valuation->id) }}" style="margin-top:6px">@csrf<input class="form-control input-sm" name="reason" placeholder="Approval reason / evidence" aria-label="Approval reason or evidence" required><button class="btn btn-info btn-sm" style="margin-top:4px">Approve offer</button></form>@endif
        @if(in_array($valuation->status,['READY_TO_ACCEPT','APPROVED'],true) && $canAccept)<form method="post" action="{{ route('recommerce.tradeins.accept',$valuation->id) }}" style="margin-top:6px">@csrf<input type="hidden" name="command_uuid" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><button class="btn btn-success btn-sm">Accept → native purchase → QC</button></form>@endif
        @if(in_array($valuation->status,['READY_TO_ACCEPT','PENDING_APPROVAL','APPROVED'],true) && $canManage)<form method="post" action="{{ route('recommerce.tradeins.reject',$valuation->id) }}" style="margin-top:6px">@csrf<select class="form-control input-sm" name="reason_code" aria-label="Rejection reason code"><option value="OFFER_TOO_LOW">Offer too low</option><option value="CUSTOMER_EXPECTED_MORE">Customer expected more</option><option value="COMPETITOR_OFFERED_MORE">Competitor offered more</option><option value="FAILED_INSPECTION">Failed inspection</option><option value="OWNERSHIP_OR_FRAUD_CONCERN">Ownership/fraud concern</option><option value="PRICE_CHECK_ONLY">Price check only</option><option value="OTHER">Other</option></select><input class="form-control input-sm" name="reason" placeholder="Reason" aria-label="Rejection reason" required><button class="btn btn-default btn-sm" style="margin-top:4px">Reject / return custody</button></form>@endif
        @if($valuation->status === 'ACCEPTED')<form method="post" action="{{ route('recommerce.tradeins.refurbishment',$valuation->id) }}" style="margin-top:6px">@csrf<button class="btn btn-warning btn-sm">Start QC / refurbishment</button></form><form method="post" action="{{ route('recommerce.tradeins.release_for_sale',$valuation->id) }}" style="margin-top:6px">@csrf<button class="btn btn-success btn-sm">Release for sale after QC</button></form>@endif
        @if($valuation->status === 'ACCEPTED' && $canReverse)<form method="post" action="{{ route('recommerce.tradeins.reverse',$valuation->id) }}" style="margin-top:6px">@csrf<input class="form-control input-sm" type="number" name="purchase_return_transaction_id" placeholder="Native purchase-return ID" aria-label="Native purchase return ID" required><input class="form-control input-sm" name="reason" placeholder="Reversal reason" aria-label="Reversal reason" required><input type="hidden" name="command_uuid" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><button class="btn btn-danger btn-sm" style="margin-top:4px">Record reversal</button></form>@endif
        </td></tr>
    @empty<tr><td colspan="5" class="text-muted">No acquisitions recorded for this branch.</td></tr>@endforelse
    </tbody></table></div></div>

    @if($canManage)<details class="box box-default"><summary class="box-header with-border"><strong>Manager pricing-policy version</strong> — hidden from ordinary operators</summary><form method="post" action="{{ route('recommerce.tradeins.rules.store') }}" class="box-body">@csrf<div class="row"><div class="col-md-4"><label>Rule code<input class="form-control" name="rule_code" pattern="[A-Za-z0-9_]+" required></label></div><div class="col-md-4"><label>Variation<select class="form-control" name="variation_id">@foreach($variations as $variation)<option value="{{ $variation->id }}">{{ $variation->product->name }} · {{ $variation->name }}</option>@endforeach</select></label></div><div class="col-md-4"><label>Category<input class="form-control" name="category_code" value="LAPTOP"></label></div></div><div class="row">@foreach(['target_margin_percent'=>'Target margin','warranty_reserve_percent'=>'Warranty reserve','hidden_defect_reserve_percent'=>'Risk reserve','markdown_reserve_percent'=>'Markdown reserve','opening_offer_ratio'=>'Opening ratio','target_acquisition_ratio'=>'Target ratio','negotiation_ceiling_ratio'=>'Max-without-approval ratio'] as $field=>$label)<div class="col-xs-6 col-md"><label>{{ $label }}<input name="{{ $field }}" type="number" min="0" max="1" step="0.001" class="form-control" required></label></div>@endforeach</div><button class="btn btn-default">Publish version</button></form></details>@endif
    @if($canApprove)<details class="box box-default"><summary class="box-header with-border"><strong>Branch offer authority</strong> — approval-required amounts are calculated by role</summary><div class="box-body"><form method="post" action="{{ route('recommerce.tradeins.authority_rules.store') }}" class="form-inline">@csrf<label>Role <select class="form-control" name="role_name"><option value="">All branch staff</option>@foreach($authorityRoles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach</select></label> <label>Maximum without approval (MYR) <input class="form-control" name="maximum_without_approval" type="number" min="0" step="0.01" required></label> <button class="btn btn-default">Activate rule</button></form>@if($authorityRules->isNotEmpty())<ul class="list-unstyled" style="margin-top:12px">@foreach($authorityRules as $authorityRule)<li>{{ $authorityRule->role_name ?: 'All branch staff' }}: RM {{ $money($authorityRule->maximum_without_approval) }}</li>@endforeach</ul>@endif</div></details>@endif
</section>
@endsection

@section('javascript')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var wizard = document.getElementById('tradein-wizard');
    if (!wizard) return;
    var steps = Array.prototype.slice.call(wizard.querySelectorAll('.tradein-step'));
    var links = Array.prototype.slice.call(wizard.querySelectorAll('.nav-pills a'));
    var show = function (index) {
        steps.forEach(function (step, stepIndex) { step.style.display = stepIndex === index ? '' : 'none'; });
        links.forEach(function (link, linkIndex) { link.parentNode.classList.toggle('active', linkIndex === index); });
        wizard.dataset.step = index;
    };
    links.forEach(function (link, index) { link.addEventListener('click', function (event) { event.preventDefault(); show(index); }); });
    var validateStep = function (index) {
        var fields = steps[index].querySelectorAll('input, select, textarea');
        for (var fieldIndex = 0; fieldIndex < fields.length; fieldIndex += 1) {
            var field = fields[fieldIndex];
            if (field.disabled || field.type === 'hidden') continue;
            if (!field.checkValidity()) {
                show(index);
                field.focus();
                if (typeof field.reportValidity === 'function') field.reportValidity();
                return false;
            }
        }
        return true;
    };
    steps.forEach(function (step, index) {
        var controls = document.createElement('div'); controls.className = 'clearfix'; controls.style.marginTop = '18px';
        if (index > 0) { var previous = document.createElement('button'); previous.type = 'button'; previous.className = 'btn btn-default pull-left'; previous.textContent = 'Previous'; previous.addEventListener('click', function () { show(index - 1); }); controls.appendChild(previous); }
        if (index < steps.length - 1) { var next = document.createElement('button'); next.type = 'button'; next.className = 'btn btn-primary pull-right'; next.textContent = 'Next'; next.addEventListener('click', function () { if (validateStep(index)) show(index + 1); }); controls.appendChild(next); }
        step.appendChild(controls);
    });
    wizard.addEventListener('submit', function (event) {
        for (var index = 0; index < steps.length; index += 1) {
            if (!validateStep(index)) {
                event.preventDefault();
                return;
            }
        }
    });
    Array.prototype.slice.call(wizard.querySelectorAll('[data-tradein-set][data-tradein-group-target]')).forEach(function (button) {
        button.addEventListener('click', function () {
            var group = wizard.querySelector('[data-tradein-group="' + button.dataset.tradeinGroupTarget + '"]');
            if (!group) return;
            Array.prototype.slice.call(group.querySelectorAll('select')).forEach(function (select) {
                select.value = button.dataset.tradeinSet;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });
    var batteryReplacement = wizard.querySelector('[name="battery_replacement_needed"]');
    var batteryEstimate = wizard.querySelector('[name="battery_replacement_estimate_amount"]');
    var syncBatteryEstimate = function () {
        if (!batteryReplacement || !batteryEstimate) return;
        batteryEstimate.disabled = batteryReplacement.value === 'NO';
        if (batteryEstimate.disabled) batteryEstimate.value = '0';
    };
    if (batteryReplacement) batteryReplacement.addEventListener('change', syncBatteryEstimate);
    syncBatteryEstimate();
    var catalogueSelect = wizard.querySelector('#tradein-variation');
    var catalogueSuggestions = wizard.querySelector('#catalogue-suggestions');
    var catalogueInputs = ['laptop-brand', 'laptop-model'].map(function (id) { return wizard.querySelector('#' + id); })
        .concat(['laptop_cpu', 'laptop_ram', 'laptop_storage'].map(function (name) { return wizard.querySelector('[name="' + name + '"]'); }));
    var updateCatalogueSuggestions = function () {
        if (!catalogueSelect || !catalogueSuggestions) return;
        var terms = catalogueInputs.map(function (input) { return input && input.value ? input.value.toLowerCase().trim() : ''; })
            .filter(function (value) { return value.length >= 2; });
        catalogueSuggestions.textContent = '';
        if (!terms.length) {
            catalogueSuggestions.textContent = 'Enter brand/model/specification to suggest an existing catalogue match. Staff must confirm it; no catalogue record is created automatically.';
            return;
        }
        var matches = Array.prototype.slice.call(catalogueSelect.options).filter(function (option) {
            var label = option.text.toLowerCase();
            return option.value && terms.some(function (term) { return label.indexOf(term) !== -1; });
        }).slice(0, 5);
        if (!matches.length) {
            catalogueSuggestions.textContent = 'No approved catalogue match found in this branch cohort. Do not create a catalogue record here.';
            return;
        }
        catalogueSuggestions.appendChild(document.createTextNode('Suggested existing match: '));
        matches.forEach(function (option, index) {
            var suggestion = document.createElement('button'); suggestion.type = 'button'; suggestion.className = 'btn btn-link btn-xs'; suggestion.textContent = option.text;
            suggestion.addEventListener('click', function () { catalogueSelect.value = option.value; catalogueSuggestions.textContent = 'Suggested match selected. Confirm it before recording the valuation.'; });
            catalogueSuggestions.appendChild(suggestion);
            if (index < matches.length - 1) catalogueSuggestions.appendChild(document.createTextNode(' · '));
        });
    };
    catalogueInputs.forEach(function (input) { if (input) input.addEventListener('input', updateCatalogueSuggestions); });
    show(0);
});
</script>
@endsection
