@php
    $money = static fn ($value) => number_format((float) $value, 2);
@endphp
<div class="sb-ti-attention" aria-label="Needs attention">
    <a href="{{ route('recommerce.tradeins.approvals') }}"><strong>{{ $needsAttention['approvals'] }}</strong><div><span>Approval required</span><small>Manager decision needed</small></div></a>
    <a href="{{ route('recommerce.tradeins.acquisitions', ['filter' => 'considering']) }}"><strong>{{ $needsAttention['considering'] }}</strong><div><span>Customer considering</span><small>Saved quotes to follow up</small></div></a>
    <a href="{{ route('recommerce.tradeins.acquisitions', ['filter' => 'pending_qc']) }}"><strong>{{ $needsAttention['pending_qc'] }}</strong><div><span>Pending QC</span><small>Acquired, not ready for sale</small></div></a>
    <a href="{{ route('recommerce.tradeins.acquisitions', ['filter' => 'expiring']) }}"><strong>{{ $needsAttention['expiring'] }}</strong><div><span>Quote expiring</span><small>Due within 24 hours</small></div></a>
</div>

<div class="sb-ti-kpis" aria-label="Today's Trade-In metrics">
    <div class="sb-ti-kpi"><span>Quotes</span><strong>{{ $metrics['quotes'] }}</strong><small>Quick + formal</small></div>
    <div class="sb-ti-kpi"><span>Offers</span><strong>{{ $metrics['offers'] }}</strong><small>Staff offer recorded</small></div>
    <div class="sb-ti-kpi"><span>Accepted</span><strong>{{ $metrics['accepted'] }}</strong><small>Native acquisition posted</small></div>
    <div class="sb-ti-kpi"><span>Lost</span><strong>{{ $metrics['lost'] }}</strong><small>Customer declined</small></div>
    <div class="sb-ti-kpi"><span>Conversion</span><strong>{{ $metrics['conversion'] === null ? '—' : $metrics['conversion'].'%' }}</strong><small>{{ $metrics['conversion_numerator'] }} / {{ $metrics['conversion_denominator'] }} decisions</small></div>
    <div class="sb-ti-kpi"><span>Acquisition spend</span><strong>RM {{ $money($metrics['spend']) }}</strong><small>Accepted today</small></div>
</div>

<div class="sb-ti-panel">
    <div class="sb-ti-panel-head"><div><h2>Active Acquisitions</h2><p>Live work ordered by newest activity. “Next action” is the operational priority.</p></div><a href="{{ route('recommerce.tradeins.acquisitions') }}">View all</a></div>
    <div class="sb-ti-table-wrap"><table class="table"><thead><tr><th>Device</th><th>Seller</th><th>Stage</th><th>Latest offer</th><th>Expected value</th><th>Staff</th><th>Age</th><th>Next action</th></tr></thead><tbody>

@forelse($activeValuations as $valuation)

@php
            $job = $qcJobsByValuation->get($valuation->id);
            $stage = match($valuation->status) {
                'PENDING_APPROVAL' => 'Approval required', 'APPROVED' => 'Approved', 'ACCEPTED' => optional($valuation->device)->lifecycle_state === 'AVAILABLE' ? 'Ready for sale' : 'Pending QC', default => 'Negotiating'
            };
            $tone = $valuation->status === 'PENDING_APPROVAL' ? 'warning' : ($valuation->status === 'APPROVED' ? 'info' : ($valuation->status === 'ACCEPTED' ? 'success' : 'info'));
            $next = $valuation->status === 'PENDING_APPROVAL' ? 'Review approval' : ($valuation->status === 'APPROVED' ? 'Close acquisition' : ($valuation->status === 'ACCEPTED' ? ($job ? 'Continue QC' : 'Send to QC') : 'Continue deal'));
        @endphp
        <tr>
            <td><strong>{{ optional($valuation->laptopInspection)->brand ?: optional($valuation->device)->brand }} {{ optional($valuation->laptopInspection)->model ?: optional($valuation->device)->model }}</strong><br><small class="text-muted">{{ optional($valuation->device)->device_code }}</small></td>
            <td>{{ optional($valuation->customer)->name ?: 'Seller record' }}</td>
            <td><span class="sb-ti-badge {{ $tone }}">{{ $stage }}</span></td>
            <td>RM {{ $money($valuation->staff_proposed_amount) }}</td>
            <td>RM {{ $money($valuation->expected_resale_amount) }}</td>
            <td>{{ trim(optional($valuation->createdBy)->first_name.' '.optional($valuation->createdBy)->last_name) ?: 'Unassigned' }}</td>
            <td>{{ optional($valuation->updated_at)->diffForHumans(null, true) }}</td>
            <td><a class="sb-ti-next" href="{{ route('recommerce.tradeins.show', $valuation->id) }}">{{ $next }} <i class="fa fa-arrow-right"></i></a></td>
        </tr>

@empty<tr><td colspan="8"><div class="sb-ti-empty"><i class="fa fa-check-circle"></i>No active acquisitions need attention.</div></td></tr>
@endforelse
    </tbody></table></div>
</div>

<div class="sb-ti-panel">
    <div class="sb-ti-panel-head"><div><h2>Today’s Acquisition Funnel</h2><p>Pending and drafts are excluded from the conversion denominator.</p></div></div>
    <div class="sb-ti-funnel">

@foreach(['quotes' => 'Quotes', 'inspections' => 'Inspections', 'offers' => 'Offers', 'acquired' => 'Acquired', 'ready' => 'Ready for sale'] as $key => $label)
            <div class="sb-ti-funnel-step"><span>{{ $label }}</span><strong>{{ $funnel[$key] }}</strong></div>

@endforeach
    </div>
</div>
