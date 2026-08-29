{{--
    Vector (mPDF) label page.
    Renders ONE page's grid of labels. Mirrors labels.partials.preview_2 field-for-field
    (every print toggle + font size), but:
      - uses a table grid instead of flexbox (mPDF has no flexbox), and
      - renders the barcode as a native vector <barcode> (crisp at any DPI, spec quiet zone built in).
    Geometry (label w/h, row/col distance, stickers per row, paper size, margins) all come from
    $barcode_details, identical to the HTML preview.
--}}
@php
    $stickers_in_one_row = max(1, (int) $barcode_details->stickers_in_one_row);
    $label_w = $barcode_details->width * 1;        // inches
    $label_h = $barcode_details->height * 1;       // inches
    $col_gap = $barcode_details->col_distance * 1; // inches
    $row_gap = $barcode_details->row_distance * 1; // inches

    $custom_labels = json_decode(session('business.custom_labels'), true);
    $product_custom_fields = ! empty($custom_labels['product']) ? $custom_labels['product'] : [];

    $count = count($page_products);
    $remainder = $count % $stickers_in_one_row;
@endphp

<style type="text/css">
    table.labels-grid {
        border-collapse: separate;
        border-spacing: {{ $col_gap }}in {{ $row_gap }}in;
        border: none;
    }
    table.labels-grid tr {
        page-break-inside: avoid;
    }
    /* Each cell is locked to the configured label height so that EXACTLY stickers_in_one_sheet
       labels fit on one physical page (never spilling onto a 2nd page). The controller's
       fit-to-label scale (barcode_render.fit_scale) shrinks the fonts + bars just enough that the
       content never exceeds this height, so overflow:hidden is only a safety backstop and does not
       actually clip the SKU. */
    table.labels-grid td.label-cell {
        width: {{ $label_w }}in;
        height: {{ $label_h }}in;
        text-align: center;
        vertical-align: top;
        overflow: hidden;
        line-height: 1.1;
        border: none;
        /* No horizontal padding: the barcode is sized to 96% of the FULL label width (it carries its
           own 4% light margin), so side padding here would shrink the content box below the barcode
           width and clip the quiet zone => it won't scan. Vertical: tight top, clear bottom cut gap. */
        padding: 1.5pt 0 4pt 0;
        box-sizing: border-box;
    }
    /* mPDF treats <div> as block reliably (it ignores display:block on <span>),
       so each label line below is a <div> to guarantee its own line. */
    .lbl-line { display: block; text-align: center; }
    .lbl-sku  { display: block; text-align: center; font-size: 10px; padding-top: 1pt; }
    /* a small gap above the barcode separates it from the text block (scanner sees a clean
       white band above the bars) and below before the human-readable SKU */
    .barcode-cell { text-align: center; padding-top: 2pt; padding-bottom: 1pt; }
</style>

<table class="labels-grid" border="0" cellpadding="0">
@foreach($page_products as $idx => $page_product)
    @if($idx % $stickers_in_one_row == 0)
        <tr>
    @endif
        <td class="label-cell">
            @php
                // Per-label fit-to-height scale from the controller: 1.0 when the content fits the
                // configured label height as-is, < 1.0 when fonts + bars had to be shrunk together so
                // the full sheet stays on a single page. Every font-size below is multiplied by it.
                $fs = (! empty($page_product->barcode_render) && ! empty($page_product->barcode_render['fit_scale']))
                    ? $page_product->barcode_render['fit_scale'] : 1;
            @endphp

            {{-- Business Name --}}
            @if(!empty($print['business_name']))
                <div class="lbl-line" style="font-size: {{$print['business_name_size'] * $fs}}px"><b>{{$business_name}}</b></div>
            @endif

            {{-- Product Name (+ optional lot number inline) --}}
            @if(!empty($print['name']))
                <div class="lbl-line" style="font-size: {{$print['name_size'] * $fs}}px">
                    {{$page_product->product_actual_name}}
                    @if(!empty($print['lot_number']) && !empty($page_product->lot_number))
                        <span style="font-size: {{12*$factor*$fs}}px"> ({{$page_product->lot_number}})</span>
                    @endif
                </div>
            @endif

            {{-- Variation --}}
            @if(!empty($print['variations']) && $page_product->is_dummy != 1)
                <div class="lbl-line" style="font-size: {{$print['variations_size'] * $fs}}px">
                    {{$page_product->product_variation_name}}:<b>{{$page_product->variation_name}}</b>
                </div>
            @endif

            {{-- Product custom fields (each on its own line) --}}
            @foreach($product_custom_fields as $index => $cf)
                @php
                    $field_name = 'product_custom_field' . $loop->iteration;
                @endphp
                @if(!empty($cf) && !empty($page_product->$field_name) && !empty($print[$field_name]))
                    <div class="lbl-line" style="font-size: {{ $print[$field_name . '_size'] * $fs }}px">
                        <b>{{ $cf }}:</b> {{ $page_product->$field_name }}
                    </div>
                @endif
            @endforeach

            {{-- Price (own line) --}}
            @if(!empty($print['price']))
                <div class="lbl-line" style="font-size: {{$print['price_size'] * $fs}}px;">
                    @lang('lang_v1.price'):
                    <b>{{session('currency')['symbol'] ?? ''}}@if($print['price_type'] == 'inclusive'){{@num_format($page_product->sell_price_inc_tax)}}@else{{@num_format($page_product->default_sell_price)}}@endif</b>
                </div>
            @endif

            {{-- Expiry date (own line) --}}
            @if(!empty($print['exp_date']) && !empty($page_product->exp_date))
                <div class="lbl-line" style="font-size: {{$print['exp_date_size'] * $fs}}px">
                    <b>@lang('product.exp_date'):</b> {{$page_product->exp_date}}
                </div>
            @endif

            {{-- Packing date (own line) --}}
            @if(!empty($print['packing_date']) && !empty($page_product->packing_date))
                <div class="lbl-line" style="font-size: {{$print['packing_date_size'] * $fs}}px">
                    <b>@lang('lang_v1.packing_date'):</b> {{$page_product->packing_date}}
                </div>
            @endif

            {{-- Barcode (native vector) --}}
            <div class="barcode-cell">
                @if(!empty($page_product->barcode_render))
                    {{-- quiet_zone_left/right pinned to the spec 10X so the light margin is guaranteed
                         regardless of mPDF defaults, and stays consistent with the width-fit math in
                         setBarcodeRenderProps() (which budgets the same 10X lightmL/lightmR). --}}
                    <barcode code="{{ $page_product->barcode_render['code'] }}" type="{{ $page_product->barcode_render['type'] }}" size="{{ $page_product->barcode_render['size'] }}" height="{{ $page_product->barcode_render['height'] }}" quiet_zone_left="10" quiet_zone_right="10" />
                @endif
            </div>

            {{-- Human-readable SKU (10px, scaled by the same fit factor as the lines above) --}}
            <div class="lbl-sku" style="font-size: {{ 10 * $fs }}px">{{$page_product->sub_sku}}</div>

        </td>
    @if(($idx + 1) % $stickers_in_one_row == 0)
        </tr>
    @endif
@endforeach

@if($remainder != 0)
    {{-- pad the final row so the grid stays aligned --}}
    @for($k = 0; $k < $stickers_in_one_row - $remainder; $k++)
        <td class="label-cell"></td>
    @endfor
    </tr>
@endif
</table>
