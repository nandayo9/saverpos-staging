@php
    $money = static fn ($value) => number_format((float) $value, 2);
@endphp
@forelse($pendingApprovals as $valuation)

@php
        $snapshot=(array)$valuation->pricing_snapshot_json;
        $requested=(float)$valuation->staff_proposed_amount;
        $resale=(float)$valuation->expected_resale_amount;
        $fixed=(float)$valuation->expected_refurbishment_amount+(float)data_get($snapshot,'components.warranty_reserve_amount',0)+(float)data_get($snapshot,'components.hidden_defect_reserve_amount',0)+(float)data_get($snapshot,'components.markdown_reserve_amount',0);
        $contribution=max(0,$resale-$fixed-$requested);
        $margin=$resale>0?round(($contribution/$resale)*100,1):0;
        $normalCeiling=$valuation->authority_limit_amount===null?(float)$valuation->negotiation_ceiling_amount:min((float)$valuation->authority_limit_amount,(float)$valuation->negotiation_ceiling_amount);
        $economicOverride=$requested>(float)$valuation->economic_ceiling_amount;
    @endphp
    <article class="sb-ti-panel">
        <div class="sb-ti-panel-head"><div><h2>{{ optional($valuation->laptopInspection)->brand }} {{ optional($valuation->laptopInspection)->model }}</h2><p>{{ optional($valuation->customer)->name ?: 'Seller record' }} · requested by {{ optional($valuation->createdBy)->first_name ?: 'Staff' }} · branch {{ $valuation->location_id }}</p></div><span class="sb-ti-badge {{ $economicOverride ? 'danger' : 'warning' }}"><i class="fa fa-exclamation-triangle"></i> {{ $economicOverride ? 'Economic override required' : 'Approval required' }}</span></div>
        <div class="sb-ti-panel-body">

@if($economicOverride)<div class="sb-ti-callout danger"><strong>Above the economic ceiling.</strong> This requires the designated override permission and a recorded reason. Expected contribution is below policy.</div>
@else<div class="sb-ti-callout warning"><strong>Outside normal authority.</strong> Review the customer context and unit economics before approving.</div>
@endif
            <div class="sb-ti-economics">
                <div><span>Requested amount</span><strong>RM {{ $money($requested) }}</strong></div><div><span>Recommended</span><strong>RM {{ $money($valuation->target_acquisition_amount) }}</strong></div><div><span>Normal ceiling</span><strong>RM {{ $money($normalCeiling) }}</strong></div><div><span>Economic ceiling</span><strong>RM {{ $money($valuation->economic_ceiling_amount) }}</strong></div>
                <div><span>Expected resale</span><strong>RM {{ $money($resale) }}</strong></div><div><span>Expected contribution</span><strong>RM {{ $money($contribution) }}</strong></div><div><span>Expected margin</span><strong>{{ $margin }}%</strong></div><div><span>Customer expected</span><strong>{{ $valuation->customer_requested_amount===null?'Not stated':'RM '.$money($valuation->customer_requested_amount) }}</strong></div>
            </div>

@php
    $latestNote = optional($valuation->negotiationEvents->where('event_type', 'STAFF_OFFER')->last())->note;
@endphp
            <p style="margin:14px 0"><strong>Requester context</strong><br><span class="text-muted">{{ $latestNote ?: 'No additional reason was recorded with the latest staff offer.' }}</span></p>
            <div class="row">

@if($canApprove)<div class="col-md-4"><form method="post" action="{{ route('recommerce.tradeins.approve',$valuation->id) }}">
@csrf<label>Approval reason</label><textarea aria-label="reason" class="form-control" name="reason" required maxlength="2000" rows="2" placeholder="Why this exception is commercially acceptable"></textarea><button class="btn btn-success btn-block" style="margin-top:8px" type="submit">Approve offer</button></form></div>
                <div class="col-md-4"><form method="post" action="{{ route('recommerce.tradeins.return_for_revision',$valuation->id) }}">
@csrf<label>Revision instruction</label><textarea aria-label="reason" class="form-control" name="reason" required maxlength="1000" rows="2" placeholder="What should the buyer revise?"></textarea><button class="btn btn-warning btn-block" style="margin-top:8px" type="submit">Return for revision</button></form></div>
                <div class="col-md-4"><form method="post" action="{{ route('recommerce.tradeins.reject',$valuation->id) }}">
@csrf<input aria-label="reason_code" type="hidden" name="reason_code" value="MARGIN_UNACCEPTABLE"><label>Rejection reason</label><textarea aria-label="reason" class="form-control" name="reason" required maxlength="255" rows="2" placeholder="Why SaverBro will not acquire this Device"></textarea><button class="btn btn-danger btn-block" style="margin-top:8px" type="submit">Reject acquisition</button></form></div>
@endif
            </div>
        </div>
    </article>
@empty
    <div class="sb-ti-panel"><div class="sb-ti-empty"><i class="fa fa-check-circle"></i><strong>No approvals waiting</strong><br>Offers within authority can continue directly in the Deal Desk.</div></div>
@endforelse
