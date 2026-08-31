$(document).ready(function() {
    if ($('#dashboard_date_filter').length == 1) {
        dateRangeSettings.startDate = moment();
        dateRangeSettings.endDate = moment();
        $('#dashboard_date_filter').daterangepicker(dateRangeSettings, function(start, end) {
            $('#dashboard_date_filter span').html(
                start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format)
            );
            update_statistics(start.format('YYYY-MM-DD'), end.format('YYYY-MM-DD'));
            if ($('#quotation_table').length && $('#dashboard_location').length) {
                quotation_datatable.ajax.reload();
            }
        });

        update_statistics(moment().format('YYYY-MM-DD'), moment().format('YYYY-MM-DD'));
    }

    $('#dashboard_location').change( function(e) {
        var start = $('#dashboard_date_filter')
                    .data('daterangepicker')
                    .startDate.format('YYYY-MM-DD');

        var end = $('#dashboard_date_filter')
                    .data('daterangepicker')
                    .endDate.format('YYYY-MM-DD');

        update_statistics(start, end);
    });

    //Most available models datatable
    var most_available_models_table = $('#most_available_models_table').DataTable({
        processing: true,
        serverSide: true,
        ordering: false,
        searching: false,
        scrollX:        true,
        fixedHeader: false,
        dom: 'Btirp',
        pageLength: 5,
        columns: [
            { data: 'model', name: 'p.name' },
            { data: 'available', name: 'available' },
            { data: 'avg_age', name: 'avg_age' },
            { data: 'avg_cost', name: 'avg_cost' },
            { data: 'stock_value', name: 'stock_value' },
        ],
        ajax: {
            "url": '/home/most-available-models',
            "data": function ( d ) {
                if ($('#most_available_models_location').length > 0) {
                    d.location_id = $('#most_available_models_location').val();
                }
            }
        },
        fnDrawCallback: function(oSettings) {
            __currency_convert_recursively($('#most_available_models_table'));
        },
    });

    $('#most_available_models_location').change( function(){
        most_available_models_table.ajax.reload();
    });
    //Recent sales transactions datatable
    recent_sell_transactions_table = $('#recent_sell_transactions_table').DataTable({
        processing: true,
        serverSide: true,
        ordering: false,
        searching: false,
        scrollX:        true,
        fixedHeader: false,
        dom: 'Btirp',
        pageLength: 5,
        columns: [
            { data: 'transaction_date', name: 'transactions.transaction_date' },
            { data: 'customer', name: 'c.name' },
            { data: 'invoice_no', name: 'transactions.invoice_no' },
            { data: 'final_total', name: 'transactions.final_total' },
            { data: 'payment_status', name: 'transactions.payment_status' },
        ],
        ajax: {
            "url": '/home/recent-sell-transactions',
            "data": function ( d ) {
                if ($('#recent_sell_transactions_location').length > 0) {
                    d.location_id = $('#recent_sell_transactions_location').val();
                }
            }
        },
        fnDrawCallback: function(oSettings) {
            __currency_convert_recursively($('#recent_sell_transactions_table'));
        },
    });

    $('#recent_sell_transactions_location').change( function(){
        recent_sell_transactions_table.ajax.reload();
    });

    //Top selling products datatable
    top_selling_products_table = $('#top_selling_products_table').DataTable({
        processing: true,
        serverSide: true,
        ordering: false,
        searching: false,
        scrollX:        true,
        fixedHeader: false,
        dom: 'Btirp',
        pageLength: 5,
        columns: [
            { data: 'product', name: 'p.name' },
            { data: 'quantity_sold', name: 'quantity_sold' },
            { data: 'sales_total', name: 'sales_total' },
        ],
        ajax: {
            "url": '/home/top-selling-products',
            "data": function ( d ) {
                if ($('#top_selling_products_location').length > 0) {
                    d.location_id = $('#top_selling_products_location').val();
                }
            }
        },
        fnDrawCallback: function(oSettings) {
            __currency_convert_recursively($('#top_selling_products_table'));
        },
    });

    $('#top_selling_products_location').change( function(){
        top_selling_products_table.ajax.reload();
    });

    //Stock expiry report table
    stock_expiry_alert_table = $('#stock_expiry_alert_table').DataTable({
        processing: true,
        serverSide: true,
        searching: false,
        scrollY:        "75vh",
        scrollX:        true,
        scrollCollapse: true,
        fixedHeader: false,
        dom: 'Btirp',
        ajax: {
            url: '/reports/stock-expiry',
            data: function(d) {
                d.exp_date_filter = $('#stock_expiry_alert_days').val();
            },
        },
        order: [[3, 'asc']],
        columns: [
            { data: 'product', name: 'p.name' },
            { data: 'location', name: 'l.name' },
            { data: 'stock_left', name: 'stock_left' },
            { data: 'exp_date', name: 'exp_date' },
        ],
        fnDrawCallback: function(oSettings) {
            __show_date_diff_for_human($('#stock_expiry_alert_table'));
            __currency_convert_recursively($('#stock_expiry_alert_table'));
        },
    });

    if ($('#quotation_table').length) {
        quotation_datatable = $('#quotation_table').DataTable({
            processing: true,
            serverSide: true,
            fixedHeader:false,
            aaSorting: [[0, 'desc']],
            "ajax": {
                "url": '/sells/draft-dt?is_quotation=1',
                "data": function ( d ) {
                    if ($('#dashboard_location').length > 0) {
                        d.location_id = $('#dashboard_location').val();
                    }
                }
            },
            columnDefs: [ {
                "targets": 4,
                "orderable": false,
                "searchable": false
            } ],
            columns: [
                { data: 'transaction_date', name: 'transaction_date'  },
                { data: 'invoice_no', name: 'invoice_no'},
                { data: 'name', name: 'contacts.name'},
                { data: 'business_location', name: 'bl.name'},
                { data: 'action', name: 'action'}
            ]            
        });
    }
});

