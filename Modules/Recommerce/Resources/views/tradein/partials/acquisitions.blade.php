@php
    $money = static fn ($value) => number_format((float) $value, 2);
    $filter = request('filter', 'all');
    $filteredQuotes = $quickQuotes->filter(function($quote) use ($filter) {
        return match($filter) {
            'mine' => (int)$quote->created_by === (int)auth()->id(),
            'considering' => $quote->status === 'CONSIDERING' && !$quote->isExpired(),
            'expiring' => $quote->status === 'CONSIDERING' && !$quote->isExpired() && $quote->expires_at->lte(now()->addDay()->endOfDay()),
            'lost' => $quote->status === 'CUSTOMER_DECLINED',
            'in_progress' => $quote->status === 'CONSIDERING',
            default => $filter === 'all',
        };
    });
    $filteredValuations = $valuations->filter(function($valuation) use ($filter, $customerLostCodes) {
        return match($filter) {
            'mine' => (int)$valuation->created_by === (int)auth()->id(),
            'in_progress' => in_array($valuation->status, ['READY_TO_ACCEPT','PENDING_APPROVAL','APPROVED'], true),
            'approval' => $valuation->status === 'PENDING_APPROVAL',
            'pending_qc' => $valuation->status === 'ACCEPTED' && optional($valuation->device)->ownership_kind === 'BUSINESS' && optional($valuation->device)->lifecycle_state === 'PENDING_QC' && (int)optional($valuation->device)->current_location_id === (int)$valuation->location_id,
            'completed' => $valuation->status === 'ACCEPTED' && optional($valuation->device)->lifecycle_state !== 'PENDING_QC',
            'lost' => $valuation->status === 'REJECTED' && in_array($valuation->rejection_reason_code, $customerLostCodes, true),
            'rejected' => $valuation->status === 'REJECTED' && !in_array($valuation->rejection_reason_code, $customerLostCodes, true),
            'considering', 'expiring' => false,
            default => $filter === 'all',
        };
    });
@endphp
<div class="sb-ti-panel">
    <div class="sb-ti-panel-head"><div><h2>Acquisition records</h2><p>Internal rule IDs and accounting linkages stay behind the operator interface.</p></div></div>
    <div class="sb-ti-panel-body"><div class="sb-ti-filters">

@foreach(['all'=>'All','mine'=>'My deals','in_progress'=>'In progress','considering'=>'Customer considering','approval'=>'Approval required','pending_qc'=>'Pending QC','completed'=>'Completed','lost'=>'Customer lost','rejected'=>'SaverBro rejected'] as $key=>$label)
            <a class="{{ $filter === $key ? 'active' : '' }}" href="{{ route('recommerce.tradeins.acquisitions', ['filter'=>$key]) }}">{{ $label }}</a>

@endforeach
    </div></div>
    <div class="sb-ti-table-wrap"><table class="table"><thead><tr><th>Reference</th><th>Date</th><th>Seller</th><th>Device / specs</th><th>Condition</th><th>Recommended</th><th>Latest offer</th><th>Stage</th><th>Staff</th><th>Next action</th></tr></thead><tbody>

@foreach($filteredQuotes as $quote)

@php
    $spec = (array) $quote->specifications_json;
    $quoteStage = match($quote->status) {
        'CONTINUED' => 'Converted to formal deal',
        'CUSTOMER_DECLINED' => 'Customer declined',
        default => $quote->isExpired() ? 'Revaluation required' : 'Customer considering',
    };
    $quoteTone = match($quote->status) {
        'CONTINUED' => 'success',
        'CUSTOMER_DECLINED' => 'danger',
        default => $quote->isExpired() ? 'warning' : 'info',
    };
