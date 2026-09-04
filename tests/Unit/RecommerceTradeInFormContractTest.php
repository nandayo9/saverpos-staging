<?php

namespace Tests\Unit;

use Tests\TestCase;

class RecommerceTradeInFormContractTest extends TestCase
{
    public function test_operator_workspace_does_not_expose_policy_configuration(): void
    {
        $shell = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/index.blade.php'));
        $create = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/create.blade.php'));

        $this->assertIsString($shell);
        $this->assertIsString($create);
        $this->assertStringNotContainsString('rule_code', $shell.$create);
        $this->assertStringNotContainsString('target_margin_ratio', $shell.$create);
        $this->assertStringContainsString('The server calculates the recommendation and all ceilings from the active pricing policy.', $create);
    }

    public function test_seller_capture_uses_progressive_disclosure_without_payee_internals(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/create.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('Existing seller', $view);
        $this->assertStringContainsString('+ Create New Seller', $view);
        $this->assertStringContainsString('Seller verification', $view);
        $this->assertStringContainsString('Stored encrypted and never shown in acquisition tables.', $view);
        $this->assertStringNotContainsString('native purchase payee', $view);
        $this->assertStringNotContainsString('data-has-native-payee', $view);
    }

    public function test_released_trade_ins_replace_redundant_actions_with_sale_ready_state(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/partials/show.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("\$isAvailable=\$valuation->status==='ACCEPTED'", $view);
        $this->assertStringContainsString("\$isPendingQc=\$valuation->status==='ACCEPTED'", $view);
        $this->assertStringContainsString('No stale QC action is available', $view);
        $this->assertStringContainsString('No QC action available', $view);
        $this->assertStringContainsString("\$job->state==='READY'", $view);
    }
}
