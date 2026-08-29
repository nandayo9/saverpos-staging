<table align="center" style="border-spacing: {{$barcode_details->col_distance * 1}}in {{$barcode_details->row_distance * 1}}in; overflow: hidden !important;">
@foreach($page_products as $page_product)

	@if($loop->index % $barcode_details->stickers_in_one_row == 0)
		<!-- create a new row -->
		<tr>
		<!-- <columns column-count="{{$barcode_details->stickers_in_one_row}}" column-gap="{{$barcode_details->col_distance*1}}"> -->
	@endif
		<td align="center" valign="center">
			<div style="overflow: hidden !important;display: flex; flex-wrap: wrap;align-content: center;width: {{$barcode_details->width * 1}}in; height: {{$barcode_details->height * 1}}in; justify-content: center;">


				<div style="line-height: 1.1;">

					{{-- Business Name --}}
					@if(!empty($print['business_name']))
						<b style="display: block !important; font-size: {{$print['business_name_size']}}px">{{$business_name}}</b>
					@endif

					{{-- Product Name --}}
					@if(!empty($print['name']))
						<span style="display: block !important; font-size: {{$print['name_size']}}px">
							{{$page_product->product_actual_name}}

							@if(!empty($print['lot_number']) && !empty($page_product->lot_number))
								<span style="font-size: {{12*$factor}}px">
									 ({{$page_product->lot_number}})
								</span>
							@endif
						</span>
					@endif

					{{-- Variation --}}
					@if(!empty($print['variations']) && $page_product->is_dummy != 1)
						<span style="display: block !important; font-size: {{$print['variations_size']}}px">
							{{$page_product->product_variation_name}}:<b>{{$page_product->variation_name}}</b>
						</span>
					@endif
					{{-- product_custom_fields --}}
					@php
						$custom_labels = json_decode(session('business.custom_labels'), true);
						$product_custom_fields = !empty($custom_labels['product']) ? $custom_labels['product'] : [];
					@endphp

					@foreach($product_custom_fields as $index => $cf)
						@php
							$field_name = 'product_custom_field' . $loop->iteration;
						@endphp
						@if(!empty($cf) && !empty($page_product->$field_name ) && !empty($print[$field_name]))
							<span style="font-size: {{ $print[$field_name . '_size'] }}px">
								<b>{{ $cf }}:</b>
								{{ $page_product->$field_name }}
							</span>
						@endif
					@endforeach

					{{-- Price --}}
					@if(!empty($print['price']))
					<span style="font-size: {{$print['price_size']}}px;">
						@lang('lang_v1.price'):
						<b>{{session('currency')['symbol'] ?? ''}}

						
						@if($print['price_type'] == 'inclusive')
							{{@num_format($page_product->sell_price_inc_tax)}}
						@else
							{{@num_format($page_product->default_sell_price)}}
						@endif</b>
					</span>
					@endif
					@if(!empty($print['exp_date']) && !empty($page_product->exp_date))
						<br>
						<span style="font-size: {{$print['exp_date_size']}}px">
							<b>@lang('product.exp_date'):</b>
							{{$page_product->exp_date}}
						</span>
						@if($barcode_details->is_continuous)
						<br>
						@endif
					@endif

					@if(!empty($print['packing_date']) && !empty($page_product->packing_date))
						<span style="font-size: {{$print['packing_date_size']}}px">
							<b>@lang('lang_v1.packing_date'):</b>
							{{$page_product->packing_date}}
						</span>
					@endif
					{{-- Barcode.
					     - High-res source PNG (w=8) so the browser DOWNSCALES (sharp) instead of upscaling (blurry).
					     - .barcode-wrap paints a WHITE background + horizontal padding: the library PNG is transparent
					       with NO quiet zone, so the wrapper supplies the mandatory Code128 quiet zone.
					     - img width:100% fills the available label width => largest possible X-dimension (narrowest bar),
					       which keeps wide labels (4in/2.625in) comfortably scannable. Height is fixed & modest so the
					       barcode never grows tall enough to push the SKU out of the fixed-height label cell.
					     - Only the vertical axis is squished; horizontal bar:space ratios are untouched (1D-safe). --}}
					<span class="barcode-wrap">
						<img class="barcode-img" src="data:image/png;base64,{{DNS1D::getBarcodePNG($page_product->sub_sku, $page_product->barcode_type, 8, 200, array(0, 0, 0), false)}}" alt="{{$page_product->sub_sku}}">
					</span>

					<span class="barcode-sku">
						{{$page_product->sub_sku}}
					</span>
				</div>
			</div>
		
		</td>

	@if($loop->iteration % $barcode_details->stickers_in_one_row == 0)
		</tr>
	@endif
@endforeach
</table>

<style type="text/css">

	td{
		border: 1px dotted lightgray;
	}

	/* Quiet zone + white background (library PNG is transparent with no quiet zone) */
	.barcode-wrap {
		display: block;
		background: #fff;
		padding: 0 3mm;            /* 3mm L/R = Code128 quiet zone; no vertical padding so content fits the cell */
		margin: 0 auto;
		box-sizing: border-box;
		width: 100%;
		-webkit-print-color-adjust: exact;
		print-color-adjust: exact;
	}
	/* Barcode: fill the width (max X-dimension), fixed modest height (room for SKU, >=5mm bars).
	   Source is high-res so this is a DOWNSCALE => keep crisp-edges (NOT pixelated, which can drop thin bars). */
	.barcode-img {
		display: block;
		width: 100%;
		height: 0.26in;            /* ~6.6mm bar height: above the ~5mm handheld minimum, leaves room so the SKU is not clipped */
		image-rendering: -webkit-optimize-contrast;
		image-rendering: -moz-crisp-edges;
		image-rendering: crisp-edges;
	}
	.barcode-sku {
		display: block;
		text-align: center;
		font-size: 10px;
		line-height: 1;
	}
	@media print{
		td { border: none !important; }
		/* Keep the SAME rendering at print time. The previous code reset this to
		   image-rendering:auto here, which re-enabled interpolation blur during printing. */
		.barcode-wrap, .barcode-img {
			-webkit-print-color-adjust: exact;
			print-color-adjust: exact;
		}
		.barcode-img {
			image-rendering: -webkit-optimize-contrast;
			image-rendering: -moz-crisp-edges;
			image-rendering: crisp-edges;
		}

		table{
			page-break-after: always;
		}

		
		@page {
		size: {{$paper_width}}in {{$paper_height}}in;

		/*width: {{$barcode_details->paper_width}}in !important;*/
		/*height:@if($barcode_details->paper_height != 0){{$barcode_details->paper_height}}in !important @else auto @endif;*/
		margin-top: {{$margin_top}}in !important;
		margin-bottom: {{$margin_top}}in !important;
		margin-left: {{$margin_left}}in !important;
		margin-right: {{$margin_left}}in !important;
	}
	}
</style>