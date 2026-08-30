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
        $this->assertStringContainsString("'canStartRepeat' => \$this->canStartRepeat(", $controller);
        $this->assertStringContainsString("'canClaimWarranty' => \$this->canClaimWarranty(", $controller);
        $this->assertStringContainsString('WarrantyClaimService::PERMISSION_MANAGE', $controller);
    }

    /**
     * The Repeat visit button could never be used: it rendered only inside the
     * collection block (READY only), carried `disabled` whenever the job was
     * not CLOSED -- which was always, inside that block -- and posted an empty
     * command_uuid that the submit handler strips before sending, so the route
     * would have rejected it as missing anyway.
     */
    public function test_repeat_visit_is_offered_on_closed_jobs_and_carries_an_idempotency_key(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/repair/show.blade.php'));

        $this->assertStringContainsString('@if($canCollect || $canStartRepeat)', $view);
        $this->assertStringContainsString('@if($canStartRepeat)<form class="collection-form"', $view);

        // The old gate and the always-on disabled attribute must both be gone.
        $this->assertStringNotContainsString("@if(\$canCollect && \$job->state !== 'CLOSED')", $view);
        $this->assertStringNotContainsString("@if(\$job->state !== 'CLOSED')disabled@endif", $view);

        // The handler drops empty values, so the key has to be filled in first.
        $this->assertStringContainsString('var uuidField = form.elements.command_uuid;', $view);
        $this->assertStringContainsString('uuidField.value = sbCommandUuid();', $view);
        $this->assertSame(1, substr_count($view, 'function sbCommandUuid()'), 'One shared uuid helper, not one per form.');
    }
}
