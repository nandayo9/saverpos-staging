<?php

namespace Tests\Unit;

use Tests\TestCase;

class RecommerceDeviceLifecycleUiContractTest extends TestCase
{
    public function test_sale_return_form_collects_the_exact_original_device_code(): void
    {
        $view = file_get_contents(base_path('resources/views/sell_return/add.blade.php'));

        $this->assertStringContainsString('products[{{$loop->index}}][recommerce_device_codes]', $view);
        $this->assertStringContainsString('products[{{$loop->index}}][product_id]', $view);
        $this->assertStringContainsString('products[{{$loop->index}}][variation_id]', $view);
        $this->assertStringContainsString('Scan the original SaverBro device code', $view);
        $this->assertStringContainsString('RETURNED_PENDING_INSPECTION', $view);
    }

    public function test_reconciliation_can_select_an_authorized_cohort_branch_and_timeline_keeps_history(): void
    {
        $controller = file_get_contents(base_path('Modules/Recommerce/Http/Controllers/ReconciliationController.php'));
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/reconciliation/index.blade.php'));
        $timeline = file_get_contents(base_path('Modules/Recommerce/Services/DeviceEventTimelineService.php'));

        $this->assertStringContainsString("query('location_id'", $controller);
        $this->assertStringContainsString("whereIn('id', \$configuredLocationIds)", $controller);
        $this->assertStringContainsString('reconciliation-location', $view);
        $this->assertStringNotContainsString('->filter(', $timeline);
    }
}
