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

    /**
     * RC-039's claim route had no caller: the service and the POST endpoint
     * shipped without a form, so a warranty claim was unreachable from the UI.
     * The card must render the claims and gate the form on the same permission
     * the controller checks, not merely on the route existing.
     */
    public function test_repair_record_exposes_warranty_claims_and_gates_the_claim_form(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/repair/show.blade.php'));
        $controller = file_get_contents(base_path('Modules/Recommerce/Http/Controllers/RepairJobController.php'));

        $this->assertStringContainsString('Warranty claims', $view);
        $this->assertStringContainsString('@forelse ($warrantyClaims as $claim)', $view);
        $this->assertStringContainsString('@if($canClaimWarranty)', $view);
        $this->assertStringContainsString("route('recommerce.repair.warranty.store', \$job->job_code)", $view);
        $this->assertStringContainsString('warranty-claim-form', $view);
        $this->assertStringContainsString('command_uuid', $view);

        $this->assertStringContainsString("'warrantyClaims' => \$this->warrantyClaims(\$job)", $controller);
        $this->assertStringContainsString("'canClaimWarranty' => \$this->canClaimWarranty(", $controller);
        $this->assertStringContainsString('WarrantyClaimService::PERMISSION_MANAGE', $controller);
    }
}
