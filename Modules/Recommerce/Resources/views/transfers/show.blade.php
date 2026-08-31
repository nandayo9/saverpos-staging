@extends('layouts.app')

@section('title', 'Device transfer')

@section('content')
<section class="container-fluid" id="recommerce-device-transfer">
    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ url('/stock-transfers') }}">All transfers</a></div>
            <h3 class="box-title">{{ $sell->ref_no ?: 'Transfer '.$sell->id }}</h3>
            <p class="text-muted" style="margin:6px 0 0">Source #{{ $sell->location_id }} → Destination #{{ $purchase->location_id }} · {{ $sell->status === 'final' ? 'Complete' : ucwords(str_replace('_', ' ', $sell->status)) }}</p>
        </div>
        <div class="box-body">
            @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
            @php($expected = (int) $tracked->sum('quantity'))
            @php($selected = $assignments->whereIn('status', ['RESERVED', 'IN_TRANSIT', 'RECEIVED', 'RECEIVED_WITH_ISSUE', 'COMPLETED'])->count())
            @php($received = $assignments->whereIn('status', ['RECEIVED', 'RECEIVED_WITH_ISSUE', 'COMPLETED'])->count())
            <div class="row">
                <div class="col-sm-4"><div class="well well-sm"><strong>Devices selected</strong><br>{{ $selected }} / {{ $expected }}</div></div>
                <div class="col-sm-4"><div class="well well-sm"><strong>Received</strong><br>{{ $received }} / {{ $expected }}</div></div>
                <div class="col-sm-4"><div class="well well-sm"><strong>Remaining</strong><br>{{ max(0, $expected - $received) }}</div></div>
            </div>

            @if($sell->status === 'pending')
                <h4>Scan devices to send</h4>
                <p class="text-muted">Scan a permanent SAVERBRO QR. Device ID is the fallback.</p>
                <form method="post" action="{{ route('recommerce.transfers.select', $sell->id) }}" class="form-inline well well-sm">@csrf
                    <label class="sr-only" for="transfer-select-scan">SAVERBRO QR or Device ID</label><input autofocus id="transfer-select-scan" class="form-control" name="scan_value" placeholder="Scan SAVERBRO QR or enter Device ID" required>
                    <button class="btn btn-primary" type="submit">Add Device</button>
                </form>
                @if($selected === $expected && $expected > 0)
                    <form method="post" action="{{ route('recommerce.transfers.dispatch', $sell->id) }}">@csrf<button class="btn btn-success" type="submit">Review &amp; Send Transfer</button></form>
                @endif
            @elseif($sell->status === 'in_transit')
                <h4>Incoming transfer</h4>
                <p class="text-muted">Scan each Device while unpacking. A received Device remains unavailable for sale until the native transfer completes.</p>
                <form method="post" action="{{ route('recommerce.transfers.receive', $sell->id) }}" class="well well-sm">@csrf
                    <div class="form-group"><label for="transfer-receive-scan">Scan received Device</label><input autofocus id="transfer-receive-scan" class="form-control" name="scan_value" placeholder="Scan SAVERBRO QR or enter Device ID" required></div>
                    <div class="form-group"><label for="transfer-condition">Condition</label><select id="transfer-condition" class="form-control" name="condition"><option value="NORMAL">Received normally</option><option value="DAMAGED">Received with issue</option></select></div>
                    <div class="form-group"><label class="sr-only" for="transfer-note">Issue note</label><input id="transfer-note" class="form-control" name="note" placeholder="Issue note, if applicable"></div>
                    <button class="btn btn-primary" type="submit">Receive Device</button>
                </form>
                @if($received === $expected && $expected > 0 && $assignments->where('status', 'RECEIVED_WITH_ISSUE')->isEmpty())
                    <form method="post" action="{{ route('recommerce.transfers.complete', $sell->id) }}">@csrf<button class="btn btn-success" type="submit">Complete Transfer</button></form>
                @elseif($assignments->where('status', 'RECEIVED_WITH_ISSUE')->isNotEmpty())
                    <p class="alert alert-warning">One or more Devices arrived with an issue. Keep this transfer open and use the established exception process before aggregate completion.</p>
                @endif
            @endif

            <h4>Exact Devices</h4>
            <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Device</th><th>Product</th><th>State</th><th>Receipt</th></tr></thead><tbody>
            @forelse($assignments as $assignment)<tr><td><strong>{{ optional($assignment->device)->device_code ?: 'Unavailable' }}</strong></td><td>{{ optional(optional($assignment->device)->product)->name ?: 'Product unavailable' }}</td><td>{{ ucwords(strtolower(str_replace('_', ' ', $assignment->status))) }}</td><td>{{ $assignment->received_at ? $assignment->received_at->format('d M Y H:i') : '—' }}</td></tr>
            @empty<tr><td colspan="4" class="text-muted">No tracked Devices selected yet. Ordinary stock continues through UltimatePOS normally.</td></tr>@endforelse
            </tbody></table></div>
        </div>
    </div>
</section>
@endsection
