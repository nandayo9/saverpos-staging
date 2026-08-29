@extends('layouts.app')
@section('title', __('report.z_report'))

@section('content')

    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('report.z_report')
            <small class="tw-text-sm md:tw-text-base tw-text-gray-700 tw-font-semibold">@lang('report.z_report_msg')</small>
        </h1>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="print_section">
            <h2>{{ session()->get('business.name') }} - @lang('report.z_report')</h2>
        </div>

        <div class="row no-print">
            <div class="col-md-3 col-xs-12">
                <div class="input-group">
                    <span class="input-group-addon bg-light-blue"><i class="fa fa-map-marker"></i></span>
                    <select class="form-control select2" id="z_report_location_filter">
                        @foreach ($business_locations as $key => $value)
                            <option value="{{ $key }}">{{ $value }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="col-md-3 col-xs-12">
                <div class="input-group">
                    <button type="button" class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm" id="z_report_date_filter">
                        <span><i class="fa fa-calendar"></i> {{ __('messages.filter_by_date') }}</span>
                        <i class="fa fa-caret-down"></i>
                    </button>
                </div>
            </div>

            <div class="col-md-6 col-xs-12 tw-flex tw-items-center tw-justify-end tw-gap-2">
                <button class="tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-sm no-print" aria-label="Print"
                    onclick="window.print();">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="icon icon-tabler icons-tabler-outline icon-tabler-printer">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" />
                        <path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" />
                        <path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" />
                    </svg> @lang('messages.print')
                </button>
            </div>
        </div>

        <div class="row tw-mt-4">
            <div id="z_report_data"></div>
        </div>
    </section>
    <!-- /.content -->
@stop
@section('javascript')
    <script type="text/javascript">
        $(document).ready(function() {
            var z_report_data_div = $('#z_report_data');

            function updateZReport() {
                if (typeof $('#z_report_date_filter').data('daterangepicker') == 'undefined') {
                    return;
                }
                var start = $('#z_report_date_filter').data('daterangepicker').startDate.format('YYYY-MM-DD');
                var end = $('#z_report_date_filter').data('daterangepicker').endDate.format('YYYY-MM-DD');
                var location_id = $('#z_report_location_filter').val();

                z_report_data_div.html('<div class="text-center"><i class="fas fa-sync fa-spin fa-fw fa-2x"></i></div>');

                $.ajax({
                    method: 'GET',
                    url: '/reports/z-report',
                    dataType: 'html',
                    data: {
                        start_date: start,
                        end_date: end,
                        location_id: location_id
                    },
                    success: function(html) {
                        z_report_data_div.html(html);
                        __currency_convert_recursively(z_report_data_div);
                    },
                });
            }

            if ($('#z_report_date_filter').length == 1) {
                $('#z_report_date_filter').daterangepicker(dateRangeSettings, function(start, end) {
                    $('#z_report_date_filter span').html(
                        start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format)
                    );
                    updateZReport();
                });
                $('#z_report_date_filter').on('cancel.daterangepicker', function(ev, picker) {
                    $('#z_report_date_filter').html(
                        '<i class="fa fa-calendar"></i> ' + LANG.filter_by_date
                    );
                });
                updateZReport();
            }

            $('#z_report_location_filter').change(function() {
                updateZReport();
            });
        });
    </script>
@endsection
