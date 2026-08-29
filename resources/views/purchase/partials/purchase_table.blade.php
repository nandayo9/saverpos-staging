@if(auth()->user()->hidePurchasePrice())
<input type="hidden" id="hide_purchase_price_columns" value="1">
<style>.add_without_price_hide{display:none !important;}</style>
@endif
<table class="table table-bordered table-striped ajax_view" id="purchase_table" style="width: 100%;">
    <thead>
        <tr>
            <th class="not-export">@lang('messages.action')</th>
            <th>@lang('messages.date')</th>
            <th>@lang('purchase.ref_no')</th>
            <th>@lang('purchase.location')</th>
            <th>@lang('purchase.supplier')</th>
            <th>@lang('purchase.purchase_status')</th>
            <th class="add_without_price_hide">@lang('purchase.payment_status')</th>
            <th class="add_without_price_hide">@lang('purchase.grand_total')</th>
            @php
                $custom_labels = json_decode(session('business.custom_labels'), true);
            @endphp
            
            <th class="add_without_price_hide">@lang('purchase.payment_due') &nbsp;&nbsp;<i class="fa fa-info-circle text-info no-print" data-toggle="tooltip" data-placement="bottom" data-html="true" data-original-title="{{ __('messages.purchase_due_tooltip')}}" aria-hidden="true"></i></th>
           
            <th>{{ $custom_labels['purchase']['custom_field_1'] ?? '' }}</th>
            <th>{{ $custom_labels['purchase']['custom_field_2'] ?? '' }}</th>
            <th>{{ $custom_labels['purchase']['custom_field_3'] ?? '' }}</th>
            <th>{{ $custom_labels['purchase']['custom_field_4'] ?? '' }}</th>
            <th>@lang('lang_v1.added_by')</th>
        </tr>
    </thead>
    <tfoot>
        <tr class="bg-gray font-17 text-center footer-total">
            <td colspan="5"><strong>@lang('sale.total'):</strong></td>
            <td class="footer_status_count"></td>
            <td class="footer_payment_status_count add_without_price_hide"></td>
            <td class="footer_purchase_total add_without_price_hide"></td>
            <td class="text-left add_without_price_hide"><small>@lang('report.purchase_due') - <span class="footer_total_due"></span><br>
            @lang('lang_v1.purchase_return') - <span class="footer_total_purchase_return_due"></span>
            </small></td>
            <td></td>
        </tr>
    </tfoot>
</table>