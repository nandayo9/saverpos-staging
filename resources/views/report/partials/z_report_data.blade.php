@php($currency_symbol = session('currency')['symbol'] ?? '')
<div class="col-md-8 col-md-offset-2 col-xs-12">
    @component('components.widget', ['class' => 'box-primary'])
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>@lang('report.label')</th>
                    <th class="text-right">@lang('report.amount')</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        @lang('report.total_item_sales')
                        <br><small class="text-muted">@lang('report.incl_discount'): {{ $currency_symbol }} <span class="display_currency">{{ $data['sell_discount'] }}</span></small>
                    </td>
                    <td class="text-right">
                        {{ $currency_symbol }} <span class="display_currency">{{ $data['total_item_sales'] }}</span>
                    </td>
                </tr>
                <tr>
                    <td>@lang('report.sell_returns') (-)</td>
                    <td class="text-right">
                        {{ $currency_symbol }} <span class="display_currency">{{ $data['total_sell_return'] }}</span>
                    </td>
                </tr>
                <tr>
                    <td>
                        @lang('report.purchases') (-)
                        <br><small class="text-muted">@lang('report.incl_discount'): {{ $currency_symbol }} <span class="display_currency">{{ $data['purchase_discount'] }}</span></small>
                    </td>
                    <td class="text-right">
                        {{ $currency_symbol }} <span class="display_currency">{{ $data['total_purchase'] }}</span>
                    </td>
                </tr>
                <tr>
                    <td>@lang('report.purchase_returns') (+)</td>
                    <td class="text-right">
                        {{ $currency_symbol }} <span class="display_currency">{{ $data['total_purchase_return'] }}</span>
                    </td>
                </tr>
                <tr>
                    <td>@lang('report.delivery_charge') (+)</td>
                    <td class="text-right">
                        {{ $currency_symbol }} <span class="display_currency">{{ $data['total_delivery_charge'] }}</span>
                    </td>
                </tr>
                <tr>
                    <td>@lang('report.expenses') (-)</td>
                    <td class="text-right">
                        {{ $currency_symbol }} <span class="display_currency">{{ $data['total_expense'] }}</span>
                    </td>
                </tr>
                <tr class="bg-gray">
                    <th>@lang('report.total_sales_in_hand')</th>
                    <th class="text-right">
                        {{ $currency_symbol }} <span class="display_currency">{{ $data['total_sales_in_hand'] }}</span>
                    </th>
                </tr>
            </tbody>
        </table>
    @endcomponent
</div>
