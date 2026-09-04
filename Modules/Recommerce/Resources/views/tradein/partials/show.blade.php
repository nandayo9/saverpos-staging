@php
    $money=static fn($value)=>number_format((float)$value,2);
    $valuation=$selectedValuation;
    $device=$valuation->device;
    $inspection=$valuation->laptopInspection;
    $snapshot=(array)$valuation->pricing_snapshot_json;
    $job=$qcJobsByValuation->get($valuation->id);
    $fixed=(float)$valuation->expected_refurbishment_amount+(float)data_get($snapshot,'components.warranty_reserve_amount',0)+(float)data_get($snapshot,'components.hidden_defect_reserve_amount',0)+(float)data_get($snapshot,'components.markdown_reserve_amount',0);
    $currentContribution=max(0,(float)$valuation->expected_resale_amount-$fixed-(float)$valuation->staff_proposed_amount);
    $currentMargin=(float)$valuation->expected_resale_amount>0?round(($currentContribution/(float)$valuation->expected_resale_amount)*100,1):0;
    $normalCeiling=$valuation->authority_limit_amount===null?(float)$valuation->negotiation_ceiling_amount:min((float)$valuation->authority_limit_amount,(float)$valuation->negotiation_ceiling_amount);
    $deviceState=optional($device)->lifecycle_state;
    $isBusinessDevice=optional($device)->ownership_kind==='BUSINESS';
    $isPendingQc=$valuation->status==='ACCEPTED' && $isBusinessDevice && $deviceState==='PENDING_QC' && (int)optional($device)->current_location_id===(int)$valuation->location_id;
    $isAvailable=$valuation->status==='ACCEPTED' && $isBusinessDevice && $deviceState==='AVAILABLE';
    $acceptedStage=match($deviceState){'PENDING_QC'=>$isPendingQc?'Pending QC':'QC unavailable','AVAILABLE'=>$isAvailable?'Ready for sale':'Acquisition record','SOLD'=>'Sold',default=>'Acquisition record'};
    $stage=match($valuation->status){'READY_TO_ACCEPT'=>$valuation->approval_required?'Revision requested':'Negotiating','PENDING_APPROVAL'=>'Approval required','APPROVED'=>'Approved','ACCEPTED'=>$acceptedStage,'REJECTED'=>in_array($valuation->rejection_reason_code,$customerLostCodes,true)?'Customer declined':'SaverBro rejected','REVERSED'=>'Reversed',default=>str_replace('_',' ',$valuation->status)};
    $tone=in_array($valuation->status,['REJECTED','REVERSED'],true)?'danger':($valuation->status==='PENDING_APPROVAL'||$valuation->approval_required?'warning':($isAvailable?'success':($isPendingQc?'warning':'info')));
@endphp
<div class="sb-ti-workspace">
<main class="sb-ti-workspace-main">

@if($valuation->status==='ACCEPTED')
        <div class="sb-ti-callout {{ $isAvailable?'success':($isPendingQc?'warning':'') }}"><strong>{{ $isAvailable?'Device ready for sale':($isPendingQc?'Device acquired · Pending QC':'Acquisition record · Device '.$stage) }}</strong><br>{{ $device->device_code }} · RM {{ $money($valuation->final_acquisition_amount) }} · Acquired from {{ optional($valuation->customer)->name ?: 'seller record' }}
@if($valuation->acquisition)<br>Native purchase transaction #{{ $valuation->acquisition->transaction_id }} is the financial authority.
@endif</div>

