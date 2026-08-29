@extends('layouts.app')

@section('title', 'New internal refurbishment')

@section('content')
<section class="container" id="internal-refurbishment-intake" data-csrf-token="{{ csrf_token() }}" data-intake-url="{{ route('recommerce.repair.intake') }}" aria-labelledby="internal-refurbishment-title">
    <div class="box box-primary">
        <div class="box-header with-border">
            <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.repair.index') }}">Back to Repair</a></div>
            <h3 id="internal-refurbishment-title" class="box-title">New internal refurbishment</h3>
            <p class="text-muted" style="margin:6px 0 0">Open controlled work for a business-owned Device in location {{ $locationId }}. Parts remain in the POS stock-adjustment path.</p>
        </div>
        <div class="box-body">
            <div class="alert alert-info">This intake does not change stock or ownership. Record the diagnosis, parts, and completion evidence from the repair record after the job is created.</div>
            <form id="internal-refurbishment-form" novalidate>
                <div class="form-group"><label for="internal-device">Business-owned Device <span class="text-danger">*</span></label><select id="internal-device" class="form-control" required><option value="">Choose a Device</option>@foreach ($devices as $device)<option value="{{ $device->id }}">{{ $device->device_code }} · {{ optional($device->product)->name ?: 'Product unavailable' }} · {{ $device->lifecycle_state }}</option>@endforeach</select><p class="help-block">Only business-owned, stock-participating Devices in this location are available.</p></div>
                <div class="row"><div class="col-sm-4 form-group"><label for="internal-priority">Priority</label><select id="internal-priority" class="form-control"><option value="NORMAL">Normal</option><option value="LOW">Low</option><option value="HIGH">High</option><option value="URGENT">Urgent</option></select></div><div class="col-sm-4 form-group"><label for="internal-due">Target completion</label><input id="internal-due" type="date" class="form-control"></div><div class="col-sm-4 form-group"><label for="internal-technician">Assigned technician</label><select id="internal-technician" class="form-control"><option value="">Assign later</option>@foreach ($technicians as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div></div>
                <div class="form-group"><label for="internal-summary">Work summary</label><textarea id="internal-summary" class="form-control" rows="4" maxlength="3000" placeholder="For example: inspect, clean, replace battery, run diagnostics."></textarea></div>
                <div id="internal-result" class="alert" style="display:none" role="status" aria-live="polite"></div>
                <button id="internal-submit" class="btn btn-primary" type="submit">Create internal refurbishment</button>
            </form>
        </div>
    </div>
</section>
<script>
(() => {
    const root = document.getElementById('internal-refurbishment-intake');
    const form = document.getElementById('internal-refurbishment-form');
    const result = document.getElementById('internal-result');
    const uuid = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
    const message = (text, kind) => { result.textContent = text; result.className = `alert alert-${kind}`; result.style.display = 'block'; };
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const deviceId = Number(document.getElementById('internal-device').value);
        if (!deviceId) { message('Choose a business-owned Device before creating refurbishment work.', 'warning'); return; }
        const submit = document.getElementById('internal-submit'); submit.disabled = true;
        try {
            const response = await fetch(root.dataset.intakeUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, body: JSON.stringify({ command_uuid: uuid(), location_id: {{ (int) $locationId }}, device_id: deviceId, job_type: 'INTERNAL_REFURBISHMENT', priority: document.getElementById('internal-priority').value, due_at: document.getElementById('internal-due').value || null, assigned_to: document.getElementById('internal-technician').value ? Number(document.getElementById('internal-technician').value) : null, intake_snapshot_json: { source: 'internal_refurbishment_workbench', work_summary: document.getElementById('internal-summary').value.trim() || null } }) });
            const data = await response.json();
            if (!response.ok || !data.job || !data.job.job_code) throw new Error(data.message || 'Internal refurbishment was not created.');
            message(`Internal refurbishment ${data.job.job_code} created in ${data.job.state}.`, 'success');
            const link = document.createElement('a'); link.href = `{{ url('/recommerce/repair') }}/${encodeURIComponent(data.job.job_code)}`; link.className = 'btn btn-success btn-sm'; link.style.marginLeft = '10px'; link.textContent = 'Open repair record'; result.appendChild(link);
            form.reset();
        } catch (error) { message(error.message || 'Internal refurbishment was not created.', 'warning'); }
        finally { submit.disabled = false; }
    });
})();
</script>
@endsection
