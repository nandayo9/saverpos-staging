<?php

namespace Tests\Unit;

use Tests\TestCase;

class PurchaseQuantityCalculationUiContractTest extends TestCase
{
    public function test_keyboard_quantity_entry_recalculates_hidden_purchase_totals(): void
    {
        $script = file_get_contents(public_path('js/purchase.js'));

        $this->assertStringContainsString("on('input change', '.purchase_quantity'", $script);
        $this->assertStringContainsString('var sub_total_before_tax = quantity * purchase_before_tax;', $script);
        $this->assertStringContainsString('var sub_total_after_tax = quantity * purchase_after_tax;', $script);
        $this->assertStringContainsString('update_grand_total();', $script);
    }
}
