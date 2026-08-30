@extends('layouts.app')

@section('title', $customerWorkspace ? 'Customer Repairs' : 'Repair workbench')

@section('content')
@include('recommerce::partials.status-tones')
@php
    // Terminal states no longer compete for attention on the due-date column.
    $sbClosedStates = ['READY', 'COLLECTED', 'COMPLETED', 'CANCELLED', 'REJECTED'];
    $sbStatusTone = [
        'RECEIVED' => 'intake',
        'DIAGNOSING' => 'active',
        'IN_PROGRESS' => 'active',
        'QC' => 'active',
        'AWAITING_PARTS' => 'blocked',
        'AWAITING_APPROVAL' => 'blocked',
        'AWAITING_COLLECTION' => 'blocked',
        'READY' => 'done',
        'COLLECTED' => 'done',
        'COMPLETED' => 'done',
        'CANCELLED' => 'closed',
        'REJECTED' => 'closed',
    ];
    $sbToday = now()->startOfDay();
    $sbIsOpen = fn ($job) => ! in_array($job->state, $sbClosedStates, true);
    $sbOverdue = $jobs->filter(fn ($job) => $job->due_at && $sbIsOpen($job) && $job->due_at->lt($sbToday))->count();
    $sbDueToday = $jobs->filter(fn ($job) => $job->due_at && $sbIsOpen($job) && $job->due_at->isSameDay($sbToday))->count();
