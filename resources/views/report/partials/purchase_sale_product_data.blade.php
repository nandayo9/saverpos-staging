@if (empty($groups))
    <div class="alert alert-info">
        <i class="fa fa-info-circle"></i> @lang('lang_v1.psp_no_data')
    </div>
@else
    <style>
        /* Thicker divider so each month block reads as its own section. */
        .psp-report-table .psp-month-start {
            border-left: 2px solid #8a8a8a !important;
        }
        .psp-report-table thead th {
            background-color: #f5f5f5;
        }
    </style>
    @foreach ($groups as $group)
        @component('components.widget', ['class' => 'box-primary'])
            <div class="table-responsive">
                <table class="table table-bordered table-striped psp-report-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="text-center" style="vertical-align: middle;">
                                {{ $group['name'] }}
                            </th>
                            <th rowspan="2" class="text-center psp-month-start" style="vertical-align: middle;">
                                @lang('lang_v1.psp_stock_balance')
                            </th>
                            @foreach ($months as $ym => $label)
                                <th colspan="2" class="text-center psp-month-start">{{ $label }}</th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach ($months as $ym => $label)
                                <th class="text-center psp-month-start">@lang('lang_v1.psp_purchase')</th>
                                <th class="text-center">@lang('lang_v1.psp_sales')</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['products'] as $product_id => $product)
                            <tr>
                                <td>
                                    {{ $product['name'] }}
                                    @if ($product['type'] === 'variable')
                                        <small class="text-muted">({{ __('lang_v1.variable') }})</small>
                                    @endif
                                </td>
                                <td class="text-right psp-month-start">
                                    {{ @format_quantity($stock[$product_id] ?? 0) }}
                                </td>
                                @foreach ($months as $ym => $label)
                                    @php
                                        $purchase_qty = $product['purchase'][$ym] ?? 0;
                                        $sales_qty = $product['sales'][$ym] ?? 0;
                                    @endphp
                                    <td class="text-right psp-month-start">{{ @format_quantity($purchase_qty) }}</td>
                                    <td class="text-right">{{ @format_quantity($sales_qty) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endcomponent
    @endforeach
@endif
