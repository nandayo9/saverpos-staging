@extends('layouts.app')

@section('title', 'Trade-in acquisition')

@section('content')
    @php
        $money = static fn ($value) => number_format((float) $value, 2);
        $selectedDevice = old('device_id');
        $selectedVariation = old('variation_id');
        $statuses = [
            'READY_TO_ACCEPT' => 'success',
            'PENDING_APPROVAL' => 'warning',
            'APPROVED' => 'info',
            'ACCEPTED' => 'primary',
            'REJECTED' => 'default',
            'REVERSED' => 'danger',
        ];
    @endphp
    <section class="container" id="recommerce-trade-ins">
        <div class="row">
            <div class="col-md-9">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <div class="pull-right"><a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Operations overview</a></div>
                        <h3 class="box-title">Trade-in acquisition</h3>
                        <p class="text-muted" style="margin:6px 0 0">Record physical evidence and a reproducible valuation first. Acceptance posts exactly one native UltimatePOS purchase; payments remain in UltimatePOS.</p>
                    </div>
                    <div class="box-body">
                        @if (session('status'))
                            <div class="alert alert-{{ data_get(session('status'), 'success') ? 'success' : 'warning' }}" role="status">{{ data_get(session('status'), 'msg') }}</div>
                        @endif
                        @if ($variations->isEmpty())
                            <div class="alert alert-warning" role="status">No authorised mapped variation is available in this Recommerce cohort. Trade-ins cannot create catalogue products.</div>
                        @elseif ($ruleSets->isEmpty())
                            <div class="alert alert-info" role="status">Create an approved, versioned pricing rule before recording a valuation.</div>
                        @endif

                        <form method="post" action="{{ route('recommerce.tradeins.store') }}" autocomplete="off">
                            @csrf
                            <input type="hidden" name="command_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <h4>1. Identify the device and counterparties</h4>
                            <div class="row">
                                <div class="col-sm-4"><div class="form-group"><label for="tradein-device">Existing customer Device</label><select class="form-control" id="tradein-device" name="device_id" required><option value="">Choose customer Device</option>@foreach ($devices as $device)<option value="{{ $device->id }}" @selected((string) $selectedDevice === (string) $device->id)>{{ $device->device_code }} · {{ $device->brand }} {{ $device->model }} · {{ $device->serial_number ?: $device->imei ?: 'identifier withheld' }}</option>@endforeach</select><p class="help-block">No new identity record is created here.</p></div></div>
                                <div class="col-sm-4"><div class="form-group"><label for="tradein-customer">Customer / seller</label><select class="form-control" id="tradein-customer" name="customer_contact_id" required><option value="">Choose customer</option>@foreach ($customers as $contact)<option value="{{ $contact->id }}" @selected((string) old('customer_contact_id') === (string) $contact->id)>{{ $contact->name }} (#{{ $contact->id }})</option>@endforeach</select></div></div>
                                <div class="col-sm-4"><div class="form-group"><label for="tradein-supplier">Explicit supplier-capable payee</label><select class="form-control" id="tradein-supplier" name="supplier_contact_id" required><option value="">Choose supplier / both contact</option>@foreach ($suppliers as $contact)<option value="{{ $contact->id }}" @selected((string) old('supplier_contact_id') === (string) $contact->id)>{{ $contact->name }} (#{{ $contact->id }})</option>@endforeach</select><p class="help-block">The customer is never converted silently.</p></div></div>
                            </div>
                            <div class="row">
                                <div class="col-sm-6"><div class="form-group"><label for="tradein-variation">Existing UltimatePOS product / variation</label><select class="form-control" id="tradein-variation" name="variation_id" required><option value="">Choose mapped variation</option>@foreach ($variations as $variation)<option value="{{ $variation->id }}" @selected((string) $selectedVariation === (string) $variation->id)>{{ $variation->product->name }} · {{ $variation->name ?: ('Variation #'.$variation->id) }}</option>@endforeach</select></div></div>
                                <div class="col-sm-6"><div class="form-group"><label for="tradein-rule">Pricing rule version</label><select class="form-control" id="tradein-rule" name="rule_set_id" required><option value="">Choose active rule</option>@foreach ($ruleSets as $rule)<option value="{{ $rule->id }}" @selected((string) old('rule_set_id') === (string) $rule->id)>{{ $rule->rule_code }} v{{ $rule->version_number }}</option>@endforeach</select></div></div>
                            </div>

                            <h4>2. Structured inspection</h4>
                            <div class="row">
                                <div class="col-sm-3"><div class="form-group"><label for="battery-health">Battery health %</label><input class="form-control" id="battery-health" name="battery_health_percent" type="number" min="0" max="100" step="0.1" value="{{ old('battery_health_percent') }}"></div></div>
                                <div class="col-sm-3"><div class="form-group"><label for="battery-replacement">Battery replacement</label><select class="form-control" id="battery-replacement" name="battery_replacement_needed"><option value="NO">No</option><option value="YES" @selected(old('battery_replacement_needed') === 'YES')>Yes</option><option value="CONDITIONAL" @selected(old('battery_replacement_needed') === 'CONDITIONAL')>Conditional</option></select></div></div>
                                <div class="col-sm-3"><div class="form-group"><label for="battery-estimate">Battery estimate (MYR)</label><input class="form-control" id="battery-estimate" name="battery_replacement_estimate_amount" type="number" min="0" step="0.01" value="{{ old('battery_replacement_estimate_amount', 0) }}" required></div></div>
                                <div class="col-sm-3"><div class="form-group"><label for="cosmetic-grade">Cosmetic grade</label><select class="form-control" id="cosmetic-grade" name="cosmetic_grade" required><option value="">Choose grade</option>@foreach (['A','B','C','D'] as $grade)<option value="{{ $grade }}" @selected(old('cosmetic_grade') === $grade)>{{ $grade }}</option>@endforeach</select></div></div>
                            </div>
                            <div class="row">
                                @foreach (['display' => 'Display', 'touch' => 'Touch', 'charging' => 'Charging'] as $key => $label)
                                    <div class="col-sm-4"><div class="form-group"><label for="{{ $key }}-outcome">{{ $label }}</label><select class="form-control" id="{{ $key }}-outcome" name="{{ $key }}_outcome"><option>NOT_TESTED</option><option>PASS</option><option>FAIL</option><option>CONDITIONAL</option></select><input class="form-control" style="margin-top:6px" name="{{ $key }}_notes" maxlength="1000" aria-label="Functional observation note" placeholder="Observation / repair note"></div></div>
                                @endforeach
                            </div>
                            <div class="row"><div class="col-sm-6"><div class="form-group"><label for="cosmetic-notes">Cosmetic observations</label><textarea class="form-control" id="cosmetic-notes" name="cosmetic_notes" rows="2" maxlength="2000">{{ old('cosmetic_notes') }}</textarea></div></div><div class="col-sm-6"><div class="form-group"><label for="accessories-notes">Accessories / other functional notes</label><textarea class="form-control" id="accessories-notes" name="accessories_notes" rows="2" maxlength="2000">{{ old('accessories_notes') }}</textarea></div></div></div>

                            <h4>3. Market evidence and proposed acquisition</h4>
                            <div class="row">
                                @foreach ([1 => 'Marketplace', 2 => 'Competitor / other source'] as $number => $label)
                                    <div class="col-sm-6"><div class="well well-sm"><strong>{{ $label }} evidence</strong><div class="form-group" style="margin-top:8px"><label for="evidence-{{ $number }}-source">Source description</label><input class="form-control" id="evidence-{{ $number }}-source" name="market_evidence_{{ $number }}_source" maxlength="320" value="{{ old('market_evidence_'.$number.'_source') }}" required></div><div class="form-group"><label for="evidence-{{ $number }}-amount">Reference amount (MYR)</label><input class="form-control" id="evidence-{{ $number }}-amount" name="market_evidence_{{ $number }}_amount" type="number" min="0" step="0.01" value="{{ old('market_evidence_'.$number.'_amount') }}" required></div><div class="form-group" style="margin-bottom:0"><label for="evidence-{{ $number }}-url">Reference URL (optional)</label><input class="form-control" id="evidence-{{ $number }}-url" name="market_evidence_{{ $number }}_url" type="url" maxlength="1000" value="{{ old('market_evidence_'.$number.'_url') }}"></div></div></div>
                                @endforeach
                            </div>
                            <div class="row">
                                <div class="col-sm-3"><div class="form-group"><label for="market-reference">Market reference (MYR)</label><input class="form-control" id="market-reference" name="market_reference_amount" type="number" min="0" step="0.01" value="{{ old('market_reference_amount') }}" required></div></div>
                                <div class="col-sm-3"><div class="form-group"><label for="expected-resale">Expected resale (MYR)</label><input class="form-control" id="expected-resale" name="expected_resale_amount" type="number" min="0" step="0.01" value="{{ old('expected_resale_amount') }}" required></div></div>
                                <div class="col-sm-3"><div class="form-group"><label for="expected-refurb">Expected refurbishment (MYR)</label><input class="form-control" id="expected-refurb" name="expected_refurbishment_amount" type="number" min="0" step="0.01" value="{{ old('expected_refurbishment_amount', 0) }}" required></div></div>
                                <div class="col-sm-3"><div class="form-group"><label for="staff-proposed">Staff proposed offer (MYR)</label><input class="form-control" id="staff-proposed" name="staff_proposed_amount" type="number" min="0" step="0.01" value="{{ old('staff_proposed_amount') }}" required></div></div>
                            </div>
                            <div class="row"><div class="col-sm-4"><div class="form-group"><label for="customer-request">Customer request (MYR, optional)</label><input class="form-control" id="customer-request" name="customer_requested_amount" type="number" min="0" step="0.01" value="{{ old('customer_requested_amount') }}"></div></div></div>
                            <p class="text-muted">The rule snapshot will show all reserves and recommendation levels. A proposal above its negotiation ceiling waits for approval.</p>
                            <button class="btn btn-primary" type="submit" @disabled($variations->isEmpty() || $ruleSets->isEmpty())>Record valuation</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="box box-default">
                    <div class="box-header with-border"><h3 class="box-title">Authority boundary</h3></div>
                    <div class="box-body">
                        <p><span class="label label-info">Recommerce</span> Exact device, inspection, evidence, valuation, and immutable acquisition history.</p>
                        <p><span class="label label-success">UltimatePOS</span> Native purchase, payable, payment, aggregate stock, and financial return.</p>
                        <p class="text-muted">This V1 has no store credit, ML price, web scraping, or auto-created catalogue product.</p>
                    </div>
                </div>
                @if ($canManage)
                    <div class="box box-default">
                        <div class="box-header with-border"><h3 class="box-title">New pricing rule version</h3></div>
                        <form method="post" action="{{ route('recommerce.tradeins.rules.store') }}" class="box-body">
                            @csrf
                            <div class="form-group"><label for="rule-code">Rule code</label><input class="form-control" id="rule-code" name="rule_code" maxlength="64" pattern="[A-Za-z0-9_]+" placeholder="LAPTOP_STANDARD" required></div>
                            <div class="form-group"><label for="rule-variation">Authorised variation</label><select class="form-control" id="rule-variation" name="variation_id" required>@foreach ($variations as $variation)<option value="{{ $variation->id }}">{{ $variation->product->name }} · {{ $variation->name ?: $variation->id }}</option>@endforeach</select></div>
                            @foreach (['target_margin_percent' => 'Target margin', 'warranty_reserve_percent' => 'Warranty reserve', 'hidden_defect_reserve_percent' => 'Hidden defect reserve', 'markdown_reserve_percent' => 'Markdown reserve', 'opening_offer_ratio' => 'Opening offer ratio', 'target_acquisition_ratio' => 'Target acquisition ratio', 'negotiation_ceiling_ratio' => 'Negotiation ceiling ratio'] as $field => $label)
                                <div class="form-group"><label for="{{ $field }}">{{ $label }} (0–1)</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" type="number" min="0" max="1" step="0.01" required></div>
                            @endforeach
                            <button class="btn btn-default btn-sm" type="submit">Publish new version</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Valuation and acquisition history</h3></div>
            <div class="box-body table-responsive">
                <table class="table table-striped table-bordered">
                    <thead><tr><th>Device</th><th>Status / evidence</th><th>Recommendation</th><th>Proposed</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse ($valuations as $valuation)
                        @php($snapshot = (array) $valuation->pricing_snapshot_json)
                        <tr>
                            <td><strong>{{ optional($valuation->device)->device_code ?: 'Device #'.$valuation->device_id }}</strong><br><small>{{ optional($valuation->ruleSet)->rule_code }} v{{ optional($valuation->ruleSet)->version_number }} · {{ $valuation->created_at }}</small></td>
                            <td><span class="label label-{{ $statuses[$valuation->status] ?? 'default' }}">{{ str_replace('_', ' ', $valuation->status) }}</span><br><small>{{ $valuation->marketEvidence->count() }} evidence record(s), range MYR {{ $money($valuation->market_low_amount) }}–{{ $money($valuation->market_high_amount) }}</small></td>
                            <td><small>Opening: MYR {{ $money($valuation->opening_offer_amount) }}<br>Target: MYR {{ $money($valuation->target_acquisition_amount) }}<br>Ceiling: MYR {{ $money($valuation->negotiation_ceiling_amount) }}<br>Economic: MYR {{ $money($valuation->economic_ceiling_amount) }}</small></td>
                            <td>MYR {{ $money($valuation->staff_proposed_amount) }}<br><small>Resale MYR {{ $money($valuation->expected_resale_amount) }} · refurb MYR {{ $money($valuation->expected_refurbishment_amount) }}</small></td>
                            <td style="min-width:220px">
                                @if ($valuation->status === 'PENDING_APPROVAL' && $canApprove)
                                    <form method="post" action="{{ route('recommerce.tradeins.approve', $valuation->id) }}" class="form-inline" style="margin-bottom:6px">@csrf<input class="form-control input-sm" name="reason" maxlength="2000" aria-label="Approval reason or evidence reference" placeholder="Approval reason / reference" required><button class="btn btn-info btn-sm" type="submit">Approve</button></form>
                                @endif
                                @if (in_array($valuation->status, ['READY_TO_ACCEPT', 'APPROVED'], true) && $canAccept)
                                    <form method="post" action="{{ route('recommerce.tradeins.accept', $valuation->id) }}" style="margin-bottom:6px">@csrf<input type="hidden" name="command_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="btn btn-success btn-sm" type="submit">Accept &amp; post native purchase</button></form>
                                @endif
                                @if (in_array($valuation->status, ['READY_TO_ACCEPT', 'PENDING_APPROVAL', 'APPROVED'], true) && $canManage)
                                    <form method="post" action="{{ route('recommerce.tradeins.reject', $valuation->id) }}" class="form-inline">@csrf<input class="form-control input-sm" name="reason" maxlength="255" aria-label="Rejection reason" placeholder="Rejection reason" required><button class="btn btn-default btn-sm" type="submit">Return to customer</button></form>
                                @endif
                                @if (in_array($valuation->status, ['ACCEPTED', 'REVERSED'], true))<small class="text-muted">History is append-only. A reversal must start with a native UltimatePOS purchase return.</small>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">No trade-in valuations have been recorded for this location.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection
