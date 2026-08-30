<?php

namespace Tests\Unit;

use Tests\TestCase;

class RecommerceCustomerRepairContractTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(base_path($path));
    }

    public function test_customer_device_service_is_transactional_and_non_stock(): void
    {
        $service = $this->source('Modules/Recommerce/Services/CustomerRepairDeviceService.php');

        $this->assertStringContainsString('DB::transaction', $service);
        $this->assertStringContainsString('lockForUpdate', $service);
        $this->assertStringContainsString("'ownership_kind' => 'CUSTOMER'", $service);
        $this->assertStringContainsString("'stock_participation' => 'NONE'", $service);
        $this->assertStringContainsString('StrongIdentifierHasher::hash', $service);
        $this->assertStringContainsString("'raw_value_encrypted'", $service);
        $this->assertStringContainsString('OwnershipPeriod::create', $service);
        $this->assertStringContainsString('CustodyPeriod::create', $service);
        $this->assertStringContainsString('Access credentials are not accepted', $service);
        $this->assertStringNotContainsString("'password' =>", $service);
        $this->assertStringNotContainsString("'pin' =>", $service);
        $this->assertStringNotContainsString("'pattern' =>", $service);
    }

    public function test_intake_contract_persists_checklist_and_is_idempotent(): void
    {
        $service = $this->source('Modules/Recommerce/Services/RepairJobIntakeService.php');

        $this->assertStringContainsString("where('command_uuid'", $service);
        $this->assertStringContainsString('command_hash', $service);
        $this->assertStringContainsString('Idempotency key was reused for a different repair intake.', $service);
        $this->assertStringContainsString('RepairChecklistItem::create', $service);
        $this->assertStringContainsString('RepairStateTransition::create', $service);
        $this->assertStringContainsString('RepairJobStateMachine::STATE_RECEIVED', $service);
        $this->assertStringContainsString('RepairPublicLookupService', $service);
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $service);
    }

    public function test_scope_validation_and_public_lookup_privacy_contracts_are_present(): void
    {
        $controller = $this->source('Modules/Recommerce/Http/Controllers/RepairJobController.php');
        $routes = $this->source('Modules/Recommerce/Routes/web.php');
        $publicView = $this->source('Modules/Recommerce/Resources/views/repair/public-status.blade.php');

        $this->assertStringContainsString('allowsWriteLocation($user, \'recommerce.repair.intake\'', $controller);
        $this->assertStringContainsString("where('business_id', \$businessId)", $controller);
        $this->assertStringContainsString("'errors' => \$exception->errors()", $controller);
        $this->assertStringContainsString("/repair/status/{jobCode}/{token}", $routes);
        $this->assertStringContainsString("->middleware('throttle:30,1')", $routes);
        $this->assertStringNotContainsString('$publicJob->contact', $publicView);
        $this->assertStringNotContainsString('$publicJob->reported_fault', $publicView);
        $this->assertStringNotContainsString('$publicJob->estimated_quote_amount', $publicView);
        $this->assertStringContainsString('internal notes', $publicView);
        $this->assertStringContainsString('access details', $publicView);
        $this->assertStringContainsString('publicJob', $controller);
    }

    public function test_migration_has_rollback_for_intake_records(): void
    {
        $migration = $this->source('Modules/Recommerce/Database/Migrations/2026_08_28_000012_enhance_customer_repair_intake.php');

        $this->assertStringContainsString('recommerce_repair_checklist_items', $migration);
        $this->assertStringContainsString('recommerce_repair_state_transitions', $migration);
        $this->assertStringContainsString('recommerce_repair_lookup_tokens', $migration);
        $this->assertStringContainsString('function down()', $migration);
        $this->assertStringContainsString('dropIfExists', $migration);
    }

    /**
     * The intake select is seeded with only the first 200 contacts by name, so
     * a client-side filter over those options can never reach customer 201.
     * The search box must call the server endpoint, which had shipped with no
     * caller at all, and must keep the chosen customer in the list.
     */
    public function test_intake_customer_search_uses_the_server_endpoint_not_a_client_side_filter(): void
    {
        $view = $this->source('Modules/Recommerce/Resources/views/repair/new.blade.php');
        $controller = $this->source('Modules/Recommerce/Http/Controllers/RepairJobController.php');

        $this->assertStringContainsString("data-customer-search-url=\"{{ route('recommerce.repair.customers') }}\"", $view);
        $this->assertStringContainsString('root.dataset.customerSearchUrl', $view);
        // Both render paths -- the search result and the restored seed list --
        // must re-append a chosen customer that is not in the list they build,
        // or clearing the box silently drops a customer found past the 200th.
        $this->assertStringContainsString('restoreSeededCustomers', $view);
        $this->assertSame(
            2,
            substr_count($view, 'customer.appendChild(new Option(selectedText, selectedValue))'),
            'Both the search result and the restored seed list must keep the current selection.'
        );

        // The abandoned filter hid options instead of querying the server.
        $this->assertStringNotContainsString('option.hidden = index > 0', $view);

        $this->assertStringContainsString('public function customers(', $controller);
        $this->assertStringContainsString("->limit(200)", $controller);
    }
}