@endif
    <div class="sb-ti-panel">
        <div class="sb-ti-panel-head"><div><h2>{{ optional($inspection)->brand ?: $device->brand }} {{ optional($inspection)->model ?: $device->model }}</h2><p>TI-{{ str_pad($valuation->id,5,'0',STR_PAD_LEFT) }} · {{ $device->device_code }} · {{ optional($valuation->customer)->name ?: 'Seller record' }}</p></div><span class="sb-ti-badge {{ $tone }}">{{ $stage }}</span></div>
        <div class="sb-ti-panel-body">
            <div class="row"><div class="col-sm-6"><h4>Device</h4><p>{{ collect([optional($inspection)->cpu,optional($inspection)->ram,optional($inspection)->storage,optional($inspection)->gpu])->filter()->implode(' · ') ?: 'Specification not recorded' }}</p><p class="text-muted">Grade {{ optional($inspection)->cosmetic_grade ?: data_get($valuation->inspection_json,'cosmetic_grade','—') }} · Battery {{ data_get($valuation->inspection_json,'battery_health_percent')!==null?data_get($valuation->inspection_json,'battery_health_percent').'%':'not verified' }}</p></div><div class="col-sm-6"><h4>Customer expectation</h4><p style="font-size:20px;font-weight:700">{{ $valuation->customer_requested_amount===null?'Not stated':'RM '.$money($valuation->customer_requested_amount) }}</p><p class="text-muted">Acquisition mode: {{ $valuation->acquisition_type==='TRADE_IN'?'Trade-In toward a sale':'Sell Device' }}</p></div></div>
        </div>
    </div>
    <div class="sb-ti-panel">
        <div class="sb-ti-panel-head"><div><h2>Deal economics</h2><p>External asking evidence is kept separate from SaverBro’s expected resale.</p></div></div>
        <div class="sb-ti-panel-body">
            <div class="sb-ti-recommended"><span>Recommended buy</span><strong>RM {{ $money($valuation->target_acquisition_amount) }}</strong></div>
            <div class="sb-ti-economics"><div><span>Opening offer</span><strong>RM {{ $money($valuation->opening_offer_amount) }}</strong></div><div><span>Normal ceiling</span><strong>RM {{ $money($normalCeiling) }}</strong></div><div><span>Economic ceiling</span><strong>RM {{ $money($valuation->economic_ceiling_amount) }}</strong></div><div><span>Current offer</span><strong>RM {{ $money($valuation->staff_proposed_amount) }}</strong></div></div>
            <hr><div class="row"><div class="col-sm-6"><h4>Market</h4><div class="sb-ti-summary-row"><span>External asking</span><strong>RM {{ $money($valuation->market_low_amount) }}–{{ $money($valuation->market_high_amount) }}</strong></div><div class="sb-ti-summary-row"><span>SaverBro expected resale</span><strong>RM {{ $money($valuation->expected_resale_amount) }}</strong></div></div><div class="col-sm-6"><h4>Current offer impact</h4><div class="sb-ti-summary-row"><span>Expected contribution</span><strong id="deal-contribution">RM {{ $money($currentContribution) }}</strong></div><div class="sb-ti-summary-row"><span>Expected margin</span><strong id="deal-margin">{{ $currentMargin }}%</strong></div><div class="sb-ti-summary-row"><span>Vs recommendation</span><strong id="deal-difference">RM {{ $money((float)$valuation->target_acquisition_amount-(float)$valuation->staff_proposed_amount) }}</strong></div></div></div>
            <details style="margin-top:15px"><summary>How the recommendation is calculated</summary><div class="sb-ti-summary" style="max-width:560px"><div class="sb-ti-summary-row"><span>Expected resale</span><strong>RM {{ $money($valuation->expected_resale_amount) }}</strong></div><div class="sb-ti-summary-row"><span>Refurbishment</span><strong>− RM {{ $money($valuation->expected_refurbishment_amount) }}</strong></div><div class="sb-ti-summary-row"><span>Warranty reserve</span><strong>− RM {{ $money(data_get($snapshot,'components.warranty_reserve_amount')) }}</strong></div><div class="sb-ti-summary-row"><span>Hidden defect reserve</span><strong>− RM {{ $money(data_get($snapshot,'components.hidden_defect_reserve_amount')) }}</strong></div><div class="sb-ti-summary-row"><span>Markdown reserve</span><strong>− RM {{ $money(data_get($snapshot,'components.markdown_reserve_amount')) }}</strong></div><div class="sb-ti-summary-row"><span>Required contribution</span><strong>− RM {{ $money(data_get($snapshot,'components.required_contribution_amount')) }}</strong></div></div></details>
        </div>
    </div>
    <div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Negotiation timeline</h2><p>Every offer and customer counter is append-only.</p></div></div><div class="sb-ti-panel-body"><div class="sb-ti-timeline">
