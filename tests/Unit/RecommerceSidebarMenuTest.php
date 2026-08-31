<?php

namespace Tests\Unit;

use Tests\TestCase;

class RecommerceSidebarMenuTest extends TestCase
{
    public function test_trade_in_has_a_dedicated_permission_gated_sidebar_entry(): void
    {
        $source = file_get_contents(base_path('app/Http/Middleware/AdminSidebarMenu.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("route('recommerce.tradeins.index')", $source);
        $this->assertStringContainsString("auth()->user()->can('recommerce.tradein.view')", $source);
        $this->assertStringContainsString(
            "'active' => request()->segment(1) == 'recommerce' && request()->segment(2) == 'trade-ins'",
            $source
        );
    }

    public function test_staff_navigation_prioritizes_existing_devices_and_inspection_without_a_generic_receiving_dead_end(): void
    {
        $source = file_get_contents(base_path('app/Http/Middleware/AdminSidebarMenu.php'));

        $this->assertStringContainsString("route('recommerce.devices.index')", $source);
        $this->assertStringContainsString("'Find Device'", $source);
        $this->assertStringContainsString("route('recommerce.inspection.index')", $source);
        $this->assertStringContainsString("'Inspection Queue'", $source);
        $this->assertStringContainsString("auth()->user()->can('recommerce.inspection.view')", $source);
        $this->assertStringNotContainsString("route('recommerce.receiving.index'),\n                                'Purchase receiving'", $source);
    }
}