function update_statistics(start, end) {
    var location_id = '';
    if ($('#dashboard_location').length > 0) {
        location_id = $('#dashboard_location').val();
    }
    var data = { start: start, end: end, location_id: location_id };
    //get purchase details
    var loader = '<i class="fas fa-sync fa-spin fa-fw margin-bottom"></i>';
    $('.total_purchase, .purchase_due, .total_sell, .invoice_due, .total_expense, .total_purchase_return, .total_sell_return, .net')
        .not('.sb-dashboard-kpi__value').html(loader);
    $.ajax({
        method: 'get',
        url: '/home/get-totals',
        dataType: 'json',
        data: data,
        success: function(data) {
            //purchase details
            $('.total_purchase').html(__currency_trans_from_en(data.total_purchase, true));
            $('.purchase_due').html(__currency_trans_from_en(data.purchase_due || 0, true));

            //sell details
            $('.total_sell').html(__currency_trans_from_en(data.total_sell || 0, true));
            $('.invoice_due').html(__currency_trans_from_en(data.invoice_due || 0, true));
            $('.walk_in_total').text(data.walk_ins || 0);
            $('.total_sell_transactions').text(data.total_sell_transactions || 0);
            $('.total_expense_kpi').html(__currency_trans_from_en(data.total_expense || 0, true));
            $('.total_sell_return_total').html(__currency_trans_from_en(data.total_sell_return_total || 0, true));
            $('.total_stock_adjustment').html(__currency_trans_from_en(data.total_adjustment || 0, true));
            //expense details
            $('.total_expense').html(__currency_trans_from_en(data.total_expense, true));
            var total_purchase_return = data.total_purchase_return - data.total_purchase_return_paid;
            $('.total_purchase_return').html(__currency_trans_from_en(total_purchase_return, true));
            var total_sell_return_due = data.total_sell_return - data.total_sell_return_paid;
            $('.total_sell_return').html(__currency_trans_from_en(total_sell_return_due, true));
            $('.total_sr').html(__currency_trans_from_en(data.total_sell_return, true));
            $('.total_srp').html(__currency_trans_from_en(data.total_sell_return_paid, true));
            $('.total_pr').html(__currency_trans_from_en(data.total_purchase_return, true));
            $('.total_prp').html(__currency_trans_from_en(data.total_purchase_return_paid, true));
            $('.net').html(__currency_trans_from_en(data.net, true));

            // assign tooltip total_sell_return 
            var lang = $('#total_srp').data('value');
            if (lang) {
                var splitlang = lang.split('-');
                var newContent = "<p class='mb-0 text-muted fs-10 mt-5'>" + splitlang[0] + ": <span class=''>" + __currency_trans_from_en(data.total_sell_return, true) + "</span><br>" + splitlang[1] + ": <span class=''>" + __currency_trans_from_en(data.total_sell_return_paid, true) + "</span></p>";
                $('#total_srp').attr('data-content', newContent)
            }
            // assign tooltip total_purchase_return 
            var lang = $('#total_prp').data('value');
            if (lang) {
                var splitlang = lang.split('-');
                var newContent = "<p class='mb-0 text-muted fs-10 mt-5'>" + splitlang[0] + ": <span class=''>" + __currency_trans_from_en(data.total_purchase_return, true) + "</span><br>" + splitlang[1] + ": <span class=''>" + __currency_trans_from_en(data.total_purchase_return_paid, true) + "</span></p>";
                $('#total_prp').attr('data-content', newContent);
            }

        },
    });
}
