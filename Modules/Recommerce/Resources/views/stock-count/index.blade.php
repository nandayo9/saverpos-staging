@extends('layouts.app')
@section('title', 'Stock Count')
@section('content')
<section class="container" aria-labelledby="stock-count-title">
    <div class="box box-success"><div class="box-header with-border">
        <a class="btn btn-primary btn-sm pull-right" href="{{ route('recommerce.stock-counts.create', ['location_id' => $locationId]) }}">Create stock count</a>
        <h3 id="stock-count-title" class="box-title">Stock Count</h3>
        <p class="text-muted" style="margin:6px 0 0">Physical inventory proof. Starting snapshots remain immutable; UltimatePOS remains the aggregate-stock record.</p>
    </div><div class="box-body">
        @if($locations->count() > 1)<form method="get" class="form-inline" style="margin-bottom:12px"><label for="location">Branch</label> <select class="form-control" id="location" name="location_id">@foreach($locations as $id => $name)<option value="{{ $id }}" @selected((int)$id === $locationId)>{{ $name }}</option>@endforeach</select> <button class="btn btn-default">View</button></form>@endif
        <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Count</th><th>Type</th><th>Status</th><th>Progress</th><th>Exceptions</th><th>Started</th><th></th></tr></thead><tbody>
        @forelse($sessions as $session) @php($summary = $summaries[$session->id]) <tr><td>SC-{{ str_pad($session->id, 6, '0', STR_PAD_LEFT) }}</td><td>{{ $session->count_type === 'FULL_BRANCH' ? 'Full branch' : 'Cycle count' }}</td><td>{{ ucwords(strtolower(str_replace('_', ' ', $session->status))) }}</td><td>{{ $summary['serialized_counted'] }} / {{ $summary['serialized_expected'] }} serialized<br><small>{{ $summary['non_serialized_counted'] }} / {{ $summary['non_serialized_expected'] }} generic</small></td><td>{{ $summary['open_exceptions'] }} open</td><td>{{ $session->started_at?->format('d M Y H:i') ?: 'Not started' }}</td><td><a class="btn btn-default btn-xs" href="{{ route('recommerce.stock-counts.show', $session->id) }}">Open</a></td></tr> @empty <tr><td colspan="7" class="text-muted">No stock counts at this branch.</td></tr> @endforelse
        </tbody></table></div>
    </div></div>
</section>
@endsection
