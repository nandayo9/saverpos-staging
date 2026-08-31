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
}
