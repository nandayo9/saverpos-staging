@extends('layouts.app')

@section('title', 'Diagnostics '.$job->job_code)

@section('content')
    <section class="container" id="recommerce-diagnostics" data-csrf-token="{{ csrf_token() }}">
        <div class="row">
            <div class="col-md-9">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Device diagnostics</h3>
                        <p class="text-muted" style="margin:6px 0 0">{{ $job->job_code }} · {{ $job->device->device_code }} · {{ $job->state }}</p>
                    </div>
                    <div class="box-body">
                        @if (! $canSubmit)
                            <div class="alert alert-info" role="status">Read-only mode. Diagnostic submission is disabled until the write gate is approved.</div>
                        @elseif ($diagnosticSession && $diagnosticSession->status === 'SUBMITTED')
                            <div class="alert alert-success" role="status">Diagnostic session submitted. The recorded template and observations are immutable.</div>
                        @endif

                        @if (! $diagnosticSession)
                            <h4>Start a diagnostic session</h4>
                            @if ($templates->isEmpty())
                                <p class="text-muted">No published diagnostic template matches this Repair job.</p>
                            @elseif ($canSubmit)
                                <form id="diagnostic-start-form">
                                    <div class="form-group">
                                        <label for="diagnostic-template">Published template</label>
                                        <select id="diagnostic-template" class="form-control" required>
                                            @foreach ($templates as $template)
                                                <option value="{{ $template->id }}">{{ $template->template->name }} · v{{ $template->version_number }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <button class="btn btn-primary" type="submit">Start diagnostic session</button>
                                </form>
                            @endif
                        @elseif ($diagnosticSession->status === 'DRAFT')
                            <h4>{{ $diagnosticSession->templateVersion->template->name }} · v{{ $diagnosticSession->templateVersion->version_number }}</h4>
                            @if ($canSubmit)
                                <form id="diagnostic-submit-form">
                                    @foreach ($diagnosticSession->templateVersion->checks as $check)
                                        <div class="well well-sm diagnostic-check" data-check-key="{{ $check->check_key }}" data-outcome-type="{{ $check->outcome_type }}" data-evidence-required="{{ $check->evidence_required ? '1' : '0' }}">
                                            <label for="diagnostic-outcome-{{ $check->check_key }}">{{ $check->label }} @if ($check->is_required)<span class="text-danger">*</span>@endif</label>
                                            <select id="diagnostic-outcome-{{ $check->check_key }}" class="form-control diagnostic-outcome" required>
                                                @foreach (($check->allowed_outcomes_json ?: ['PASS', 'FAIL', 'NOT_TESTED', 'NOT_APPLICABLE']) as $outcome)
                                                    <option value="{{ $outcome }}">{{ $outcome }}</option>
                                                @endforeach
                                            </select>
                                            @if ($check->outcome_type === 'NUMERIC')
                                                <label class="sr-only" for="dx-value-{{ $check->check_key }}">Measured value for {{ $check->label }}</label><input id="dx-value-{{ $check->check_key }}" class="form-control diagnostic-value-numeric" style="margin-top:8px" type="number" step="any" @if ($check->minimum_value !== null) min="{{ $check->minimum_value }}" @endif @if ($check->maximum_value !== null) max="{{ $check->maximum_value }}" @endif placeholder="Measured value{{ $check->unit ? ' ('.$check->unit.')' : '' }}">
                                            @else
                                                <label class="sr-only" for="dx-value-{{ $check->check_key }}">Observed value or note for {{ $check->label }}</label><input id="dx-value-{{ $check->check_key }}" class="form-control diagnostic-value-text" style="margin-top:8px" type="text" placeholder="Observed value or note">
                                            @endif
                                            <label class="sr-only" for="dx-notes-{{ $check->check_key }}">Technician notes for {{ $check->label }}</label><textarea id="dx-notes-{{ $check->check_key }}" class="form-control diagnostic-notes" style="margin-top:8px" rows="2" placeholder="Technician notes"></textarea>
                                            @if ($check->evidence_required)
                                                <label class="sr-only" for="dx-evidence-{{ $check->check_key }}">Evidence reference for {{ $check->label }}</label><input id="dx-evidence-{{ $check->check_key }}" class="form-control diagnostic-evidence" style="margin-top:8px" type="text" placeholder="Evidence reference (required)">
                                            @endif
                                        </div>
                                    @endforeach
                                    <div class="form-group">
                                        <label for="diagnostic-grade">Grade</label>
                                        <input id="diagnostic-grade" class="form-control" maxlength="40" required placeholder="Example: A">
                                    </div>
                                    <div class="form-group">
                                        <label for="diagnostic-override">Grade override reason <span class="text-muted">(optional)</span></label>
                                        <input id="diagnostic-override" class="form-control" maxlength="255">
                                    </div>
                                    <button class="btn btn-primary" type="submit">Submit diagnostic evidence</button>
                                </form>
                            @else
                                <p class="text-muted">This draft session can be viewed but not submitted with the current permission scope.</p>
                            @endif
                        @else
                            <h4>Submitted grade: {{ $diagnosticSession->grade_code }}</h4>
                            @php
                                // The session captured the template it was filled against, so the
                                // review reads its labels, units and order from that snapshot rather
                                // than the live template or the raw keys. A published template can be
                                // retired or superseded; this page promises what the technician saw.
                                $snapshotChecks = collect(data_get($diagnosticSession->template_snapshot_json, 'checks', []))
                                    ->keyBy('check_key');
                            @endphp
                            @foreach ($diagnosticSession->observations->sortBy(fn ($observation) => sprintf(
                                '%08d-%s',
                                (int) data_get($snapshotChecks->get($observation->check_key), 'sort_order', 99999999),
                                $observation->check_key
                            )) as $observation)
                                @php $check = $snapshotChecks->get($observation->check_key); @endphp
                                <div class="well well-sm"><strong>{{ data_get($check, 'label') ?: $observation->check_key }}</strong> · {{ $observation->outcome }} @if ($observation->value_numeric !== null) · {{ $observation->value_numeric }}{{ data_get($check, 'unit') ? ' '.data_get($check, 'unit') : '' }} @endif @if ($observation->value_text) · {{ $observation->value_text }} @endif</div>
                            @endforeach
                        @endif
                        <div id="diagnostic-result" class="alert" style="display:none;margin-top:12px" role="status"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="box box-default">
                    <div class="box-header with-border"><h3 class="box-title">Diagnostic boundary</h3></div>
                    <div class="box-body">
                        <p><span class="label label-info">Versioned</span> Published templates are captured in the session snapshot.</p>
                        <p><span class="label label-success">Structured</span> Required checks need an explicit outcome.</p>
                        <p><span class="label label-warning">Evidence</span> Required evidence is checked before submission.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if (! $diagnosticSession && $canSubmit && $templates->isNotEmpty())
        <script>
            (function () {
                const root = document.getElementById('recommerce-diagnostics');
                const form = document.getElementById('diagnostic-start-form');
                const result = document.getElementById('diagnostic-result');
                form.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    const button = form.querySelector('button[type="submit"]');
                    button.disabled = true;
                    try {
                        const response = await fetch('{{ route('recommerce.repair.diagnostics.start', $job->job_code) }}', { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, credentials: 'same-origin', body: JSON.stringify({ template_version_id: Number(document.getElementById('diagnostic-template').value) }) });
                        const data = await response.json().catch(function () { return {}; });
                        if (!response.ok) throw new Error(data.message || 'Diagnostic session start was rejected.');
                        window.location.reload();
                    } catch (error) {
                        result.textContent = error.message || 'Diagnostic session start was rejected.';
                        result.className = 'alert alert-warning';
                        result.style.display = 'block';
                        button.disabled = false;
                    }
                });
            }());
        </script>
    @elseif ($diagnosticSession && $diagnosticSession->status === 'DRAFT' && $canSubmit)
        <script>
            (function () {
                const root = document.getElementById('recommerce-diagnostics');
                const form = document.getElementById('diagnostic-submit-form');
                const result = document.getElementById('diagnostic-result');
                form.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    const button = form.querySelector('button[type="submit"]');
                    button.disabled = true;
                    const observations = Array.from(form.querySelectorAll('.diagnostic-check')).map(function (row) {
                        const numeric = row.querySelector('.diagnostic-value-numeric');
                        const text = row.querySelector('.diagnostic-value-text');
                        const evidence = row.querySelector('.diagnostic-evidence');
                        const evidenceText = evidence ? evidence.value.trim() : '';
                        return { check_key: row.dataset.checkKey, outcome: row.querySelector('.diagnostic-outcome').value, value_numeric: numeric && numeric.value !== '' ? Number(numeric.value) : null, value_text: text ? text.value : null, notes: row.querySelector('.diagnostic-notes').value || null, evidence: evidenceText === '' ? null : { reference: evidenceText } };
                    });
                    try {
                        const response = await fetch('{{ route('recommerce.repair.diagnostics.submit', [$job->job_code, $diagnosticSession->id]) }}', { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, credentials: 'same-origin', body: JSON.stringify({ grade_code: document.getElementById('diagnostic-grade').value.trim(), override_reason: document.getElementById('diagnostic-override').value.trim() || null, observations: observations }) });
                        const data = await response.json().catch(function () { return {}; });
                        if (!response.ok) throw new Error(data.message || 'Diagnostic submission was rejected.');
                        window.location.reload();
                    } catch (error) {
                        result.textContent = error.message || 'Diagnostic submission was rejected.';
                        result.className = 'alert alert-warning';
                        result.style.display = 'block';
                        button.disabled = false;
                    }
                });
            }());
        </script>
    @endif
@endsection
