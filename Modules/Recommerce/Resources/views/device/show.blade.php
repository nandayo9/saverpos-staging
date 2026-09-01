@extends('layouts.app')

@section('title', 'Device '.$device->device_code)

@section('content')
@php
    $deviceProfile = $deviceProfile ?? [
        'brand' => data_get($device->specifications_json, 'brand', 'Not recorded'),
        'model' => data_get($device->specifications_json, 'model', optional($device->product)->name ?: 'Not recorded'),
        'category' => $device->category_code ?: 'Not recorded',
        'product' => optional($device->product)->name ?: 'Not recorded',
        'variation' => optional($device->variation)->name ?: 'Not recorded',
        'serial_hint' => $device->manufacturer_serial_display ? 'Recorded' : 'Not recorded',
    ];
    $technicalSpecifications = $technicalSpecifications ?? [];
@endphp
<style>
    .sb-device-record { max-width:1180px; margin:0 auto; }
    .sb-device-hero { display:flex; gap:24px; justify-content:space-between; align-items:flex-start; padding:24px; margin-bottom:18px; border-radius:10px; background:linear-gradient(135deg,#0f172a,#1e3a5f); color:#f8fafc; }
    .sb-device-hero__eyebrow { margin:0 0 7px; color:#bfdbfe; font-size:12px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
    .sb-device-hero__name { margin:0; font-size:25px; line-height:1.2; }
    .sb-device-hero__code { display:inline-block; margin-top:10px; padding:5px 9px; border-radius:5px; background:rgba(255,255,255,.12); font-family:monospace; font-size:14px; font-weight:700; letter-spacing:.04em; }
    .sb-device-hero__product { margin:9px 0 0; color:#dbeafe; }
    .sb-device-hero__state { display:flex; max-width:410px; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
    .sb-device-pill { padding:6px 10px; border:1px solid rgba(255,255,255,.23); border-radius:999px; background:rgba(255,255,255,.10); color:#fff; font-size:12px; font-weight:700; }
    .sb-device-panel { height:100%; margin-bottom:18px; padding:18px; border:1px solid var(--sb-border,#dbe3ea); border-radius:9px; background:var(--sb-surface,#fff); }
    .sb-device-panel h4 { margin:0 0 5px; color:var(--sb-text,#0f172a); font-size:16px; font-weight:700; }
    .sb-device-panel__help { margin:0 0 15px; color:var(--sb-muted,#64748b); font-size:13px; }
    .sb-device-profile-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:11px 20px; margin:0; }
    .sb-device-profile-grid div { min-width:0; padding-bottom:9px; border-bottom:1px solid var(--sb-border,#e5e7eb); }
    .sb-device-profile-grid dt { margin:0 0 3px; color:var(--sb-muted,#64748b); font-size:12px; font-weight:600; }
    .sb-device-profile-grid dd { margin:0; overflow-wrap:anywhere; color:var(--sb-text,#0f172a); font-weight:700; }
    .sb-device-specs { margin:0; }
    .sb-device-specs div { display:flex; justify-content:space-between; gap:14px; padding:8px 0; border-bottom:1px solid var(--sb-border,#e5e7eb); }
    .sb-device-specs dt { color:var(--sb-muted,#64748b); font-weight:600; }
    .sb-device-specs dd { margin:0; color:var(--sb-text,#0f172a); font-weight:700; text-align:right; overflow-wrap:anywhere; }
    .sb-device-status-list { margin:0; }
    .sb-device-status-list div { padding:9px 0; border-bottom:1px solid var(--sb-border,#e5e7eb); }
    .sb-device-status-list dt { color:var(--sb-muted,#64748b); font-size:12px; font-weight:600; }
    .sb-device-status-list dd { margin:2px 0 0; color:var(--sb-text,#0f172a); font-weight:700; }
    @media (max-width:767px) { .sb-device-hero { display:block; padding:18px; } .sb-device-hero__state { margin-top:16px; justify-content:flex-start; } .sb-device-profile-grid { grid-template-columns:1fr; } }
</style>
    <section class="container sb-device-record">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <div class="pull-right">
                            <a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Operations overview</a>
                            <a class="btn btn-default btn-sm" href="{{ route('recommerce.scans.index') }}">Scan &amp; Entry</a>
                            <a class="btn btn-default btn-sm" href="{{ route('recommerce.devices.index') }}">Back to registry</a>
                            @if ($labelPrintEnabled)
                                <form method="post" action="{{ route('recommerce.devices.label.print', $device->id) }}" data-recommerce-label-print style="display:inline">
                                    @csrf
                                    <button class="btn btn-primary btn-sm" type="button"><i class="fa fa-print"></i> {{ $hasLabelPrintView ? 'Reprint Label' : 'Print SAVERBRO Label' }}</button>
                                </form>
                                @if ($labelStatus === 'Print view opened')
                                    <form method="post" action="{{ route('recommerce.devices.label.confirm', $device->id) }}" style="display:inline">
                                        @csrf
                                        <button class="btn btn-success btn-sm" type="submit">Label Attached</button>
                                    </form>
                                @endif
                            @endif
                        </div>
                        <h3 class="box-title">Device detail</h3>
                    </div>
                    <div class="box-body">
                        @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
                        <div class="sb-device-hero">
                            <div>
                                <p class="sb-device-hero__eyebrow">Physical device record</p>
                                <h1 class="sb-device-hero__name">{{ $deviceProfile['brand'] }} {{ $deviceProfile['model'] }}</h1>
                                <span class="sb-device-hero__code">{{ $device->device_code }}</span>
                                <p class="sb-device-hero__product">{{ $deviceProfile['product'] }} · {{ $deviceProfile['variation'] }}</p>
                            </div>
                            <div class="sb-device-hero__state" aria-label="Current Device status">
                                <span class="sb-device-pill">{{ ucwords(strtolower(str_replace('_', ' ', $device->lifecycle_state))) }}</span>
                                <span class="sb-device-pill">{{ $device->custody_kind === 'LOCATION' ? (optional($device->currentLocation)->name ?: ($device->current_location_id ? 'Branch #'.$device->current_location_id : 'Branch not recorded')) : ucwords(strtolower(str_replace('_', ' ', $device->custody_kind))) }}</span>
                                <span class="sb-device-pill">{{ $labelStatus }}</span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-7">
                                <section class="sb-device-panel" aria-labelledby="device-profile-heading">
                                    <h4 id="device-profile-heading">Device profile</h4>
                                    <p class="sb-device-panel__help">Catalog and intake information for this individual physical Device.</p>
                                    <dl class="sb-device-profile-grid">
                                        <div><dt>Brand</dt><dd>{{ $deviceProfile['brand'] }}</dd></div>
                                        <div><dt>Model</dt><dd>{{ $deviceProfile['model'] }}</dd></div>
                                        <div><dt>Category</dt><dd>{{ $deviceProfile['category'] }}</dd></div>
                                        <div><dt>Product</dt><dd>{{ $deviceProfile['product'] }}</dd></div>
                                        <div><dt>Variation</dt><dd>{{ $deviceProfile['variation'] }}</dd></div>
                                        <div><dt>Recorded serial</dt><dd>{{ $deviceProfile['serial_hint'] }}</dd></div>
                                    </dl>
                                </section>
                            </div>
                            <div class="col-md-5">
                                <section class="sb-device-panel" aria-labelledby="device-status-heading">
                                    <h4 id="device-status-heading">Operational status</h4>
                                    <p class="sb-device-panel__help">Live operating state, custody and label evidence.</p>
                                    <dl class="sb-device-status-list">
                                        <div><dt>QR identity</dt><dd>{{ $hasLabelPrintView ? 'Active' : 'Created when the first label is generated' }}</dd></div>
                                        <div><dt>Label</dt><dd>{{ $labelStatus }}@if($labelStatus === 'Print view opened') <small class="text-muted">· physical printing is not confirmed</small>@endif</dd></div>
                                        <div><dt>Current holder</dt><dd>{{ $device->custody_kind === 'LOCATION' ? (optional($device->currentLocation)->name ?: ($device->current_location_id ? 'Branch #'.$device->current_location_id : 'Branch not recorded')) : ucwords(strtolower(str_replace('_', ' ', $device->custody_kind))) }}</dd></div>
                                        <div><dt>Inventory</dt><dd>{{ ucwords(strtolower(str_replace('_', ' ', $device->stock_participation))) }}</dd></div>
                                        @if ($economicsVisible && $device->purchaseAssignment && $device->purchaseAssignment->unit_acquisition_cost !== null)
                                            <div><dt>Acquisition cost</dt><dd>RM {{ number_format((float) $device->purchaseAssignment->unit_acquisition_cost, 2) }}</dd></div>
                                        @endif
                                    </dl>
                                </section>
                            </div>
                        </div>

                        <section class="sb-device-panel" aria-labelledby="device-specifications-heading">
                            <h4 id="device-specifications-heading">Technical specifications</h4>
                            @if ($technicalSpecifications)
                                <dl class="sb-device-specs">
                                    @foreach ($technicalSpecifications as $label => $value)
                                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                    @endforeach
                                </dl>
                            @else
                                <p class="sb-device-panel__help" style="margin-bottom:0">No additional technical specifications have been recorded for this Device.</p>
                            @endif
                        </section>

                        <h4>Acquisition provenance</h4>
                        @if ($device->purchaseAssignment)
                            <dl class="dl-horizontal">
                                <dt>Source purchase</dt><dd>{{ optional($acquisition)->ref_no ?: optional($acquisition)->invoice_no ?: 'Purchase #'.$device->purchaseAssignment->transaction_id }}</dd>
                                <dt>Supplier</dt><dd>{{ optional($acquisition)->supplier_business_name ?: optional($acquisition)->supplier_name ?: 'Supplier unavailable' }}</dd>
                                <dt>Received</dt><dd>{{ optional($acquisition)->transaction_date ? \Carbon\Carbon::parse($acquisition->transaction_date)->format('d M Y') : ($device->purchaseAssignment->assigned_at?->format('d M Y') ?: 'Not recorded') }}</dd>
                                <dt>Receiving location</dt><dd>{{ optional($acquisition)->location_name ?: 'Location #'.$device->current_location_id }}</dd>
                                <dt>Purchase line</dt><dd>#{{ $device->purchaseAssignment->purchase_line_id }} · unit {{ $device->purchaseAssignment->unit_ordinal }}</dd>
                            </dl>
                            @if ($economicsVisible && $device->costOverrideEvents->isNotEmpty())
                                <p><strong>Cost override history</strong></p>
                                @foreach ($device->costOverrideEvents as $override)
                                    <div class="well well-sm">RM {{ number_format((float) $override->previous_unit_acquisition_cost, 2) }} → RM {{ number_format((float) $override->new_unit_acquisition_cost, 2) }}<br><small>{{ str_replace('_', ' ', $override->reason_code) }} · {{ $override->overridden_at?->format('d M Y H:i') }} · user #{{ $override->overridden_by }}</small>@if($override->reason_notes)<br><small>{{ $override->reason_notes }}</small>@endif</div>
                                @endforeach
                            @endif
                        @else
                            <p class="text-muted">No native supplier-purchase assignment is recorded for this Device.</p>
                        @endif

                        @if ($device->inspection)
                            <h4>Receiving inspection</h4>
                            <p><strong>{{ str_replace('_', ' ', $device->inspection->status) }}</strong> · received {{ $device->inspection->received_at?->format('d M Y H:i') }}</p>
                            @if($device->inspection->assigned_to)<p class="text-muted">Assigned to user #{{ $device->inspection->assigned_to }}</p>@endif
                            @if($device->inspection->outcome_notes)<p class="text-muted">{{ $device->inspection->outcome_notes }}</p>@endif
                            @forelse($device->intakeObservations as $observation)<div class="well well-sm"><strong>{{ str_replace('_', ' ', $observation->observation_type) }}</strong>@if($observation->notes)<br><small>{{ $observation->notes }}</small>@endif</div>@empty<p class="text-muted">No intake observations recorded.</p>@endforelse
                        @endif

                        <h4>SaverBro certification</h4>
                        @if ($device->certification)
                            <div class="well well-sm">
                                <strong>Public warranty record active</strong>
                                <br><small>Grade {{ $device->certification->grade }} · QC passed · Battery {{ $device->certification->battery_health_percent }}%</small>
                                <br><small>Purchased {{ $device->certification->purchased_at?->format('d M Y') }} · Warranty until {{ $device->certification->warranty_expires_at?->format('d M Y') }}</small>
                            </div>
                        @elseif (empty($device->sold_at))
                            <p class="text-muted">A public certificate can be published only after this Device has recorded a sale. This does not create or alter a POS sale.</p>
                        @elseif ($certificationPublishEnabled)
                            <form method="post" action="{{ route('recommerce.devices.certification.store', $device->id) }}" class="well well-sm">
                                @csrf
                                @if ($errors->has('certification'))<p class="text-danger">{{ $errors->first('certification') }}</p>@endif
                                <div class="row">
                                    <div class="col-sm-3 form-group"><label for="certify-grade">Grade</label><select id="certify-grade" class="form-control" name="grade" required><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option></select></div>
                                    <div class="col-sm-3 form-group"><label for="certify-battery">Battery health</label><input id="certify-battery" class="form-control" name="battery_health_percent" type="number" min="0" max="100" required></div>
                                    <div class="col-sm-3 form-group"><label for="certify-purchased">Purchased</label><input id="certify-purchased" class="form-control" name="purchased_at" type="date" value="{{ $device->sold_at?->format('Y-m-d') }}" required></div>
                                    <div class="col-sm-3 form-group"><label for="certify-warranty">Warranty expiry</label><input id="certify-warranty" class="form-control" name="warranty_expires_at" type="date" required></div>
                                </div>
                                <label><input type="checkbox" name="qc_passed" value="1" required> QC passed</label>
                                <button class="btn btn-success btn-sm" type="submit">Publish customer-safe certificate</button>
                            </form>
                        @else
                            <p class="text-muted">No public certificate is published for this Device in the current permission scope.</p>
                        @endif
                        <p class="text-muted">Sale price and gross profit remain in Ultimate POS. The protected lifecycle record retains the exact device-sale link; this view does not duplicate the financial ledger.</p>

                        <h4>Ownership periods</h4>
                        @forelse ($device->ownershipPeriods as $period)
                            <div class="well well-sm">
                                <strong>{{ $period->owner_kind }}</strong>
                                <span class="text-muted">{{ $period->starts_at?->toISOString() ?: 'Start unavailable' }}</span>
                                <br><small>Reason: {{ $period->reason ?: 'Not recorded' }}</small>
                                @if ($period->acquisition_transaction_id)
                                    <br><small>Purchase transaction: {{ $period->acquisition_transaction_id }}</small>
                                @endif
                                @if ($period->ends_at)
                                    <br><small>Ended: {{ $period->ends_at->toISOString() }}</small>
                                @else
                                    <br><small>Open ownership period</small>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted">No ownership-period evidence is available in the current scope.</p>
                        @endforelse

                        <h4>Custody periods</h4>
                        @forelse ($device->custodyPeriods as $period)
                            <div class="well well-sm">
                                <strong>{{ $period->custody_kind === 'LOCATION' ? (optional($period->location)->name ?: ($period->location_id ? 'Branch #'.$period->location_id : 'Branch not recorded')) : ucwords(strtolower(str_replace('_', ' ', $period->custody_kind))) }}</strong>
                                <span class="text-muted">{{ $period->starts_at?->toISOString() ?: 'Start unavailable' }}</span>
                                @if ($period->custody_kind === 'LOCATION')<br><small>Branch custody</small>@endif
                                @if ($period->source_movement_id)
                                    <br><small>Source movement: {{ $period->source_movement_id }}</small>
                                @endif
                                @if ($period->ends_at)
                                    <br><small>Ended: {{ $period->ends_at->toISOString() }}</small>
                                @else
                                    <br><small>Open custody period</small>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted">No custody-period evidence is available in the current scope.</p>
                        @endforelse

                        @if ($auditVisible)
                            <h4>Operational timeline</h4>
                            @forelse ($events as $event)
                                <div class="well well-sm">
                                    <strong>{{ $event['label'] ?? $event['event_type'] }}</strong>
                                    <span class="text-muted">{{ $event['occurred_at'] ?: 'Time unavailable' }}</span>
                                    @if ($event['source_command_uuid'])
                                        <br><small class="text-muted">Evidence command {{ $event['source_command_uuid'] }}</small>
                                    @endif
                                </div>
                            @empty
                                <p class="text-muted">No safe operational events are available in the current scope.</p>
                            @endforelse
                            <p class="text-muted">Protected identifiers and raw token material are excluded from this timeline.</p>
                        @else
                            <p class="text-muted">Operational timeline unavailable for the current permission scope.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('javascript')
    <script src="{{ asset('js/recommerce-label-print.js') }}?v={{ config('constants.asset_version') }}"></script>
@endsection
