@php
    $intake=$selectedIntake;
    $valuation=$intake->valuation;
    $latestOffer=$intake->offers->sortByDesc('offer_version')->first();
    $estimate=(array)$intake->indicative_snapshot_json;
    $evidence=(array)$intake->evidence_references_json;
    $money=static fn($value)=>number_format((float)$value,2);
@endphp
<div class="sb-ti-workspace">
<main class="sb-ti-workspace-main">
    <div class="sb-ti-callout"><strong>Website submission · {{ $intake->external_case_reference }}</strong><br>POS case {{ $intake->intake_uuid }}. Submission {{ $intake->submission_id }} v{{ $intake->submission_version }} is immutable. Request submission is not physical receipt, approval, acquisition, or payment.</div>
    <div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Customer submission</h2><p>{{ $intake->customer_name }} · {{ $intake->customer_email }} · {{ $intake->customer_phone }}</p></div><span class="sb-ti-badge info">Website</span></div><div class="sb-ti-panel-body">
        <div class="row"><div class="col-sm-6"><h4>{{ trim($intake->brand.' '.$intake->model) }}</h4><p>Category: {{ $intake->category_code }}</p><p>Preferred branch: {{ $intake->preferred_branch ?: 'Not supplied' }}</p></div><div class="col-sm-6"><h4>Specifications</h4>@forelse((array)$intake->specifications_json as $key=>$value)<div class="sb-ti-summary-row"><span>{{ ucwords(str_replace('_',' ',$key)) }}</span><strong>{{ is_scalar($value)?$value:'Recorded' }}</strong></div>@empty<p class="text-muted">No structured specifications supplied.</p>@endforelse</div></div>
        <hr><h4>Customer-declared condition</h4>@forelse((array)$intake->declared_condition_json as $key=>$value)<span class="label label-default" style="margin-right:6px">{{ ucwords(str_replace('_',' ',$key)) }}: {{ is_scalar($value)?$value:'Recorded' }}</span>@empty<p class="text-muted">No declaration fields supplied.</p>@endforelse
    </div></div>
    <div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Original indicative estimate</h2><p>Immutable evidence of what the customer saw; not authority to acquire.</p></div></div><div class="sb-ti-panel-body">
        @if($estimate)<div class="sb-ti-summary"><div class="sb-ti-summary-row"><span>Customer-visible range</span><strong>RM {{ $money(((int)data_get($estimate,'estimate_min_minor',0))/100) }}–RM {{ $money(((int)data_get($estimate,'estimate_max_minor',0))/100) }}</strong></div><div class="sb-ti-summary-row"><span>Policy</span><strong>{{ data_get($estimate,'pricing_policy_version','Recorded snapshot') }}</strong></div><div class="sb-ti-summary-row"><span>Engine</span><strong>{{ data_get($estimate,'engine_version','Recorded snapshot') }}</strong></div></div>@else<p>No instant estimate was created for this manual-category request.</p>@endif
    </div></div>
    <div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Private customer evidence</h2><p>Retrieved server-to-server from the linked private website case. No connector credential is exposed to the browser.</p></div><span>{{ count($evidence) }} files</span></div><div class="sb-ti-panel-body">
        @forelse($evidence as $item)<figure style="display:inline-block;max-width:220px;margin:0 12px 12px 0"><img style="max-width:100%;height:auto" src="{{ route('recommerce.tradeins.intakes.evidence',[$intake->id,$item['evidence_id']]) }}" alt="{{ ucwords(strtolower(str_replace('_',' ',$item['evidence_type']??'customer'))) }} evidence"><figcaption>{{ $item['evidence_type']??'CUSTOMER' }} · Customer</figcaption></figure>@empty<p class="text-muted">No customer evidence was attached when this submission version was delivered.</p>@endforelse
    </div></div>
</main>
<aside class="sb-ti-sticky">
    <div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>POS authority</h2><p>Inspection and approval stay native.</p></div></div><div class="sb-ti-panel-body">
        @if(!$valuation)
            <div class="sb-ti-callout warning"><strong>Native assessment required</strong><br>Receive and inspect the customer-owned Device through the existing New Acquisition flow, then link its valuation here.</div>
            @if($canManage)<form method="post" action="{{ route('recommerce.tradeins.intakes.link_valuation',$intake->id) }}">@csrf<label>Native valuation ID</label><input class="form-control" type="number" min="1" name="valuation_id" required><button class="btn btn-primary btn-block" style="margin-top:8px" type="submit">Link inspected valuation</button></form><hr><a class="btn btn-default btn-block" href="{{ route('recommerce.tradeins.create') }}">Open native intake & inspection</a>@endif
        @else
            <p><strong>Valuation TI-{{ str_pad($valuation->id,5,'0',STR_PAD_LEFT) }}</strong><br>{{ optional($valuation->device)->device_code }} · {{ $valuation->status }}<br>RM {{ $money($valuation->final_acquisition_amount) }}</p><a class="btn btn-default btn-block" href="{{ route('recommerce.tradeins.show',$valuation->id) }}">Open native Deal Desk</a>
            @if(in_array($valuation->status,['READY_TO_ACCEPT','APPROVED'],true) && (!$valuation->approval_required || $valuation->status==='APPROVED') && $canManage)
                <form method="post" action="{{ route('recommerce.tradeins.intakes.publish_offer',$intake->id) }}" style="margin-top:8px">@csrf<button class="btn btn-success btn-block" type="submit">Approve &amp; Send Final Offer</button></form><p class="help-block">Publishes an immutable customer-safe offer version. It does not imply customer acceptance or payment.</p>
            @elseif($valuation->status==='PENDING_APPROVAL')<div class="sb-ti-callout warning"><strong>Manager approval required</strong><br>Use the existing approval queue before publishing.</div>
            @endif
        @endif
    </div></div>
    <div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Offer &amp; decision</h2></div></div><div class="sb-ti-panel-body">@if($latestOffer)<p>Offer <strong>{{ $latestOffer->offer_uuid }}</strong><br>Version {{ $latestOffer->offer_version }} · RM {{ $money($latestOffer->amount) }}<br>Status: {{ $latestOffer->status }}<br>Approved by user #{{ $latestOffer->approved_by }} at {{ optional($latestOffer->approved_at)->format('d M Y H:i') }}</p>@else<p>No POS-approved offer has been published.</p>@endif</div></div>
    <div class="sb-ti-callout"><strong>Authoritative boundaries</strong><br>Purchase: {{ optional(optional($valuation)->acquisition)->transaction_id ?: 'not created' }}<br>Device acquisition: {{ optional(optional($valuation)->acquisition)->device_id ?: 'not committed' }}<br>Settlement: not recorded by this website integration.</div>
</aside></div>
