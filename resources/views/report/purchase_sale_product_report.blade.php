@extends('layouts.app')
@section('title', __('lang_v1.purchase_sale_product_report'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">{{ __('lang_v1.purchase_sale_product_report') }}</h1>
</section>

<!-- Main content -->
<section class="content">
    <input type="hidden" id="psp_group_by" value="category">

    <div class="row">
        <div class="col-md-12">
            @component('components.widget')
                <div class="nav-tabs-custom">
                    <ul class="nav nav-tabs" id="psp_group_tabs">
                        <li class="psp-group-tab active" data-group="category">
                            <a href="#" data-group="category">
                                <i class="fa fa-sitemap"></i> <strong>@lang('lang_v1.psp_by_category')</strong>
                            </a>
                        </li>
                        <li class="psp-group-tab" data-group="brand">
                            <a href="#" data-group="brand">
                                <i class="fa fa-tags"></i> <strong>@lang('lang_v1.psp_by_brand')</strong>
                            </a>
                        </li>
                        <li class="psp-group-tab" data-group="supplier">
                            <a href="#" data-group="supplier">
                                <i class="fa fa-truck"></i> <strong>@lang('lang_v1.psp_by_supplier')</strong>
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content">
                        {{-- Filters + results. Category is the default active grouping. --}}
                        <div id="psp_filters_wrap">
                            <div class="row">
                                <div class="col-md-3 psp-entity-wrap" data-group="category">
                                    <div class="form-group">
                                        {!! Form::label('psp_category_id', __('lang_v1.psp_by_category') . ':') !!}
                                        {!! Form::select('psp_category_id', $categories, null, ['class' => 'form-control select2 psp-entity', 'data-group' => 'category', 'style' => 'width:100%;', 'placeholder' => __('lang_v1.all')]) !!}
                                    </div>
                                </div>
                                <div class="col-md-3 psp-entity-wrap" data-group="brand" style="display: none;">
                                    <div class="form-group">
                                        {!! Form::label('psp_brand_id', __('lang_v1.psp_by_brand') . ':') !!}
                                        {!! Form::select('psp_brand_id', $brands, null, ['class' => 'form-control select2 psp-entity', 'data-group' => 'brand', 'style' => 'width:100%;', 'placeholder' => __('lang_v1.all')]) !!}
                                    </div>
                                </div>
                                <div class="col-md-3 psp-entity-wrap" data-group="supplier" style="display: none;">
                                    <div class="form-group">
                                        {!! Form::label('psp_supplier_id', __('lang_v1.psp_by_supplier') . ':') !!}
                                        {!! Form::select('psp_supplier_id', $suppliers, null, ['class' => 'form-control select2 psp-entity', 'data-group' => 'supplier', 'style' => 'width:100%;', 'placeholder' => __('lang_v1.all')]) !!}
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        {!! Form::label('psp_period', __('lang_v1.psp_period') . ':') !!}
                                        {!! Form::select('psp_period', [1 => __('lang_v1.psp_last_1_month'), 3 => __('lang_v1.psp_last_3_months'), 6 => __('lang_v1.psp_last_6_months'), 9 => __('lang_v1.psp_last_9_months'), 12 => __('lang_v1.psp_last_12_months')], 3, ['class' => 'form-control select2', 'id' => 'psp_period', 'style' => 'width:100%;']) !!}
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        {!! Form::label('psp_location_id', __('purchase.business_location') . ':') !!}
                                        {!! Form::select('psp_location_id', $business_locations, null, ['class' => 'form-control select2', 'id' => 'psp_location_id', 'style' => 'width:100%;', 'placeholder' => __('lang_v1.all')]) !!}
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="button" class="btn btn-primary form-control" id="psp_generate_btn">
                                            <i class="fa fa-search"></i> @lang('lang_v1.search')
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <div id="psp_report_container">
                                <div class="alert alert-info">
                                    <i class="fa fa-info-circle"></i> @lang('lang_v1.psp_search_prompt')
                                </div>
                            </div>
                        </div>
                    </div>
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
        var psp_data_url = '{{ url('/reports/purchase-sale-product-data') }}';

        function pspLoadReport() {
            var group_by = $('#psp_group_by').val();
            if (!group_by) {
                return;
            }

            var entity_id = $('.psp-entity-wrap[data-group="' + group_by + '"]').find('.psp-entity').val();

            $('#psp_report_container').html(
                '<div class="text-center" style="padding:20px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>'
            );

            $.get(psp_data_url, {
                group_by: group_by,
                entity_id: entity_id,
                period: $('#psp_period').val(),
                location_id: $('#psp_location_id').val()
            }, function (data) {
                $('#psp_report_container').html(data);
            });
        }

        var psp_search_prompt = '<div class="alert alert-info"><i class="fa fa-info-circle"></i> {{ __('lang_v1.psp_search_prompt') }}</div>';

        $('#psp_group_tabs').on('click', '.psp-group-tab a', function (e) {
            e.preventDefault();
            var group = $(this).data('group');

            // Switching grouping resets the previous result; user must Search again.
            if ($('#psp_group_by').val() !== group) {
                $('#psp_report_container').html(psp_search_prompt);
            }

            $('.psp-group-tab').removeClass('active');
            $(this).closest('.psp-group-tab').addClass('active');

            $('#psp_group_by').val(group);

            // Show only the entity selector that matches the active grouping.
            $('.psp-entity-wrap').hide();
            $('.psp-entity-wrap[data-group="' + group + '"]').show();
        });

        $('#psp_generate_btn').on('click', function () {
            pspLoadReport();
        });
    });
</script>
@endsection
