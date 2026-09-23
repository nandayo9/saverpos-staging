@extends('layouts.app')

@section('title', 'Branch Attributed Sales Report')

@section('content')

<section class="content-header">
    <h1>Branch Attributed Sales Report</h1>
</section>

<section class="content">

    @component('components.widget', ['class' => 'box-primary', 'title' => __('report.filters')])
        <div class="row">

            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('sell_list_filter_date_range', __('report.date_range') . ':') !!}
                    {!! Form::text('sell_list_filter_date_range', null, [
                        'placeholder' => __('lang_v1.select_a_date_range'),
                        'class' => 'form-control',
                        'readonly'
                    ]) !!}
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('date_filter_type', 'Date Filter Type:') !!}
                    {!! Form::select('date_filter_type', [
                        'invoice_date' => 'Invoice Date',
                        'payment_date' => 'Payment Date'
                    ], 'invoice_date', [
                        'class' => 'form-control select2',
                        'style' => 'width:100%'
                    ]) !!}
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('attribution_branch_id', 'Attribution Branch:') !!}
                    {!! Form::select('attribution_branch_id', $business_locations, null, [
                        'class' => 'form-control select2',
                        'style' => 'width:100%',
                        'placeholder' => __('lang_v1.all')
                    ]) !!}
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('pos_location_id', 'POS Location:') !!}
                    {!! Form::select('pos_location_id', $business_locations, null, [
                        'class' => 'form-control select2',
                        'style' => 'width:100%',
                        'placeholder' => __('lang_v1.all')
                    ]) !!}
                </div>
            </div>

        </div>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Branch Attributed Sales'])
        {{-- No .table-responsive wrapper here. It scrolled the whole
             DataTables wrapper horizontally, which dragged "Showing x to y"
             and the pager sideways with the table. scrollX below gives the
             table body its own scroller and leaves the info and pager
             outside it, which is how the other tables in this app behave. --}}
        <div>
            <table class="table table-bordered table-striped" id="branch_attributed_sales_table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice No</th>
                        <th>Customer</th>
                        <th>POS Location</th>
                        <th>Attribution Branch</th>
                        <th>Commission Agent</th>
                        <th>Final Total</th>
                        <th>Paid In Selected Period</th>
                        <th>Payment Status</th>
                    </tr>
                </thead>
                <tfoot>
                    <tr class="bg-gray font-17 footer-total text-center">
                        <td colspan="6"><strong>@lang('sale.total'):</strong></td>
                        <td><span class="display_currency" id="footer_final_total" data-currency_symbol="true"></span></td>
                        <td><span class="display_currency" id="footer_paid_in_selected_period" data-currency_symbol="true"></span></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endcomponent

</section>

@endsection

@section('javascript')
<script type="text/javascript">
$(document).ready(function() {

    if ($('#sell_list_filter_date_range').length == 1) {
        $('#sell_list_filter_date_range').daterangepicker(
            dateRangeSettings,
            function(start, end) {
                $('#sell_list_filter_date_range').val(
                    start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format)
                );
                branch_attributed_sales_table.ajax.reload();
            }
        );

        $('#sell_list_filter_date_range').data('daterangepicker').setStartDate(moment().startOf('month'));
        $('#sell_list_filter_date_range').data('daterangepicker').setEndDate(moment().endOf('month'));

        $('#sell_list_filter_date_range').val(
            moment().startOf('month').format(moment_date_format) + ' ~ ' + moment().endOf('month').format(moment_date_format)
        );
    }

    var branch_attributed_sales_table = $('#branch_attributed_sales_table').DataTable({
        processing: true,
        serverSide: true,
        scrollX: true,
        aaSorting: [[0, 'desc']],
        // Date, Invoice No, Final Total, Paid In Selected Period and
        // Payment Status are centred; the four name/branch columns stay
        // left so they stay readable. Vertical centring is in
        // saverbro-layout.css so it also covers the cloned scrollX header.
        columnDefs: [
            { targets: [0, 1, 6, 7, 8], className: 'text-center' }
        ],
        ajax: {
            url: '/reports/branch-attributed-sales-data',
            data: function(d) {
                if ($('#sell_list_filter_date_range').val()) {
                    var start = $('#sell_list_filter_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                    var end = $('#sell_list_filter_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');

                    d.start_date = start;
                    d.end_date = end;
                }

                d.date_filter_type = $('#date_filter_type').val();
                d.attribution_branch_id = $('#attribution_branch_id').val();
                d.pos_location_id = $('#pos_location_id').val();
            }
        },
        columns: [
            { data: 'transaction_date', name: 'transactions.transaction_date' },
            { data: 'invoice_no', name: 'transactions.invoice_no' },
            { data: 'customer_name', name: 'contacts.name' },
            { data: 'pos_location', name: 'pos_location.name' },
            { data: 'attribution_branch', name: 'attribution_branch.name' },
            { data: 'commission_agent_name', name: 'commission_agent_name', searchable: false },
            { data: 'final_total', name: 'transactions.final_total' },
            { data: 'paid_in_selected_period', name: 'paid_in_selected_period', searchable: false },
            { data: 'payment_status', name: 'transactions.payment_status' }
        ],
        fnDrawCallback: function(oSettings) {
            var final_total = sum_table_col($('#branch_attributed_sales_table'), 'final-total');
            var paid_in_selected_period = sum_table_col($('#branch_attributed_sales_table'), 'paid-in-selected-period');

            $('#footer_final_total').text(final_total);
            $('#footer_paid_in_selected_period').text(paid_in_selected_period);

            __currency_convert_recursively($('#branch_attributed_sales_table'));
        }
    });

    $('#date_filter_type, #attribution_branch_id, #pos_location_id').change(function() {
        branch_attributed_sales_table.ajax.reload();
    });

});
</script>
@endsection
