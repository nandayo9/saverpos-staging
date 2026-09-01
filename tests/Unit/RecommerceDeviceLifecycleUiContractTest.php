<?php

namespace Tests\Unit;

use Tests\TestCase;

class RecommerceDeviceLifecycleUiContractTest extends TestCase
{
    public function test_pos_only_shows_the_device_scanner_for_approved_serialised_variations(): void
    {
        $productUtil = file_get_contents(base_path('app/Utils/ProductUtil.php'));
        $row = file_get_contents(base_path('resources/views/sale_pos/product_row.blade.php'));
        $script = file_get_contents(base_path('public/js/pos.js'));

        $this->assertStringContainsString('recommerce_tracking_required', $productUtil);
        $this->assertStringContainsString("Schema::hasTable('recommerce_serialization_profiles')", $productUtil);
        $this->assertStringContainsString("where('mode', 'TRACKED_REQUIRED')", $productUtil);
        $this->assertStringContainsString("@if(!empty(\$product->recommerce_tracking_required))", $row);
        $this->assertStringContainsString('Identify device', $row);
        $this->assertStringContainsString('recommerce-device-scan-count', $row);
        $this->assertStringContainsString('pos_validate_recommerce_device_scans', $script);
        $this->assertStringContainsString('recommerce_device_is_already_in_cart', $script);
        $this->assertStringContainsString('This Device is already in the current sale.', $script);
        $this->assertStringContainsString('Identify exactly ', $script);
    }

    public function test_sale_return_form_collects_the_exact_original_device_code(): void
    {
        $view = file_get_contents(base_path('resources/views/sell_return/add.blade.php'));

        $this->assertStringContainsString('products[{{$loop->index}}][recommerce_device_codes]', $view);
        $this->assertStringContainsString('products[{{$loop->index}}][product_id]', $view);
        $this->assertStringContainsString('products[{{$loop->index}}][variation_id]', $view);
        $this->assertStringContainsString('Scan the original SaverBro device code', $view);
        $this->assertStringContainsString('RETURNED_PENDING_INSPECTION', $view);
    }

    public function test_receiving_ui_uses_the_configured_bounded_batch_limit(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/receiving/index.blade.php'));

        $this->assertStringContainsString("\$receivingBatchLimit = max(1, (int) config('recommerce.receive_batch_limit', 50));", $view);
        $this->assertStringContainsString('max: @json($receivingBatchLimit)', $view);
        $this->assertStringNotContainsString('max: 1, remaining:', $view);
        $this->assertStringContainsString('Register staged devices', $view);
        $this->assertStringContainsString('more labels need print and attachment', $view);
        $this->assertStringContainsString('buildLabelAction(device)', $view);
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

    public function test_customer_repair_exposes_the_linked_pos_customer_receipt(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/repair/show.blade.php'));
        $controller = file_get_contents(base_path('Modules/Recommerce/Http/Controllers/RepairJobController.php'));
        $routes = file_get_contents(base_path('Modules/Recommerce/Routes/web.php'));

        $this->assertStringContainsString('Print customer receipt', $view);
        $this->assertStringContainsString("route('recommerce.repair.customer_receipt', \$job->job_code)", $view);
        $this->assertStringContainsString('public function customerReceipt(', $controller);
        $this->assertStringContainsString("'recommerce.repair.view_cost'", $controller);
        $this->assertStringContainsString('getInvoiceUrl', $controller);
        $this->assertStringContainsString('print_on_load=1', $controller);
        $this->assertStringContainsString("/repair/{jobCode}/customer-receipt", $routes);
        $this->assertStringContainsString("name('recommerce.repair.customer_receipt')", $routes);
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

    /**
     * The checklist builds its class from the stored outcome, and the
     * controller restricts that column to PASS, FAIL and NOT_APPLICABLE -- so
     * the generated classes are outcome-pass, outcome-fail and
     * outcome-not-applicable. Only the first two and an unused `outcome-na`
     * were ever styled, so an N/A row fell through to the card's default text.
     * On the light card that merely looked unemphasised; on the dark card it
     * inherits the brightest colour and outranks PASS and FAIL.
     */
    public function test_every_checklist_outcome_the_controller_allows_has_a_style(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/repair/show.blade.php'));
        $controller = file_get_contents(base_path('Modules/Recommerce/Http/Controllers/RepairJobController.php'));

        preg_match("/checklist[^']*outcome' => \['required', 'in:([A-Z_,]+)'\]/", $controller, $allowed);
        $this->assertNotEmpty($allowed, 'Could not read the allowed checklist outcomes from the controller.');

        foreach (explode(',', $allowed[1]) as $outcome) {
            $class = 'outcome-'.strtolower(str_replace('_', '-', $outcome));
            $this->assertStringContainsString(
                '.'.$class,
                $view,
                $class.' is generated by the checklist but has no rule in the view.'
            );
        }
    }
}
