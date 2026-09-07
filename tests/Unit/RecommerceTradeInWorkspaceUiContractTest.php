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
        $this->assertStringContainsString('Not in catalogue yet — quick estimate only', $create);
        $this->assertStringContainsString('Confirm a catalogue match before formal inspection and acquisition.', $create);
        $this->assertStringContainsString('Match catalogue', $create);
        $this->assertStringContainsString('Incoming Device', $create);
        $this->assertStringContainsString('Existing SKU found', $create);
        $this->assertStringContainsString('Possible duplicate', $create);
        $this->assertStringContainsString('Create Trade-In SKU', $create);
        $this->assertStringContainsString('Create &amp; Continue', $create);
        $this->assertStringContainsString('Duplicate override', $create);
        $this->assertStringContainsString('Catalogue Match Required', $create);
        $this->assertStringContainsString("number_format((float) data_get(\$selectedQuote->pricing_snapshot_json, 'recommendation.target_acquisition_amount'), 2, '.', '')", $create);
        $records = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/acquisitions.blade.php'));
        $this->assertStringContainsString('Converted to formal deal', $records);
        $this->assertStringNotContainsString('supplier-capable', $create);
        $this->assertStringNotContainsString('native purchase payee', $create);
    }

    public function test_trade_in_catalogue_routes_and_service_preserve_permanent_tn_policy(): void
    {
        $routes = file_get_contents(base_path('Modules/Recommerce/Routes/web.php'));
        $service = file_get_contents(base_path('Modules/Recommerce/Services/TradeInCatalogueService.php'));

        $this->assertStringContainsString("name('recommerce.tradeins.quick_quotes.catalogue')", $routes);
        $this->assertStringContainsString('REUSED_EXACT', $service);
        $this->assertStringContainsString('NEW_TN_VARIATION', $service);
        $this->assertStringContainsString('NEW_TN_PRODUCT', $service);
        $this->assertStringContainsString('specification_fingerprint', $service);
        $this->assertStringContainsString('syncVariation', $service);
        $this->assertStringContainsString('DUPLICATE_OVERRIDE_REASONS', $service);
        $this->assertStringContainsString('duplicate_override_reason', $service);
        $this->assertStringContainsString("'TN-LEN-T14G2-I5-16-512'", file_get_contents(base_path('tests/Feature/RecommerceTradeInCatalogueTest.php')));
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
        $this->assertStringContainsString('Catalogue origin', $deal);
        $this->assertStringContainsString('Acquisition source', $deal);
        $passport = file_get_contents(base_path('Modules/Recommerce/Resources/views/device/show.blade.php'));
        $this->assertStringContainsString('Sell-to-SaverBro Trade-In', $passport);
        $this->assertStringContainsString('No native purchase provenance is recorded', $passport);
        $this->assertStringContainsString('Current owner', $repair);
        $this->assertStringContainsString('Acquired from', $repair);
        $this->assertStringContainsString('Intake findings carried forward', $repair);
    }

    public function test_photo_ai_stays_inside_the_existing_case_workspace_and_names_its_authority_boundary(): void
    {
        $website = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/website.blade.php'));
        $routes = file_get_contents(base_path('Modules/Recommerce/Routes/web.php'));
        $service = file_get_contents(base_path('Modules/Recommerce/Services/TradeInPhotoAiIntakeService.php'));
        $controller = file_get_contents(base_path('Modules/Recommerce/Http/Controllers/TradeInController.php'));
        $this->assertStringContainsString('AI Pre-Inspection', $website);
        $this->assertStringContainsString('Customer confirmation and technician findings remain separate', $website);
        $this->assertStringContainsString('Customer', $website);
        $this->assertStringContainsString('AI observation', $website);
        $this->assertStringContainsString('Technician', $website);
        $this->assertStringContainsString("name('recommerce.tradeins.intakes.photo_ai.review')", $routes);
        $this->assertStringContainsString("route('recommerce.tradeins.intakes.show', \$intakeId)", $controller);
        $this->assertStringContainsString('never creates identity, price, stock, purchase, or approval', $service);
        $this->assertStringNotContainsString('TradeInVisionProvider', file_get_contents(base_path('Modules/Recommerce/Services/TradeInPricingService.php')));
    }
}
