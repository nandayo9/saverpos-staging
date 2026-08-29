@extends('layouts.app')
@section('title', 'Walk-In Intelligence')

@section('content')
<section class="content-header">
    <h1>Walk-In Intelligence <small>Traffic, conversion and no-sale reasons</small></h1>
</section>

<section class="content">
    @if(session('status.msg'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <div class="box box-solid">
        <div class="box-body">
            <form method="get" class="row">
                <div class="col-md-3">
                    <label for="walkin_location_id">Branch</label>
                    <select name="location_id" id="walkin_location_id" class="form-control select2" @if(!$canViewAll) disabled @endif>
                        @if($canViewAll)<option value="">All branches</option>@endif
                        @foreach($locations as $id => $name)
                            <option value="{{ $id }}" @selected((string) $locationId === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @if(!$canViewAll)<input type="hidden" name="location_id" value="{{ $locationId }}">@endif
                </div>
                <div class="col-md-3"><label for="walkin_start">From</label><input id="walkin_start" class="form-control" type="date" name="start" value="{{ $start->toDateString() }}"></div>
                <div class="col-md-3"><label for="walkin_end">To</label><input id="walkin_end" class="form-control" type="date" name="end" value="{{ $end->toDateString() }}"></div>
                <div class="col-md-3" style="padding-top:25px">
                    <button class="btn btn-primary">Apply</button>
                    @foreach($datePresets as $preset)
                        <a class="btn btn-default" href="{{ route('walk-ins.index', ['location_id' => $locationId, 'start' => $preset['start'], 'end' => $preset['end']]) }}">{{ $preset['label'] }}</a>
                    @endforeach
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        @foreach(['Walk-Ins' => $summary['walk_ins'], 'Converted' => $summary['converted'], 'No Sale' => $summary['no_sale'], 'Open / Unresolved' => $summary['open'], 'Conversion Rate' => number_format($summary['conversion_rate'], 1).'%'] as $label => $value)
            <div class="col-md-2 col-sm-4 col-xs-6"><div class="small-box bg-aqua"><div class="inner"><p>{{ $label }}</p><h3>{{ $value }}</h3></div></div></div>
        @endforeach
        <div class="col-md-2 col-sm-4 col-xs-6"><div class="small-box bg-green"><div class="inner"><p>Attributed Revenue</p><h3>@format_currency($summary['revenue'])</h3></div></div></div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">No-Sale Reasons</h3></div>
                <div class="box-body table-responsive"><table class="table table-striped"><thead><tr><th>Reason</th><th>Type</th><th>Count</th><th>% classified</th></tr></thead><tbody>
                    @forelse($reasons as $reason)<tr><td>{{ $reason['label'] }}</td><td>{{ $reason['kind'] === 'OPPORTUNITY' ? 'Purchase opportunity' : 'Non-sales / other' }}</td><td>{{ $reason['total'] }}</td><td>{{ number_format($reason['percentage'], 1) }}%</td></tr>
                    @empty<tr><td colspan="4" class="text-muted">No classified no-sales in this period.</td></tr>@endforelse
                </tbody></table></div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Walk-Ins</h3><span class="pull-right text-muted">Open records are deliberately not auto-classified.</span></div>
                <div class="box-body table-responsive"><table class="table table-striped"><thead><tr><th>ID</th><th>Arrived</th><th>Branch</th><th>Recorded by</th><th>Outcome</th><th>POS sale</th><th>Action</th></tr></thead><tbody>
                    @forelse($walkIns as $walkIn)
                        <tr><td>#{{ $walkIn->id }}</td><td>{{ optional($walkIn->arrived_at)->format('d M H:i') }}</td><td>{{ optional($walkIn->location)->name }}</td><td>{{ optional($walkIn->recorder)->first_name }}</td><td>{{ str_replace('_', ' ', $walkIn->status) }}@if($walkIn->no_sale_reason)<br><small>{{ config('walkin.reasons.'.$walkIn->no_sale_reason.'.label', $walkIn->no_sale_reason) }}</small>@endif</td><td>{{ optional($walkIn->transaction)->invoice_no ?: '—' }}</td>
                        <td>@if($walkIn->status === \App\WalkIn::STATUS_OPEN && auth()->user()->can('walkin.close'))<form method="post" action="{{ route('walk-ins.close', $walkIn) }}" class="form-inline">@csrf<input type="hidden" name="location_id" value="{{ $locationId }}"><input type="hidden" name="start" value="{{ $start->toDateString() }}"><input type="hidden" name="end" value="{{ $end->toDateString() }}"><label class="sr-only" for="walkin_no_sale_reason_{{ $walkIn->id }}">No-sale reason for walk-in #{{ $walkIn->id }}</label><select name="no_sale_reason" id="walkin_no_sale_reason_{{ $walkIn->id }}" class="form-control input-sm" required><option value="">No sale reason…</option>@foreach(config('walkin.reasons') as $code => $definition)<option value="{{ $code }}">{{ $definition['label'] }}</option>@endforeach</select> <button class="btn btn-warning btn-sm">Close</button></form>@endif</td></tr>
                    @empty<tr><td colspan="7" class="text-muted">No walk-ins for this period.</td></tr>@endforelse
                </tbody></table></div>
            </div>
        </div>
    </div>
</section>
@endsection
