@extends('layouts.app')

@section('title', 'Device Registry')

@section('content')
@php
    $state = $state ?? '';
    $query = $query ?? '';
    $labelStatus = $labelStatus ?? '';
    $locationName = $locationName ?? null;
    $lifecycleLabels = ['RECEIVED_PENDING_INSPECTION' => 'Waiting for inspection', 'INSPECTION_IN_PROGRESS' => 'Inspection in progress', 'REFURBISHMENT_REQUIRED' => 'Action required', 'AVAILABLE' => 'Ready for sale', 'RESERVED' => 'Reserved', 'SOLD' => 'Sold'];
    $custodyLabels = ['CUSTOMER' => 'Customer', 'IN_TRANSIT' => 'In transit', 'EXTERNAL_PROVIDER' => 'External provider'];
    $stockLabels = ['ON_HAND' => 'In stock', 'RESERVED' => 'Reserved', 'IN_TRANSFER' => 'In transfer', 'NONE' => 'Not in stock'];
@endphp
<section class="container-fluid" aria-labelledby="recommerce-device-registry-title">
    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Device Overview</a> <a class="btn btn-default btn-sm" href="{{ route('recommerce.scans.index') }}">Find Device</a> @if ($canReceive)<a class="btn btn-primary btn-sm" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}"><i class="fa fa-truck"></i> Receive stock from Purchases</a>@endif</div>
            <h3 id="recommerce-device-registry-title" class="box-title">{{ $state === 'RECEIVED_PENDING_INSPECTION' ? 'Inspection queue' : 'Device Registry' }}</h3>
            <p class="text-muted" style="margin:6px 0 0">Find and investigate an existing physical device at {{ $locationName ?: 'Branch #'.$locationId }}. New supplier stock starts from Purchases.</p>
        </div>
        <div class="box-body">
            <form method="get" class="form-inline" style="margin-bottom:16px" role="search">
                <label class="sr-only" for="device-query">Search device code or product</label>
                @if ($state !== '')<input type="hidden" name="state" value="{{ $state }}">@endif
                <input id="device-query" name="q" class="form-control" value="{{ $query }}" maxlength="160" placeholder="Device code or product">
                <label class="sr-only" for="device-label-status">Label status</label>
                <select id="device-label-status" name="label_status" class="form-control" aria-label="Label status">
                    <option value="">All label statuses</option>
                    <option value="NEEDS_LABEL" @if($labelStatus === 'NEEDS_LABEL') selected @endif>Needs label attention</option>
                    <option value="NOT_PRINTED" @if($labelStatus === 'NOT_PRINTED') selected @endif>Not printed</option>
                    <option value="PRINT_VIEW_OPENED" @if($labelStatus === 'PRINT_VIEW_OPENED') selected @endif>Print view opened</option>
                    <option value="PRINTED" @if($labelStatus === 'PRINTED') selected @endif>Printed</option>
                    <option value="REPRINTED" @if($labelStatus === 'REPRINTED') selected @endif>Reprinted</option>
                </select>
                <button class="btn btn-default" type="submit"><i class="fa fa-search"></i> Search</button>
                @if ($query !== '' || $state !== '' || $labelStatus !== '')<a class="btn btn-link" href="{{ route('recommerce.devices.index') }}">Clear</a>@endif
            </form>
            <div class="table-responsive"><table class="table table-hover"><caption class="sr-only">Devices visible in the authorized location</caption><thead><tr><th>Device code</th><th>Product</th><th>Status</th><th>Label</th><th>Current holder</th><th>Inventory</th><th></th></tr></thead><tbody>
                @forelse ($devices as $device)
                    @php $latestLabel = ($device->labelJobItems ?? collect())->sortByDesc('id')->first(); $labelStatus = ! $latestLabel ? 'Not printed' : ($latestLabel->job?->status === 'REPRINT_CONFIRMED' ? 'Reprinted' : ($latestLabel->job?->status === 'PRINT_CONFIRMED' ? 'Printed' : 'Print view opened')); @endphp
                    @php($currentHolder = $device->custody_kind === 'LOCATION' ? (optional($device->currentLocation ?? null)->name ?: ($device->current_location_id ? 'Branch #'.$device->current_location_id : 'Branch not recorded')) : ($custodyLabels[$device->custody_kind] ?? ucwords(strtolower(str_replace('_', ' ', $device->custody_kind)))))
                    <tr><td><strong>{{ $device->device_code }}</strong></td><td>{{ optional($device->product)->name ?: 'Product unavailable' }}@if(optional($device->variation)->name)<br><small class="text-muted">{{ $device->variation->name }}</small>@endif</td><td>{{ $lifecycleLabels[$device->lifecycle_state] ?? ucwords(strtolower(str_replace('_', ' ', $device->lifecycle_state))) }}@if(($device->transfer_state ?? 'NONE') !== 'NONE')<br><small class="text-warning">{{ $device->transfer_state === 'RECEIVED_PENDING_COMPLETION' ? 'Received — transfer awaiting completion' : ucwords(strtolower(str_replace('_', ' ', $device->transfer_state))) }}</small>@endif</td><td>{{ $labelStatus }}</td><td>{{ $currentHolder }}</td><td>{{ $stockLabels[$device->stock_participation] ?? ucwords(strtolower(str_replace('_', ' ', $device->stock_participation))) }}</td><td><a class="btn btn-default btn-xs" href="{{ route('recommerce.devices.show', $device->device_code) }}">Open</a>@if(($canPrintLabel ?? false) && ! empty($device->id)) <form method="post" action="{{ route('recommerce.devices.label.print', $device->id) }}" data-recommerce-label-print style="display:inline-block;margin-left:4px">@csrf<button class="btn btn-primary btn-xs" type="button"><i class="fa fa-print"></i> Print label</button></form>@endif</td></tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No authorized devices matched this search.</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
</section>
@endsection

@section('javascript')
    <script src="{{ asset('js/recommerce-label-print.js') }}?v={{ config('constants.asset_version') }}"></script>
@endsection
