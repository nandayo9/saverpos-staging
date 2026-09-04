<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Charts\CommonChart;
use App\Currency;
use App\Media;
use App\Transaction;
use App\User;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use App\Utils\RestaurantUtil;
use App\Utils\TransactionUtil;
use App\Utils\ProductUtil;
use App\Utils\Util;
use App\Services\WalkInService;
use App\VariationLocationDetails;
use Datatables;
use DB;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class HomeController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $businessUtil;

    protected $transactionUtil;

    protected $moduleUtil;

    protected $commonUtil;

    protected $restUtil;
    protected $productUtil;
    protected $walkInService;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        BusinessUtil $businessUtil,
        TransactionUtil $transactionUtil,
        ModuleUtil $moduleUtil,
        Util $commonUtil,
        RestaurantUtil $restUtil,
        ProductUtil $productUtil,
        WalkInService $walkInService,
    ) {
        $this->businessUtil = $businessUtil;
        $this->transactionUtil = $transactionUtil;
        $this->moduleUtil = $moduleUtil;
        $this->commonUtil = $commonUtil;
        $this->restUtil = $restUtil;
        $this->productUtil = $productUtil;
        $this->walkInService = $walkInService;
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = auth()->user();
        if ($user->user_type == 'user_customer') {
            return redirect()->action([\Modules\Crm\Http\Controllers\DashboardController::class, 'index']);
        }

        // CustomDashboard default-on-login: only the FIRST time /home is hit in a session
        // (i.e. right after login) redirect to the user's role default dashboard. Once that
        // has happened, clicking the "Home" menu shows the normal home page. The
        // ?skip_default=1 query param is an additional escape hatch ("Main home" link).
        if (!request()->boolean('skip_default')
            && !session()->has('cd_default_landing_done')
            && $this->moduleUtil->isModuleInstalled('CustomDashboard')) {
            $role = $user->roles->first();

            if (!empty($role)) {
                $default_id = \DB::table('custom_dashboard_role_defaults')
                    ->where('business_id', $user->business_id)
                    ->where('role_id', $role->id)
                    ->value('custom_dashboard_id');

                if ($default_id && $user->can($default_id . 'custom_dashboard')) {
                    // Mark the one-time landing as done so future /home visits show home.
                    session()->put('cd_default_landing_done', true);

                    return redirect()->action(
                        [\Modules\CustomDashboard\Http\Controllers\CustomDashboardController::class, 'get_custom_dashboard'],
                        ['id' => $default_id]
                    );
                }
            }
        }

        $business_id = request()->session()->get('user.business_id');

        $is_admin = $this->businessUtil->is_admin(auth()->user());
        $walkInSummary = null;
        $walkInMonthSummary = null;
        if ($user->can('walkin.view') || $user->can('walkin.view_all')) {
            $locationId = null;
            if (! $user->can('walkin.view_all')) {
                $locationId = BusinessLocation::forDropdown($business_id, false)->keys()->first();
            }
            if ($locationId !== null || $user->can('walkin.view_all')) {
                $now = now();
                $walkInSummary = $this->walkInService->summary(
                    $business_id,
                    $locationId,
                    $now->copy()->startOfDay()->toDateTimeString(),
                    $now->copy()->endOfDay()->toDateTimeString()
                );
                $walkInMonthSummary = $this->walkInService->summary(
                    $business_id,
                    $locationId,
                    $now->copy()->startOfMonth()->toDateTimeString(),
                    $now->copy()->endOfDay()->toDateTimeString()
                );
            }
        }

        if (! auth()->user()->can('dashboard.data')) {
            return view('home.index', compact('is_admin', 'walkInSummary', 'walkInMonthSummary'));
        }

        $fy = $this->businessUtil->getCurrentFinancialYear($business_id);

        $currency = Currency::where('id', request()->session()->get('business.currency_id'))->first();
        //ensure start date starts from at least 30 days before to get sells last 30 days
        $least_30_days = \Carbon::parse($fy['start'])->subDays(30)->format('Y-m-d');

        //get all sells
        $sells_this_fy = $this->transactionUtil->getSellsCurrentFy($business_id, $least_30_days, $fy['end']);

        $all_locations = BusinessLocation::forDropdown($business_id)->toArray();

        //Chart for sells last 30 days
        $labels = [];
        $all_sell_values = [];
        $dates = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = \Carbon::now()->subDays($i)->format('Y-m-d');
            $dates[] = $date;

            $labels[] = date('j M Y', strtotime($date));

            $total_sell_on_date = $sells_this_fy->where('date', $date)->sum('total_sells');

            if (! empty($total_sell_on_date)) {
                $all_sell_values[] = (float) $total_sell_on_date;
            } else {
                $all_sell_values[] = 0;
            }
        }

        //Group sells by location
        $location_sells = [];
        foreach ($all_locations as $loc_id => $loc_name) {
            $values = [];
            foreach ($dates as $date) {
                $total_sell_on_date_location = $sells_this_fy->where('date', $date)->where('location_id', $loc_id)->sum('total_sells');

                if (! empty($total_sell_on_date_location)) {
                    $values[] = (float) $total_sell_on_date_location;
                } else {
                    $values[] = 0;
                }
            }
            $location_sells[$loc_id]['loc_label'] = $loc_name;
            $location_sells[$loc_id]['values'] = $values;
        }

        $sells_chart_1 = new CommonChart;

        $sells_chart_1->labels($labels)
                        ->options($this->__chartOptions(__(
                            'home.total_sells',
                            ['currency' => $currency->code]
                            )));

        if (! empty($location_sells)) {
            foreach ($location_sells as $location_sell) {
                $sells_chart_1->dataset($location_sell['loc_label'], 'line', $location_sell['values']);
            }
        }

        if (count($all_locations) > 1) {
            $sells_chart_1->dataset(__('report.all_locations'), 'line', $all_sell_values);
        }

        $labels = [];
        $values = [];
        $date = strtotime($fy['start']);
        $last = date('m-Y', strtotime($fy['end']));
        $fy_months = [];
        do {
            $month_year = date('m-Y', $date);
            $fy_months[] = $month_year;

            $labels[] = \Carbon::createFromFormat('m-Y', $month_year)
                            ->format('M-Y');
            $date = strtotime('+1 month', $date);

            $total_sell_in_month_year = $sells_this_fy->where('yearmonth', $month_year)->sum('total_sells');

            if (! empty($total_sell_in_month_year)) {
                $values[] = (float) $total_sell_in_month_year;
            } else {
                $values[] = 0;
            }
        } while ($month_year != $last);

        $fy_sells_by_location_data = [];

        foreach ($all_locations as $loc_id => $loc_name) {
            $values_data = [];
            foreach ($fy_months as $month) {
                $total_sell_in_month_year_location = $sells_this_fy->where('yearmonth', $month)->where('location_id', $loc_id)->sum('total_sells');

                if (! empty($total_sell_in_month_year_location)) {
                    $values_data[] = (float) $total_sell_in_month_year_location;
                } else {
                    $values_data[] = 0;
                }
            }
            $fy_sells_by_location_data[$loc_id]['loc_label'] = $loc_name;
            $fy_sells_by_location_data[$loc_id]['values'] = $values_data;
        }

        $sells_chart_2 = new CommonChart;
        $sells_chart_2->labels($labels)
                    ->options($this->__chartOptions(__(
                        'home.total_sells',
                        ['currency' => $currency->code]
                            )));
        if (! empty($fy_sells_by_location_data)) {
            foreach ($fy_sells_by_location_data as $location_sell) {
                $sells_chart_2->dataset($location_sell['loc_label'], 'line', $location_sell['values']);
            }
        }
        if (count($all_locations) > 1) {
            $sells_chart_2->dataset(__('report.all_locations'), 'line', $values);
        }

        //Get Dashboard widgets from module
        $module_widgets = $this->moduleUtil->getModuleData('dashboard_widget');

        $widgets = [];

        foreach ($module_widgets as $widget_array) {
            if (! empty($widget_array['position'])) {
                $widgets[$widget_array['position']][] = $widget_array['widget'];
            }
        }

        $common_settings = ! empty(session('business.common_settings')) ? session('business.common_settings') : [];


        return view('home.index', compact('sells_chart_1', 'sells_chart_2', 'widgets', 'all_locations', 'common_settings', 'is_admin', 'walkInSummary', 'walkInMonthSummary'));
    }

    /**
     * Retrieves purchase and sell details for a given time period.
     *
     * @return \Illuminate\Http\Response
     */
    public function getTotals()
    {
        if (request()->ajax()) {
            $start = request()->start;
            $end = request()->end;
            $location_id = request()->location_id;
            $business_id = request()->session()->get('user.business_id');

            // get user id parameter
            $created_by = request()->user_id;

            $purchase_details = $this->transactionUtil->getPurchaseTotals($business_id, $start, $end, $location_id, $created_by);

            $sell_details = $this->transactionUtil->getSellTotals($business_id, $start, $end, $location_id, $created_by);

            $total_ledger_discount = $this->transactionUtil->getTotalLedgerDiscount($business_id, $start, $end);

            $purchase_details['purchase_due'] = $purchase_details['purchase_due'] - $total_ledger_discount['total_purchase_discount'];

            $transaction_types = [
                'purchase_return', 'sell_return', 'expense', 'stock_adjustment',
            ];

            $transaction_totals = $this->transactionUtil->getTransactionTotals(
                $business_id,
                $transaction_types,
                $start,
                $end,
                $location_id,
                $created_by
            );

            $total_purchase_inc_tax = ! empty($purchase_details['total_purchase_inc_tax']) ? $purchase_details['total_purchase_inc_tax'] : 0;
            $total_purchase_return_inc_tax = $transaction_totals['total_purchase_return_inc_tax'];

            $output = $purchase_details;
            $output['total_purchase'] = $total_purchase_inc_tax;
            $output['total_purchase_return'] = $total_purchase_return_inc_tax;
            $output['total_purchase_return_paid'] = $this->transactionUtil->getTotalPurchaseReturnPaid($business_id, $start, $end, $location_id);

            $total_sell_inc_tax = ! empty($sell_details['total_sell_inc_tax']) ? $sell_details['total_sell_inc_tax'] : 0;
            $total_sell_return_inc_tax = ! empty($transaction_totals['total_sell_return_inc_tax']) ? $transaction_totals['total_sell_return_inc_tax'] : 0;
            $output['total_sell_return_paid'] = $this->transactionUtil->getTotalSellReturnPaid($business_id, $start, $end, $location_id);

            $output['total_sell'] = $total_sell_inc_tax;
            $output['total_sell_return'] = $total_sell_return_inc_tax;
            $output['total_sell_return_total'] = $total_sell_return_inc_tax;

            $output['invoice_due'] = $sell_details['invoice_due'] - $total_ledger_discount['total_sell_discount'];
            $output['total_expense'] = $transaction_totals['total_expense'];
            $output['total_adjustment'] = $transaction_totals['total_adjustment'];

            $sellTransactionQuery = Transaction::query()
                ->where('business_id', $business_id)
                ->where('type', 'sell')
                ->where('status', 'final');
            if (! empty($start) && ! empty($end)) {
                $sellTransactionQuery->whereDate('transaction_date', '>=', $start)
                    ->whereDate('transaction_date', '<=', $end);
            }
            if (! empty($location_id)) {
                $sellTransactionQuery->where('location_id', $location_id);
            }
            $output['total_sell_transactions'] = (int) $sellTransactionQuery->count();

            // Keep the visits KPI aligned with the same selected date range and location.
            $output['walk_ins'] = 0;
            $user = auth()->user();
            if ($user->can('walkin.view') || $user->can('walkin.view_all')) {
                $walkInLocationId = $user->can('walkin.view_all')
                    ? $location_id
                    : BusinessLocation::forDropdown($business_id, false)->keys()->first();
                if ($walkInLocationId !== null || $user->can('walkin.view_all')) {
                    $output['walk_ins'] = $this->walkInService->summary(
                        $business_id,
                        $walkInLocationId,
                        $start,
                        $end
                    )['walk_ins'];
                }
            }

            //NET = TOTAL SALES - INVOICE DUE - EXPENSE
            $output['net'] = $output['total_sell'] - $output['invoice_due'] - $output['total_expense'];

            return $output;
        }
    }

    /**
     * Retrieves sell products whose available quntity is less than alert quntity.
     *
     * @return \Illuminate\Http\Response
     */
    public function getProductStockAlert()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $permitted_locations = auth()->user()->permitted_locations();
            $products = $this->productUtil->getProductAlert($business_id, $permitted_locations);

            return Datatables::of($products)
                ->editColumn('product', function ($row) {
                    if ($row->type == 'single') {
                        return $row->product.' ('.$row->sku.')';
                    } else {
                        return $row->product.' - '.$row->product_variation.' - '.$row->variation.' ('.$row->sub_sku.')';
                    }
                })
                ->editColumn('stock', function ($row) {
                    $stock = $row->stock ? $row->stock : 0;

                    return '<span data-is_quantity="true" data-orig-value="'.(float) $stock.'" class="display_currency" data-currency_symbol=false>'.(float) $stock.'</span> '.$row->unit;
                })
                ->removeColumn('sku')
                ->removeColumn('sub_sku')
                ->removeColumn('unit')
                ->removeColumn('type')
                ->removeColumn('product_variation')
                ->removeColumn('variation')
                ->rawColumns([2])
                ->make(false);
        }
    }

    /**
     * Retrieves the models with the most available stock.
     *
     * @return \Illuminate\Http\Response
     */
    public function getMostAvailableModels()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $remaining_quantity = 'GREATEST(COALESCE(pl.quantity, 0) - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0), 0)';

            $purchase_metrics = DB::table('purchase_lines as pl')
                ->join('transactions as pt', 'pl.transaction_id', '=', 'pt.id')
                ->where('pt.business_id', $business_id)
                ->where(function ($query) {
                    $query->where('pt.type', 'opening_stock')
                        ->orWhere(function ($query) {
                            $query->whereIn('pt.type', ['purchase', 'production_purchase'])
                                ->where('pt.status', 'received');
                        });
                })
                ->select([
                    'pl.variation_id',
                    'pt.location_id',
                    DB::raw('SUM('.$remaining_quantity.') as remaining_quantity'),
                    DB::raw('SUM('.$remaining_quantity.' * COALESCE(pl.purchase_price_inc_tax, 0)) / NULLIF(SUM('.$remaining_quantity.'), 0) as avg_cost'),
                    DB::raw('SUM('.$remaining_quantity.' * DATEDIFF(CURDATE(), DATE(pt.transaction_date))) / NULLIF(SUM('.$remaining_quantity.'), 0) as avg_age'),
                ])
                ->groupBy('pl.variation_id', 'pt.location_id');

            $query = DB::table('variation_location_details as vld')
                ->join('products as p', 'vld.product_id', '=', 'p.id')
                ->join('variations as v', 'vld.variation_id', '=', 'v.id')
                ->leftJoin('units as u', 'p.unit_id', '=', 'u.id')
                ->leftJoinSub($purchase_metrics, 'pm', function ($join) {
                    $join->on('pm.variation_id', '=', 'vld.variation_id')
                        ->on('pm.location_id', '=', 'vld.location_id');
                })
                ->where('p.business_id', $business_id)
                ->where('p.enable_stock', 1)
                ->where('p.is_inactive', 0)
                ->whereNull('v.deleted_at');

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('vld.location_id', $permitted_locations);
            }

            if (! empty(request()->input('location_id'))) {
                $query->where('vld.location_id', request()->input('location_id'));
            }

            $models = $query->select([
                'p.id as product_id',
                'p.name as model',
                'u.short_name as unit',
                DB::raw('SUM(vld.qty_available) as available'),
                DB::raw('COALESCE(SUM(vld.qty_available * COALESCE(pm.avg_age, 0)) / NULLIF(SUM(vld.qty_available), 0), 0) as avg_age'),
                DB::raw('COALESCE(SUM(vld.qty_available * COALESCE(pm.avg_cost, v.dpp_inc_tax)) / NULLIF(SUM(vld.qty_available), 0), 0) as avg_cost'),
                DB::raw('SUM(vld.qty_available * COALESCE(pm.avg_cost, v.dpp_inc_tax)) as stock_value'),
            ])
                ->groupBy('p.id', 'p.name', 'u.short_name')
                ->havingRaw('SUM(vld.qty_available) > 0')
                ->orderByDesc('available');

            return Datatables::of($models)
                ->editColumn('model', function ($row) {
                    return e($row->model);
                })
                ->editColumn('available', function ($row) {
                    $available = (float) $row->available;

                    return '<span data-is_quantity="true" class="display_currency" data-currency_symbol="false" data-orig-value="'.$available.'">'.$available.'</span>'.(! empty($row->unit) ? ' '.e($row->unit) : '');
                })
                ->editColumn('avg_age', function ($row) {
                    $age = max(0, (int) round((float) $row->avg_age));
                    $warning = $age >= 60 ? ' <span title="Aged stock" aria-label="Aged stock">⚠️</span>' : '';

                    return $age.' day'.($age === 1 ? '' : 's').$warning;
                })
                ->editColumn('avg_cost', '<span class="display_currency" data-currency_symbol="true">@format_currency($avg_cost)</span>')
                ->editColumn('stock_value', '<span class="display_currency" data-currency_symbol="true">@format_currency($stock_value)</span>')
                ->removeColumn('product_id')
                ->rawColumns(['available', 'avg_age', 'avg_cost', 'stock_value'])
                ->make(true);
        }
    }

    /**
     * Retrieves branch-level sales and walk-in performance for a selected period.
     *
     * @return \Illuminate\Http\Response
     */
    public function getBranchPerformance()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $period = request()->input('period', '30');
            $periods = ['today', 'yesterday', '7', '30'];
            if (! in_array($period, $periods, true)) {
                $period = '30';
            }

            $now = now();
            if ($period === 'today') {
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
            } elseif ($period === 'yesterday') {
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
            } elseif ($period === '7') {
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
            } else {
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy()->endOfDay();
            }

            $permitted_locations = auth()->user()->permitted_locations();
            $locations = BusinessLocation::forDropdown($business_id, false);

            $sales = DB::table('transactions as t')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereBetween('t.transaction_date', [$start, $end])
                ->when($permitted_locations != 'all', function ($query) use ($permitted_locations) {
                    $query->whereIn('t.location_id', $permitted_locations);
                })
                ->select('t.location_id', DB::raw('SUM(t.final_total) as sales'))
                ->groupBy('t.location_id')
                ->get()
                ->keyBy('location_id');

            // Keep the dashboard aligned with UltimatePOS's financial source of truth.
            // The core utility handles stock/non-stock products, returns and combo lines;
            // a simplified sell-line join can silently report zero for valid branch sales.
            $gross_profit_by_location = $locations->keys()->mapWithKeys(function ($location_id) use ($business_id, $start, $end, $permitted_locations) {
                return [(int) $location_id => (float) $this->transactionUtil->getGrossProfit(
                    $business_id,
                    $start->toDateString(),
                    $end->toDateString(),
                    (int) $location_id,
                    null,
                    $permitted_locations
                )];
            });

            $walk_ins = DB::table('walk_ins as wi')
                ->where('wi.business_id', $business_id)
                ->whereBetween('wi.arrived_at', [$start, $end])
                ->when($permitted_locations != 'all', function ($query) use ($permitted_locations) {
                    $query->whereIn('wi.location_id', $permitted_locations);
                })
                ->select(
                    'wi.location_id',
                    DB::raw('COUNT(*) as walk_ins'),
                    DB::raw("SUM(CASE WHEN wi.status = 'CONVERTED' THEN 1 ELSE 0 END) as sold")
                )
                ->groupBy('wi.location_id')
                ->get()
                ->keyBy('location_id');

            $branches = $locations->map(function ($name, $location_id) use ($sales, $gross_profit_by_location, $walk_ins) {
                $sales_total = (float) ($sales->get($location_id)->sales ?? 0);
                $walk_in_total = (int) ($walk_ins->get($location_id)->walk_ins ?? 0);
                $sold_total = (int) ($walk_ins->get($location_id)->sold ?? 0);
                $conversion = $walk_in_total === 0 ? 0 : round(($sold_total / $walk_in_total) * 100, 1);

                return (object) [
                    'branch' => $name,
                    'sales' => $sales_total,
                    'walk_ins' => $walk_in_total,
                    'sold' => $sold_total,
                    'conversion' => $conversion,
                    'gross_profit' => (float) $gross_profit_by_location->get((int) $location_id, 0),
                ];
            })->sortByDesc('sales')->values();

            return Datatables::of($branches)
                ->editColumn('branch', function ($row) {
                    return e($row->branch);
                })
                ->editColumn('sales', '<span class="display_currency" data-currency_symbol="true">@format_currency($sales)</span>')
                ->editColumn('conversion', function ($row) {
                    $warning = $row->conversion < 30 ? ' <span title="Low walk-in conversion" aria-label="Low walk-in conversion">⚠</span>' : '';

                    return number_format($row->conversion, 1).'%'.$warning;
                })
                ->editColumn('gross_profit', '<span class="display_currency" data-currency_symbol="true">@format_currency($gross_profit)</span>')
                ->rawColumns(['sales', 'conversion', 'gross_profit'])
                ->make(true);
        }
    }

    /**
     * Retrieves payment dues for the purchases.
     *
     * @return \Illuminate\Http\Response
     */
    public function getPurchasePaymentDues()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $today = \Carbon::now()->format('Y-m-d H:i:s');

            $query = Transaction::join(
                'contacts as c',
                'transactions.contact_id',
                '=',
                'c.id'
            )
                    ->leftJoin(
                        'transaction_payments as tp',
                        'transactions.id',
                        '=',
                        'tp.transaction_id'
                    )
                    ->where('transactions.business_id', $business_id)
                    ->where('transactions.type', 'purchase')
                    ->where('transactions.payment_status', '!=', 'paid')
                    ->whereRaw("DATEDIFF( DATE_ADD( transaction_date, INTERVAL IF(transactions.pay_term_type = 'days', transactions.pay_term_number, 30 * transactions.pay_term_number) DAY), '$today') <= 7");

            //Check for permitted locations of a user
            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('transactions.location_id', $permitted_locations);
            }

            if (! empty(request()->input('location_id'))) {
                $query->where('transactions.location_id', request()->input('location_id'));
            }

            $dues = $query->select(
                'transactions.id as id',
                'c.name as supplier',
                'c.supplier_business_name',
                'ref_no',
                'final_total',
                DB::raw('SUM(tp.amount) as total_paid')
            )
                        ->groupBy('transactions.id');

            return Datatables::of($dues)
                ->addColumn('due', function ($row) {
                    $total_paid = ! empty($row->total_paid) ? $row->total_paid : 0;
                    $due = $row->final_total - $total_paid;

                    return '<span class="display_currency" data-currency_symbol="true">'.
                    $due.'</span>';
                })
                ->addColumn('action', '@can("purchase.create") <a href="{{action([\App\Http\Controllers\TransactionPaymentController::class, \'addPayment\'], [$id])}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-accent add_payment_modal"><i class="fas fa-money-bill-alt"></i> @lang("purchase.add_payment")</a> @endcan')
                ->removeColumn('supplier_business_name')
                ->editColumn('supplier', '@if(!empty($supplier_business_name)) {{$supplier_business_name}}, <br> @endif {{$supplier}}')
                ->editColumn('ref_no', function ($row) {
                    if (auth()->user()->can('purchase.view')) {
                        return  '<a href="#" data-href="'.action([\App\Http\Controllers\PurchaseController::class, 'show'], [$row->id]).'"
                                    class="btn-modal" data-container=".view_modal">'.$row->ref_no.'</a>';
                    }

                    return $row->ref_no;
                })
                ->removeColumn('id')
                ->removeColumn('final_total')
                ->removeColumn('total_paid')
                ->rawColumns([0, 1, 2, 3])
                ->make(false);
        }
    }

    /**
     * Retrieves payment dues for the purchases.
     *
     * @return \Illuminate\Http\Response
     */
    public function getSalesPaymentDues()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $today = \Carbon::now()->format('Y-m-d H:i:s');

            $query = Transaction::join(
                'contacts as c',
                'transactions.contact_id',
                '=',
                'c.id'
            )
                    ->leftJoin(
                        'transaction_payments as tp',
                        'transactions.id',
                        '=',
                        'tp.transaction_id'
                    )
                    ->where('transactions.business_id', $business_id)
                    ->where('transactions.type', 'sell')
                    ->where('transactions.payment_status', '!=', 'paid')
                    ->whereNotNull('transactions.pay_term_number')
                    ->whereNotNull('transactions.pay_term_type')
                    ->whereRaw("DATEDIFF( DATE_ADD( transaction_date, INTERVAL IF(transactions.pay_term_type = 'days', transactions.pay_term_number, 30 * transactions.pay_term_number) DAY), '$today') <= 7");

            //Check for permitted locations of a user
            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('transactions.location_id', $permitted_locations);
            }

            if (! empty(request()->input('location_id'))) {
                $query->where('transactions.location_id', request()->input('location_id'));
            }

            $dues = $query->select(
                'transactions.id as id',
                'c.name as customer',
                'c.supplier_business_name',
                'transactions.invoice_no',
                'final_total',
                DB::raw('SUM(tp.amount) as total_paid')
            )
                        ->groupBy('transactions.id');

            return Datatables::of($dues)
                ->addColumn('due', function ($row) {
                    $total_paid = ! empty($row->total_paid) ? $row->total_paid : 0;
                    $due = $row->final_total - $total_paid;

                    return '<span class="display_currency" data-currency_symbol="true">'.
                    $due.'</span>';
                })
                ->editColumn('invoice_no', function ($row) {
                    if (auth()->user()->can('sell.view')) {
                        return  '<a href="#" data-href="'.action([\App\Http\Controllers\SellController::class, 'show'], [$row->id]).'"
                                    class="btn-modal" data-container=".view_modal">'.$row->invoice_no.'</a>';
                    }

                    return $row->invoice_no;
                })
                ->addColumn('action', '@if(auth()->user()->can("sell.create") || auth()->user()->can("direct_sell.access")) <a href="{{action([\App\Http\Controllers\TransactionPaymentController::class, \'addPayment\'], [$id])}}" class="tw-dw-btn tw-dw-btn-xs tw-dw-btn-outline tw-dw-btn-accent add_payment_modal"><i class="fas fa-money-bill-alt"></i> @lang("purchase.add_payment")</a> @endif')
                ->editColumn('customer', '@if(!empty($supplier_business_name)) {{$supplier_business_name}}, <br> @endif {{$customer}}')
                ->removeColumn('supplier_business_name')
                ->removeColumn('id')
                ->removeColumn('final_total')
                ->removeColumn('total_paid')
                ->rawColumns([0, 1, 2, 3])
                ->make(false);
        }
    }

    /**
     * Retrieves recent finalized sales transactions.
     *
     * @return \Illuminate\Http\Response
     */
    public function getRecentSellTransactions()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $query = Transaction::leftJoin('contacts as c', 'transactions.contact_id', '=', 'c.id')
                ->where('transactions.business_id', $business_id)
                ->where('transactions.type', 'sell')
                ->where('transactions.status', 'final');

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('transactions.location_id', $permitted_locations);
            }

            if (! empty(request()->input('location_id'))) {
                $query->where('transactions.location_id', request()->input('location_id'));
            }

            $transactions = $query->select([
                'transactions.id',
                'transactions.transaction_date',
                'transactions.invoice_no',
                'transactions.final_total',
                'transactions.payment_status',
                'c.name as customer',
                'c.supplier_business_name',
            ])->orderByDesc('transactions.transaction_date');

            return Datatables::of($transactions)
                ->editColumn('transaction_date', '{{@format_datetime($transaction_date)}}')
                ->editColumn('customer', function ($row) {
                    $customer = $row->customer;
                    if (! empty($row->supplier_business_name)) {
                        $customer = $row->supplier_business_name.', '.$customer;
                    }

                    return e($customer ?: 'Walk-in customer');
                })
                ->editColumn('invoice_no', function ($row) {
                    if (auth()->user()->can('sell.view') || auth()->user()->can('view_own_sell_only')) {
                        return '<a href="#" data-href="'.action([\App\Http\Controllers\SellController::class, 'show'], [$row->id]).'" class="btn-modal" data-container=".view_modal">'.e($row->invoice_no).'</a>';
                    }

                    return e($row->invoice_no);
                })
                ->editColumn('final_total', '<span class="display_currency" data-currency_symbol="true">@format_currency($final_total)</span>')
                ->editColumn('payment_status', function ($row) {
                    return (string) view('sell.partials.payment_status', [
                        'payment_status' => $row->payment_status,
                        'id' => $row->id,
                    ]);
                })
                ->removeColumn('id')
                ->removeColumn('supplier_business_name')
                ->rawColumns(['transaction_date', 'invoice_no', 'final_total', 'payment_status'])
                ->make(true);
        }
    }

    /**
     * Retrieves the top-selling products for the current calendar month.
     *
     * @return \Illuminate\Http\Response
     */
    public function getTopSellingProducts()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $month_start = now()->startOfMonth()->toDateTimeString();
            $month_end = now()->endOfMonth()->toDateTimeString();
            $quantity_sold = 'SUM(COALESCE(transaction_sell_lines.quantity, 0) - COALESCE(transaction_sell_lines.quantity_returned, 0))';
            $sales_total = 'SUM((COALESCE(transaction_sell_lines.quantity, 0) - COALESCE(transaction_sell_lines.quantity_returned, 0)) * COALESCE(transaction_sell_lines.unit_price_inc_tax, 0))';

            $query = DB::table('transaction_sell_lines')
                ->join('transactions as t', 'transaction_sell_lines.transaction_id', '=', 't.id')
                ->join('products as p', 'transaction_sell_lines.product_id', '=', 'p.id')
                ->leftJoin('units as u', 'p.unit_id', '=', 'u.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereBetween('t.transaction_date', [$month_start, $month_end]);

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }

            if (! empty(request()->input('location_id'))) {
                $query->where('t.location_id', request()->input('location_id'));
            }

            $products = $query->select([
                'p.id as product_id',
                'p.name as product',
                'u.short_name as unit',
                DB::raw($quantity_sold.' as quantity_sold'),
                DB::raw($sales_total.' as sales_total'),
            ])
                ->groupBy('p.id', 'p.name', 'u.short_name')
                ->havingRaw($quantity_sold.' > 0')
                ->orderByDesc('quantity_sold');

            return Datatables::of($products)
                ->editColumn('product', function ($row) {
                    return e($row->product);
                })
                ->editColumn('quantity_sold', function ($row) {
                    return '<span data-is_quantity="true" class="display_currency" data-currency_symbol="false" data-orig-value="'.(float) $row->quantity_sold.'" data-unit="'.e($row->unit ?: '').'">'.(float) $row->quantity_sold.'</span>'.(! empty($row->unit) ? ' '.e($row->unit) : '');
                })
                ->editColumn('sales_total', '<span class="display_currency" data-currency_symbol="true">@format_currency($sales_total)</span>')
                ->removeColumn('product_id')
                ->rawColumns(['quantity_sold', 'sales_total'])
                ->make(true);
        }
    }

    public function loadMoreNotifications()
    {
        $notifications = auth()->user()->notifications()->orderBy('created_at', 'DESC')->paginate(10);

        if (request()->input('page') == 1) {
            auth()->user()->unreadNotifications->markAsRead();
        }
        $notifications_data = $this->commonUtil->parseNotifications($notifications);

        return view('layouts.partials.notification_list', compact('notifications_data'));
    }

    /**
     * Function to count total number of unread notifications
     *
     * @return json
     */
    public function getTotalUnreadNotifications()
    {
        $unread_notifications = auth()->user()->unreadNotifications;
        $total_unread = $unread_notifications->count();

        $notification_html = '';
        $modal_notifications = [];
        foreach ($unread_notifications as $unread_notification) {
            if (isset($data['show_popup'])) {
                $modal_notifications[] = $unread_notification;
                $unread_notification->markAsRead();
            }
        }
        if (! empty($modal_notifications)) {
            $notification_html = view('home.notification_modal')->with(['notifications' => $modal_notifications])->render();
        }

        return [
            'total_unread' => $total_unread,
            'notification_html' => $notification_html,
        ];
    }

    private function __chartOptions($title)
    {
        return [
            'yAxis' => [
                'title' => [
                    'text' => $title,
                ],
            ],
            'legend' => [
                'align' => 'right',
                'verticalAlign' => 'top',
                'floating' => true,
                'layout' => 'vertical',
                'padding' => 20,
            ],
        ];
    }

    public function getCalendar()
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->restUtil->is_admin(auth()->user(), $business_id);
        $is_superadmin = auth()->user()->can('superadmin');
        if (request()->ajax()) {
            $data = [
                'start_date' => request()->start,
                'end_date' => request()->end,
                'user_id' => ($is_admin || $is_superadmin) && ! empty(request()->user_id) ? request()->user_id : auth()->user()->id,
                'location_id' => ! empty(request()->location_id) ? request()->location_id : null,
                'business_id' => $business_id,
                'events' => request()->events ?? [],
                'color' => '#007FFF',
            ];
            $events = [];

            if (in_array('bookings', $data['events'])) {
                $events = $this->restUtil->getBookingsForCalendar($data);
            }

            $module_events = $this->moduleUtil->getModuleData('calendarEvents', $data);

            foreach ($module_events as $module_event) {
                $events = array_merge($events, $module_event);
            }

            return $events;
        }

        $all_locations = BusinessLocation::forDropdown($business_id)->toArray();
        $users = [];
        if ($is_admin) {
            $users = User::forDropdown($business_id, false);
        }

        $event_types = [
            'bookings' => [
                'label' => __('restaurant.bookings'),
                'color' => '#007FFF',
            ],
        ];
        $module_event_types = $this->moduleUtil->getModuleData('eventTypes');
        foreach ($module_event_types as $module_event_type) {
            $event_types = array_merge($event_types, $module_event_type);
        }

        return view('home.calendar')->with(compact('all_locations', 'users', 'event_types'));
    }

    public function showNotification($id)
    {
        $notification = DatabaseNotification::find($id);

        $data = $notification->data;

        $notification->markAsRead();

        return view('home.notification_modal')->with([
            'notifications' => [$notification],
        ]);
    }

    public function attachMediasToGivenModel(Request $request)
    {
        if ($request->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                $model_id = $request->input('model_id');
                $model = $request->input('model_type');
                $model_media_type = $request->input('model_media_type');

                DB::beginTransaction();

                //find model to which medias are to be attached
                $model_to_be_attached = $model::where('business_id', $business_id)
                                        ->findOrFail($model_id);

                Media::uploadMedia($business_id, $model_to_be_attached, $request, 'file', false, $model_media_type);

                DB::commit();

                $output = [
                    'success' => true,
                    'msg' => __('lang_v1.success'),
                ];
            } catch (Exception $e) {
                DB::rollBack();

                \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    public function getUserLocation($latlng)
    {
        $latlng_array = explode(',', $latlng);

        $response = $this->moduleUtil->getLocationFromCoordinates($latlng_array[0], $latlng_array[1]);

        return ['address' => $response];
    }
}
