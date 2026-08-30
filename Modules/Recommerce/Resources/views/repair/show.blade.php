@extends('layouts.app')

@section('content')
<style>
    .sb-record { max-width:1100px; margin:0 auto; color:#172033; }
    .sb-record .record-header,.sb-record .record-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 5px 18px rgba(15,23,42,.05); margin-bottom:16px; }
    .sb-record .record-header { padding:20px; display:flex; justify-content:space-between; gap:18px; align-items:flex-start; }
    .sb-record h1 { font-size:25px; margin:0 0 7px; font-weight:700; }
    .sb-record h2 { font-size:16px; margin:0; padding:14px 18px; border-bottom:1px solid #eef0f4; }
    .sb-record .card-body { padding:18px; }
    .sb-record dl { margin:0; display:grid; grid-template-columns:150px 1fr; gap:9px 16px; }
    .sb-record dt { color:#64748b; font-weight:500; }
    .sb-record dd { margin:0; font-weight:600; }
    .sb-record .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
    .sb-record .badge { display:inline-block; border-radius:999px; padding:5px 11px; background:#eef2ff; color:#4338ca; font-weight:700; font-size:12px; }
    .sb-record .timeline { border-left:2px solid #dbeafe; padding-left:16px; }
    .sb-record .timeline-item { position:relative; margin-bottom:14px; }
    .sb-record .timeline-item:before { content:""; position:absolute; left:-23px; top:5px; width:8px; height:8px; border-radius:50%; background:#4f46e5; }
    .sb-record .timeline-time { color:#64748b; font-size:12px; }
    .sb-record .checklist-item { display:flex; justify-content:space-between; gap:14px; padding:9px 0; border-bottom:1px solid #f1f5f9; }
    .sb-record .outcome { font-weight:700; font-size:12px; white-space:nowrap; }
    .sb-record .outcome-pass { color:#15803d; }.sb-record .outcome-fail { color:#b91c1c; }.sb-record .outcome-na { color:#64748b; }
    .sb-record .toolbar { display:flex; gap:8px; flex-wrap:wrap; }
    @media(max-width:700px){.sb-record .record-header{display:block}.sb-record .toolbar{margin-top:15px}.sb-record .grid{grid-template-columns:1fr}.sb-record dl{grid-template-columns:1fr;gap:3px;margin-bottom:10px}}
    @media print { body{background:#fff!important}.no-print{display:none!important}.sb-record{max-width:none}.sb-record .record-header,.sb-record .record-card{box-shadow:none;border-color:#d1d5db;break-inside:avoid}a{color:#172033;text-decoration:none} }
</style>

<section class="container-fluid sb-record" aria-labelledby="repair-record-title">
    <div class="record-header">
        <div><p class="text-muted" style="margin:0 0 5px">SAVERPOS Recommerce · Customer service record</p><h1 id="repair-record-title">{{ $job->job_code }}</h1><span class="badge">{{ str_replace('_', ' ', $job->state) }}</span> <span class="text-muted">Lock version {{ $job->lock_version }}</span></div>
        <div class="toolbar no-print"><button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa fa-print"></i> Print service record</button><a class="btn btn-default" href="{{ route('recommerce.repair.index') }}">Back to Repair</a></div>
    </div>

    <div class="grid">
        <div class="record-card"><h2>Customer and device</h2><div class="card-body"><dl><dt>Customer</dt><dd>{{ optional($job->contact)->name ?: 'Unavailable' }}</dd><dt>Device</dt><dd>{{ $job->device->device_code }}</dd><dt>Category</dt><dd>{{ $job->device->category_code ?: '—' }}</dd><dt>Brand / model</dt><dd>{{ data_get($job->device->specifications_json, 'brand', '—') }} {{ data_get($job->device->specifications_json, 'model', '') }}</dd><dt>Identifier</dt><dd>{{ $job->device->identifiers->count() ? 'Verified on secure device record' : 'Not supplied' }}</dd><dt>Location</dt><dd>{{ $job->location_id }}</dd></dl></div></div>
        <div class="record-card"><h2>Work plan</h2><div class="card-body"><dl><dt>Received</dt><dd>{{ optional($job->opened_at)->format('d M Y H:i') }}</dd><dt>Due</dt><dd>{{ $job->due_at ? $job->due_at->format('d M Y') : 'Not set' }}</dd><dt>Priority</dt><dd>{{ $job->priority }}</dd><dt>Technician</dt><dd>{{ optional($job->assignee)->name ?: 'Assign later' }}</dd><dt>Access</dt><dd>{{ str_replace('_', ' ', $job->access_status) }}</dd><dt>Quote</dt><dd>{{ $job->estimated_quote_amount !== null ? 'RM '.number_format((float) $job->estimated_quote_amount, 2) : 'Not estimated' }}</dd></dl></div></div>
    </div>

    <div class="record-card"><h2>Fault and intake notes</h2><div class="card-body"><p><strong>Reported fault</strong><br>{!! nl2br(e($job->reported_fault ?: 'Not recorded')) !!}</p><p style="margin-bottom:0"><strong>Cosmetic condition</strong><br>{!! nl2br(e($job->cosmetic_condition ?: 'Not recorded')) !!}</p></div></div>

    <div class="grid">
        <div class="record-card"><h2>Pre-repair checklist</h2><div class="card-body">@forelse ($job->checklistItems as $item)<div class="checklist-item"><div><strong>{{ $item->label }}</strong>@if($item->notes)<br><small class="text-muted">{{ $item->notes }}</small>@endif</div><span class="outcome outcome-{{ strtolower(str_replace('_','-',$item->outcome)) }}">{{ str_replace('_', ' ', $item->outcome) }}</span></div>@empty<p class="text-muted">No checklist captured.</p>@endforelse</div></div>
        <div class="record-card"><h2>Warranty</h2><div class="card-body">@if(is_array($job->warranty_json) && ($job->warranty_json['days'] ?? null) !== null)<p><strong>{{ $job->warranty_json['days'] }} days</strong></p>@endif<p style="white-space:pre-wrap;margin:0">{{ is_array($job->warranty_json) ? ($job->warranty_json['terms'] ?? 'No warranty terms recorded.') : 'No warranty terms recorded.' }}</p></div></div>
    </div>

    <div class="record-card"><h2>Repair state timeline</h2><div class="card-body"><div class="timeline">@forelse ($job->stateTransitions as $transition)<div class="timeline-item"><strong>{{ $transition->from_state ? str_replace('_',' ',$transition->from_state).' → ' : '' }}{{ str_replace('_',' ',$transition->to_state) }}</strong><div class="timeline-time">{{ optional($transition->occurred_at)->format('d M Y H:i') }}</div></div>@empty<div class="text-muted">No state timeline recorded.</div>@endforelse</div></div></div>

    <div class="grid">
        <div class="record-card"><h2>Diagnostics</h2><div class="card-body">@if($diagnosticViewEnabled)@forelse ($job->diagnosticSessions as $session)<p><strong>{{ optional($session->templateVersion->template)->name ?: 'Diagnostic session' }}</strong><br>Status: {{ $session->status }} · Grade: {{ $session->grade_code ?: 'Not submitted' }}</p>@if($session->observations->count())<ul>@foreach($session->observations as $observation)<li>{{ $observation->check_key }}: {{ $observation->outcome }}@if($observation->notes) — {{ $observation->notes }}@endif</li>@endforeach</ul>@endif @empty<p class="text-muted">No diagnostics submitted.</p>@endforelse<a class="btn btn-default btn-sm no-print" href="{{ route('recommerce.repair.diagnostics.show', $job->job_code) }}">Open diagnostics</a>@else<p class="text-muted">Diagnostics are restricted for this role.</p>@endif</div></div>
        <div class="record-card"><h2>Parts workflow</h2><div class="card-body">@if($job->partReservations->count() || $job->partUsages->count())<p>{{ $job->partReservations->count() }} reservation(s) · {{ $job->partUsages->count() }} usage record(s)</p>@foreach($job->partUsages as $usage)<p><strong>Variation {{ $usage->variation_id }}</strong><br>Status: {{ $usage->status }}@if($usage->source_transaction_id)<br>POS source transaction: {{ $usage->source_transaction_id }} / line {{ $usage->source_line_id }}@endif</p>@endforeach @else<p class="text-muted">No parts recorded.</p>@endif<a class="btn btn-default btn-sm no-print" href="{{ route('recommerce.repair.parts.show', $job->job_code) }}">Open parts workbench</a></div></div>
    </div>

    <div class="record-card"><h2>Collection</h2><div class="card-body">@if($collectionSummary)<p><strong>POS sale</strong> {{ $collectionSummary['sale_transaction_id'] ?: 'Not billed' }} · billed RM {{ number_format($collectionSummary['billed_total'], 2) }} · paid RM {{ number_format($collectionSummary['paid_amount'], 2) }}@if($collectionSummary['outstanding_amount'] > 0) · outstanding RM {{ number_format($collectionSummary['outstanding_amount'], 2) }}@endif</p>@if($collectionSummary['pending_parts'])<p class="text-muted">{{ $collectionSummary['pending_parts'] }} installed part(s) still wait for billing.</p>@endif @else<p class="text-muted">Collection evidence is available after the repair is QC-passed and billed.</p>@endif @if($canCollect || $canStartRepeat)<div class="collection-actions">@if($canCollect)<form class="collection-form" data-csrf-token="{{ csrf_token() }}" action="{{ route('recommerce.repair.collection.collect', $job->job_code) }}"><input type="hidden" name="_token" value=""><label for="collector-name">Collector</label><input id="collector-name" name="collector_name" maxlength="160" required><label for="collector-phone">Phone (optional)</label><input id="collector-phone" name="collector_phone" maxlength="60"><label for="override-reason">Override reason (unpaid only)</label><input id="override-reason" name="override_reason" maxlength="255"><button class="btn btn-success btn-sm" type="submit">Collect and close</button></form>@endif @if($canStartRepeat)<form class="collection-form" data-csrf-token="{{ csrf_token() }}" action="{{ route('recommerce.repair.collection.repeat', $job->job_code) }}"><input type="hidden" name="command_uuid" value=""><button class="btn btn-default btn-sm" type="submit">Repeat visit</button></form>@endif</div><div id="collection-result" class="alert" style="display:none;margin-top:10px" role="status"></div>@endif</div></div>

    <div class="record-card"><h2>Warranty claims</h2><div class="card-body">@forelse ($warrantyClaims as $claim)<div class="checklist-item"><div><strong>{{ $claim->claim_number }}</strong> <span class="outcome outcome-{{ $claim->coverage_status === 'IN_COVERAGE' ? 'pass' : 'na' }}">{{ str_replace('_', ' ', $claim->coverage_status) }}</span><br><small class="text-muted">{{ $claim->decision_reason }}</small><br><small class="text-muted">Claimed {{ optional($claim->claim_requested_at)->format('d M Y') }}@if($claim->policy_name) · policy {{ $claim->policy_name }}@endif @if($claim->coverage_end_at) · cover ends {{ $claim->coverage_end_at->format('d M Y') }}@endif</small>@foreach($claim->lines as $line)<br><small class="text-muted">{{ str_replace('_', ' ', $line->billing_treatment) }} · {{ $line->description }} · RM {{ number_format((float) $line->amount, 2) }}</small>@endforeach</div><span class="outcome text-muted">@if((int) $claim->repair_job_id === (int) $job->id)Repeat job from this claim @elseif($claim->repair_job_id)Repeat job #{{ $claim->repair_job_id }}@else No repeat job @endif</span></div>@empty<p class="text-muted">No warranty claim has been raised against this job.</p>@endforelse
@if($canClaimWarranty)<form id="warranty-claim-form" class="no-print" style="margin-top:14px" action="{{ route('recommerce.repair.warranty.store', $job->job_code) }}" data-csrf-token="{{ csrf_token() }}"><div class="row"><div class="col-sm-5 form-group"><label for="warranty-claimed-on">Claim date</label><input id="warranty-claimed-on" name="claimed_on" class="form-control" type="date" required></div><div class="col-sm-5 form-group"><label for="warranty-covered-amount">Covered amount (optional)</label><input id="warranty-covered-amount" name="covered_amount" class="form-control" type="number" min="0" step="0.01" inputmode="decimal"></div><div class="col-sm-2" style="padding-top:25px"><button class="btn btn-primary btn-block" type="submit">Raise claim</button></div></div></form><div id="warranty-claim-result" class="alert" style="display:none" role="status"></div>@endif</div></div>

    <div class="record-card"><h2>Quote versions</h2><div class="card-body">@forelse ($job->quotes as $quote)<div class="checklist-item"><div><strong>Version {{ $quote->version_number }} · {{ $quote->status }}</strong>@if($quote->summary)<br><small class="text-muted">{{ $quote->summary }}</small>@endif<br><small class="text-muted">Total RM {{ number_format((float) $quote->total_amount, 2) }}@if($quote->expires_at) · expires {{ optional($quote->expires_at)->format('d M Y') }}@endif</small></div><span class="outcome text-muted">{{ optional($quote->sent_at)->format('d M Y H:i') ?: 'Draft' }}</span></div>@empty<p class="text-muted">No quote versions recorded.</p>@endforelse</div></div>

    <div class="record-card"><h2>POS sale and payment evidence</h2><div class="card-body">@if($financialEvidence['sale'])<dl><dt>Sale reference</dt><dd>{{ $financialEvidence['sale']->ref_no ?: $financialEvidence['sale']->invoice_no ?: $financialEvidence['sale']->id }}</dd><dt>Sale status</dt><dd>{{ $financialEvidence['sale']->status }}</dd><dt>Payments</dt><dd>{{ $financialEvidence['payment_count'] }} recorded · RM {{ number_format((float) $financialEvidence['payment_total'], 2) }}</dd></dl>@else<p class="text-muted">No linked finalized POS sale or payment evidence has been linked yet. POS remains the financial authority.</p>@endif</div></div>

    @if($transitionEnabled && $allowedTransitions)
        <div class="record-card no-print"><h2>Controlled state action</h2><div class="card-body"><form id="repair-transition-form" action="{{ route('recommerce.repair.transition', $job->job_code) }}" data-csrf-token="{{ csrf_token() }}">@csrf<input type="hidden" name="expected_lock_version" value="{{ $job->lock_version }}"><div class="row"><div class="col-sm-4 form-group"><label for="repair-to-state">Move to</label><select id="repair-to-state" class="form-control">@foreach($allowedTransitions as $state)<option value="{{ $state }}">{{ str_replace('_',' ',$state) }}</option>@endforeach</select></div><div class="col-sm-6 form-group"><label for="repair-evidence">Evidence JSON</label><input id="repair-evidence" class="form-control" placeholder='For example: {"work_submitted":true}'></div><div class="col-sm-2" style="padding-top:25px"><button class="btn btn-primary btn-block" type="submit">Update</button></div></div></form><div id="repair-transition-result" class="alert" style="display:none" role="status"></div></div></div>
    @endif
</section>
<script>
// Repeat visits and warranty claims are both deduplicated server-side on a v4
// command_uuid, so the browser has to supply one. randomUUID is absent on older
// Safari and on non-secure origins, hence the fallback.
function sbCommandUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') { return window.crypto.randomUUID(); }
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    var hex = [].map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}

(function(){const form=document.getElementById('repair-transition-form');if(!form)return;const result=document.getElementById('repair-transition-result');form.addEventListener('submit',async function(e){e.preventDefault();const button=form.querySelector('button');button.disabled=true;try{let evidence={};const raw=document.getElementById('repair-evidence').value.trim();if(raw)evidence=JSON.parse(raw);const response=await fetch(form.action,{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':form.dataset.csrfToken},credentials:'same-origin',body:JSON.stringify({to_state:document.getElementById('repair-to-state').value,expected_lock_version:Number(form.elements.expected_lock_version.value),evidence:evidence})});const data=await response.json().catch(function(){return{}});if(!response.ok)throw new Error(data.message||'The state update could not be applied.');result.textContent='Updated to '+data.state+'.';result.className='alert alert-success';result.style.display='block';setTimeout(function(){window.location.reload()},400)}catch(error){result.textContent=error.message;result.className='alert alert-warning';result.style.display='block';button.disabled=false}})}());
(function(){
    var forms = document.querySelectorAll('.collection-form');
    var box = document.getElementById('collection-result');
    if (!forms.length) { return; }
    forms.forEach(function(form){
        form.addEventListener('submit', function(event){
            event.preventDefault();
            var button = form.querySelector('button');
            button.disabled = true;
            var uuidField = form.elements.command_uuid;
            if (uuidField) { uuidField.value = sbCommandUuid(); }
            var payload = {};
            var data = new FormData(form);
            data.forEach(function(value, key){
                if (key !== '_token' && String(value).trim() !== '') { payload[key] = String(value); }
            });
            fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.dataset.csrfToken },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            }).then(function(response){
                return response.json().then(function(parsed){ return { ok: response.ok, parsed: parsed }; });
            }).then(function(result){
                if (!result.ok) { throw new Error(result.parsed.message || 'The collection action was rejected.'); }
                box.textContent = 'Updated: ' + result.parsed.status + '.';
                box.className = 'alert alert-success';
                box.style.display = 'block';
                setTimeout(function(){ window.location.reload(); }, 500);
            }).catch(function(error){
                box.textContent = error.message;
                box.className = 'alert alert-warning';
                box.style.display = 'block';
                button.disabled = false;
            });
        });
    });
})();
(function(){
    var form = document.getElementById('warranty-claim-form');
    if (!form) { return; }
    var box = document.getElementById('warranty-claim-result');
    form.addEventListener('submit', function(event){
        event.preventDefault();
        var button = form.querySelector('button');
        button.disabled = true;
        var payload = { command_uuid: sbCommandUuid(), claimed_on: form.elements.claimed_on.value };
        var covered = form.elements.covered_amount.value.trim();
        if (covered !== '') { payload.covered_amount = covered; }
        fetch(form.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.dataset.csrfToken },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function(response){
            return response.json().then(function(parsed){ return { ok: response.ok, parsed: parsed }; });
        }).then(function(result){
            if (!result.ok) { throw new Error(result.parsed.message || 'The warranty claim was rejected.'); }
            box.textContent = 'Claim ' + result.parsed.claim_number + ': ' + result.parsed.coverage_status.replace(/_/g, ' ') + '.';
            box.className = 'alert alert-success';
            box.style.display = 'block';
            setTimeout(function(){ window.location.reload(); }, 500);
        }).catch(function(error){
            box.textContent = error.message;
            box.className = 'alert alert-warning';
            box.style.display = 'block';
            button.disabled = false;
        });
    });

})();
</script>
@endsection
