@extends('layouts.app')
@section('title', __('lang_v1.sell_payment_report'))

@section('content')
@php $custom_labels = json_decode(session('business.custom_labels'), true); @endphp

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">{{ __('lang_v1.sell_payment_report')}}</h1>
</section>

<!-- Main content -->
<section class="content no-print">
    <div class="row">
        <div class="col-md-12">
           @component('components.filters', ['title' => __('report.filters')])
              {!! Form::open(['url' => '#', 'method' => 'get', 'id' => 'sell_payment_report_form' ]) !!}
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('customer_id', __('contact.customer') . ':') !!}
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="fa fa-user"></i>
                            </span>
                            {!! Form::select('customer_id', $customers, null, ['class' => 'form-control select2',  'style' => 'width:100%', 'placeholder' => __('messages.all'), 'required']); !!}
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('location_id', __('purchase.business_location').':') !!}
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="fa fa-map-marker"></i>
                            </span>
                            {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2',  'style' => 'width:100%', 'placeholder' => __('messages.all'), 'required']); !!}
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('payment_types', __('lang_v1.payment_method').':') !!}
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="fas fa-money-bill-alt"></i>
                            </span>
                            {!! Form::select('payment_types', $payment_types, null, ['class' => 'form-control select2', 'placeholder' => __('messages.all'), 'required', 'style' => 'width:100%']); !!}
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('customer_group_filter', __('lang_v1.customer_group').':') !!}
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="fa fa-users"></i>
                            </span>
                            {!! Form::select('customer_group_filter', $customer_groups, null, ['class' => 'form-control select2', 'style' => 'width:100%']); !!}
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('spr_user_id', __('report.user').':') !!}
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="fa fa-user"></i>
                            </span>
                            {!! Form::select('user_id', $users, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('messages.all'), 'id' => 'spr_user_id']); !!}
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('spr_date_filter', __('report.date_range') . ':') !!}
                        {!! Form::text('date_range', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'id' => 'spr_date_filter', 'readonly']); !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('spr_age_filter', __('lang_v1.age') . ':') !!}
                        {!! Form::select('age_filter', [
                            '0-15'  => '0 - 15 ' . __('lang_v1.days'),
                            '16-30' => '16 - 30 ' . __('lang_v1.days'),
                            '31-45' => '31 - 45 ' . __('lang_v1.days'),
                            '46-60' => '46 - 60 ' . __('lang_v1.days'),
                            '61-75' => '61 - 75 ' . __('lang_v1.days'),
                            '76-90' => '76 - 90 ' . __('lang_v1.days'),
                            '90+'   => '90+ ' . __('lang_v1.days'),
                        ], null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('messages.all'), 'id' => 'spr_age_filter']); !!}
                    </div>
                </div>
                @if(!empty($custom_labels['sell']['custom_field_1']) || !empty($custom_labels['sell']['custom_field_2']) || !empty($custom_labels['sell']['custom_field_3']) || !empty($custom_labels['sell']['custom_field_4']))
                <div class="col-md-12"></div>
                @if(!empty($custom_labels['sell']['custom_field_1']))
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('spr_custom_field_1', $custom_labels['sell']['custom_field_1'] . ':') !!}
                        {!! Form::text('custom_field_1', null, ['class' => 'form-control', 'id' => 'spr_custom_field_1', 'placeholder' => $custom_labels['sell']['custom_field_1']]); !!}
                    </div>
                </div>
                @endif
                @if(!empty($custom_labels['sell']['custom_field_2']))
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('spr_custom_field_2', $custom_labels['sell']['custom_field_2'] . ':') !!}
                        {!! Form::text('custom_field_2', null, ['class' => 'form-control', 'id' => 'spr_custom_field_2', 'placeholder' => $custom_labels['sell']['custom_field_2']]); !!}
                    </div>
                </div>
                @endif
                @if(!empty($custom_labels['sell']['custom_field_3']))
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('spr_custom_field_3', $custom_labels['sell']['custom_field_3'] . ':') !!}
                        {!! Form::text('custom_field_3', null, ['class' => 'form-control', 'id' => 'spr_custom_field_3', 'placeholder' => $custom_labels['sell']['custom_field_3']]); !!}
                    </div>
                </div>
                @endif
                @if(!empty($custom_labels['sell']['custom_field_4']))
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('spr_custom_field_4', $custom_labels['sell']['custom_field_4'] . ':') !!}
                        {!! Form::text('custom_field_4', null, ['class' => 'form-control', 'id' => 'spr_custom_field_4', 'placeholder' => $custom_labels['sell']['custom_field_4']]); !!}
                    </div>
                </div>
                @endif
                @endif
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped ajax_view" 
                    id="sell_payment_report_table">
                        <thead>
                            <tr>
                                <th>&nbsp;</th>
                                <th>@lang('purchase.ref_no')</th>
                                <th>@lang('lang_v1.paid_on')</th>
                                <th>@lang('sale.amount')</th>
                                <th>@lang('lang_v1.age')</th>
                                <th>@lang('contact.customer')</th>
                                <th>@lang('lang_v1.contact_id')</th>
                                <th>@lang('lang_v1.customer_group')</th>
                                <th>@lang('lang_v1.payment_method')</th>
                                <th>@lang('sale.sale')</th>
                                <th>@lang('report.user')</th>
                                <th>{{ $custom_labels['sell']['custom_field_1'] ?? '' }}</th>
                                <th>{{ $custom_labels['sell']['custom_field_2'] ?? '' }}</th>
                                <th>{{ $custom_labels['sell']['custom_field_3'] ?? '' }}</th>
                                <th>{{ $custom_labels['sell']['custom_field_4'] ?? '' }}</th>
                                <th class="not-export">@lang('messages.action')</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total text-center">
                                <td colspan="3"><strong>@lang('sale.total'):</strong></td>
                                <td><span class="display_currency footer_total_amount" id="footer_total_amount" data-currency_symbol ="true"></span></td>
                                <td colspan="12"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endcomponent
        </div>
    </div>
</section>
<!-- /.content -->
<div class="modal fade view_register" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>

@endsection

@section('javascript')
    <script type="text/javascript">
        var spr_custom_field_visible = {
            cf1: {{ !empty($custom_labels['sell']['custom_field_1']) ? 'true' : 'false' }},
            cf2: {{ !empty($custom_labels['sell']['custom_field_2']) ? 'true' : 'false' }},
            cf3: {{ !empty($custom_labels['sell']['custom_field_3']) ? 'true' : 'false' }},
            cf4: {{ !empty($custom_labels['sell']['custom_field_4']) ? 'true' : 'false' }}
        };
    </script>
    <script src="{{ asset('js/report.js?v=' . $asset_v) }}"></script>
    <script src="{{ asset('js/payment.js?v=' . $asset_v) }}"></script>
@endsection