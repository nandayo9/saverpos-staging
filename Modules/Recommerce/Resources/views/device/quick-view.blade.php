<div class="sb-registry-quick-view" data-device-quick-view>
    <div class="sb-registry-quick-view__header">
        <div><p class="sb-registry-kicker">Physical device</p><h3>{{ optional($device->product)->name ?: 'Product unavailable' }}</h3><strong>{{ $device->device_code }}</strong></div>
        <button type="button" class="close" data-registry-close aria-label="Close quick view">&times;</button>
    </div>
    <div class="sb-registry-quick-view__badges">
        <span class="label label-primary">{{ $stateLabel }}</span><span class="label label-default">{{ $holder }}</span><span class="label label-default">{{ $labelStatus }}</span>
    </div>
    <dl class="sb-registry-quick-view__facts">
        <div><dt>Product</dt><dd>{{ optional($device->product)->name ?: 'Not recorded' }}@if(optional($device->variation)->name) · {{ $device->variation->name }}@endif</dd></div>
        <div><dt>Identity</dt><dd>{{ $serialHint }}</dd></div>
        <div><dt>Inventory</dt><dd>{{ $inventoryLabel }}</dd></div>
        <div><dt>Received</dt><dd>{{ $device->acquired_at ? $device->acquired_at->format('d M Y') : 'Not recorded' }}</dd></div>
        @if($economicsVisible && $device->purchaseAssignment && $device->purchaseAssignment->unit_acquisition_cost !== null)<div><dt>Acquisition cost</dt><dd>RM {{ number_format((float) $device->purchaseAssignment->unit_acquisition_cost, 2) }}</dd></div>@endif
        @if($device->certification)<div><dt>Grade / battery</dt><dd>{{ $device->certification->grade }} · {{ $device->certification->battery_health_percent }}%</dd></div>@endif
        @if($device->inspection)<div><dt>Inspection</dt><dd>{{ ucwords(strtolower(str_replace('_', ' ', $device->inspection->status))) }}</dd></div>@endif
    </dl>
    @if($events->isNotEmpty())
        <h4>Recent operational history</h4><ol class="sb-registry-quick-view__timeline">@foreach($events as $event)<li><strong>{{ $event['label'] }}</strong><br><small>{{ $event['occurred_at'] ?: 'Time unavailable' }}</small></li>@endforeach</ol>
    @elseif(!$auditVisible)
        <p class="text-muted">Operational history is unavailable for your role.</p>
    @else
        <p class="text-muted">No safe operational events are recorded for this Device.</p>
    @endif
    <div class="sb-registry-quick-view__actions">
        <a class="btn btn-primary" href="{{ route('recommerce.devices.show', $device->device_code) }}">View full record</a>
        @if($inspectionUrl)<a class="btn btn-default" href="{{ $inspectionUrl }}">Inspection Queue</a>@endif
        @if($repairUrl)<a class="btn btn-default" href="{{ $repairUrl }}">Repair workspace</a>@endif
    </div>
</div>
