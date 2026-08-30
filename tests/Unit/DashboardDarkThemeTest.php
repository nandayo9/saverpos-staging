<?php

namespace Tests\Unit;

use Tests\TestCase;

class DashboardDarkThemeTest extends TestCase
{
    public function test_dashboard_chart_surfaces_have_dark_highcharts_contract(): void
    {
        $view = file_get_contents(base_path('resources/views/home/index.blade.php'));
        $css = file_get_contents(base_path('public/css/saverbro-dark-pos.css'));
        $layout = file_get_contents(base_path('resources/views/layouts/partials/css.blade.php'));

        $this->assertStringContainsString('sb-dashboard-chart-card', $view);
        $this->assertStringContainsString('sb-dashboard-chart-frame', $view);
        $this->assertStringContainsString('sb-dashboard-kpi-grid', $view);
        $this->assertStringContainsString('Today sale', $view);
        $this->assertStringContainsString('Today purchase due', $view);
        $this->assertStringContainsString('Total visits this month', $view);
        $this->assertStringContainsString('@format_currency(0)', $view);
        $this->assertStringContainsString('walkInMonthSummary', $view);
        $this->assertStringContainsString('.sb-dashboard-kpi {', $css);
        $this->assertMatchesRegularExpression('/\.sb-dashboard-chart-card\s*\{[^}]*background:\s*var\(--sb-surface\)/s', $css);
        $this->assertMatchesRegularExpression('/\.sb-dashboard-chart-frame\s*\.highcharts-background\s*\{[^}]*fill:\s*#0d1726/s', $css);
        $this->assertStringContainsString("saverbro-dark-pos.css?v='.", $layout);
        $this->assertStringContainsString(".'&mtime='.filemtime", $layout);
    }
}
