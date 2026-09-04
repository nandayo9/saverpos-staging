<?php

namespace Tests\Unit;

use Tests\TestCase;

class RecommerceTradeInWorkspaceUiContractTest extends TestCase
{
    public function test_trade_in_module_has_one_sidebar_entry_and_separate_workspace_routes(): void
    {
        $sidebar = file_get_contents(base_path('app/Http/Middleware/AdminSidebarMenu.php'));
        $routes = file_get_contents(base_path('Modules/Recommerce/Routes/web.php'));

        $this->assertSame(1, substr_count($sidebar, "'Trade-In Acquisition'"));
        $this->assertStringContainsString("name('recommerce.tradeins.index')", $routes);
        $this->assertStringContainsString("name('recommerce.tradeins.acquisitions')", $routes);
        $this->assertStringContainsString("name('recommerce.tradeins.approvals')", $routes);
        $this->assertStringContainsString("name('recommerce.tradeins.reports')", $routes);
        $this->assertStringContainsString("name('recommerce.tradeins.create')", $routes);
        $this->assertStringContainsString("name('recommerce.tradeins.show')", $routes);
    }

    public function test_overview_is_operational_and_new_acquisition_is_a_four_stage_workspace(): void
    {
        $overview = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/overview.blade.php'));
        $create = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/create.blade.php'));

        $this->assertStringContainsString('Needs attention', $overview);
        $this->assertStringContainsString('Active Acquisitions', $overview);
        $this->assertStringNotContainsString('tradein-full-form', $overview);
        foreach (['1 Intake', '2 Check', '3 Deal', '4 Close'] as $step) {
            $this->assertStringContainsString($step, $create);
        }
        $this->assertStringContainsString('Quick Quote', $create);
        $this->assertStringContainsString("number_format((float) data_get(\$selectedQuote->pricing_snapshot_json, 'recommendation.target_acquisition_amount'), 2, '.', '')", $create);
        $records = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/acquisitions.blade.php'));
        $this->assertStringContainsString('Converted to formal deal', $records);
        $this->assertStringNotContainsString('supplier-capable', $create);
        $this->assertStringNotContainsString('native purchase payee', $create);
    }

    public function test_deal_desk_and_qc_context_explain_state_without_stale_available_actions(): void
    {
        $deal = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/show.blade.php'));
        $repair = file_get_contents(base_path('Modules/Recommerce/Resources/views/repair/show.blade.php'));

        $this->assertStringContainsString('Recommended buy', $deal);
        $this->assertStringContainsString('Negotiation timeline', $deal);
        $this->assertStringContainsString('$isAvailable', $deal);
        $this->assertStringContainsString('$isPendingQc', $deal);
        $this->assertStringContainsString('No stale QC action is available', $deal);
        $this->assertStringContainsString('No QC action available', $deal);
        $this->assertStringContainsString('Current owner', $repair);
        $this->assertStringContainsString('Acquired from', $repair);
        $this->assertStringContainsString('Intake findings carried forward', $repair);
    }
}
