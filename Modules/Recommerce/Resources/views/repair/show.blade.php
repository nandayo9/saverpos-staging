@extends('layouts.app')

@section('title', 'Repair record '.$job->job_code)

@section('content')
@php
    $quoteManageEnabled = $quoteManageEnabled ?? false;
    $tradeInContext = $tradeInContext ?? null;
@endphp
<style>
    /* Screen styling follows the shared dark POS palette; the print block at
       the end restores a light record for white stock. Each fallback is the
       value this rule used before the conversion, so if the shared stylesheet
       ever fails to load the screen degrades to the old light design rather
       than painting near-white text on the stock POS chrome. */
    .sb-record { max-width:1100px; margin:0 auto; color:var(--sb-text,#172033); }
    .sb-record .record-header,.sb-record .record-card { background:var(--sb-surface-raised,#fff); border:1px solid var(--sb-border,#e5e7eb); border-radius:10px; box-shadow:0 5px 18px rgba(0,0,0,.28); margin-bottom:16px; }
    .sb-record .record-header { padding:20px; display:flex; justify-content:space-between; gap:18px; align-items:flex-start; }
    .sb-record h1 { font-size:25px; margin:0 0 7px; font-weight:700; }
    .sb-record h2 { font-size:16px; margin:0; padding:14px 18px; border-bottom:1px solid var(--sb-border,#eef0f4); }
    .sb-record .card-body { padding:18px; }
    .sb-record dl { margin:0; display:grid; grid-template-columns:150px 1fr; gap:9px 16px; }
    .sb-record dt { color:var(--sb-muted,#64748b); font-weight:500; }
    .sb-record dd { margin:0; font-weight:600; }
    .sb-record .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
    .sb-record .badge { display:inline-block; border-radius:999px; padding:5px 11px; background:#312e81; color:#c7d2fe; font-weight:700; font-size:12px; }
    .sb-record .timeline { border-left:2px solid var(--sb-border-strong,#dbeafe); padding-left:16px; }
    .sb-record .timeline-item { position:relative; margin-bottom:14px; }
    .sb-record .timeline-item:before { content:""; position:absolute; left:-23px; top:5px; width:8px; height:8px; border-radius:50%; background:var(--sb-accent,#4f46e5); }
    .sb-record .timeline-time { color:var(--sb-muted,#64748b); font-size:12px; }
    .sb-record .checklist-item { display:flex; justify-content:space-between; gap:14px; padding:9px 0; border-bottom:1px solid var(--sb-border,#f1f5f9); }
    .sb-record .outcome { font-weight:700; font-size:12px; white-space:nowrap; }
    /* The checklist emits outcome-not-applicable (from NOT_APPLICABLE);
       the warranty card emits outcome-na. Both must be muted -- without
       the first selector an N/A row inherits the card's brightest text
       and reads as more prominent than a PASS or FAIL. */
    .sb-record .outcome-pass { color:var(--sb-success,#15803d); }.sb-record .outcome-fail { color:var(--sb-danger,#b91c1c); }
    .sb-record .outcome-na,.sb-record .outcome-not-applicable { color:var(--sb-muted,#64748b); }
    .sb-record .toolbar { display:flex; gap:8px; flex-wrap:wrap; }
    .sb-record .repair-transition-grid { display:grid; grid-template-columns:minmax(220px,1fr) minmax(140px,.45fr); gap:16px; align-items:start; }
    .sb-record .repair-transition-form--with-context .repair-transition-grid { grid-template-columns:minmax(180px,.85fr) minmax(280px,1.4fr) minmax(140px,.55fr); }
    .sb-record .repair-transition-field { min-width:0; margin-bottom:0; }
    .sb-record .repair-transition-actions { padding-top:25px; }
    .sb-record .repair-transition-actions .btn { min-height:38px; }
    .sb-record .repair-transition-help { margin:6px 0 0; }
    .sb-record .text-muted { color:var(--sb-muted,#64748b) !important; }
    @media(max-width:900px){.sb-record .repair-transition-grid,.sb-record .repair-transition-form--with-context .repair-transition-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sb-record .repair-transition-actions{padding-top:0}}
    @media(max-width:700px){.sb-record .record-header{display:block}.sb-record .toolbar{margin-top:15px}.sb-record .grid{grid-template-columns:1fr}.sb-record dl{grid-template-columns:1fr;gap:3px;margin-bottom:10px}.sb-record .repair-transition-grid,.sb-record .repair-transition-form--with-context .repair-transition-grid{grid-template-columns:1fr}}
    @media print {
        body{background:#fff!important}.no-print{display:none!important}
        .sb-record{max-width:none;color:#172033}
        .sb-record .record-header,.sb-record .record-card{background:#fff;box-shadow:none;border-color:#d1d5db;break-inside:avoid}
        .sb-record h2{border-bottom-color:#eef0f4}
        .sb-record dt,.sb-record .timeline-time,.sb-record .outcome-na,.sb-record .outcome-not-applicable,.sb-record .text-muted{color:#64748b !important}
        .sb-record .badge{background:#eef2ff;color:#4338ca}
        .sb-record .timeline{border-left-color:#dbeafe}
        .sb-record .timeline-item:before{background:#4f46e5}
        .sb-record .checklist-item{border-bottom-color:#f1f5f9}
        .sb-record .outcome-pass{color:#15803d}.sb-record .outcome-fail{color:#b91c1c}
        a{color:#172033;text-decoration:none}
    }
</style>

<section class="container-fluid sb-record" aria-labelledby="repair-record-title">
    <div class="record-header">
        <div><p class="text-muted" style="margin:0 0 5px">SAVERPOS Recommerce · {{ $tradeInContext ? 'Acquisition QC record' : 'Customer service record' }}</p><h1 id="repair-record-title">{{ $job->job_code }}</h1><span class="badge">{{ str_replace('_', ' ', $job->state) }}</span> <span class="text-muted">Lock version {{ $job->lock_version }}</span></div>
        <div class="toolbar no-print"><button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa fa-print"></i> Print service record</button><a class="btn btn-default" href="{{ route('recommerce.repair.index') }}">Back to Repair</a></div>
    </div>

    <div class="grid">
        <div class="record-card">
            <h2>{{ $tradeInContext ? 'Acquisition and device' : 'Customer and device' }}</h2>
            <div class="card-body">
                <dl>
                    @if($tradeInContext)
                        <dt>Current owner</dt><dd>{{ $tradeInContext['current_owner'] }}</dd>
                        <dt>Acquired from</dt>
                        <dd>
                            {{ $tradeInContext['acquired_from'] }}
                            @if($tradeInContext['seller_phone'])
                                <small class="text-muted">· {{ $tradeInContext['seller_phone'] }}</small>
                            @endif
                        </dd>
                        <dt>Acquisition</dt><dd>RM {{ number_format((float) $tradeInContext['acquisition_amount'], 2) }} · {{ optional($tradeInContext['acquired_at'])->format('d M Y H:i') ?: 'Date unavailable' }}</dd>
                    @else
                        <dt>Customer</dt><dd>{{ optional($job->contact)->name ?: 'Unavailable' }}</dd>
                    @endif
                    <dt>Device</dt><dd>{{ $job->device->device_code }}</dd>
                    <dt>Category</dt><dd>{{ $job->device->category_code ?: '—' }}</dd>
                    <dt>Brand / model</dt><dd>{{ data_get($job->device->specifications_json, 'brand', '—') }} {{ data_get($job->device->specifications_json, 'model', '') }}</dd>
                    <dt>Identifier</dt><dd>{{ $job->device->identifiers->count() ? 'Verified on secure device record' : 'Not supplied' }}</dd>
                    <dt>Location</dt><dd>{{ $job->location_id }}</dd>
                </dl>
                @if($tradeInContext)
                    <hr>
                    <strong>Intake findings carried forward</strong>
                    <p class="text-muted" style="margin:7px 0 0">Grade {{ $tradeInContext['cosmetic_grade'] ?: 'not recorded' }} · Battery {{ $tradeInContext['battery_health'] !== null ? $tradeInContext['battery_health'].'%' : 'not verified' }}</p>
                    @if($tradeInContext['failures'])
                        <ul style="margin:7px 0 0">
                            @foreach($tradeInContext['failures'] as $failure)
                                <li>{{ $failure }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted" style="margin:7px 0 0">No failed or conditional functional checks were recorded at intake.</p>
                    @endif
                @endif
            </div>
        </div>
        <div class="record-card"><h2>Work plan</h2><div class="card-body"><dl><dt>Received</dt><dd>{{ optional($job->opened_at)->format('d M Y H:i') }}</dd><dt>Due</dt><dd>{{ $job->due_at ? $job->due_at->format('d M Y') : 'Not set' }}</dd><dt>Priority</dt><dd>{{ $job->priority }}</dd><dt>Technician</dt><dd>{{ trim((string) optional($job->assignee)->user_full_name) ?: 'Assign later' }}</dd><dt>Access</dt><dd>{{ str_replace('_', ' ', $job->access_status) }}</dd><dt>Quote</dt><dd>{{ $job->estimated_quote_amount !== null ? 'RM '.number_format((float) $job->estimated_quote_amount, 2) : 'Not estimated' }}</dd></dl></div></div>
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

    <div class="record-card" id="repair-quotes"><h2>Quote versions</h2><div class="card-body">
        @forelse ($job->quotes as $quote)
            <div class="well well-sm"><strong>Version {{ $quote->version_number }} · {{ str_replace('_', ' ', $quote->status) }}</strong>@if($quote->summary)<br><small class="text-muted">{{ $quote->summary }}</small>@endif<br><small class="text-muted">Subtotal RM {{ number_format((float) $quote->subtotal_amount, 2) }} · tax RM {{ number_format((float) $quote->tax_amount, 2) }} · total RM {{ number_format((float) $quote->total_amount, 2) }}@if($quote->expires_at) · expires {{ optional($quote->expires_at)->format('d M Y') }}@endif</small>
                @foreach ($quote->lines as $line)
                    <br><small>{{ $line->line_type }} · {{ $line->description }} · {{ rtrim(rtrim(number_format((float) $line->quantity, 4), '0'), '.') }} × RM {{ number_format((float) $line->unit_amount, 2) }}</small>
                @endforeach
                @if($quoteManageEnabled && $quote->status === 'DRAFT')
                    <form class="quote-draft-form" data-action="{{ route('recommerce.repair.quotes.update', [$job->job_code, $quote->id]) }}" data-csrf-token="{{ csrf_token() }}" style="margin-top:10px"><input aria-label="Quote summary" class="quote-summary form-control" value="{{ $quote->summary }}" maxlength="320" placeholder="Quote summary"><input aria-label="Quote expiry" class="quote-expiry form-control" type="date" value="{{ optional($quote->expires_at)->format('Y-m-d') }}" style="margin-top:6px"><div class="quote-lines" style="margin-top:6px">
                    @foreach ($quote->lines as $line)
                        <div class="quote-line row"><div class="col-sm-2"><select aria-label="Quote line type" class="quote-line-type form-control"><option {{ $line->line_type === 'LABOUR' ? 'selected' : '' }}>LABOUR</option><option {{ $line->line_type === 'PART' ? 'selected' : '' }}>PART</option><option {{ $line->line_type === 'SERVICE' ? 'selected' : '' }}>SERVICE</option><option {{ $line->line_type === 'OTHER' ? 'selected' : '' }}>OTHER</option></select></div><div class="col-sm-4"><input aria-label="Quote line description" class="quote-description form-control" value="{{ $line->description }}" maxlength="255" required></div><div class="col-sm-2"><input aria-label="Quote line quantity" class="quote-quantity form-control" type="number" min="0.0001" step="0.0001" value="{{ $line->quantity }}" required></div><div class="col-sm-2"><input aria-label="Quote line unit amount" class="quote-unit-amount form-control" type="number" min="0" step="0.01" value="{{ $line->unit_amount }}" required></div><div class="col-sm-2"><input aria-label="Quote line tax amount" class="quote-tax-amount form-control" type="number" min="0" step="0.01" value="{{ $line->tax_amount }}"></div></div>
                    @endforeach
                    </div><button class="btn btn-default btn-sm quote-add-line" type="button">Add line</button> <button class="btn btn-primary btn-sm" type="submit">Save draft</button></form>
                    <form class="quote-send-form" data-action="{{ route('recommerce.repair.quotes.send', [$job->job_code, $quote->id]) }}" data-csrf-token="{{ csrf_token() }}" style="margin-top:8px"><select aria-label="Quote delivery channel" class="quote-channel form-control input-sm" style="display:inline-block;width:auto"><option value="IN_PERSON">In person</option><option value="PHONE">Phone</option><option value="WHATSAPP">WhatsApp</option><option value="EMAIL">Email</option></select> <button class="btn btn-success btn-sm" type="submit">Send quote</button></form>
                @elseif($quoteManageEnabled && $quote->isDecidable())
                    <form class="quote-decision-form" data-action="{{ route('recommerce.repair.quotes.decide', [$job->job_code, $quote->id]) }}" data-csrf-token="{{ csrf_token() }}" style="margin-top:8px"><select aria-label="Quote decision" class="quote-decision form-control input-sm" style="display:inline-block;width:auto"><option value="APPROVED">Approve</option><option value="DECLINED">Decline</option></select> <select aria-label="Customer approval method" class="quote-approval-method form-control input-sm" style="display:inline-block;width:auto"><option value="IN_PERSON">In person</option><option value="PHONE">Phone</option><option value="WHATSAPP">WhatsApp</option><option value="EMAIL">Email</option><option value="OTHER">Other</option></select> <input aria-label="Approval or decline note" class="quote-decision-note form-control input-sm" style="display:inline-block;width:220px" maxlength="1000" placeholder="Approval or decline note"> <button class="btn btn-primary btn-sm" type="submit">Record decision</button></form>
                @endif
            </div>
        @empty<p class="text-muted">No quote versions recorded.</p>@endforelse
        @if($quoteManageEnabled)<hr><h4>Create draft quote</h4><form class="quote-draft-form" data-action="{{ route('recommerce.repair.quotes.store', $job->job_code) }}" data-csrf-token="{{ csrf_token() }}"><input aria-label="Quote summary" class="quote-summary form-control" maxlength="320" placeholder="Summary"><input aria-label="Quote expiry" class="quote-expiry form-control" type="date" style="margin-top:6px"><div class="quote-lines" style="margin-top:6px"><div class="quote-line row"><div class="col-sm-2"><select aria-label="Quote line type" class="quote-line-type form-control"><option>LABOUR</option><option>PART</option><option>SERVICE</option><option>OTHER</option></select></div><div class="col-sm-4"><input aria-label="Quote line description" class="quote-description form-control" maxlength="255" placeholder="Line description" required></div><div class="col-sm-2"><input aria-label="Quote line quantity" class="quote-quantity form-control" type="number" min="0.0001" step="0.0001" value="1" required></div><div class="col-sm-2"><input aria-label="Quote line unit amount" class="quote-unit-amount form-control" type="number" min="0" step="0.01" placeholder="Unit RM" required></div><div class="col-sm-2"><input aria-label="Quote line tax amount" class="quote-tax-amount form-control" type="number" min="0" step="0.01" value="0" placeholder="Tax RM"></div></div></div><button class="btn btn-default btn-sm quote-add-line" type="button">Add line</button> <button class="btn btn-primary btn-sm" type="submit">Create draft</button></form><div id="quote-result" class="alert" style="display:none;margin-top:10px" role="status"></div>@endif
    </div></div>

    <div class="record-card"><h2>POS sale and payment evidence</h2><div class="card-body">@if($financialEvidence['sale'])<dl><dt>Sale reference</dt><dd>{{ $financialEvidence['sale']->ref_no ?: $financialEvidence['sale']->invoice_no ?: $financialEvidence['sale']->id }}</dd><dt>Sale status</dt><dd>{{ $financialEvidence['sale']->status }}</dd><dt>Payments</dt><dd>{{ $financialEvidence['payment_count'] }} recorded · RM {{ number_format((float) $financialEvidence['payment_total'], 2) }}</dd></dl><div class="toolbar no-print" style="margin-top:14px"><a class="btn btn-primary btn-sm" href="{{ route('recommerce.repair.customer_receipt', $job->job_code) }}" target="_blank" rel="noopener"><i class="fa fa-print"></i> Print customer receipt</a></div>@else<p class="text-muted">No linked finalized POS sale or payment evidence has been linked yet. POS remains the financial authority.</p>@endif</div></div>

    @php($operatorTransitions = array_values(array_diff($allowedTransitions, ['CLOSED'])))
    @if($transitionEnabled && $operatorTransitions)
        <div class="record-card no-print"><h2>Controlled state action</h2><div class="card-body"><form id="repair-transition-form" class="repair-transition-form" action="{{ route('recommerce.repair.transition', $job->job_code) }}" data-csrf-token="{{ csrf_token() }}" data-current-state="{{ $job->state }}" novalidate>@csrf<input type="hidden" name="expected_lock_version" value="{{ $job->lock_version }}"><div class="repair-transition-grid"><div class="form-group repair-transition-field"><label for="repair-to-state">Move to</label><select id="repair-to-state" name="to_state" class="form-control">@foreach($operatorTransitions as $state)<option value="{{ $state }}">{{ str_replace('_',' ',$state) }}</option>@endforeach</select></div><div id="repair-transition-context" class="form-group repair-transition-field" style="display:none"><div id="repair-completion-fields" style="display:none"><label for="repair-resolution-code">Completion outcome</label><select id="repair-resolution-code" name="resolution_code" class="form-control"><option value="">Choose outcome</option><option value="COMPLETED">Completed</option><option value="CANCELLED">Cancelled</option><option value="DECLINED">Declined</option><option value="UNREPAIRABLE">Unrepairable</option></select><label id="repair-qc-passed-wrap" class="checkbox" style="display:none;margin-top:8px"><input id="repair-qc-passed" name="qc_passed" type="checkbox"> QC passed</label><p class="help-block repair-transition-help">Required before moving to Ready.</p></div><div id="repair-approval-fields" style="display:none"><label class="checkbox"><input id="repair-approval-satisfied" name="approval_satisfied" type="checkbox"> Approved work is confirmed</label><p class="help-block repair-transition-help">The approved quote is checked again before work can begin.</p></div><div id="repair-qc-fields" style="display:none"><label class="checkbox"><input id="repair-work-submitted" name="work_submitted" type="checkbox"> Repair work is ready for quality control</label><p class="help-block repair-transition-help">Confirm the work is complete before handing it to QC.</p></div><div id="repair-rework-fields" style="display:none"><label for="repair-qc-failure-reason">QC rework reason</label><input id="repair-qc-failure-reason" name="qc_failure_reason" class="form-control" maxlength="255" placeholder="Describe the issue found"><p class="help-block repair-transition-help">Required when QC returns the job to repair.</p></div><div id="repair-reopen-fields" style="display:none"><label for="repair-reopen-reason">Reason for reopening</label><input id="repair-reopen-reason" name="reopen_reason" class="form-control" maxlength="255" placeholder="Describe why this job must be reopened"><p class="help-block repair-transition-help">Required when reopening a ready job.</p></div></div><div class="repair-transition-actions"><button class="btn btn-primary btn-block" type="submit">Update</button></div></div></form><div id="repair-transition-result" class="alert" style="display:none" role="status"></div></div></div>
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

(function(){
    const form=document.getElementById('repair-transition-form');
    if(!form)return;
    const result=document.getElementById('repair-transition-result');
    const toState=document.getElementById('repair-to-state');
    const transitionContext=document.getElementById('repair-transition-context');
    const completionFields=document.getElementById('repair-completion-fields');
    const resolutionCode=document.getElementById('repair-resolution-code');
    const qcPassed=document.getElementById('repair-qc-passed');
    const qcPassedWrap=document.getElementById('repair-qc-passed-wrap');
    const approvalFields=document.getElementById('repair-approval-fields');
    const approvalSatisfied=document.getElementById('repair-approval-satisfied');
    const qcFields=document.getElementById('repair-qc-fields');
    const workSubmitted=document.getElementById('repair-work-submitted');
    const reworkFields=document.getElementById('repair-rework-fields');
    const qcFailureReason=document.getElementById('repair-qc-failure-reason');
    const reopenFields=document.getElementById('repair-reopen-fields');
    const reopenReason=document.getElementById('repair-reopen-reason');
    const showResult=function(message, tone){result.textContent=message;result.className='alert alert-'+tone;result.style.display='block';};
    const setVisibility=function(element, visible){element.style.display=visible?'':'none';};
    const syncTransitionFields=function(){
        const isReady=toState.value==='READY';
        const needsApproval=form.dataset.currentState==='AWAITING_APPROVAL'&&['WAITING_PARTS','IN_REPAIR'].includes(toState.value);
        const needsQcSubmission=toState.value==='QC';
        const needsRework=form.dataset.currentState==='QC'&&toState.value==='IN_REPAIR';
        const needsReopen=form.dataset.currentState==='READY'&&toState.value==='IN_REPAIR';
        const hasContext=isReady||needsApproval||needsQcSubmission||needsRework||needsReopen;
        form.classList.toggle('repair-transition-form--with-context',hasContext);
        setVisibility(transitionContext,hasContext);
        setVisibility(completionFields,isReady);
        setVisibility(qcPassedWrap,isReady&&form.dataset.currentState==='QC');
        setVisibility(approvalFields,needsApproval);
        setVisibility(qcFields,needsQcSubmission);
        setVisibility(reworkFields,needsRework);
        setVisibility(reopenFields,needsReopen);
        resolutionCode.setCustomValidity('');
        qcPassed.setCustomValidity('');
        approvalSatisfied.setCustomValidity('');
        workSubmitted.setCustomValidity('');
        qcFailureReason.setCustomValidity('');
        reopenReason.setCustomValidity('');
    };
    toState.addEventListener('change',syncTransitionFields);
    resolutionCode.addEventListener('change',function(){resolutionCode.setCustomValidity('');});
    qcPassed.addEventListener('change',function(){qcPassed.setCustomValidity('');});
    approvalSatisfied.addEventListener('change',function(){approvalSatisfied.setCustomValidity('');});
    workSubmitted.addEventListener('change',function(){workSubmitted.setCustomValidity('');});
    qcFailureReason.addEventListener('input',function(){qcFailureReason.setCustomValidity('');});
    reopenReason.addEventListener('input',function(){reopenReason.setCustomValidity('');});
    syncTransitionFields();
    form.addEventListener('submit',async function(e){
        e.preventDefault();
        const button=form.querySelector('button[type="submit"]');
        button.disabled=true;
        try{
            let evidence={};
            if(toState.value==='READY'){
                if(!resolutionCode.value){resolutionCode.setCustomValidity('Choose a completion outcome before moving to Ready.');resolutionCode.reportValidity();throw new Error('Choose a completion outcome before moving to Ready.');}
                if(form.dataset.currentState==='QC'&&!qcPassed.checked){qcPassed.setCustomValidity('Confirm that QC passed before moving to Ready.');qcPassed.reportValidity();throw new Error('Confirm that QC passed before moving to Ready.');}
                evidence.resolution_code=resolutionCode.value;
                if(qcPassed.checked)evidence.qc_passed=true;
            }
            if(form.dataset.currentState==='AWAITING_APPROVAL'&&['WAITING_PARTS','IN_REPAIR'].includes(toState.value)){
                if(!approvalSatisfied.checked){approvalSatisfied.setCustomValidity('Confirm that approved work is in place before starting.');approvalSatisfied.reportValidity();throw new Error('Confirm that approved work is in place before starting.');}
                evidence.approval_satisfied=true;
            }
            if(toState.value==='QC'){
                if(!workSubmitted.checked){workSubmitted.setCustomValidity('Confirm that repair work is ready for quality control.');workSubmitted.reportValidity();throw new Error('Confirm that repair work is ready for quality control.');}
                evidence.work_submitted=true;
            }
            if(form.dataset.currentState==='QC'&&toState.value==='IN_REPAIR'){
                if(!qcFailureReason.value.trim()){qcFailureReason.setCustomValidity('Describe the QC issue before returning the job to repair.');qcFailureReason.reportValidity();throw new Error('Describe the QC issue before returning the job to repair.');}
                evidence.qc_failure_reason=qcFailureReason.value.trim();
            }
            if(form.dataset.currentState==='READY'&&toState.value==='IN_REPAIR'){
                if(!reopenReason.value.trim()){reopenReason.setCustomValidity('Describe why this job must be reopened.');reopenReason.reportValidity();throw new Error('Describe why this job must be reopened.');}
                evidence.reopen_reason=reopenReason.value.trim();
            }
            const response=await fetch(form.action,{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':form.dataset.csrfToken},credentials:'same-origin',body:JSON.stringify({to_state:toState.value,expected_lock_version:Number(form.elements.expected_lock_version.value),evidence:evidence})});
            const data=await response.json().catch(function(){return{}});
            if(!response.ok)throw new Error(data.message||'The state update could not be applied.');
            showResult('Updated to '+data.state+'.','success');
            setTimeout(function(){window.location.reload()},400);
        }catch(error){showResult(error.message,'warning');button.disabled=false;}
    });
}());
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
(function(){
    var root = document.getElementById('repair-quotes');
    if (!root) { return; }
    var result = document.getElementById('quote-result');
    function lineMarkup() {
        return '<div class="quote-line row" style="margin-top:6px"><div class="col-sm-2"><select aria-label="Quote line type" class="quote-line-type form-control"><option>LABOUR</option><option>PART</option><option>SERVICE</option><option>OTHER</option></select></div><div class="col-sm-4"><input aria-label="Quote line description" class="quote-description form-control" maxlength="255" placeholder="Line description" required></div><div class="col-sm-2"><input aria-label="Quote line quantity" class="quote-quantity form-control" type="number" min="0.0001" step="0.0001" value="1" required></div><div class="col-sm-2"><input aria-label="Quote line unit amount" class="quote-unit-amount form-control" type="number" min="0" step="0.01" placeholder="Unit RM" required></div><div class="col-sm-2"><input aria-label="Quote line tax amount" class="quote-tax-amount form-control" type="number" min="0" step="0.01" value="0" placeholder="Tax RM"></div></div>';
    }
    function request(form, method, payload) {
        return fetch(form.dataset.action, { method: method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.dataset.csrfToken }, credentials: 'same-origin', body: JSON.stringify(payload) }).then(function(response){ return response.json().catch(function(){ return {}; }).then(function(data){ return { ok: response.ok, data: data }; }); });
    }
    root.querySelectorAll('.quote-add-line').forEach(function(button){ button.addEventListener('click', function(){ button.closest('form').querySelector('.quote-lines').insertAdjacentHTML('beforeend', lineMarkup()); }); });
    root.querySelectorAll('.quote-draft-form').forEach(function(form){ form.addEventListener('submit', function(event){ event.preventDefault(); var button = form.querySelector('button[type="submit"]'); button.disabled = true; var lines = Array.prototype.map.call(form.querySelectorAll('.quote-line'), function(row){ return { line_type: row.querySelector('.quote-line-type').value, description: row.querySelector('.quote-description').value.trim(), quantity: Number(row.querySelector('.quote-quantity').value), unit_amount: Number(row.querySelector('.quote-unit-amount').value), tax_amount: Number(row.querySelector('.quote-tax-amount').value || 0) }; }); var payload = { summary: form.querySelector('.quote-summary').value.trim() || null, expires_at: form.querySelector('.quote-expiry').value || null, lines: lines }; var update = button.textContent.trim() === 'Save draft'; if (!update) { payload.command_uuid = sbCommandUuid(); payload.currency = 'MYR'; } request(form, update ? 'PUT' : 'POST', payload).then(function(response){ if (!response.ok) { throw new Error(response.data.message || 'Quote draft was rejected.'); } window.location.reload(); }).catch(function(error){ if (result) { result.textContent = error.message; result.className = 'alert alert-warning'; result.style.display = 'block'; } button.disabled = false; }); }); });
    root.querySelectorAll('.quote-send-form').forEach(function(form){ form.addEventListener('submit', function(event){ event.preventDefault(); var button = form.querySelector('button'); button.disabled = true; request(form, 'POST', { channel: form.querySelector('.quote-channel').value }).then(function(response){ if (!response.ok) { throw new Error(response.data.message || 'Quote send was rejected.'); } window.location.reload(); }).catch(function(error){ if (result) { result.textContent = error.message; result.className = 'alert alert-warning'; result.style.display = 'block'; } button.disabled = false; }); }); });
    root.querySelectorAll('.quote-decision-form').forEach(function(form){ form.addEventListener('submit', function(event){ event.preventDefault(); var button = form.querySelector('button'); button.disabled = true; var decision = form.querySelector('.quote-decision').value; var payload = { decision: decision, note: form.querySelector('.quote-decision-note').value.trim() || null, evidence: {} }; if (decision === 'APPROVED') { payload.evidence.approval_method = form.querySelector('.quote-approval-method').value; } request(form, 'POST', payload).then(function(response){ if (!response.ok) { throw new Error(response.data.message || 'Quote decision was rejected.'); } window.location.reload(); }).catch(function(error){ if (result) { result.textContent = error.message; result.className = 'alert alert-warning'; result.style.display = 'block'; } button.disabled = false; }); }); });
})();
</script>
@endsection
