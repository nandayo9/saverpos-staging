@extends('layouts.app')
@section('title', 'Sync Log')

@section('content')

    <section class="content-header">
        @include('woocommerce.partials.tabs')
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Sync Log</h1>
    </section>

    <section class="content">
        @component('components.widget', ['class' => 'box-primary'])
            {{-- Real data: woocommerce_sync_logs came across with the production
                 import, so these are the same rows the live site lists. Served
                 server-side because the table holds a few thousand of them. --}}
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="woocommerce_sync_log_table">
                    <thead>
                        <tr>
                            <th>@lang('messages.date')</th>
                            <th>Sync Type</th>
                            <th>Operation</th>
                            <th>Synced By</th>
                            <th>Records</th>
                        </tr>
                    </thead>
                </table>
            </div>
        @endcomponent
    </section>
@stop

@section('javascript')
    <script type="text/javascript">
        $(document).ready(function () {
            $('#woocommerce_sync_log_table').DataTable({
                processing: true,
                serverSide: true,
                fixedHeader: false,
                ajax: '{{ action([\App\Http\Controllers\WoocommerceController::class, 'syncLog']) }}',
                // Newest first, as on the live log.
                order: [[0, 'desc']],
                columns: [
                    { data: 'created_at', name: 'wsl.created_at', className: 'text-center' },
                    { data: 'sync_type', name: 'wsl.sync_type', className: 'text-center' },
                    { data: 'operation_type', name: 'wsl.operation_type', className: 'text-center' },
                    { data: 'synced_by', name: 'u.first_name', className: 'text-center' },
                    { data: 'records', name: 'wsl.data', orderable: false, searchable: false },
                ],
            });
        });
    </script>
@endsection
