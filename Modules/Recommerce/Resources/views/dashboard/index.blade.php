@extends('layouts.app')

@section('title', 'SAVERPOS Recommerce Operations')

@section('content')
<style>
    .sb-ops { max-width: 1280px; margin: 0 auto; }
    .sb-ops .box { border-radius: 10px; box-shadow: 0 4px 16px rgba(15, 23, 42, .06); }
    .sb-ops-card { min-height: 145px; }
    .sb-ops-card .metric { display:block; font-size: 28px; font-weight: 700; color: var(--sb-text, #1f2937); margin: 8px 0 3px; }
    .sb-ops-card .text-muted { min-height: 36px; }
    .sb-ops .box-primary,
    .sb-ops .box-warning,
    .sb-ops .box-success,
    .sb-ops .box-default { border-top-width: 3px; }
</style>
<section class="container-fluid sb-ops" aria-labelledby="recommerce-operations-title">
    <div class="row">
        <div class="col-sm-8">
            <div class="text-muted" style="letter-spacing:.08em;text-transform:uppercase;font-size:11px;font-weight:700">SAVERPOS · Recommerce</div>
            <h1 id="recommerce-operations-title" style="margin-top:6px">Device Pipeline</h1>
            <p class="text-muted">Follow business-owned Devices from receiving through inspection, refurbishment, QC, and ready-for-sale status at this branch.</p>
        </div>
        <div class="col-sm-4 text-right" style="margin: 5px 0 18px">
            @can('purchase.create')<a class="btn btn-primary" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'create']) }}"><i class="fa fa-plus"></i> New stock purchase</a>@endcan
            @if ($canReceive)<a class="btn btn-default" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}">Receive Devices</a>@endif
        </div>
    </div>

    <div class="row">
        @if ($canViewDevices)
        <div class="col-sm-4"><div class="box box-primary sb-ops-card"><div class="box-body"><strong>Received today</strong><span class="metric">{{ (int) optional($deviceCounts)->received_today }}</span><p class="text-muted">{{ (int) optional($deviceCounts)->awaiting_inspection }} awaiting inspection</p><a href="{{ route('recommerce.inspection.index') }}">Open inspection queue <i class="fa fa-arrow-right"></i></a></div></div></div>
        @endif
        @if ($canViewRepairs)
        <div class="col-sm-4"><div class="box box-warning sb-ops-card"><div class="box-body"><strong>Repair required</strong><span class="metric">{{ (int) optional($deviceCounts)->repair_required }}</span><p class="text-muted">{{ $repairJobs->where('job_type', 'INTERNAL_REFURBISHMENT')->count() }} active refurbishment job(s)</p><a href="{{ route('recommerce.repair.index') }}">Open refurbishment <i class="fa fa-arrow-right"></i></a></div></div></div>
        @endif
        @if ($canReconcile)
        <div class="col-sm-4"><div class="box box-success sb-ops-card"><div class="box-body"><strong>Ready for sale</strong><span class="metric">{{ (int) optional($deviceCounts)->ready_for_sale }}</span><p class="text-muted">{{ (int) optional($deviceCounts)->reserved }} reserved · {{ $repairJobs->where('state', 'QC')->count() }} awaiting QC</p><a href="{{ route('recommerce.devices.index') }}">Open Devices <i class="fa fa-arrow-right"></i></a></div></div></div>
        @endif
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="box box-default"><div class="box-header with-border"><h3 class="box-title">Internal refurbishment queue</h3></div><div class="box-body">
                @if (! $canViewRepairs)<p class="text-muted">Internal refurbishment is unavailable for the current permission scope.</p>
                @elseif ($repairJobs->where('job_type', 'INTERNAL_REFURBISHMENT')->isEmpty())<p class="text-muted">No internal refurbishment jobs are visible in this location.</p>
                @else<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Job</th><th>Type</th><th>Device</th><th>State</th><th>Priority</th></tr></thead><tbody>@foreach ($repairJobs->where('job_type', 'INTERNAL_REFURBISHMENT') as $job)<tr><td><a href="{{ route('recommerce.repair.show', $job->job_code) }}">{{ $job->job_code }}</a></td><td>{{ str_replace('_', ' ', $job->job_type) }}</td><td>{{ optional($job->device)->device_code ?: 'Unavailable' }}</td><td>{{ str_replace('_', ' ', $job->state) }}</td><td>{{ $job->priority }}</td></tr>@endforeach</tbody></table></div>@endif
            </div></div>
        </div>
        <div class="col-md-5">
            <div class="box box-default"><div class="box-header with-border"><h3 class="box-title">Operator workflow</h3></div><div class="box-body">
                @can('purchase.create')<a class="btn btn-default btn-block" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'create']) }}">1. Purchase stock</a>@endcan
                @if ($canReceive)<a class="btn btn-default btn-block" href="{{ action([\App\Http\Controllers\PurchaseController::class, 'index']) }}">2. Receive Devices</a>@endif
                @if ($canViewDevices)<a class="btn btn-default btn-block" href="{{ route('recommerce.devices.index') }}">3. Inspect Devices and print labels</a>@endif
                @if ($canRepairIntake)<a class="btn btn-default btn-block" href="{{ route('recommerce.repair.internal.create') }}">4. Refurbishment</a>@endif
                @if ($canReconcile)<a class="btn btn-default btn-block" href="{{ route('recommerce.reconciliation.index') }}">5. Stock Check</a>@endif
            </div></div>
        </div>
    </div>
</section>
@endsection
