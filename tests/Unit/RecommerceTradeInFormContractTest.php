<?php

namespace Tests\Unit;

use Tests\TestCase;

class RecommerceTradeInFormContractTest extends TestCase
{
    public function test_pricing_rule_ratios_accept_thousandth_precision(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('name="{{ $field }}" type="number" min="0" max="1" step="0.001"', $view);
        $this->assertStringNotContainsString('name="{{ $field }}" type="number" min="0" max="1" step="0.01"', $view);
    }

    public function test_seller_payee_requirements_are_explained_before_submission(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-has-phone="{{ $customer->mobile ? \'1\' : \'0\' }}"', $view);
        $this->assertStringContainsString('data-has-native-payee="{{ in_array((int) $customer->id, $customerNativePayeeIds, true) ? \'1\' : \'0\' }}"', $view);
        $this->assertStringContainsString('This seller has no saved phone. Enter one before continuing so a native purchase payee can be created.', $view);
        $this->assertStringContainsString('An active native purchase payee already exists for this seller.', $view);
        $this->assertStringContainsString("sellerPhoneHelp.classList.add('text-danger');", $view);
        $this->assertStringContainsString('Enter a seller name when creating a new seller.', $view);
        $this->assertStringContainsString('Enter a seller phone number when creating a new seller.', $view);
    }

    public function test_released_trade_ins_replace_redundant_actions_with_sale_ready_state(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/tradein/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("optional(\$valuation->device)->lifecycle_state === 'AVAILABLE'", $view);
        $this->assertStringContainsString('QC release recorded.', $view);
    }
}
