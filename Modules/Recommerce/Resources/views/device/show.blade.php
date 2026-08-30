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
                                    <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-print"></i> Print label</button>
                                </form>
                            @endif
                        </div>
                        <h3 class="box-title">Device detail</h3>
                    </div>
                    <div class="box-body">
                        <dl class="dl-horizontal">
                            <dt>Device code</dt>
                            <dd>{{ $device->device_code }}</dd>

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

                            @if ($device->purchaseAssignment && $device->purchaseAssignment->unit_acquisition_cost !== null)
                                <dt>Acquisition cost</dt>
                                <dd>RM {{ number_format((float) $device->purchaseAssignment->unit_acquisition_cost, 2) }}</dd>
                            @endif
                        </dl>

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
                        <p class="text-muted">Sale price and gross profit remain in Ultimate POS until an exact Device-to-sale-line assignment is implemented; this view will not invent a parallel sales ledger.</p>

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
                                    <strong>{{ $event['event_type'] }}</strong>
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