@endphp
<style>
    .sb-repair-list { max-width:1180px; margin:0 auto; }
    .sb-repair-list .box { border-radius:10px; border-top:3px solid var(--sb-accent,#4f46e5); box-shadow:0 5px 18px rgba(0,0,0,.28); }
    .sb-repair-list .box-title { color:var(--sb-text,#172033); font-weight:700; }
    .sb-repair-list .summary { display:flex; gap:18px; flex-wrap:wrap; color:var(--sb-muted,#58657a); }
    .sb-repair-list .summary strong { display:block; color:var(--sb-text,#172033); font-size:20px; }
    .sb-repair-list .summary .is-alert strong { color:var(--sb-danger,#b91c1c); }
    .sb-repair-list .summary .is-warn strong { color:var(--sb-warning,#92400e); }
    .sb-repair-list .empty-state { padding:26px 18px; text-align:center; }
    .sb-repair-list .empty-state h4 { color:var(--sb-text,#172033); font-weight:700; margin-bottom:6px; }
    .sb-repair-list .empty-state p { margin:0 0 14px; color:var(--sb-muted,#64748b); }
    .sb-repair-list .actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .sb-prio { font-size:12px; font-weight:700; letter-spacing:.02em; }
    .sb-prio-urgent { display:inline-block; background:#7f1d1d; color:#fecaca; border-radius:999px; padding:3px 9px; }
    .sb-prio-high { color:var(--sb-danger,#b91c1c); }
    .sb-prio-normal { color:var(--sb-muted,#475569); font-weight:600; }
    .sb-prio-low { color:var(--sb-muted,#64748b); font-weight:600; }
    .sb-due-overdue { color:var(--sb-danger,#b91c1c); font-weight:700; }
    .sb-due-today { color:var(--sb-warning,#92400e); font-weight:700; }
    .sb-due-flag { display:block; font-size:11px; font-weight:700; letter-spacing:.03em; }

    @media (max-width:767px) {
        /* Keep the heading first: the action buttons precede it in source so
           they can float right on desktop. */
        .sb-repair-list .box-header { display:flex; flex-direction:column; }
        .sb-repair-list .box-header .box-title { order:1; }
        .sb-repair-list .box-header .sb-subtitle { order:2; }
        .sb-repair-list .box-header .actions { order:3; float:none; margin-top:12px; }
        .sb-repair-list .summary { gap:14px; }
        .sb-repair-list .summary strong { font-size:18px; }

        /* Stack each row into a labelled card so status, priority and due date
           stay on screen instead of scrolling out of the viewport. */
        .sb-repair-list table.sb-jobs, .sb-repair-list table.sb-jobs tbody, .sb-repair-list table.sb-jobs tr, .sb-repair-list table.sb-jobs td { display:block; width:100%; }
        .sb-repair-list table.sb-jobs thead { display:none; }
        .sb-repair-list table.sb-jobs tr { border:1px solid var(--sb-border,#e2e8f0); border-radius:9px; padding:10px 12px; margin-bottom:10px; }
        .sb-repair-list table.sb-jobs td { border:0; padding:3px 0; display:flex; gap:10px; align-items:baseline; justify-content:space-between; }
        .sb-repair-list table.sb-jobs td::before { content:attr(data-label); color:var(--sb-muted,#64748b); font-size:12px; font-weight:600; flex:0 0 auto; }
        .sb-repair-list table.sb-jobs td[data-label="Repair code"] { padding-bottom:7px; margin-bottom:5px; border-bottom:1px solid var(--sb-border,#eef2f7); }
    }
</style>
<section class="container-fluid sb-repair-list" aria-labelledby="repair-workbench-title">
    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="pull-right actions"><a class="btn btn-default" href="{{ route('recommerce.dashboard') }}">Stock &amp; device operations</a> @if ($intakeEnabled && ! $customerWorkspace)<a class="btn btn-default" href="{{ route('recommerce.repair.internal.create') }}">Internal refurbishment</a>@endif @if ($intakeEnabled)<a class="btn btn-primary" href="{{ route('recommerce.repair.new') }}"><i class="fa fa-plus"></i> New customer repair</a>@endif</div>
            <h3 id="repair-workbench-title" class="box-title">{{ $customerWorkspace ? 'Customer Repairs' : 'Repair workbench' }}</h3>
            <p class="text-muted sb-subtitle" style="margin:6px 0 0">{{ $customerWorkspace ? 'Counter intake, customer-owned devices, and repair updates' : 'Customer repairs and business-owned refurbishment work' }} · Location {{ $locationId }}</p>
        </div>
        <div class="box-body">
            @unless ($intakeEnabled)
                <div class="alert alert-info" role="status">Read-only mode. Repair intake and transitions are disabled until the write gate is approved.</div>
            @endunless
            @if ($jobs->isEmpty())
                <div class="empty-state">
                    <h4>{{ $customerWorkspace ? 'No customer repairs yet' : 'No repair jobs yet' }}</h4>
                    <p>{{ $customerWorkspace ? 'Customer repairs appear here as soon as a device is handed in at this counter.' : 'Repair jobs appear here once intake or internal refurbishment work starts in this location.' }}</p>
                    @if ($intakeEnabled)
                        <a class="btn btn-primary" href="{{ route('recommerce.repair.new') }}"><i class="fa fa-plus"></i> New customer repair</a>
                    @endif
                </div>
            @else
                <div class="summary" aria-label="Repair summary">
                    <div><strong>{{ $jobs->count() }}</strong> {{ $customerWorkspace ? 'customer repairs' : 'visible jobs' }}</div>
                    <div class="{{ $sbOverdue ? 'is-alert' : '' }}"><strong>{{ $sbOverdue }}</strong> overdue</div>
                    <div class="{{ $sbDueToday ? 'is-warn' : '' }}"><strong>{{ $sbDueToday }}</strong> due today</div>
                    <div><strong>{{ $jobs->where('state', 'READY')->count() }}</strong> ready</div>
                </div>
                <hr>
                <div class="table-responsive">
                    <table class="table table-hover sb-jobs">
                        <caption class="sr-only">{{ $customerWorkspace ? 'Customer repair jobs' : 'Repair jobs' }} visible in this location</caption>
                        <thead><tr><th>Repair code</th><th>Customer</th><th>Device</th><th>Status</th><th>Priority</th><th>Due</th></tr></thead>
                        <tbody>
                        @foreach ($jobs as $job)
                            @php
                                $tone = $sbStatusTone[$job->state] ?? 'closed';
                                $priority = strtoupper((string) $job->priority);
                                $priorityClass = match ($priority) {
                                    'URGENT' => 'sb-prio-urgent',
                                    'HIGH' => 'sb-prio-high',
                                    'LOW' => 'sb-prio-low',
                                    default => 'sb-prio-normal',
                                };
                                $open = $sbIsOpen($job);
                                $isOverdue = $job->due_at && $open && $job->due_at->lt($sbToday);
                                $isDueToday = $job->due_at && $open && $job->due_at->isSameDay($sbToday);
                            @endphp
                            <tr>
                                <td data-label="Repair code"><a href="{{ route('recommerce.repair.show', $job->job_code) }}"><strong>{{ $job->job_code }}</strong></a></td>
                                <td data-label="Customer">{{ optional($job->contact)->name ?: 'Customer unavailable' }}</td>
                                <td data-label="Device">{{ $job->device->device_code }}<br><small class="text-muted">{{ data_get($job->device->specifications_json, 'brand') }} {{ data_get($job->device->specifications_json, 'model') }}</small></td>
                                <td data-label="Status"><span class="sb-status sb-status-{{ $tone }}">{{ str_replace('_', ' ', $job->state) }}</span></td>
                                <td data-label="Priority"><span class="sb-prio {{ $priorityClass }}">{{ $priority ?: '—' }}</span></td>
                                <td data-label="Due" class="{{ $isOverdue ? 'sb-due-overdue' : ($isDueToday ? 'sb-due-today' : '') }}">
                                    @if ($job->due_at)
                                        <span>{{ $job->due_at->format('d M Y') }}@if ($isOverdue)<span class="sb-due-flag">Overdue</span>@elseif ($isDueToday)<span class="sb-due-flag">Due today</span>@endif</span>
                                    @else
                                        <span>—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
@endsection
