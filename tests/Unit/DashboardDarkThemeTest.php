<?php

namespace Tests\Unit;

use Tests\TestCase;

class DashboardDarkThemeTest extends TestCase
{
    public function test_dashboard_chart_surfaces_have_dark_highcharts_contract(): void
    {
        $view = file_get_contents(base_path('resources/views/home/index.blade.php'));
        $css = file_get_contents(base_path('public/css/saverbro-dark-pos.css'));
        $controller = file_get_contents(base_path('app/Http/Controllers/HomeController.php'));
        $javascript = file_get_contents(base_path('public/js/home.js'));
        $layout = file_get_contents(base_path('resources/views/layouts/partials/css.blade.php'));
        $header = file_get_contents(base_path('resources/views/layouts/partials/header.blade.php'));

        $this->assertStringContainsString('sb-dashboard-chart-card', $view);
        $this->assertStringContainsString('sb-dashboard-chart-frame', $view);
        $this->assertStringContainsString('sb-dashboard-header-row', $view);
        $this->assertStringContainsString('sb-dashboard-header-filters', $view);
        $this->assertStringContainsString('dashboard_location', $view);
        $this->assertStringContainsString('dashboard_date_filter', $view);
        $this->assertStringContainsString('sb-dashboard-kpi-grid', $view);
        $this->assertStringContainsString('Total Sale', $view);
        $this->assertStringContainsString('Total Purchase', $view);
        $this->assertStringContainsString('Total Walk-In', $view);
        $this->assertStringContainsString('walk_in_total', $view);
        $this->assertStringContainsString('Total Sell Transaction', $view);
        $this->assertStringContainsString('Total Expenses', $view);
        $this->assertStringContainsString('Total Sell Return', $view);
        $this->assertStringContainsString('Total Stock Adjustment', $view);
        $this->assertStringContainsString('Recent Sales Transaction', $view);
        $this->assertStringContainsString('recent_sell_transactions_table', $view);
        $this->assertStringContainsString('recent_sell_transactions_location', $view);
        $this->assertStringContainsString('Top Selling Products', $view);
        $this->assertStringContainsString('top_selling_products_table', $view);
        $this->assertStringContainsString('top_selling_products_location', $view);
        $this->assertStringContainsString('Most Available Model', $view);
        $this->assertStringContainsString('most_available_models_table', $view);
        $this->assertStringContainsString('most_available_models_location', $view);
        $this->assertStringContainsString('Branch Performance', $view);
        $this->assertStringContainsString('branch_performance_table', $view);
        $this->assertStringContainsString('branch_performance_period', $view);
        $this->assertStringContainsString('sb-account-menu', $header);
        $this->assertStringContainsString('sb-account-menu__panel', $header);
        $this->assertStringContainsString('sb-account-menu__item', $header);
        $this->assertStringContainsString('Sales in selected range', $view);
        $this->assertStringContainsString('Finalized sales count', $view);
        $this->assertStringContainsString('Stock adjustments value', $view);
        $this->assertStringContainsString('total_sell_transactions', $view);
        $this->assertStringContainsString('total_expense_kpi', $view);
        $this->assertStringContainsString('total_sell_return_total', $view);
        $this->assertStringContainsString('total_stock_adjustment', $view);
        $this->assertStringContainsString('@format_currency(0)', $view);
        $this->assertStringContainsString('walkInSummary', $view);
        $this->assertStringContainsString('$output[\'walk_ins\']', $controller);
        $this->assertStringContainsString('$output[\'total_sell_transactions\']', $controller);
        $this->assertStringContainsString('$output[\'total_adjustment\']', $controller);
        $this->assertStringContainsString('getRecentSellTransactions', $controller);
        $this->assertStringContainsString('getMostAvailableModels', $controller);
        $this->assertStringContainsString('getBranchPerformance', $controller);
        $this->assertStringContainsString('$this->transactionUtil->getGrossProfit(', $controller);
        $this->assertStringNotContainsString('$allocated_quantity = \'COALESCE(tspl.quantity', $controller);
        $this->assertStringContainsString("$('.walk_in_total').text", $javascript);
        $this->assertStringContainsString("$('.total_sell_transactions').text", $javascript);
        $this->assertStringContainsString('/home/top-selling-products', $javascript);
        $this->assertStringContainsString('top_selling_products_table', $javascript);
        $this->assertStringContainsString('/home/recent-sell-transactions', $javascript);
        $this->assertStringContainsString('recent_sell_transactions_table', $javascript);
        $this->assertStringContainsString('/home/most-available-models', $javascript);
        $this->assertStringContainsString('most_available_models_table', $javascript);
        $this->assertStringContainsString('/home/branch-performance', $view);
        $this->assertStringContainsString('branch_performance_table', $view);
        $this->assertStringContainsString('if (lang)', $javascript);
        $this->assertStringContainsString('home.js?v=\' . $asset_v . \'&mtime=\' . filemtime', $view);
        $this->assertStringContainsString('.sb-dashboard-header-row {', $css);
        $this->assertStringContainsString('.sb-dashboard-filter-button {', $css);
        $this->assertStringContainsString('.sb-dashboard-kpi {', $css);
        $this->assertStringContainsString('.sb-account-menu__panel {', $css);
        $this->assertStringContainsString('.sb-account-menu .sb-account-menu__item {', $css);
        $this->assertMatchesRegularExpression('/\.sb-dashboard-chart-card\s*\{[^}]*background:\s*var\(--sb-surface\)/s', $css);
        $this->assertMatchesRegularExpression('/\.sb-dashboard-chart-frame\s*\.highcharts-background\s*\{[^}]*fill:\s*#0d1726/s', $css);
        $this->assertStringContainsString("saverbro-dark-pos.css?v='.", $layout);
        $this->assertStringContainsString(".'&mtime='.filemtime", $layout);
    }
}
