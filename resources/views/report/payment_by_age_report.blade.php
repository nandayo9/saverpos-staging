@extends('layouts.app')
@section('title', __('lang_v1.payment_by_age_report'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">{{ __('lang_v1.payment_by_age_report') }}</h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                {!! Form::open(['url' => '#', 'method' => 'get', 'id' => 'payment_by_age_report_form']) !!}
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('pba_customer_id', __('contact.customer') . ':') !!}
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="fa fa-user"></i>
                                </span>
                                {!! Form::select('customer_id', $customers, null, ['class' => 'form-control select2', 'id' => 'pba_customer_id', 'placeholder' => __('messages.all'), 'style' => 'width:100%']) !!}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('pba_payment_types', __('lang_v1.payment_method') . ':') !!}
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="fas fa-money-bill-alt"></i>
                                </span>
                                {!! Form::select('payment_types', $payment_types, null, ['class' => 'form-control select2', 'id' => 'pba_payment_types', 'placeholder' => __('messages.all'), 'style' => 'width:100%']) !!}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('pba_location_id', __('purchase.business_location') . ':') !!}
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="fa fa-map-marker"></i>
                                </span>
                                {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2', 'id' => 'pba_location_id', 'placeholder' => __('messages.all'), 'style' => 'width:100%']) !!}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('pba_user_id', __('report.user') . ':') !!}
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="fa fa-user"></i>
                                </span>
                                {!! Form::select('user_id', $users, null, ['class' => 'form-control select2', 'id' => 'pba_user_id', 'placeholder' => __('messages.all'), 'style' => 'width:100%']) !!}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('pba_age_filter', __('lang_v1.age') . ':') !!}
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="fa fa-hourglass-half"></i>
                                </span>
                                {!! Form::select('age_filter', $age_filter_options, null, ['class' => 'form-control select2', 'id' => 'pba_age_filter', 'placeholder' => __('messages.all'), 'style' => 'width:100%']) !!}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('pba_date_filter', __('report.date_range') . ':') !!}
                            {!! Form::text('date_range', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'id' => 'pba_date_filter', 'readonly']) !!}
                        </div>
                    </div>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped ajax_view" id="payment_by_age_report_table">
                        <thead>
                            <tr>
                                <th>@lang('contact.customer')</th>
                                <th>@lang('lang_v1.payment_method')</th>
                                <th>@lang('report.user')</th>
                                @foreach ($buckets as $key => $range)
                                    <th class="text-right">@lang('lang_v1.age') {{ $key }}</th>
                                @endforeach
                                <th class="text-right">@lang('sale.total')</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total">
                                <td><strong>@lang('sale.total')</strong></td>
                                <td></td>
                                <td></td>
                                @foreach ($buckets as $key => $range)
                                    <td class="text-right pba_foot_{{ $bucket_cols[$key] }}"></td>
                                @endforeach
                                <td class="text-right pba_foot_total"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endcomponent
        </div>
    </div>
</section>
<!-- /.content -->

@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        var payment_by_age_table = $('#payment_by_age_report_table').DataTable({
            processing: true,
            serverSide: true,
            // Override the global fixedHeader:true and don't use scrollX: that combo
            // clones the <tfoot> and renders a duplicate footer row. The table fits
            // and is wrapped in .table-responsive, so it spans full width like #sell_table.
            fixedHeader: false,
            scrollX: false,
            ordering: false,
            searching: false,
            ajax: {
                url: '{{ url('/reports/payment-by-age-report') }}',
                data: function (d) {
                    d.customer_id = $('#pba_customer_id').val();
                    d.payment_types = $('#pba_payment_types').val();
                    d.location_id = $('#pba_location_id').val();
                    d.user_id = $('#pba_user_id').val();
                    d.age_filter = $('#pba_age_filter').val();
                    if ($('#pba_date_filter').val()) {
                        d.start_date = $('#pba_date_filter').data('daterangepicker').startDate.format('YYYY-MM-DD');
                        d.end_date = $('#pba_date_filter').data('daterangepicker').endDate.format('YYYY-MM-DD');
                    } else {
                        d.start_date = '';
                        d.end_date = '';
                    }
                },
            },
            columns: [
                { data: 'customer', name: 'customer', orderable: false, searchable: false },
                { data: 'method', name: 'method', orderable: false, searchable: false },
                { data: 'user_name', name: 'user_name', orderable: false, searchable: false },
                @foreach ($buckets as $key => $range)
                { data: '{{ $bucket_cols[$key] }}', name: '{{ $bucket_cols[$key] }}', orderable: false, searchable: false, className: 'text-right' },
                @endforeach
                { data: 'total', name: 'total', orderable: false, searchable: false, className: 'text-right' },
            ],
            drawCallback: function () {
                var json = this.api().ajax.json();
                if (json && json.footer) {
                    // Update by class (not id): scrollX clones the <tfoot>, so a
                    // class selector reaches both the original and the visible clone.
                    @foreach ($buckets as $key => $range)
                    $('.pba_foot_{{ $bucket_cols[$key] }}').html(json.footer.buckets['{{ $key }}'] || '');
                    @endforeach
                    $('.pba_foot_total').html(json.footer.total || '');
                }
            },
        });

        $('#pba_customer_id, #pba_payment_types, #pba_location_id, #pba_user_id, #pba_age_filter').on('change', function () {
            payment_by_age_table.ajax.reload();
        });

        $('#pba_date_filter').daterangepicker(dateRangeSettings, function (start, end) {
            $('#pba_date_filter').val(
                start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format)
            );
            payment_by_age_table.ajax.reload();
        });

        $('#pba_date_filter').on('cancel.daterangepicker', function (ev, picker) {
            $('#pba_date_filter').val('');
            payment_by_age_table.ajax.reload();
        });
    });
</script>
@endsection
