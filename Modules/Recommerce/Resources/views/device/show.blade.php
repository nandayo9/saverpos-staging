@extends('layouts.app')

@section('title', 'Device '.$device->device_code)

@section('content')
    <section class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <div class="pull-right">
                            <a class="btn btn-default btn-sm" href="{{ route('recommerce.dashboard') }}">Operations overview</a>
                            <a class="btn btn-default btn-sm" href="{{ route('recommerce.scans.index') }}">Scan &amp; Entry</a>
                            <a class="btn btn-default btn-sm" href="{{ route('recommerce.devices.index') }}">Back to registry</a>
                            @if ($labelPrintEnabled)
                                <form method="post" action="{{ route('recommerce.devices.label.print', $device->id) }}" target="_blank" style="display:inline">
                                    @csrf
                                    <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-print"></i> {{ $hasLabelPrintView ? 'Reprint Label' : 'Print SAVERBRO Label' }}</button>
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
                        <dl class="dl-horizontal">
                            <dt>Device code</dt>
                            <dd>{{ $device->device_code }}</dd>

                            <dt>QR identity</dt>
                            <dd>{{ $hasLabelPrintView ? 'Active' : 'Created when the first label is generated' }}</dd>

                            <dt>Label</dt>
                            <dd>{{ $labelStatus }}@if($labelStatus === 'Print view opened') <small class="text-muted">· physical printing is not confirmed</small>@endif</dd>

                            <dt>Lifecycle</dt>
                            <dd>{{ $device->lifecycle_state }}</dd>

                            <dt>Custody</dt>
                            <dd>{{ $device->custody_kind }}</dd>

                            <dt>Stock participation</dt>
                            <dd>{{ $device->stock_participation }}</dd>

                            @if ($device->product)
                                <dt>Product</dt>
                                <dd>{{ $device->product->name }}</dd>
                            @endif

                            @if ($device->variation)
                                <dt>Variation</dt>
                                <dd>{{ $device->variation->name }}</dd>
                            @endif

                            @if ($device->manufacturer_serial_display)
                                <dt>Serial</dt>
                                <dd>{{ $device->manufacturer_serial_display }}</dd>
                            @endif

                            @if ($economicsVisible && $device->purchaseAssignment && $device->purchaseAssignment->unit_acquisition_cost !== null)
                                <dt>Acquisition cost</dt>
                                <dd>RM {{ number_format((float) $device->purchaseAssignment->unit_acquisition_cost, 2) }}</dd>
                            @endif
                        </dl>

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
                                <strong>{{ $period->custody_kind }}</strong>
                                <span class="text-muted">{{ $period->starts_at?->toISOString() ?: 'Start unavailable' }}</span>
                                <br><small>Location: {{ $period->location_id ?: 'Not recorded' }}</small>
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
