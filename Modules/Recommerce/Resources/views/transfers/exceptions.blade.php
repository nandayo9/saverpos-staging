@extends('layouts.app')

@section('title', 'Transfer receiving exceptions')

@section('content')
<section class="container" id="recommerce-transfer-exceptions">
    <div class="box box-warning">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Operations overview</a></div>
            <h3 class="box-title">Transfer receiving exceptions</h3>
            <p class="text-muted" style="margin:6px 0 0">{{ $sellTransfer->ref_no ?: 'Transfer '.$sellTransfer->id }} · {{ $locations[$sellTransfer->location_id] ?? $sellTransfer->location_id }} → {{ $locations[$purchaseTransfer->location_id] ?? $purchaseTransfer->location_id }} · {{ $sellTransfer->status }}</p>
        </div>
        <div class="box-body">
            @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
            <h4>Expected manifest</h4>
            <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Device code</th><th>Product variation</th><th>Assignment</th></tr></thead><tbody>
                @foreach($assignments as $assignment)
                    @php($device = $devices->get($assignment->device_id))
                    <tr><td><code>{{ $device?->device_code ?: 'Unavailable' }}</code></td><td>{{ $device?->variation_id ?: '—' }}</td><td>{{ $assignment->status }}</td></tr>
                @endforeach
            </tbody></table></div>

            <h4>Receiving scan</h4>
            <form method="post" action="{{ route('recommerce.transfers.exceptions.receive', $sellTransfer->id) }}" class="well well-sm">
                @csrf
                <label for="scanned-codes">Scan device codes (one per line)</label>
                <textarea id="scanned-codes" name="scanned_codes" class="form-control" rows="4" required></textarea>
                <label for="evidence-note" style="margin-top:8px">Evidence note (optional)</label>
                <textarea id="evidence-note" name="evidence_note" class="form-control" rows="2"></textarea>
                <button class="btn btn-primary" type="submit" style="margin-top:8px">Record receiving scan</button>
            </form>

            <h4>Exceptions</h4>
            <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Type</th><th>Expected</th><th>Observed</th><th>Status</th><th>Resolution</th></tr></thead><tbody>
                @forelse($exceptions as $exception)
                    <tr><td>{{ $exception->exception_type }}</td><td>{{ optional($devices->get($exception->expected_device_id))->device_code ?: '—' }}</td><td>{{ optional($devices->get($exception->observed_device_id))->device_code ?: ($exception->observed_device_code_hint ? '…'.$exception->observed_device_code_hint : '—') }}</td><td>{{ $exception->status }}</td><td>@if($exception->status === 'OPEN')<form method="post" action="{{ route('recommerce.transfers.exceptions.resolve', $exception->id) }}" class="form-inline">@csrf<input name="resolution_note" class="form-control input-sm" placeholder="Correction / return reference" required><button class="btn btn-default btn-sm" type="submit">Resolve</button></form>@else{{ $exception->resolution_note ?: 'Resolved' }}@endif</td></tr>
                @empty
                    <tr><td colspan="5" class="text-muted">No receiving exceptions recorded.</td></tr>
                @endforelse
            </tbody></table></div>
            <p class="text-muted">Open exceptions block tracked transfer completion. Resolving an exception records managerial evidence only; it does not move stock or change Device custody.</p>
        </div>
    </div>
</section>
@endsection
