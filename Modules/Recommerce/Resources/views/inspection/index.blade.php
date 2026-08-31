@extends('layouts.app')

@section('title', 'Inspection Queue')

@section('content')
<section class="container-fluid" aria-labelledby="inspection-queue-title">
    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.devices.index') }}">Device Registry</a></div>
            <h1 id="inspection-queue-title" class="box-title">Inspection Queue</h1>
        </div>
        <div class="box-body">
            @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
            @if($errors->has('inspection'))<div class="alert alert-warning">{{ $errors->first('inspection') }}</div>@endif
            <div class="row text-center" style="margin-bottom:12px">
                <div class="col-xs-4"><strong>{{ (int) ($counts['PENDING'] ?? 0) }}</strong><br><small>Awaiting</small></div>
                <div class="col-xs-4"><strong>{{ (int) (($counts['ASSIGNED'] ?? 0) + ($counts['IN_INSPECTION'] ?? 0)) }}</strong><br><small>Assigned / in inspection</small></div>
                <div class="col-xs-4"><strong>{{ (int) ($counts['FAILED'] ?? 0) }}</strong><br><small>Action required</small></div>
            </div>
            <div class="btn-group" role="group" aria-label="Inspection queue status filter">
                <a class="btn btn-default {{ $status === '' ? 'active' : '' }}" href="{{ route('recommerce.inspection.index') }}">Open work</a>
                @foreach(['PENDING' => 'Awaiting', 'ASSIGNED' => 'Assigned', 'IN_INSPECTION' => 'In inspection', 'FAILED' => 'Action required', 'PASSED' => 'Cleared'] as $value => $label)
                    <a class="btn btn-default {{ $status === $value ? 'active' : '' }}" href="{{ route('recommerce.inspection.index', ['status' => $value]) }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    <form method="post" action="{{ route('recommerce.inspection.assign') }}">
        @csrf
        <div class="box box-default">
            <div class="box-body table-responsive">
                @if($canAssign)<div class="form-inline" style="margin-bottom:12px"><label for="inspection-inspector">Assign selected</label> <select id="inspection-inspector" class="form-control" name="inspector_id" required><option value="">Choose inspector</option>@foreach($inspectors as $inspector)<option value="{{ $inspector->id }}">User #{{ $inspector->id }}</option>@endforeach</select> <button class="btn btn-primary">Assign inspection</button></div>@endif
                <table class="table table-hover"><thead><tr>@if($canAssign)<th><span class="sr-only">Select</span></th>@endif<th>Device ID</th><th>Product</th><th>Purchase / supplier</th><th>Received</th><th>Age waiting</th><th>State</th><th>Exceptions</th><th>Action</th></tr></thead><tbody>
                    @forelse($inspections as $inspection)
                        @php $age = \Carbon\Carbon::parse($inspection->received_at)->diffForHumans(null, true); @endphp
                        <tr>
                            @if($canAssign)<td><input type="checkbox" name="device_ids[]" value="{{ $inspection->device_id }}" aria-label="Select {{ $inspection->device_code }}"></td>@endif
                            <td><a href="{{ route('recommerce.devices.show', $inspection->device_code) }}"><strong>{{ $inspection->device_code }}</strong></a></td>
                            <td>{{ $inspection->product_name ?: 'Product unavailable' }}@if($inspection->variation_name)<br><small>{{ $inspection->variation_name }}</small>@endif</td>
                            <td>{{ $inspection->purchase_reference ?: 'Purchase link unavailable' }}<br><small>{{ $inspection->supplier_business_name ?: $inspection->supplier_name ?: 'Supplier unavailable' }}</small></td>
                            <td>{{ \Carbon\Carbon::parse($inspection->received_at)->format('d M Y H:i') }}</td><td>{{ $age }}</td>
                            <td>{{ str_replace('_', ' ', $inspection->status) }}</td>
                            <td>{{ $inspection->open_observation_count ? 'Intake observation' : '—' }}</td>
                            <td style="min-width:180px">
                                @if($canComplete && in_array($inspection->status, ['PENDING','ASSIGNED'], true))<button class="btn btn-default btn-xs" formaction="{{ route('recommerce.inspection.start', $inspection->device_id) }}" formmethod="post" formnovalidate>Start</button>@endif
                                @if($canComplete && in_array($inspection->status, ['PENDING','ASSIGNED','IN_INSPECTION'], true))
                                    <button class="btn btn-success btn-xs" formaction="{{ route('recommerce.inspection.complete', $inspection->device_id) }}" formmethod="post" formnovalidate name="outcome" value="PASS">Pass → Available</button>
                                    <button class="btn btn-warning btn-xs" formaction="{{ route('recommerce.inspection.complete', $inspection->device_id) }}" formmethod="post" formnovalidate name="outcome" value="FAIL">Fail</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canAssign ? 9 : 8 }}" class="text-muted">No Devices match this inspection view.</td></tr>
                    @endforelse
                </tbody></table>
            </div>
        </div>
    </form>
</section>
@endsection
