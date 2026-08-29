@extends('layouts.app')

@section('title', $customerWorkspace ? 'Customer Repairs' : 'Repair workbench')

@section('content')
<style>
    .sb-repair-list { max-width:1180px; margin:0 auto; }
    .sb-repair-list .box { border-radius:10px; border-top:3px solid #4f46e5; box-shadow:0 5px 18px rgba(15,23,42,.05); }
    .sb-repair-list .box-title { color:#172033; font-weight:700; }
    .sb-repair-list .summary { display:flex; gap:24px; flex-wrap:wrap; color:#64748b; }
    .sb-repair-list .summary strong { display:block; color:#172033; font-size:20px; }
    .sb-repair-list .label { border-radius:999px; padding:4px 9px; }
</style>
<section class="container-fluid sb-repair-list" aria-labelledby="repair-workbench-title">
    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default" href="{{ route('recommerce.dashboard') }}">Stock &amp; device operations</a> @if ($intakeEnabled && ! $customerWorkspace)<a class="btn btn-default" href="{{ route('recommerce.repair.internal.create') }}">Internal refurbishment</a>@endif @if ($intakeEnabled)<a class="btn btn-primary" href="{{ route('recommerce.repair.new') }}"><i class="fa fa-plus"></i> New customer repair</a>@endif</div>
            <h3 id="repair-workbench-title" class="box-title">{{ $customerWorkspace ? 'Customer Repairs' : 'Repair workbench' }}</h3>
            <p class="text-muted" style="margin:6px 0 0">{{ $customerWorkspace ? 'Counter intake, customer-owned devices, and repair updates' : 'Customer repairs and business-owned refurbishment work' }} · Location {{ $locationId }}</p>
        </div>
        <div class="box-body">
            @if ($intakeEnabled)
                <div class="alert alert-success" role="status"><strong>{{ $customerWorkspace ? 'Counter intake is ready.' : 'Repair intake is ready.' }}</strong> {{ $customerWorkspace ? 'Start a customer repair without entering stock. The flow searches device identity before creating a new no-stock device record.' : 'Customer-owned repairs remain separate from stock; internal refurbishment is reserved for business-owned Devices.' }}</div>
            @else
                <div class="alert alert-info" role="status">Read-only mode. Repair intake and transitions are disabled until the write gate is approved.</div>
            @endif
            <div class="summary" aria-label="Repair summary">
                <div><strong>{{ $jobs->count() }}</strong> {{ $customerWorkspace ? 'customer repairs' : 'visible jobs' }}</div>
                <div><strong>{{ $jobs->where('state', 'RECEIVED')->count() }}</strong> received</div>
                <div><strong>{{ $jobs->where('state', 'READY')->count() }}</strong> ready</div>
            </div>
            <hr>
            <div class="table-responsive">
                <table class="table table-hover">
                    <caption class="sr-only">{{ $customerWorkspace ? 'Customer repair jobs' : 'Repair jobs' }} visible in this location</caption>
                    <thead><tr><th>Repair code</th><th>Customer</th><th>Device</th><th>Status</th><th>Priority</th><th>Due</th></tr></thead>
                    <tbody>
                    @forelse ($jobs as $job)
                        <tr>
                            <td><a href="{{ route('recommerce.repair.show', $job->job_code) }}"><strong>{{ $job->job_code }}</strong></a></td>
                            <td>{{ optional($job->contact)->name ?: 'Customer unavailable' }}</td>
                            <td>{{ $job->device->device_code }}<br><small class="text-muted">{{ data_get($job->device->specifications_json, 'brand') }} {{ data_get($job->device->specifications_json, 'model') }}</small></td>
                            <td><span class="label label-primary">{{ str_replace('_', ' ', $job->state) }}</span></td>
                            <td>{{ $job->priority }}</td>
                            <td>{{ $job->due_at ? $job->due_at->format('d M Y') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">{{ $customerWorkspace ? 'No customer repairs are available in this location.' : 'No repair jobs are available in this location.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
@endsection
