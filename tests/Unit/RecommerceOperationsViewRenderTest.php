<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\Fixtures\RendersRecommerceViews;
use Tests\TestCase;

/**
 * Renders the operations screens rather than asserting their Blade source, and
 * checks the permission gating and empty states they are supposed to show. Each
 * screen is fed plain objects in the shape its controller passes, so these stay
 * independent of the module's tables.
 */
class RecommerceOperationsViewRenderTest extends TestCase
{
    use RendersRecommerceViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRecommerceViewRendering();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_the_device_registry_lists_devices_and_survives_a_missing_product(): void
    {
        $html = $this->renderRecommerceView('recommerce::device.index', [
            'devices' => collect([
                (object) [
                    'device_code' => 'SB-DV-00000001-9', 'lifecycle_state' => 'AVAILABLE',
                    'custody_kind' => 'LOCATION', 'stock_participation' => 'ON_HAND',
                    'product' => (object) ['name' => 'SaverBro Demo Device'],
                    'variation' => (object) ['name' => 'Default'],
                ],
                (object) [
                    'device_code' => 'SB-DV-00000002-1', 'lifecycle_state' => 'RESERVED',
                    'custody_kind' => 'LOCATION', 'stock_participation' => 'ON_HAND',
                    'product' => null, 'variation' => null,
                ],
            ]),
            'locationId' => 1,
            'query' => '',
            'canReceive' => true,
        ]);

        $this->assertStringContainsString('SB-DV-00000001-9', $html);
        $this->assertStringContainsString('SaverBro Demo Device', $html);
        $this->assertStringContainsString('Product unavailable', $html);
        $this->assertStringContainsString('Tracked receiving', $html);
    }

    public function test_the_device_registry_hides_receiving_and_shows_its_empty_state(): void
    {
        $html = $this->renderRecommerceView('recommerce::device.index', [
            'devices' => collect(), 'locationId' => 1, 'query' => 'nothing', 'canReceive' => false,
        ]);

        $this->assertStringContainsString('No authorized devices matched this search.', $html);
        $this->assertStringNotContainsString('Tracked receiving', $html);
        $this->assertStringContainsString('Clear', $html);
    }

    public function test_the_operations_dashboard_shows_only_the_cards_a_role_may_see(): void
    {
        $data = [
            'locationId' => 1,
            'canViewDevices' => true, 'canViewRepairs' => true, 'canReconcile' => true,
            'canReceive' => true, 'canRepairIntake' => true,
            'deviceCounts' => (object) ['total' => 17, 'on_hand' => 15, 'reserved' => 2],
            'repairJobs' => collect([(object) [
                'job_type' => 'INTERNAL_REFURBISHMENT', 'job_code' => 'SB-RP-00000009',
                'state' => 'IN_REPAIR', 'priority' => 'NORMAL', 'device' => null,
            ]]),
            'profiles' => collect([(object) ['variation_id' => 303]]),
        ];

        $full = $this->renderRecommerceView('recommerce::dashboard.index', $data);
        $this->assertStringContainsString('17', $full);
        $this->assertStringContainsString('15 on hand · 2 reserved', $full);
        $this->assertStringContainsString('Open device registry', $full);
        $this->assertStringContainsString('Open reconciliation', $full);
        $this->assertStringContainsString('SB-RP-00000009', $full);
        // A refurbishment row whose device row is missing must still render.
        $this->assertStringContainsString('Unavailable', $full);

        $reader = $this->renderRecommerceView('recommerce::dashboard.index', array_merge($data, [
            'canViewDevices' => false, 'canReconcile' => false, 'canReceive' => false,
        ]));
        $this->assertStringNotContainsString('Open device registry', $reader);
        $this->assertStringNotContainsString('Open reconciliation', $reader);
        $this->assertStringContainsString('Open internal workbench', $reader);
    }

    public function test_reconciliation_offers_a_location_switch_only_when_there_is_more_than_one(): void
    {
        $profiles = collect([(object) [
            'variation_id' => 303, 'mode' => 'TRACKED_REQUIRED', 'version' => 1,
            'effective_at' => Carbon::parse('2026-08-01'),
            'product' => (object) ['name' => 'SaverBro Demo Device'],
            'variation' => (object) ['name' => 'Default'],
        ]]);

        $two = $this->renderRecommerceView('recommerce::reconciliation.index', [
            'profiles' => $profiles, 'locationId' => 2,
            'locations' => new Collection([1 => 'Branch A', 2 => 'Branch B']),
        ]);
        $this->assertStringContainsString('reconciliation-location', $two);
        $this->assertStringContainsString('TRACKED_REQUIRED · v1', $two);
        $this->assertStringContainsString('01 Aug 2026', $two);

        $one = $this->renderRecommerceView('recommerce::reconciliation.index', [
            'profiles' => collect(), 'locationId' => 1,
            'locations' => new Collection([1 => 'Branch A']),
        ]);
        $this->assertStringNotContainsString('reconciliation-location', $one);
        $this->assertStringContainsString('No approved Recommerce variation is configured for this location.', $one);
    }

    public function test_transfer_exceptions_render_the_manifest_and_a_validation_error(): void
    {
        $errors = new ViewErrorBag();
        $errors->put('default', new \Illuminate\Support\MessageBag(['scanned_codes' => ['Scan at least one device code.']]));

        $html = $this->renderRecommerceView('recommerce::transfers.exceptions', [
            'sellTransfer' => (object) ['id' => 55, 'ref_no' => 'TR-0055', 'location_id' => 1, 'status' => 'in_transit'],
            'purchaseTransfer' => (object) ['location_id' => 2],
            'assignments' => collect([
                (object) ['device_id' => 11, 'status' => 'DISPATCHED'],
                (object) ['device_id' => 99, 'status' => 'DISPATCHED'],
            ]),
            'devices' => collect([11 => (object) ['device_code' => 'SB-DV-00000001-9', 'variation_id' => 303]]),
            'exceptions' => collect(),
            'locations' => [1 => 'Branch A', 2 => 'Branch B'],
            'errors' => $errors,
        ]);

        $this->assertStringContainsString('TR-0055 · Branch A → Branch B · in_transit', $html);
        $this->assertStringContainsString('SB-DV-00000001-9', $html);
        // A manifest row whose device row is missing must still render.
        $this->assertStringContainsString('Unavailable', $html);
        $this->assertStringContainsString('Scan at least one device code.', $html);
    }
}
