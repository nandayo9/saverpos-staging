<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/**
 * Woocommerce screens.
 *
 * NOTE: this is a presentation-only rebuild of the page that the paid
 * Modules/Woocommerce package serves on the live site. That package is not
 * present in this repository - modules_statuses.json declares it enabled, but
 * only Modules/Recommerce exists on disk - so there is no sync engine, no API
 * client and no settings table behind these screens.
 *
 * Everything the view renders comes from summary() below. The figures are
 * placeholders that mirror the live page's shape; the Sync buttons post
 * nowhere. Wiring them up means either restoring the real module or writing a
 * WooCommerce REST client, which is a separate piece of work.
 */
class WoocommerceController extends Controller
{
    /**
     * Placeholder figures standing in for the real module's sync state.
     */
    private function summary()
    {
        return [
            'categories' => [
                'not_synced' => 4,
                'last_synced' => '2 months ago',
            ],
            'products' => [
                'not_synced' => 8,
                'updated_since_sync' => 28,
                'last_synced_new' => '8 months ago',
                'last_synced_all' => '2 months ago',
            ],
            'orders' => [
                'last_synced' => '8 months ago',
            ],
            'tax_rates' => [],
        ];
    }

    public function index()
    {
        return view('woocommerce.index', [
            'summary' => $this->summary(),
            'active_tab' => 'woocommerce',
        ]);
    }

    /**
     * Sync log.
     *
     * Unlike the dashboard above this is real: woocommerce_sync_logs came
     * across with the production import, so the rows here are the same rows
     * the live site lists. Read-only - nothing writes to this table without
     * the sync engine.
     */
    public function syncLog()
    {
        $business_id = request()->session()->get('user.business_id');

        if (request()->ajax()) {
            $logs = DB::table('woocommerce_sync_logs as wsl')
                ->leftJoin('users as u', 'wsl.created_by', '=', 'u.id')
                ->where('wsl.business_id', $business_id)
                ->select([
                    'wsl.created_at',
                    'wsl.sync_type',
                    'wsl.operation_type',
                    'wsl.data',
                    DB::raw("CONCAT(COALESCE(u.surname,''),' ',COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as synced_by"),
                ]);

            return DataTables::of($logs)
                ->editColumn('created_at', function ($row) {
                    $at = \Carbon\Carbon::parse($row->created_at);

                    return '<span>' . $at->format('d/m/Y H:i') . '</span><br>'
                        . '<small class="text-muted">' . $at->diffForHumans() . '</small>';
                })
                ->editColumn('sync_type', function ($row) {
                    // The table stores all_products/new_products separately;
                    // the live log shows both simply as "Products".
                    // Literals rather than __() - there are no lang_v1 keys
                    // for these, so the helper just echoed the key back.
                    $map = [
                        'all_products' => 'Products',
                        'new_products' => 'Products',
                        'categories' => 'Categories',
                        'orders' => 'Orders',
                    ];

                    return $map[$row->sync_type] ?? ucfirst(str_replace('_', ' ', (string) $row->sync_type));
                })
                ->editColumn('operation_type', function ($row) {
                    return ucfirst((string) $row->operation_type);
                })
                ->addColumn('records', function ($row) {
                    $records = json_decode((string) $row->data, true);

                    if (empty($records) || ! is_array($records)) {
                        return '';
                    }

                    return '<span>' . e(implode(', ', $records)) . '</span><br>'
                        . '<small class="text-muted">' . count($records) . ' Records</small>';
                })
                ->removeColumn('data')
                ->rawColumns(['created_at', 'records'])
                ->make(true);
        }

        return view('woocommerce.sync_log', ['active_tab' => 'sync_log']);
    }

    /**
     * API settings. Read-only - see the view for why.
     */
    public function apiSettings()
    {
        $business_id = request()->session()->get('user.business_id');

        $raw = DB::table('business')->where('id', $business_id)->value('woocommerce_api_settings');
        $settings = $raw ? json_decode($raw, true) : [];

        return view('woocommerce.api_settings', [
            'active_tab' => 'api_settings',
            'settings' => is_array($settings) ? $settings : [],
        ]);
    }
}