@forelse($valuation->negotiationEvents as $event)<div class="sb-ti-timeline-item"><time>{{ optional($event->occurred_at)->format('d M H:i') }}</time><strong>{{ ucwords(strtolower(str_replace('_',' ',$event->event_type))) }}
@if($event->amount!==null) · RM {{ $money($event->amount) }}
@endif</strong>
@if($event->note)<div class="text-muted">{{ $event->note }}</div>
@endif</div>
@empty<div class="text-muted">No negotiation events recorded.</div>
@endforelse</div></div></div>
</main>
<aside class="sb-ti-sticky">
    <div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Next action</h2><p>The backend state remains authoritative.</p></div></div><div class="sb-ti-panel-body">

@if(in_array($valuation->status,['READY_TO_ACCEPT','PENDING_APPROVAL','APPROVED'],true) && $canManage)

@if($valuation->status==='PENDING_APPROVAL')<div class="sb-ti-callout warning"><strong>Manager approval required</strong><br>The current offer exceeds normal authority. Payment cannot be posted yet.</div>
@elseif($valuation->status==='READY_TO_ACCEPT' && $valuation->approval_required)<div class="sb-ti-callout warning"><strong>Revision requested</strong><br>Record a revised staff offer before this acquisition can be closed.</div>
@elseif($valuation->status==='APPROVED')<div class="sb-ti-callout success"><strong>Approved for RM {{ $money($valuation->staff_proposed_amount) }}</strong><br>The acquisition can now be closed.</div>
@endif
            <form method="post" action="{{ route('recommerce.tradeins.negotiation',$valuation->id) }}" id="deal-offer-form">
@csrf<label>Record negotiation</label><select aria-label="Negotiation event type" class="form-control" name="event_type"><option value="STAFF_OFFER">Staff offer</option><option value="CUSTOMER_COUNTER">Customer counter</option></select><input id="deal-offer-amount" class="form-control" style="margin-top:7px" type="number" min="0" step="0.01" name="amount" required value="{{ number_format((float) $valuation->staff_proposed_amount, 2, '.', '') }}" aria-label="Negotiation amount"><textarea aria-label="Negotiation note" class="form-control" style="margin-top:7px" name="note" maxlength="1000" rows="2" placeholder="Customer context or reason"></textarea><div id="deal-offer-status" class="help-block"></div><button class="btn btn-primary btn-block" type="submit">Record event</button></form><hr>

@endif

@if(($valuation->status==='APPROVED' || ($valuation->status==='READY_TO_ACCEPT' && !$valuation->approval_required)) && $canAccept)
            <div class="sb-ti-callout success"><strong>Ready to close</strong><br>Final amount: RM {{ $money($valuation->staff_proposed_amount) }}</div>
            <form method="post" action="{{ route('recommerce.tradeins.accept',$valuation->id) }}">
@csrf<input aria-label="command_uuid" type="hidden" name="command_uuid" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><button class="btn btn-success btn-lg btn-block" type="submit"><i class="fa fa-check"></i> Accept & post acquisition</button></form><p class="help-block">Posts one native UltimatePOS purchase and moves this Device to Pending QC.</p>

@elseif($valuation->status==='PENDING_APPROVAL')<a class="btn btn-warning btn-block" href="{{ route('recommerce.tradeins.approvals') }}">Open approval queue</a>

@elseif($valuation->status==='ACCEPTED')

@if($isAvailable)<div class="sb-ti-callout success"><strong>Available for sale</strong><br>QC is complete. No stale QC action is available.</div><a class="btn btn-default btn-block" href="{{ route('recommerce.devices.show',$device->device_code) }}">View Device Passport</a>

@elseif($isPendingQc && !$job)<form method="post" action="{{ route('recommerce.tradeins.refurbishment',$valuation->id) }}">
@csrf<textarea aria-label="notes" class="form-control" name="notes" rows="2" placeholder="Buyer notes for QC (optional)"></textarea><button class="btn btn-primary btn-block" style="margin-top:8px" type="submit">Send to QC</button></form>

@elseif($isPendingQc && $job)<a class="btn btn-primary btn-block" href="{{ route('recommerce.repair.show',$job->job_code) }}">{{ $job->state==='READY'?'Review QC evidence':'Continue QC' }}</a>
@if($job->state==='READY')<form method="post" action="{{ route('recommerce.tradeins.release_for_sale',$valuation->id) }}" style="margin-top:8px">
@csrf<button class="btn btn-success btn-block" type="submit">Release for sale</button></form>
@endif
@else<div class="sb-ti-callout"><strong>No QC action available</strong><br>This acquisition record points to a Device currently marked {{ str_replace('_',' ',strtolower($deviceState ?: 'unknown')) }}. The Device lifecycle remains authoritative.</div><a class="btn btn-default btn-block" href="{{ route('recommerce.devices.show',$device->device_code) }}">View Device Passport</a>
@endif

