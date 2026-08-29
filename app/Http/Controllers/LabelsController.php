<?php

namespace App\Http\Controllers;

use App\Barcode;
use App\Product;
use App\SellingPriceGroup;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LabelsController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $transactionUtil;

    protected $productUtil;

    /**
     * Constructor
     *
     * @param  TransactionUtil  $TransactionUtil
     * @return void
     */
    public function __construct(TransactionUtil $transactionUtil, ProductUtil $productUtil)
    {
        $this->transactionUtil = $transactionUtil;
        $this->productUtil = $productUtil;
    }

    /**
     * Display labels
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $purchase_id = $request->get('purchase_id', false);
        $product_id = $request->get('product_id', false);

        //Get products for the business
        $products = [];
        $price_groups = [];
        if ($purchase_id) {
            $products = $this->transactionUtil->getPurchaseProducts($business_id, $purchase_id);
        } elseif ($product_id) {
            $products = $this->productUtil->getDetailsFromProduct($business_id, $product_id);
        }

        //get price groups
        $price_groups = [];
        if (! empty($purchase_id) || ! empty($product_id)) {
            $price_groups = SellingPriceGroup::where('business_id', $business_id)
                                    ->active()
                                    ->pluck('name', 'id');
        }

        $barcode_settings = Barcode::where('business_id', $business_id)
                                ->orWhereNull('business_id')
                                ->select(DB::raw('CONCAT(name, ", ", COALESCE(description, "")) as name, id, is_default'))
                                ->get();
        $default = $barcode_settings->where('is_default', 1)->first();
        $barcode_settings = $barcode_settings->pluck('name', 'id');

        return view('labels.show')
            ->with(compact('products', 'barcode_settings', 'default', 'price_groups'));
    }

    /**
     * Returns the html for product row
     *
     * @return \Illuminate\Http\Response
     */
    public function addProductRow(Request $request)
    {
        if ($request->ajax()) {
            $product_id = $request->input('product_id');
            $variation_id = $request->input('variation_id');
            $business_id = $request->session()->get('user.business_id');

            if (! empty($product_id)) {
                $index = $request->input('row_count');
                $products = $this->productUtil->getDetailsFromProduct($business_id, $product_id, $variation_id);

                $price_groups = SellingPriceGroup::where('business_id', $business_id)
                                            ->active()
                                            ->pluck('name', 'id');

                return view('labels.partials.show_table_rows')
                        ->with(compact('products', 'index', 'price_groups'));
            }
        }
    }

    /**
     * Returns the html for labels preview
     *
     * @return \Illuminate\Http\Response
     */
    public function preview(Request $request)
    {
        try {
            $products = $request->get('products');
            $print = $request->get('print');
            $barcode_setting = $request->get('barcode_setting');
            $business_id = $request->session()->get('user.business_id');

            $barcode_details = Barcode::find($barcode_setting);
            $barcode_details->stickers_in_one_sheet = $barcode_details->is_continuous ? $barcode_details->stickers_in_one_row : $barcode_details->stickers_in_one_sheet;
            $barcode_details->paper_height = $barcode_details->is_continuous ? $barcode_details->height : $barcode_details->paper_height;
            if ($barcode_details->stickers_in_one_row == 1) {
                $barcode_details->col_distance = 0;
                $barcode_details->row_distance = 0;
            }
            // if($barcode_details->is_continuous){
            //     $barcode_details->row_distance = 0;
            // }

            $business_name = $request->session()->get('business.name');

            $product_details_page_wise = [];
            $total_qty = 0;
            foreach ($products as $value) {
                $details = $this->productUtil->getDetailsFromVariation($value['variation_id'], $business_id, null, false);

                if (! empty($value['exp_date'])) {
                    $details->exp_date = $value['exp_date'];
                }
                if (! empty($value['packing_date'])) {
                    $details->packing_date = $value['packing_date'];
                }
                if (! empty($value['lot_number'])) {
                    $details->lot_number = $value['lot_number'];
                }

                if (! empty($value['price_group_id'])) {
                    $tax_id = $print['price_type'] == 'inclusive' ?: $details->tax_id;

                    $group_prices = $this->productUtil->getVariationGroupPrice($value['variation_id'], $value['price_group_id'], $tax_id);

                    $details->sell_price_inc_tax = $group_prices['price_inc_tax'];
                    $details->default_sell_price = $group_prices['price_exc_tax'];
                }

                for ($i = 0; $i < $value['quantity']; $i++) {
                    $page = intdiv($total_qty, $barcode_details->stickers_in_one_sheet);

                    if ($total_qty % $barcode_details->stickers_in_one_sheet == 0) {
                        $product_details_page_wise[$page] = [];
                    }

                    $product_details_page_wise[$page][] = $details;
                    $total_qty++;
                }
            }

            $margin_top = $barcode_details->is_continuous ? 0 : $barcode_details->top_margin * 1;
            $margin_left = $barcode_details->is_continuous ? 0 : $barcode_details->left_margin * 1;
            $paper_width = $barcode_details->paper_width * 1;
            $paper_height = $barcode_details->paper_height * 1;

            // print_r($paper_height);
            // echo "==";
            // print_r($margin_left);exit;

            // $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8',
            //             'format' => [$paper_width, $paper_height],
            //             'margin_top' => $margin_top,
            //             'margin_bottom' => $margin_top,
            //             'margin_left' => $margin_left,
            //             'margin_right' => $margin_left,
            //             'autoScriptToLang' => true,
            //             // 'disablePrintCSS' => true,
            // 'autoLangToFont' => true,
            // 'autoVietnamese' => true,
            // 'autoArabic' => true
            //             ]
            //         );
            //print_r($mpdf);exit;

            $i = 0;
            $len = count($product_details_page_wise);
            $is_first = false;
            $is_last = false;

            //$original_aspect_ratio = 4;//(w/h)
            $factor = (($barcode_details->width / $barcode_details->height)) / ($barcode_details->is_continuous ? 2 : 4);
            $html = '';
            foreach ($product_details_page_wise as $page => $page_products) {
                if ($i == 0) {
                    $is_first = true;
                }

                if ($i == $len - 1) {
                    $is_last = true;
                }

                $output = view('labels.partials.preview_2')
                            ->with(compact('print', 'page_products', 'business_name', 'barcode_details', 'margin_top', 'margin_left', 'paper_width', 'paper_height', 'is_first', 'is_last', 'factor'))->render();
                print_r($output);
                //$mpdf->WriteHTML($output);

                // if($i < $len - 1){
                //     // '', '', '', '', '', '', $margin_left, $margin_left, $margin_top, $margin_top, '', '', '', '', '', '', 0, 0, 0, 0, '', [$barcode_details->paper_width*1, $barcode_details->paper_height*1]
                //     $mpdf->AddPage();
                // }

                $i++;
            }

            print_r('<script>window.print()</script>');
            exit;
            //return $output;

            //$mpdf->Output();

            // $page_height = null;
            // if ($barcode_details->is_continuous) {
            //     $rows = ceil($total_qty/$barcode_details->stickers_in_one_row) + 0.4;
            //     $barcode_details->paper_height = $barcode_details->top_margin + ($rows*$barcode_details->height) + ($rows*$barcode_details->row_distance);
            // }

            // $output = view('labels.partials.preview')
            //     ->with(compact('print', 'product_details', 'business_name', 'barcode_details', 'product_details_page_wise'))->render();

            // $output = ['html' => $html,
            //                 'success' => true,
            //                 'msg' => ''
            //             ];
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            $output = __('lang_v1.barcode_label_error');
        }

        //return $output;
    }

    /**
     * Generates a crisp, vector (mPDF) version of the labels for reliable scanning & printing.
     *
     * This is an ADDITIONAL output path (triggered by the "Print PDF" button). It reuses the exact
     * same per-page logic, geometry and print/font settings as preview(), but renders the barcode as
     * a native vector <barcode> (sharp at any DPI, with a spec quiet zone) and outputs a PDF sized to
     * the exact label dimensions, so the browser/printer never rescales it.
     *
     * @return \Illuminate\Http\Response
     */
    public function printPdf(Request $request)
    {
        try {
            $products = $request->get('products');
            $print = $request->get('print');
            $barcode_setting = $request->get('barcode_setting');
            $business_id = $request->session()->get('user.business_id');

            $barcode_details = Barcode::find($barcode_setting);
            $barcode_details->stickers_in_one_sheet = $barcode_details->is_continuous ? $barcode_details->stickers_in_one_row : $barcode_details->stickers_in_one_sheet;
            $barcode_details->paper_height = $barcode_details->is_continuous ? $barcode_details->height : $barcode_details->paper_height;
            if ($barcode_details->stickers_in_one_row == 1) {
                $barcode_details->col_distance = 0;
                $barcode_details->row_distance = 0;
            }

            $business_name = $request->session()->get('business.name');

            $product_details_page_wise = [];
            $total_qty = 0;
            $barcode_helper = new \Mpdf\Barcode();

            // DEBUG: ?test_sku=XXXX forces a fixed barcode value on EVERY label (both the bars and the
            // human-readable line), so you can print a known symbol and test whether the printer +
            // scanner read it - isolating a hardware/DPI problem from your real SKU length/content.
            // Omit it from the URL for normal printing.
            $test_sku = trim((string) $request->get('test_sku'));

            foreach ($products as $value) {
                $details = $this->productUtil->getDetailsFromVariation($value['variation_id'], $business_id, null, false);

                if (! empty($value['exp_date'])) {
                    $details->exp_date = $value['exp_date'];
                }
                if (! empty($value['packing_date'])) {
                    $details->packing_date = $value['packing_date'];
                }
                if (! empty($value['lot_number'])) {
                    $details->lot_number = $value['lot_number'];
                }

                if (! empty($value['price_group_id'])) {
                    $tax_id = $print['price_type'] == 'inclusive' ?: $details->tax_id;

                    $group_prices = $this->productUtil->getVariationGroupPrice($value['variation_id'], $value['price_group_id'], $tax_id);

                    $details->sell_price_inc_tax = $group_prices['price_inc_tax'];
                    $details->default_sell_price = $group_prices['price_exc_tax'];
                }

                // DEBUG static-SKU override: swap in the fixed test value so BOTH the barcode bars and
                // the printed SKU line show it, before the fit math runs on it.
                if ($test_sku !== '') {
                    $details->sub_sku = $test_sku;
                }

                // Pre-compute the vector-barcode fit (size + bar height) once per product.
                // $print is passed so bar height can claim the vertical space the enabled text
                // lines leave free.
                $this->setBarcodeRenderProps($details, $barcode_helper, $barcode_details, $print);

                for ($i = 0; $i < $value['quantity']; $i++) {
                    $page = intdiv($total_qty, $barcode_details->stickers_in_one_sheet);

                    if ($total_qty % $barcode_details->stickers_in_one_sheet == 0) {
                        $product_details_page_wise[$page] = [];
                    }

                    $product_details_page_wise[$page][] = $details;
                    $total_qty++;
                }
            }

            $margin_top = $barcode_details->is_continuous ? 0 : $barcode_details->top_margin * 1;
            $margin_left = $barcode_details->is_continuous ? 0 : $barcode_details->left_margin * 1;
            $paper_width = $barcode_details->paper_width * 1;
            $paper_height = $barcode_details->paper_height * 1;

            $factor = (($barcode_details->width / $barcode_details->height)) / ($barcode_details->is_continuous ? 2 : 4);

            // Inch -> mm for mPDF (which works in mm).
            $mm = 25.4;

            $mpdf = new \Mpdf\Mpdf([
                'tempDir' => public_path('uploads/temp'),
                'mode' => 'utf-8',
                'format' => [$paper_width * $mm, $paper_height * $mm],
                'margin_top' => $margin_top * $mm,
                'margin_bottom' => $margin_top * $mm,
                'margin_left' => $margin_left * $mm,
                'margin_right' => $margin_left * $mm,
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'autoVietnamese' => true,
                'autoArabic' => true,
            ]);
            $mpdf->useSubstitutions = true;
            $mpdf->SetTitle(__('barcode.print_labels'));

            $i = 0;
            foreach ($product_details_page_wise as $page => $page_products) {
                $html = view('labels.partials.pdf')
                            ->with(compact('print', 'page_products', 'business_name', 'barcode_details', 'factor'))
                            ->render();

                if ($i > 0) {
                    $mpdf->AddPage();
                }
                $mpdf->WriteHTML($html);

                $i++;
            }

            // Ask the PDF viewer to open the print dialog automatically (honoured by Adobe/Firefox;
            // Chrome's built-in viewer shows the PDF and the user presses print).
            $mpdf->SetJS('this.print();');

            $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="labels.pdf"',
            ]);
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return __('lang_v1.barcode_label_error');
        }
    }

    /**
     * Computes how to render a single product's barcode as a native mPDF vector <barcode>:
     *   - resolves the stored barcode_type to an mPDF type, auto-picking the densest SAFE Code128
     *     subset (C for even-length numeric SKUs => wider bars/bigger X-dim), with a C128B fallback
     *   - fits the full symbol (bars + spec quiet zones) to ~96% of the label width, but never lets
     *     the X-dim fall below a handheld-scannable floor (~0.25mm / 10mil) if there is room
     *   - MAXIMISES bar height into the vertical space left over after the enabled text lines, so a
     *     sparsely-toggled label gets tall, forgiving bars instead of a fixed 9mm ribbon
     * The result is attached to $details->barcode_render for the view to consume.
     *
     * @param  array|null  $print  the print/field-toggle settings (so height can use spare space).
     */
    private function setBarcodeRenderProps($details, \Mpdf\Barcode $barcode_helper, $barcode_details, $print = null)
    {
        $code = (string) $details->sub_sku;
        $type = $this->mapBarcodeType($details->barcode_type);

        // For the Code128 family, pick the densest subset this exact value allows (C for numeric).
        if ($type === 'C128') {
            $type = $this->resolveCode128Subset($code);
        }

        $arrcode = $this->safeGetBarcodeArray($barcode_helper, $code, $type);

        // Fallback to Code128 (subset B) which encodes any printable ASCII, so an unscannable
        // configured type (e.g. a non-numeric value set to EAN13, or a C128C value that failed the
        // even-length/numeric check) never breaks the whole sheet.
        if (empty($arrcode) || empty($arrcode['maxw'])) {
            $type = 'C128B';
            $arrcode = $this->safeGetBarcodeArray($barcode_helper, $code, $type);
        }

        if (empty($arrcode) || empty($arrcode['maxw'])) {
            $details->barcode_render = null;

            return;
        }

        $nom_x = $arrcode['nom-X'];
        $nom_h = $arrcode['nom-H'];
        $light_l = isset($arrcode['lightmL']) ? $arrcode['lightmL'] : 0;
        $light_r = isset($arrcode['lightmR']) ? $arrcode['lightmR'] : 0;

        $label_w_mm = $barcode_details->width * 25.4;
        $label_h_mm = $barcode_details->height * 25.4;

        // ---- WIDTH FIT + X-DIM FLOOR ----------------------------------------------------------
        // Full symbol width (incl. the spec 10X quiet zones, which are already inside $natural_w),
        // then scale to ~98% of the label width to MAXIMISE the X-dimension (narrow-bar width) on
        // tight labels. The quiet zones live inside $natural_w, so even at 98% the spec light margin
        // is intact; the remaining ~2% is just extra cut tolerance (column/row gaps add more).
        $width_fit = 0.98;
        $natural_w = ($arrcode['maxw'] + $light_l + $light_r) * $nom_x;
        $bsize = $natural_w > 0 ? (($label_w_mm * $width_fit) / $natural_w) : 1;

        // X-dimension (narrow-bar width) floor for handheld laser/CCD readers: ~0.25mm (10 mil).
        // If the width-fit X-dim is below the floor, push it up to the floor as long as the symbol
        // still fits the full label width (<=100%). On a narrow label + long SKU it may be
        // physically impossible to reach the floor - then we keep the largest X-dim that fits
        // (the original width-fit) rather than overflowing the label.
        $x_floor_mm = 0.25;
        if ($nom_x > 0 && $natural_w > 0) {
            $floor_bsize = $x_floor_mm / $nom_x;
            $max_bsize = $label_w_mm / $natural_w; // symbol exactly fills the label width
            if ($floor_bsize > $bsize) {
                $bsize = min($floor_bsize, $max_bsize);
            }
        }

        // ---- BAR HEIGHT + FIT-TO-LABEL SCALE -----------------------------------------------
        // The label cell is locked to the configured label height so that EXACTLY
        // stickers_in_one_sheet labels fit on one physical page (never spilling onto a 2nd page).
        // We therefore have to make the enabled text lines + bars + SKU fit inside that height.
        //
        // $scalable_mm = natural height of the enabled text lines + the SKU (these shrink with the
        // font scale). $fixed_mm = cell padding + border + the barcode/SKU gaps, which do NOT scale.
        $scalable_mm = $this->estimateTextHeightMm($print, $details, $factor = null);
        $fixed_mm = 4.0;

        // Bar-height band: tall enough to stay reliably scannable on a handheld reader; the ceiling
        // stops a tall roll/continuous label from making an absurd ribbon.
        $min_scan_h = 7.0;   // mm
        $max_scan_h = 22.0;  // mm

        // BARCODE-FIRST allocation. The barcode is the POINT of the label, so reserve a generous,
        // scannable bar height FIRST and let the text lines shrink (via $fit_scale) into the space
        // that's left. The old logic did the opposite - it crushed the bars to ~4.5mm to fit 5 text
        // lines onto a 1" label, which is too short to aim/scan on 40-50/sheet and continuous rolls.
        $avail_h = $label_h_mm - $fixed_mm;            // vertical space shared by text + bars
        // Aim the bars at ~42% of the label height (a big, easy-to-aim symbol), within the band.
        $desired_bar = min(max($min_scan_h, $label_h_mm * 0.42), $max_scan_h);
        // Reserve at least 50% of the text's natural height so it stays legible...
        $min_text_h = $scalable_mm * 0.5;
        // ...but the bars win ties: give them the desired height unless that would starve the text,
        // and never let them fall below the scannable floor.
        $target_h = max($min_scan_h, min($desired_bar, $avail_h - $min_text_h));

        // Text shrinks (fonts * $fit_scale) to fill whatever vertical space the bars leave.
        $text_room = max(0.0, $avail_h - $target_h);
        $fit_scale = $scalable_mm > 0 ? min(1.0, $text_room / $scalable_mm) : 1.0;
        $fit_scale = max(0.4, $fit_scale); // hard floor so the fonts never vanish entirely

        $bheight = ($nom_h * $bsize) > 0 ? ($target_h / ($nom_h * $bsize)) : 1;

        $details->barcode_render = [
            'type' => $type,
            'code' => $code,
            'size' => round($bsize, 4),
            'height' => round($bheight, 4),
            'fit_scale' => round($fit_scale, 4),
        ];
    }

    /**
     * Estimates the vertical space (mm) the enabled label text lines + SKU will consume at scale 1,
     * so setBarcodeRenderProps() can size the bars (and, when crowded, the fit-to-label font scale).
     *
     * Mirrors the field toggles in resources/views/labels/partials/pdf.blade.php. Font sizes are in
     * px; rendered line height ~= font_px * 1.1 (the cell's line-height) converted px->mm at 96dpi
     * (25.4/96). Returns only the SCALABLE text height (lines + the 10px SKU); the unscaled cell
     * padding/border + barcode gaps are added by the caller as $fixed_mm.
     */
    private function estimateTextHeightMm($print, $details, $factor = null)
    {
        $px_to_mm = 25.4 / 96;
        $line_factor = 1.1; // matches .label-cell line-height:1.1 in the blade

        // Always-present: the human-readable SKU line (.lbl-sku is 10px in the blade). The fixed cell
        // padding/border + barcode gaps are NOT added here - the caller accounts for them separately
        // as $fixed_mm (those do not scale with the font fit-scale, the text lines below do).
        $reserved_mm = 10 * $line_factor * $px_to_mm; // 10px -> mm

        if (empty($print) || ! is_array($print)) {
            return $reserved_mm;
        }

        // Map each optional text line to its font-size key, counting it only when toggled on AND the
        // underlying value is present (same conditions as the blade).
        $lines = [
            ['business_name', 'business_name_size', true],
            ['name', 'name_size', true],
            ['variations', 'variations_size', empty($details->is_dummy) || $details->is_dummy != 1],
            ['price', 'price_size', true],
            ['exp_date', 'exp_date_size', ! empty($details->exp_date)],
            ['packing_date', 'packing_date_size', ! empty($details->packing_date)],
        ];

        foreach ($lines as [$toggle, $size_key, $value_present]) {
            if (! empty($print[$toggle]) && $value_present) {
                $px = isset($print[$size_key]) && $print[$size_key] > 0 ? (float) $print[$size_key] : 12;
                $reserved_mm += $px * $line_factor * $px_to_mm;
            }
        }

        // Product custom fields (printX_size keys follow the same convention as the blade).
        for ($n = 1; $n <= 4; $n++) {
            $field = 'product_custom_field'.$n;
            if (! empty($print[$field]) && ! empty($details->$field)) {
                $px = isset($print[$field.'_size']) && $print[$field.'_size'] > 0 ? (float) $print[$field.'_size'] : 12;
                $reserved_mm += $px * $line_factor * $px_to_mm;
            }
        }

        return $reserved_mm;
    }

    /**
     * Wraps mPDF's getBarcodeArray so an invalid code/type combination returns null instead of throwing.
     */
    private function safeGetBarcodeArray(\Mpdf\Barcode $barcode_helper, $code, $type)
    {
        try {
            return $barcode_helper->getBarcodeArray($code, $type);
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Maps the stored product barcode_type (C128, C39, EAN13, EAN8, UPCA, UPCE) to an mPDF type.
     *
     * NOTE: 'C128' is returned as a SENTINEL (not yet C128B). setBarcodeRenderProps() resolves the
     * actual Code128 subset per code value: subset C (denser, ~1.2-1.5x narrower bars for numeric
     * SKUs => bigger X-dim => faster/more reliable scan) for even-length all-numeric codes, else
     * subset B. mPDF has no auto-subset encoder, so the subset must be chosen by us.
     */
    private function mapBarcodeType($type)
    {
        $type = strtoupper(trim((string) $type));

        $map = [
            'C128' => 'C128',   // sentinel: subset (B/C) resolved later from the code value
            'C39' => 'C39',
            'EAN13' => 'EAN13',
            'EAN8' => 'EAN8',
            'UPCA' => 'UPCA',
            'UPCE' => 'UPCE',
        ];

        return isset($map[$type]) ? $map[$type] : 'C128';
    }

    /**
     * Resolves a Code128 family request to the densest SAFE subset for this code value.
     *   - subset C: pure digits AND even length (packs 2 digits / 11 modules => narrower symbol)
     *   - subset B: everything else (handles any printable ASCII)
     * The scanned/decoded string is identical for B and C, and every subset carries the mandatory
     * mod-103 checksum, so the choice is zero-risk for misreads. ctype_digit() correctly rejects
     * empty strings, spaces, hyphens and letters - exactly the eligibility test C128C needs.
     */
    private function resolveCode128Subset($code)
    {
        return (ctype_digit($code) && (strlen($code) % 2 === 0)) ? 'C128C' : 'C128B';
    }
}
