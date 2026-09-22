@extends('layouts.app')
@section('title', __('lang_v1.types_of_service'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang( 'lang_v1.types_of_service' ) @show_tooltip(__('lang_v1.types_of_service_help_long'))
    </h1>
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary'])
        @slot('tool')
            <div class="box-tools">
                <button class="tw-inline-flex tw-items-center tw-bg-gradient-to-r tw-from-indigo-500 tw-to-blue-500 tw-font-semibold tw-text-xs tw-px-3 tw-py-1 tw-rounded-lg tw-shadow-md hover:tw-from-indigo-600 hover:tw-to-blue-600 hover:tw-shadow-lg tw-transition tw-duration-200 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-blue-500 focus:tw-ring-offset-2 active:tw-from-indigo-700 active:tw-to-blue-700 btn-modal pull-right"
                    data-href="{{action([\App\Http\Controllers\TypesOfServiceController::class, 'create'])}}" 
                    data-container=".type_of_service_modal">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="icon icon-tabler icons-tabler-outline icon-tabler-plus tw-mr-1">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M12 5l0 14" />
                        <path d="M5 12l14 0" />
                    </svg> @lang('messages.add')
                </button>
            </div>
        @endslot
        @can('brand.view')
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="types_of_service_table">
                    <thead>
                        <tr>
                            <th>@lang( 'tax_rate.name' )</th>
                            <th>@lang( 'lang_v1.description' )</th>
                            <th>@lang( 'lang_v1.packing_charge' )</th>
                            <th class="not-export">@lang( 'messages.action' )</th>
                        </tr>
                    </thead>
                </table>
            </div>
        @endcan
    @endcomponent

    <div class="modal fade type_of_service_modal contains_select2" tabindex="-1" role="dialog" 
    	aria-labelledby="gridSystemModalLabel">
    </div>

</section>
<!-- /.content -->

@endsection
