@extends('layouts.app')

@section('title', 'Recommerce device registry')

@section('content')
<section class="container-fluid" aria-labelledby="recommerce-device-registry-title">
    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Device pipeline</a> <a class="btn btn-default btn-sm" href="{{ route('recommerce.scans.index') }}">Scan Device</a> @if ($canReceive)<a class="btn btn-primary btn-sm" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}"><i class="fa fa-plus"></i> Receive Devices</a>@endif</div>
            <h3 id="recommerce-device-registry-title" class="box-title">{{ $state === 'RECEIVED_PENDING_INSPECTION' ? 'Inspection queue' : 'Device registry' }}</h3>
            <p class="text-muted" style="margin:6px 0 0">Physical units currently in location {{ $locationId }}. Protected serials and token material are never listed or searched here.</p>
        </div>
        <div class="box-body">
            <form method="get" class="form-inline" style="margin-bottom:16px" role="search">
                <label class="sr-only" for="device-query">Search device code or product</label>
                @if ($state !== '')<input type="hidden" name="state" value="{{ $state }}">@endif
                <input id="device-query" name="q" class="form-control" value="{{ $query }}" maxlength="160" placeholder="Device code or product">
                <button class="btn btn-default" type="submit"><i class="fa fa-search"></i> Search</button>
                @if ($query !== '' || $state !== '')<a class="btn btn-link" href="{{ route('recommerce.devices.index') }}">Clear</a>@endif
            </form>
            <div class="table-responsive"><table class="table table-hover"><caption class="sr-only">Recommerce devices visible in the authorized location</caption><thead><tr><th>Device code</th><th>Product</th><th>Lifecycle</th><th>Custody</th><th>Stock</th><th></th></tr></thead><tbody>
                @forelse ($devices as $device)
                    <tr><td><strong>{{ $device->device_code }}</strong></td><td>{{ optional($device->product)->name ?: 'Product unavailable' }}@if(optional($device->variation)->name)<br><small class="text-muted">{{ $device->variation->name }}</small>@endif</td><td>{{ $device->lifecycle_state }}</td><td>{{ $device->custody_kind }}</td><td>{{ $device->stock_participation }}</td><td><a class="btn btn-default btn-xs" href="{{ route('recommerce.devices.show', $device->device_code) }}">Open</a></td></tr>
                @empty
                    <tr><td colspan="6" class="text-muted">No authorized devices matched this search.</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
</section>
@endsection
