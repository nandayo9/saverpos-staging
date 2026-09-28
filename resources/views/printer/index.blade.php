@extends('layouts.app')
@section('title', __('printer.printers'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang('printer.printers')
        <small class="tw-text-sm md:tw-text-base tw-text-gray-700 tw-font-semibold">@lang('printer.manage_your_printers')</small>
    </h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => __('printer.all_your_printer')])
        <div class="sb-table-host">
            <table class="table table-bordered table-striped" id="printer_table">
                <thead>
                    <tr>
                        <th>@lang('printer.name')</th>
                        <th>@lang('printer.connection_type')</th>
                        <th>@lang('printer.capability_profile')</th>
                        <th>@lang('printer.character_per_line')</th>
                        <th>@lang('printer.ip_address')</th>
                        <th>@lang('printer.port')</th>
                        <th>@lang('printer.path')</th>
                        <th class="not-export">@lang('messages.action')</th>
                    </tr>
                </thead>
            </table>
        </div>
    @endcomponent

</section>
<!-- /.content -->
@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready( function(){
        var printer_table = $('#printer_table').DataTable({
        scrollX: true,
            processing: true,
            serverSide: true,
            fixedHeader:false,
            buttons:[],
            ajax: '/printers',
            columnDefs: [ {
                "targets": [0, 1, 2, 3, 4, 5, 6],
                "className": 'text-center'
            }, {
                "targets": 2,
                "orderable": false,
                "searchable": false
            } ]
        });
        $(document).on('click', 'button.delete_printer_button', function(){
            swal({
              title: LANG.sure,
              text: LANG.confirm_delete_printer,
              icon: "warning",
              buttons: true,
              dangerMode: true,
            }).then((willDelete) => {
                if (willDelete) {
                    var href = $(this).data('href');
                    var data = $(this).serialize();

                    $.ajax({
                        method: "DELETE",
                        url: href,
                        dataType: "json",
                        data: data,
                        success: function(result){
                            if(result.success === true){
                                toastr.success(result.msg);
                                printer_table.ajax.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
            });
        });
        $(document).on('click', 'button.set_default', function(){
            var href = $(this).data('href');
            var data = $(this).serialize();

            $.ajax({
                method: "get",
                url: href,
                dataType: "json",
                data: data,
                success: function(result){
                    if(result.success === true){
                        toastr.success(result.msg);
                        printer_table.ajax.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });
    });
</script>
@endsection