@else<div class="sb-ti-callout"><strong>{{ $stage }}</strong><br>This deal is closed. Its evidence remains available for audit.</div>
@endif
    </div></div>

@if(in_array($valuation->status,['READY_TO_ACCEPT','PENDING_APPROVAL','APPROVED'],true) && $canManage)
    <div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Close without acquisition</h2><p>Customer and business outcomes report separately.</p></div></div><div class="sb-ti-panel-body"><details><summary>Customer declined</summary><form method="post" action="{{ route('recommerce.tradeins.reject',$valuation->id) }}" style="margin-top:10px">
@csrf<select aria-label="Customer decline reason" class="form-control" name="reason_code" required><option value="OFFER_TOO_LOW">Offer too low</option><option value="CUSTOMER_EXPECTED_MORE">Customer expected more</option><option value="COMPETITOR_OFFERED_MORE">Competitor offered more</option><option value="CUSTOMER_DECIDED_NOT_TO_SELL">Changed mind</option><option value="PRICE_CHECK_ONLY">Price check only</option><option value="CUSTOMER_OTHER">Other customer reason</option></select><textarea aria-label="Customer decline note" class="form-control" style="margin-top:7px" name="reason" required maxlength="255" rows="2" placeholder="Short outcome note"></textarea><button class="btn btn-default btn-block" style="margin-top:7px" type="submit">Close as Customer Declined</button></form></details><hr><details><summary>SaverBro rejected Device</summary><form method="post" action="{{ route('recommerce.tradeins.reject',$valuation->id) }}" style="margin-top:10px">
@csrf<select aria-label="SaverBro rejection reason" class="form-control" name="reason_code" required><option value="FAILED_INSPECTION">Failed inspection</option><option value="OWNERSHIP_OR_FRAUD_CONCERN">Ownership concern</option><option value="MDM_LOCK">MDM / account lock</option><option value="TOO_DAMAGED">Too damaged</option><option value="INVENTORY_TOO_HIGH">Over-stocked</option><option value="LOW_DEMAND">Low demand</option><option value="MARGIN_UNACCEPTABLE">Margin unacceptable</option><option value="CATALOGUE_MISMATCH">Catalogue mismatch</option><option value="SAVERBRO_OTHER">Other business reason</option></select><textarea aria-label="SaverBro rejection note" class="form-control" style="margin-top:7px" name="reason" required maxlength="255" rows="2" placeholder="Why SaverBro will not acquire this Device"></textarea><button class="btn btn-danger btn-block" style="margin-top:7px" type="submit">Reject Device</button></form></details></div></div>

@endif
</aside></div>
<script>
(function(){var input=document.getElementById('deal-offer-amount');if(!input)return;var resale={{ (float)$valuation->expected_resale_amount }},fixed={{ $fixed }},recommended={{ (float)$valuation->target_acquisition_amount }},normal={{ $normalCeiling }},economic={{ (float)$valuation->economic_ceiling_amount }},approved={{ $valuation->status==='APPROVED'?'true':'false' }},approvedAmount={{ (float)$valuation->staff_proposed_amount }};function money(v){return 'RM '+Number(v).toLocaleString('en-MY',{minimumFractionDigits:2,maximumFractionDigits:2})}function sync(){var offer=Number(input.value||0),contribution=Math.max(0,resale-fixed-offer),margin=resale?contribution/resale*100:0;document.getElementById('deal-contribution').textContent=money(contribution);document.getElementById('deal-margin').textContent=margin.toFixed(1)+'%';document.getElementById('deal-difference').textContent=money(recommended-offer);var status=document.getElementById('deal-offer-status'),isApproved=approved&&Math.abs(offer-approvedAmount)<0.005;status.textContent=isApproved?'Approved exception':offer>economic?'Economic override required':offer>normal?'Manager approval required':offer>recommended?'Above recommendation · within normal authority':'Within recommendation';status.style.color=isApproved?'#9ff1c8':offer>economic?'#ffadb5':offer>normal?'#ffd28a':'#9ff1c8'}input.addEventListener('input',sync);sync()})();
</script>
