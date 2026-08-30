@extends('layouts.app')

@section('content')
@php
    // Same vocabulary as the repair workbench list, and the same one the
    // "Parts boundary" legend below already promised: held, pending, audited.
    $sbPartTone = [
        'RESERVED' => 'intake',
        'ISSUED' => 'active',
        'INSTALLED_PENDING_BILLING' => 'blocked',
        'CONSUMED' => 'done',
        'RELEASED' => 'closed',
    ];
@endphp
<style>
    #recommerce-parts .sb-status { display:inline-block; border-radius:999px; padding:3px 9px; font-size:11px; font-weight:700; letter-spacing:.02em; white-space:nowrap; }
    #recommerce-parts .sb-status-intake { background:#e0e7ff; color:#3730a3; }
    #recommerce-parts .sb-status-active { background:#dbeafe; color:#1e40af; }
    #recommerce-parts .sb-status-blocked { background:#fef3c7; color:#92400e; }
    #recommerce-parts .sb-status-done { background:#d1fae5; color:#065f46; }
    #recommerce-parts .sb-status-closed { background:#e5e7eb; color:#374151; }
</style>
    <section class="container" id="recommerce-parts" data-csrf-token="{{ csrf_token() }}">
        <div class="row">
            <div class="col-md-9">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Parts workbench · {{ $job->job_code }}</h3>
                        <p class="text-muted" style="margin:6px 0 0">{{ $job->device->device_code }} · {{ $job->job_type }} · {{ $job->state }}</p>
                    </div>
                    <div class="box-body">
                        @if ($canReserve && $job->state !== 'CLOSED')
                            <h4>Reserve a part</h4>
                            @if ($stockOptions->isEmpty())
                                <p class="text-muted">No available stock is in this repair location.</p>
                            @else
                                <form id="part-reserve-form" method="post" action="{{ route('recommerce.repair.parts.reserve', $job->job_code) }}">
                                    <input type="hidden" name="command_uuid" value="">
                                    <div class="row">
                                        <div class="col-md-8 form-group">
                                            <label for="part-variation">Stock item</label>
                                            <select id="part-variation" class="form-control" name="variation_id" required>
                                                @foreach ($stockOptions as $option)
                                                    <option value="{{ $option->variation_id }}">{{ $option->product_name }} · {{ $option->variation_name ?: $option->sub_sku }} · {{ number_format($option->available_quantity, 4) }} available</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-4 form-group">
                                            <label for="part-quantity">Quantity</label>
                                            <input id="part-quantity" class="form-control" name="quantity" type="number" min="0.0001" step="0.0001" value="1" required>
                                        </div>
                                    </div>
                                    <button class="btn btn-primary" type="submit">Reserve part</button>
                                </form>
                            @endif
                        @else
                            <div class="alert alert-info">Part reservation is read-only or this job is closed.</div>
                        @endif
                        <div id="parts-result" class="alert" style="display:none;margin-top:12px" role="status"></div>

                        <h4>Reservations and usage</h4>
                        @forelse ($reservations as $reservation)
                            <div class="well well-sm">
                                <p><strong>{{ $reservation->variation->product->name ?? 'Stock item' }}</strong> · {{ $reservation->variation->name ?? $reservation->variation_id }} · {{ $reservation->quantity }} · <span class="sb-status sb-status-{{ $sbPartTone[$reservation->status] ?? 'closed' }}">{{ str_replace('_', ' ', $reservation->status) }}</span></p>
                                @if ($reservation->usage)
                                    <p>Usage: <span class="sb-status sb-status-{{ $sbPartTone[$reservation->usage->status] ?? 'closed' }}">{{ str_replace('_', ' ', $reservation->usage->status) }}</span> · path {{ $reservation->usage->consumption_path }}</p>
                                @endif
                                <div class="parts-actions">
                                    @if ($reservation->status === 'RESERVED' && $canUse)
                                        <form class="parts-action-form" method="post" action="{{ route('recommerce.repair.parts.issue', [$job->job_code, $reservation->id]) }}" data-success="Part issued to the repair job.">
                                            <input type="hidden" name="command_uuid" value="">
                                            <button class="btn btn-default btn-xs" type="submit">Issue to job</button>
                                        </form>
                                    @endif
                                    @if ($reservation->status === 'RESERVED' && $canReserve)
                                        <form class="parts-action-form" method="post" action="{{ route('recommerce.repair.parts.release', [$job->job_code, $reservation->id]) }}" data-success="Reservation released.">
                                            <input type="text" name="reason" placeholder="Release reason" required maxlength="255" style="margin-right:4px">
                                            <button class="btn btn-warning btn-xs" type="submit">Release</button>
                                        </form>
                                    @endif
                                    @if ($reservation->usage && $reservation->usage->status === 'ISSUED' && $canUse)
                                        <form class="parts-action-form" method="post" action="{{ route('recommerce.repair.parts.install', [$job->job_code, $reservation->usage->id]) }}" data-success="Part marked installed; billing or internal consumption is still pending.">
                                            <button class="btn btn-default btn-xs" type="submit">Mark installed</button>
                                        </form>
                                    @endif
                                    @if ($reservation->usage && $reservation->usage->status === 'INSTALLED_PENDING_BILLING' && $reservation->usage->consumption_path === 'INTERNAL' && $canResolve)
                                        <form class="parts-action-form" method="post" action="{{ route('recommerce.repair.parts.consume-internal', [$job->job_code, $reservation->usage->id]) }}" data-success="Internal part consumed through the POS stock-adjustment seam; cost recorded.">
                                            <input type="text" name="reason" placeholder="Consumption reason" required maxlength="255" style="margin-right:4px">
                                            <button class="btn btn-success btn-xs" type="submit">Consume internally</button>
                                        </form>
                                    @endif
                                    @if ($reservation->usage && $reservation->usage->status === 'INSTALLED_PENDING_BILLING' && $reservation->usage->consumption_path === 'CUSTOMER' && $canResolve)
                                        <form class="parts-action-form" method="post" action="{{ route('recommerce.repair.parts.resolve', [$job->job_code, $reservation->usage->id]) }}" data-success="Customer part linked to the finalized POS sale.">
                                            <input type="number" name="source_transaction_id" placeholder="POS sale ID" min="1" required style="width:90px;margin-right:4px">
                                            <input type="number" name="source_line_id" placeholder="Sale line ID" min="1" required style="width:90px;margin-right:4px">
                                            <input type="hidden" name="source_type" value="SALE">
                                            <button class="btn btn-success btn-xs" type="submit">Resolve POS sale</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-muted">No parts have been reserved for this job.</p>
                        @endforelse

                        <h4>Projected POS invoice</h4>
                        @if ($billing)
                            @if (collect($billing['parts'])->isEmpty() && collect($billing['services'])->isEmpty())
                                <p class="text-muted">Nothing is waiting for billing. Billed parts appear in the linked POS sale.</p>
                            @else
                                @forelse ($billing['parts'] as $line)
                                    <div class="well well-sm"><strong>Part</strong> · {{ $line['description'] }} · {{ $line['quantity'] }} @if($line['unit_price'] !== null)· RM {{ number_format($line['unit_price'], 2) }}@endif</div>
                                @endforeach
                                @foreach ($billing['services'] as $line)
                                    <div class="well well-sm"><strong>{{ $line['description'] }}</strong> · {{ $line['quantity'] }} · RM {{ number_format($line['line_total_amount'], 2) }} (service, no stock effect)</div>
                                @endforeach
                            @endif
                            @if ($canBill && $job->state !== 'CLOSED')
                                <form class="parts-action-form" method="post" action="{{ route('recommerce.repair.billing.link', $job->job_code) }}" data-success="Finalized POS sale linked; billing recorded.">
                                    <input type="hidden" name="command_uuid" value="">
                                    <input type="number" name="sale_transaction_id" placeholder="Finalized POS sale ID" min="1" required style="width:120px;margin-right:4px">
                                    <button class="btn btn-success btn-xs" type="submit">Link finalized POS sale</button>
                                </form>
                                <form class="parts-action-form" method="post" action="{{ route('recommerce.repair.billing.release', $job->job_code) }}" data-success="Billed state reverted; parts wait for billing again.">
                                    <input type="number" name="sale_transaction_id" placeholder="POS sale ID" min="1" required style="width:120px;margin-right:4px">
                                    <input type="text" name="reason" placeholder="Reversal reason" required maxlength="255" style="margin-right:4px">
                                    <button class="btn btn-warning btn-xs" type="submit">Reverse billed state</button>
                                </form>
                            @endif
                        @else
                            <p class="text-muted">Billing projection is available to authorized roles only.</p>
                        @endif

                        <h4>Recorded part costs</h4>
                        @forelse ($costEntries as $entry)
                            <div class="well well-sm"><strong>{{ $entry->cost_category }}</strong> · {{ number_format((float) $entry->amount, 2) }} {{ $entry->currency ?: '' }} · {{ $entry->reason }}</div>
                        @empty
                            <p class="text-muted">No part cost has been recorded yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="box box-default">
                    <div class="box-header with-border"><h3 class="box-title">Parts boundary</h3></div>
                    <div class="box-body">
                        <p><span class="sb-status sb-status-intake">Held</span> Reservations reduce available quantity without mutating POS stock.</p>
                        <p><span class="sb-status sb-status-blocked">Pending</span> Installed parts wait for a source transaction or internal adjustment.</p>
                        <p><span class="sb-status sb-status-done">Audited</span> Customer parts link to a finalized POS sale; internal parts record a POS adjustment and actual cost.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        (function () {
            const root = document.getElementById('recommerce-parts');
            const result = document.getElementById('parts-result');
            const uuid = function () { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { const r = Math.random() * 16 | 0; const v = c === 'x' ? r : (r & 0x3 | 0x8); return v.toString(16); }); };
            const message = function (text, kind) { result.textContent = text; result.className = 'alert alert-' + kind; result.style.display = 'block'; };
            const submit = async function (form) {
                const button = form.querySelector('button[type="submit"]');
                button.disabled = true;
                const command = form.querySelector('input[name="command_uuid"]');
                if (command && !command.value) command.value = uuid();
                try {
                    const response = await fetch(form.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken }, credentials: 'same-origin', body: new FormData(form) });
                    const data = await response.json().catch(function () { return {}; });
                    if (!response.ok) throw new Error(data.message || 'Parts action was rejected.');
                    message(form.dataset.success || 'Parts action completed.', 'success');
                    window.setTimeout(function () { window.location.reload(); }, 450);
                } catch (error) {
                    message(error.message || 'Parts action was rejected.', 'warning');
                    button.disabled = false;
                }
            };
            document.querySelectorAll('.parts-action-form').forEach(function (form) { form.addEventListener('submit', function (event) { event.preventDefault(); submit(form); }); });
            const reserve = document.getElementById('part-reserve-form');
            if (reserve) reserve.addEventListener('submit', function (event) { event.preventDefault(); submit(reserve); });
        }());
    </script>
@endsection
