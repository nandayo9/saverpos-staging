@php
    $money=static fn($value)=>number_format((float)$value,2);
    $decisions=$reporting['accepted_count']+$reporting['customer_lost'];
    $conversion=$decisions?round(($reporting['accepted_count']/$decisions)*100,1):null;
@endphp
<div class="sb-ti-kpis">
    <div class="sb-ti-kpi"><span>Acquisitions</span><strong>{{ $reporting['accepted_count'] }}</strong><small>Native purchases posted</small></div>
    <div class="sb-ti-kpi"><span>Acquisition spend</span><strong>RM {{ $money($reporting['accepted_spend']) }}</strong><small>Accepted records</small></div>
    <div class="sb-ti-kpi"><span>Average acquisition</span><strong>{{ $reporting['average_price']===null?'—':'RM '.$money($reporting['average_price']) }}</strong><small>Accepted only</small></div>
    <div class="sb-ti-kpi"><span>Conversion</span><strong>{{ $conversion===null?'—':$conversion.'%' }}</strong><small>{{ $reporting['accepted_count'] }} / {{ $decisions }} decisions</small></div>
    <div class="sb-ti-kpi"><span>Customer lost</span><strong>{{ $reporting['customer_lost'] }}</strong><small>Declined by customer</small></div>
    <div class="sb-ti-kpi"><span>SaverBro rejected</span><strong>{{ $reporting['saverbro_rejected'] }}</strong><small>Commercial or risk decision</small></div>
</div>
<div class="row">
    <div class="col-md-6"><div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Closed-deal reasons</h2><p>Customer-declined and SaverBro-rejected outcomes remain distinct in the totals above.</p></div></div><div class="sb-ti-table-wrap"><table class="table sb-ti-compact-table"><thead><tr><th>Recorded reason</th><th>Count</th></tr></thead><tbody>
@forelse($reporting['lost_reasons'] as $reason=>$count)<tr><td>{{ ucwords(strtolower(str_replace('_',' ',$reason))) }}</td><td>{{ $count }}</td></tr>
@empty<tr><td colspan="2"><div class="sb-ti-empty">No closed-deal reasons recorded.</div></td></tr>
@endforelse</tbody></table></div></div></div>
    <div class="col-md-6"><div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>Staff performance</h2><p>Formal valuations and accepted spend; Quick Quotes without staff valuation are excluded.</p></div></div><div class="sb-ti-table-wrap"><table class="table sb-ti-compact-table"><thead><tr><th>Staff</th><th>Offers</th><th>Accepted</th><th>Spend</th></tr></thead><tbody>
@forelse($reporting['staff'] as $staff=>$row)<tr><td>{{ $staff }}</td><td>{{ $row['offers'] }}</td><td>{{ $row['accepted'] }}</td><td>RM {{ $money($row['spend']) }}</td></tr>
@empty<tr><td colspan="4"><div class="sb-ti-empty">No formal valuations recorded.</div></td></tr>
@endforelse</tbody></table></div></div></div>
</div>
<div class="sb-ti-panel"><div class="sb-ti-panel-head"><div><h2>QC aging</h2><p>Accepted Devices still in PENDING_QC. Ready-to-sale time is shown only when the underlying lifecycle evidence exists.</p></div></div><div class="sb-ti-table-wrap"><table class="table sb-ti-compact-table"><thead><tr><th>Device</th><th>Seller</th><th>Acquired</th><th>Age</th><th>Action</th></tr></thead><tbody>
@forelse($reporting['qc_aging'] as $row)<tr><td>{{ optional($row['valuation']->device)->device_code }} · {{ optional($row['valuation']->laptopInspection)->brand }} {{ optional($row['valuation']->laptopInspection)->model }}</td><td>{{ optional($row['valuation']->customer)->name }}</td><td>{{ optional($row['valuation']->accepted_at)->format('d M Y H:i') }}</td><td><span class="sb-ti-badge {{ $row['days']>2?'warning':'info' }}">{{ $row['days'] }} day{{ $row['days']===1?'':'s' }}</span></td><td><a href="{{ route('recommerce.tradeins.show',$row['valuation']->id) }}">Open deal</a></td></tr>
@empty<tr><td colspan="5"><div class="sb-ti-empty">No Devices are waiting for QC.</div></td></tr>
@endforelse</tbody></table></div></div>
<div class="sb-ti-callout"><strong>Reporting scope</strong><br><span class="text-muted">This first report uses recorded Trade-In valuations, Quick Quotes, acquisitions and Device lifecycle state for branch {{ $locationId }}. Demand, realized unit profit and branch comparisons are intentionally omitted until their evidence links are complete.</span></div>
