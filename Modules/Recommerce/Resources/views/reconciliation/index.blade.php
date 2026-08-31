@extends('layouts.app')

@section('title', 'Recommerce reconciliation')

@section('content')
<section class="container" id="recommerce-reconciliation-index" data-location-id="{{ $locationId }}" aria-labelledby="recommerce-reconciliation-title">
    <div class="box box-success">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Operations overview</a></div>
            <h3 id="recommerce-reconciliation-title" class="box-title">Stock reconciliation</h3>
            <p class="text-muted" style="margin:6px 0 0">Compare Ultimate POS aggregate stock against tracked devices and approved persisted evidence. This page never adjusts stock.</p>
        </div>
        <div class="box-body">
            @if($locations->count() > 1)
                <form method="get" action="{{ route('recommerce.reconciliation.index') }}" class="form-inline" style="margin-bottom:12px">
                    <label for="reconciliation-location">Location</label>
                    <select id="reconciliation-location" class="form-control" name="location_id">
                        @foreach($locations as $id => $name)<option value="{{ $id }}" @selected((int) $id === (int) $locationId)>{{ $name }}</option>@endforeach
                    </select>
                    <button type="submit" class="btn btn-default">View location</button>
                </form>
            @endif
            <div class="alert alert-info" role="status">Select a tracked product configuration to run a read-only comparison for location {{ $locationId }}.</div>
            <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Product</th><th>Variation</th><th>Profile</th><th>Effective</th><th></th></tr></thead><tbody>
                @forelse ($profiles as $profile)
                    <tr><td>{{ optional($profile->product)->name ?: 'Product unavailable' }}</td><td>{{ optional($profile->variation)->name ?: $profile->variation_id }}</td><td>{{ $profile->mode }} · v{{ $profile->version }}</td><td>{{ $profile->effective_at ? $profile->effective_at->format('d M Y') : '—' }}</td><td><button class="btn btn-default btn-sm run-reconciliation" type="button" data-variation-id="{{ $profile->variation_id }}">Run check</button></td></tr>
                @empty
                    <tr><td colspan="5" class="text-muted">No approved Recommerce variation is configured for this location.</td></tr>
                @endforelse
            </tbody></table></div>
            <div id="reconciliation-result" class="alert" style="display:none" role="status" aria-live="polite"></div>
        </div>
    </div>
</section>
<script>
(() => {
    const root = document.getElementById('recommerce-reconciliation-index');
    const result = document.getElementById('reconciliation-result');
    const locationId = root.dataset.locationId;
    const show = (message, kind) => { result.textContent = message; result.className = `alert alert-${kind}`; result.style.display = 'block'; };
    document.querySelectorAll('.run-reconciliation').forEach((button) => button.addEventListener('click', async () => {
        button.disabled = true;
        show('Running read-only reconciliation…', 'info');
        try {
            const response = await fetch(`{{ url('/recommerce/reconciliation') }}/${encodeURIComponent(button.dataset.variationId)}?location_id=${encodeURIComponent(locationId)}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Reconciliation request was rejected.');
            show(`${data.status} · core ${data.core_quantity === null ? 'unavailable' : data.core_quantity} · tracked ${data.tracked_device_count} · legacy ${data.approved_legacy_balance === null ? 'unavailable' : data.approved_legacy_balance}. No stock was changed.`, data.status === 'PASS' ? 'success' : 'warning');
        } catch (error) { show(error.message || 'Reconciliation request was rejected.', 'warning'); }
        finally { button.disabled = false; }
    }));
})();
</script>
@endsection