@endphp
        <tr>
            <td><strong>QQ-{{ str_pad($quote->id, 5, '0', STR_PAD_LEFT) }}</strong><br><small class="text-muted">Quick Quote</small></td><td>{{ $quote->created_at->format('d M Y H:i') }}</td>
            <td>{{ optional($quote->customer)->name ?: ($quote->seller_name_snapshot ?: 'Seller not captured') }}</td>
            <td><strong>{{ data_get($spec,'brand') }} {{ data_get($spec,'model') }}</strong><br><small class="text-muted">{{ collect([data_get($spec,'cpu'),data_get($spec,'ram'),data_get($spec,'storage')])->filter()->implode(' · ') }}</small></td>
            <td>Grade {{ data_get($quote->condition_json,'cosmetic_grade','—') }}</td><td>RM {{ $money(data_get($quote->pricing_snapshot_json,'recommendation.target_acquisition_amount')) }}</td><td>RM {{ $money($quote->estimated_low_amount) }}–{{ $money($quote->estimated_high_amount) }}</td>
            <td><span class="sb-ti-badge {{ $quoteTone }}">{{ $quoteStage }}</span></td>
            <td>{{ optional($quote->createdBy)->first_name ?: 'Unassigned' }}</td><td>
@if($quote->status === 'CONSIDERING')<a class="sb-ti-next" href="{{ route('recommerce.tradeins.create',['quote'=>$quote->id]) }}">{{ $quote->isExpired() ? 'Create new quote' : 'Resume' }} <i class="fa fa-arrow-right"></i></a>
@else<span class="text-muted">Closed</span>
@endif</td>
        </tr>

@endforeach

@foreach($filteredValuations as $valuation)

@php
            $inspection=$valuation->laptopInspection;$device=$valuation->device;$job=$qcJobsByValuation->get($valuation->id);
            $deviceState=optional($device)->lifecycle_state;
            $isPendingQc=$valuation->status==='ACCEPTED' && optional($device)->ownership_kind==='BUSINESS' && $deviceState==='PENDING_QC' && (int)optional($device)->current_location_id===(int)$valuation->location_id;
            $stage=match($valuation->status){'READY_TO_ACCEPT'=>'Negotiating','PENDING_APPROVAL'=>'Approval required','APPROVED'=>'Approved','ACCEPTED'=>match($deviceState){'AVAILABLE'=>'Ready for sale','PENDING_QC'=>$isPendingQc?'Pending QC':'QC unavailable','SOLD'=>'Sold',default=>'Acquisition record'},'REJECTED'=>in_array($valuation->rejection_reason_code,$customerLostCodes,true)?'Customer declined':'SaverBro rejected','REVERSED'=>'Reversed',default=>str_replace('_',' ',$valuation->status)};
            $tone=in_array($valuation->status,['REJECTED','REVERSED'],true)?'danger':($valuation->status==='PENDING_APPROVAL'?'warning':($valuation->status==='ACCEPTED'&&$deviceState==='AVAILABLE'?'success':($isPendingQc?'warning':'info')));
            $next=$isPendingQc?($job?'Continue QC':'Send to QC'):'Open record';
        @endphp
        <tr><td><strong>TI-{{ str_pad($valuation->id,5,'0',STR_PAD_LEFT) }}</strong><br><small class="text-muted">{{ substr($valuation->valuation_uuid,0,8) }}</small></td><td>{{ $valuation->created_at->format('d M Y H:i') }}</td><td>{{ optional($valuation->customer)->name ?: 'Seller record' }}</td><td><strong>{{ optional($inspection)->brand ?: optional($device)->brand }} {{ optional($inspection)->model ?: optional($device)->model }}</strong><br><small class="text-muted">{{ collect([optional($inspection)->cpu,optional($inspection)->ram,optional($inspection)->storage])->filter()->implode(' · ') }}</small></td><td>Grade {{ optional($inspection)->cosmetic_grade ?: data_get($valuation->inspection_json,'cosmetic_grade','—') }}</td><td>RM {{ $money($valuation->target_acquisition_amount) }}</td><td>RM {{ $money($valuation->staff_proposed_amount) }}</td><td><span class="sb-ti-badge {{ $tone }}">{{ $stage }}</span></td><td>{{ optional($valuation->createdBy)->first_name ?: 'Unassigned' }}</td><td><a class="sb-ti-next" href="{{ route('recommerce.tradeins.show',$valuation->id) }}">{{ $next }} <i class="fa fa-arrow-right"></i></a></td></tr>

@endforeach

@if($filteredQuotes->isEmpty() && $filteredValuations->isEmpty())<tr><td colspan="10"><div class="sb-ti-empty"><i class="fa fa-inbox"></i>No acquisitions match this filter.</div></td></tr>
@endif
    </tbody></table></div>
</div>
