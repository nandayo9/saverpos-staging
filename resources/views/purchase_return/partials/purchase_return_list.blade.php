@if(auth()->user()->hidePurchasePrice())
<input type="hidden" id="hide_purchase_price_columns" value="1">
<style>.add_without_price_hide{display:none !important;}</style>
@endif
<div class="table-responsive">
    <table class="table table-bordered table-striped ajax_view" id="purchase_return_datatable">
        <thead>
            <tr>
                <th>@lang('messages.date')</th>
                <th>@lang('purchase.ref_no')</th>
                <th>@lang('lang_v1.parent_purchase')</th>
                <th>@lang('purchase.location')</th>
                <th>@lang('purchase.supplier')</th>
                <th class="add_without_price_hide">@lang('purchase.payment_status')</th>
                <th class="add_without_price_hide">@lang('purchase.grand_total')</th>
                <th class="add_without_price_hide">@lang('purchase.payment_due') &nbsp;&nbsp;<i class="fa fa-info-circle text-info" data-toggle="tooltip" data-placement="bottom" data-html="true" data-original-title="{{ __('messages.purchase_due_tooltip')}}" aria-hidden="true"></i></th>
                <th class="not-export">@lang('messages.action')</th>
            </tr>
        </thead>
        <tfoot>
            <tr class="bg-gray font-17 text-center footer-total">
                <td colspan="5"><strong>@lang('sale.total'):</strong></td>
                <td id="footer_payment_status_count" class="add_without_price_hide"></td>
                <td class="add_without_price_hide"><span class="display_currency" id="footer_purchase_return_total" data-currency_symbol ="true"></span></td>
                <td class="add_without_price_hide"><span class="display_currency" id="footer_total_due" data-currency_symbol ="true"></span></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>