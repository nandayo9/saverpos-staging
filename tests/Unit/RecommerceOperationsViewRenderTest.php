<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Modules\Recommerce\Entities\Device;
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
                    'id' => 1, 'device_code' => 'SB-DV-00000001-9', 'lifecycle_state' => 'AVAILABLE',
                    'custody_kind' => 'LOCATION', 'current_location_id' => 1, 'currentLocation' => (object) ['name' => 'Branch A'], 'stock_participation' => 'ON_HAND',
                    'manufacturer_serial_display' => null,
                    'acquired_at' => Carbon::now()->subDays(2),
                    'product' => (object) ['name' => 'SaverBro Demo Device'],
                    'variation' => (object) ['name' => 'Default'],
                ],
                (object) [
                    'device_code' => 'SB-DV-00000002-1', 'lifecycle_state' => 'RESERVED',
                    'custody_kind' => 'LOCATION', 'current_location_id' => 2, 'currentLocation' => (object) ['name' => 'Branch B'], 'stock_participation' => 'ON_HAND',
                    'manufacturer_serial_display' => null,
                    'acquired_at' => null,
                    'product' => null, 'variation' => null,
                ],
            ]),
            'locationId' => 1,
            'locationName' => 'Branch A',
            'query' => '',
            'labelStatus' => 'NOT_PRINTED',
            'canReceive' => true,
            'canPrintLabel' => true,
        ]);

        $this->assertStringContainsString('SB-DV-00000001-9', $html);
        $this->assertStringContainsString('SaverBro Demo Device', $html);
        $this->assertStringContainsString('Product unavailable', $html);
        $this->assertStringContainsString('Device Registry', $html);
        $this->assertStringContainsString('Find and investigate an existing physical device', $html);
        $this->assertStringContainsString('Ready for sale', $html);
        $this->assertStringContainsString('Branch A', $html);
        $this->assertStringContainsString('Branch B', $html);
        $this->assertStringNotContainsString('SaverBro location', $html);
        $this->assertStringContainsString('Quick view', $html);
        $this->assertStringContainsString('/label/print', $html);
        $this->assertStringContainsString('recommerce-bulk-label-print', $html);
        $this->assertStringContainsString('data-registry-select-visible', $html);
        $this->assertStringContainsString('data-registry-selection', $html);
        $this->assertStringContainsString('Print labels', $html);
        $this->assertStringContainsString('Any label state', $html);
        $this->assertMatchesRegularExpression('/value="NOT_PRINTED"\\s+selected/', $html);
        $this->assertStringContainsString('Print view opened', $html);
        $this->assertStringNotContainsString('Add Device', $html);
    }

    public function test_the_device_registry_hides_receiving_and_shows_its_empty_state(): void
    {
        $html = $this->renderRecommerceView('recommerce::device.index', [
            'devices' => collect(), 'locationId' => 1, 'query' => 'nothing', 'canReceive' => false,
        ]);

        $this->assertStringContainsString('No device matches', $html);
        $this->assertStringNotContainsString('Receive stock from Purchases', $html);
        $this->assertStringContainsString('Clear', $html);
    }

    public function test_device_quick_view_masks_identity_and_gates_acquisition_cost(): void
    {
        $device = Device::unguarded(fn () => new Device([
            'id' => 9, 'device_code' => 'SB-DV-00000009-4', 'lifecycle_state' => 'AVAILABLE',
            'stock_participation' => 'ON_HAND', 'acquired_at' => Carbon::parse('2026-09-01'),
        ]));
        $device->setRelation('product', (object) ['name' => 'Demo Laptop']);
        $device->setRelation('variation', (object) ['name' => 'Default']);
        $device->setRelation('purchaseAssignment', (object) ['unit_acquisition_cost' => 1875.50]);
        $device->setRelation('certification', null);
        $device->setRelation('inspection', null);

        $data = [
            'device' => $device, 'stateLabel' => 'Ready for sale', 'holder' => 'Branch A',
            'inventoryLabel' => 'In stock', 'labelStatus' => 'Printed',
            'serialHint' => 'Serial ending A123', 'auditVisible' => false, 'events' => collect(),
            'inspectionUrl' => null, 'repairUrl' => null,
        ];
        $restricted = $this->renderRecommerceView('recommerce::device.quick-view', $data + ['economicsVisible' => false]);
        $economic = $this->renderRecommerceView('recommerce::device.quick-view', $data + ['economicsVisible' => true]);

        $this->assertStringContainsString('Serial ending A123', $restricted);
        $this->assertStringNotContainsString('1875.50', $restricted);
        $this->assertStringNotContainsString('Acquisition cost', $restricted);
        $this->assertStringContainsString('RM 1,875.50', $economic);
    }

    public function test_device_detail_prioritises_safe_profile_and_technical_information(): void
    {
        $device = Device::unguarded(fn () => new Device([
            'id' => 1, 'device_code' => 'SB-DV-00000001-9', 'category_code' => 'LAPTOP',
            'lifecycle_state' => 'AVAILABLE', 'custody_kind' => 'LOCATION',
            'current_location_id' => 1, 'stock_participation' => 'ON_HAND',
            'manufacturer_serial_display' => 'PF82AXXX',
        ]));
        $device->setRelation('product', (object) ['name' => 'ThinkPad T14']);
        $device->setRelation('variation', (object) ['name' => '16 GB / 512 GB']);
        $device->setRelation('currentLocation', (object) ['name' => 'Branch A']);
        $device->setRelation('purchaseAssignment', null);
        $device->setRelation('inspection', null);
        $device->setRelation('certification', null);
        $device->setRelation('labelJobItems', collect());
        $device->setRelation('ownershipPeriods', collect());
        $device->setRelation('custodyPeriods', collect());

        $html = $this->renderRecommerceView('recommerce::device.show', [
            'device' => $device,
            'deviceProfile' => [
                'brand' => 'Lenovo', 'model' => 'ThinkPad T14 Gen 4', 'category' => 'Laptop',
                'product' => 'ThinkPad T14', 'variation' => '16 GB / 512 GB', 'serial_hint' => 'Ending AXXX',
            ],
            'technicalSpecifications' => [
                'Processor' => 'Core i7', 'Memory' => '16 GB', 'Storage' => '512 GB SSD',
                'Operating system' => 'Windows 11 Pro',
            ],
            'auditVisible' => true, 'events' => collect(), 'labelPrintEnabled' => false,
            'labelStatus' => 'Not printed', 'hasLabelPrintView' => false,
            'certificationPublishEnabled' => false, 'acquisition' => null, 'economicsVisible' => false,
        ]);

        $this->assertStringContainsString('Device profile', $html);
        $this->assertStringContainsString('Lenovo', $html);
        $this->assertStringContainsString('ThinkPad T14 Gen 4', $html);
        $this->assertStringContainsString('Technical specifications', $html);
        $this->assertStringContainsString('Core i7', $html);
        $this->assertStringContainsString('Windows 11 Pro', $html);
        $this->assertStringContainsString('Ending AXXX', $html);
        $this->assertStringNotContainsString('do-not-display', $html);
    }

    public function test_find_device_exposes_the_safe_phone_camera_qr_path(): void
    {
        $html = $this->renderRecommerceView('recommerce::scans.index', [
            'canReceive' => true,
            'canRepair' => false,
        ]);

        $this->assertStringContainsString('Find Device', $html);
        $this->assertStringContainsString('id="recommerce-open-camera"', $html);
        $this->assertStringContainsString('id="recommerce-scan-camera"', $html);
        $this->assertStringContainsString('navigator.mediaDevices.getUserMedia', $html);
        $this->assertStringContainsString("facingMode: { ideal: 'environment' }", $html);
        $this->assertStringContainsString("new BarcodeDetector({ formats: ['qr_code'] })", $html);
        $this->assertStringContainsString('stream.getTracks().forEach(track => track.stop())', $html);
        $this->assertStringContainsString("stopCamera('Device scan captured. Resolving…')", $html);
        $this->assertStringContainsString('resolveButton.click()', $html);
        $this->assertStringContainsString('Camera scanning requires HTTPS (or localhost) and browser camera permission.', $html);
        $this->assertStringContainsString('jsqr@1.4.0/dist/jsQR.min.js', $html);
        $this->assertStringContainsString('detectFrameWithFallback', $html);
        $this->assertStringContainsString('QR camera decoding is unavailable in this browser.', $html);
    }

    public function test_the_operations_dashboard_shows_only_the_cards_a_role_may_see(): void
    {
        $data = [
            'locationId' => 1,
            'canViewDevices' => true, 'canViewRepairs' => true, 'canReconcile' => true,
            'canReceive' => true, 'canRepairIntake' => true,
            'deviceCounts' => (object) [
                'total' => 17, 'on_hand' => 15, 'reserved' => 2,
                'received_today' => 4, 'awaiting_inspection' => 3,
                'repair_required' => 1, 'ready_for_sale' => 10,
            ],
            'repairJobs' => collect([(object) [
                'job_type' => 'INTERNAL_REFURBISHMENT', 'job_code' => 'SB-RP-00000009',
                'state' => 'IN_REPAIR', 'priority' => 'NORMAL', 'device' => null,
            ]]),
            'profiles' => collect([(object) ['variation_id' => 303]]),
        ];

        $full = $this->renderRecommerceView('recommerce::dashboard.index', $data);
        $this->assertStringContainsString('Received today', $full);
        $this->assertStringContainsString('4', $full);
        $this->assertStringContainsString('3 awaiting inspection', $full);
        $this->assertStringContainsString('Open Device Registry', $full);
        $this->assertStringContainsString('Stock Check', $full);
        $this->assertStringContainsString('SB-RP-00000009', $full);
        // A refurbishment row whose device row is missing must still render.
        $this->assertStringContainsString('Unavailable', $full);

        $reader = $this->renderRecommerceView('recommerce::dashboard.index', array_merge($data, [
            'canViewDevices' => false, 'canReconcile' => false, 'canReceive' => false,
        ]));
        $this->assertStringNotContainsString('Open Device Registry', $reader);
        $this->assertStringNotContainsString('Stock Check', $reader);
        $this->assertStringContainsString('Open refurbishment', $reader);
    }

    public function test_purchase_receiving_renders_partial_progress_without_operator_serialization_language(): void
    {
        $line = (object) [
            'id' => 707, 'product_id' => 202, 'variation_id' => 303,
            'product_name' => 'ThinkPad T14', 'variation_name' => 'Default',
            'tracking_mode' => 'SERIALIZED_DEVICE', 'inspection_required' => true,
            'expected_count' => 10, 'registered_count' => 7, 'remaining_count' => 3,
            'inspection_cleared_count' => 2, 'inspection_open_count' => 5,
            'inspection_failed_count' => 0, 'is_whole_unit' => true,
            'default_unit_acquisition_cost' => 1850,
        ];
        $html = $this->renderRecommerceView('recommerce::receiving.index', [
            'purchaseContext' => [
                'purchase' => (object) [
                    'id' => 606, 'ref_no' => 'PO-1048', 'invoice_no' => null,
                    'supplier_business_name' => 'ABC Computers', 'supplier_name' => null,
                    'location_name' => 'Karamunsing Branch', 'transaction_date' => '2026-08-31',
                ],
                'lines' => collect([$line]), 'selected_line' => $line,
                'expected_count' => 10, 'registered_count' => 7, 'remaining_count' => 3,
                'inspection_cleared_count' => 2,
            ],
            'locationId' => 101, 'postEnabled' => true, 'canOverrideCost' => false,
            'canViewInspection' => true, 'registeredDevices' => collect(), 'reconciliationRecordEnabled' => false,
        ]);

        $this->assertStringContainsString('Receive Stock', $html);
        $this->assertStringContainsString('7 / 10 registered · 3 remaining', $html);
        $this->assertStringContainsString('id="ready-count">2', $html);
        $this->assertStringContainsString('id="line-inspection-707"', $html);
        $this->assertStringContainsString('2 ready · 5 awaiting inspection', $html);
        $this->assertStringContainsString("lineAwaitingInspection += awaitingAdded", $html);
        $this->assertStringContainsString("lineProgressBar.style.width", $html);
        $this->assertStringContainsString("lineAction.textContent = 'View devices'", $html);
        $this->assertStringContainsString('Manufacturer identifier', $html);
        $this->assertStringContainsString('Register staged devices', $html);
        $this->assertStringNotContainsString('Serialization', $html);
        $this->assertStringNotContainsString('serialized product line', $html);
    }

    public function test_purchase_receiving_renders_a_clear_completion_state_and_next_actions(): void
    {
        $line = (object) [
            'id' => 707, 'product_id' => 202, 'variation_id' => 303,
            'product_name' => 'ThinkPad T14', 'variation_name' => 'Default',
            'tracking_mode' => 'SERIALIZED_DEVICE', 'inspection_required' => true,
            'expected_count' => 10, 'registered_count' => 10, 'remaining_count' => 0,
            'inspection_cleared_count' => 0, 'inspection_open_count' => 10,
            'inspection_failed_count' => 0, 'is_whole_unit' => true,
            'default_unit_acquisition_cost' => 1850,
        ];
        $html = $this->renderRecommerceView('recommerce::receiving.index', [
            'purchaseContext' => [
                'purchase' => (object) [
                    'id' => 606, 'ref_no' => 'PO-1048', 'invoice_no' => null,
                    'supplier_business_name' => 'ABC Computers', 'supplier_name' => null,
                    'location_name' => 'Karamunsing Branch', 'transaction_date' => '2026-08-31',
                ],
                'lines' => collect([$line]), 'selected_line' => $line,
                'expected_count' => 10, 'registered_count' => 10, 'remaining_count' => 0,
                'inspection_cleared_count' => 0, 'inspection_open_count' => 10,
                'label_confirmed_count' => 8, 'label_remaining_count' => 2,
            ],
            'locationId' => 101, 'postEnabled' => true, 'canOverrideCost' => false,
            'canViewInspection' => true, 'registeredDevices' => collect(), 'reconciliationRecordEnabled' => false,
        ]);

        $this->assertStringContainsString('Receiving Complete', $html);
        $this->assertStringContainsString('10 / 10', $html);
        $this->assertStringContainsString('Open Inspection Queue', $html);
        $this->assertStringContainsString('id="inspection-waiting-count">10', $html);
        $this->assertStringContainsString('id="inspection-waiting-grammar">devices are', $html);
        $this->assertStringContainsString('2 registered Devices still need a label', $html);
        $this->assertStringContainsString('Open Label Recovery Queue', $html);
        $this->assertStringContainsString('label_status=NEEDS_LABEL', $html);
        $this->assertStringContainsString('Return to purchases', $html);
        $this->assertStringContainsString('View devices', $html);
    }

    public function test_purchase_receiving_does_not_offer_the_inspection_queue_without_permission(): void
    {
        $line = (object) [
            'id' => 707, 'product_id' => 202, 'variation_id' => 303,
            'product_name' => 'ThinkPad T14', 'variation_name' => 'Default',
            'tracking_mode' => 'SERIALIZED_DEVICE', 'inspection_required' => true,
            'expected_count' => 1, 'registered_count' => 1, 'remaining_count' => 0,
            'inspection_cleared_count' => 0, 'inspection_open_count' => 1,
            'inspection_failed_count' => 0, 'is_whole_unit' => true,
            'default_unit_acquisition_cost' => 1850,
        ];
        $html = $this->renderRecommerceView('recommerce::receiving.index', [
            'purchaseContext' => [
                'purchase' => (object) [
                    'id' => 606, 'ref_no' => 'PO-1048', 'invoice_no' => null,
                    'supplier_business_name' => 'ABC Computers', 'supplier_name' => null,
                    'location_name' => 'Karamunsing Branch', 'transaction_date' => '2026-08-31',
                ],
                'lines' => collect([$line]), 'selected_line' => $line,
                'expected_count' => 1, 'registered_count' => 1, 'remaining_count' => 0,
                'inspection_cleared_count' => 0, 'inspection_open_count' => 1,
            ],
            'locationId' => 101, 'postEnabled' => true, 'canOverrideCost' => false,
            'canViewInspection' => false, 'registeredDevices' => collect(), 'reconciliationRecordEnabled' => false,
        ]);

        $this->assertStringNotContainsString('Open Inspection Queue', $html);
        $this->assertStringContainsString('Ask a supervisor with inspection access to continue', $html);
    }

    public function test_purchase_receiving_explains_mixed_tracked_and_ordinary_lines(): void
    {
        $thinkpad = (object) [
            'id' => 701, 'product_id' => 201, 'variation_id' => 301,
            'product_name' => 'ThinkPad T14', 'variation_name' => 'Default',
            'tracking_mode' => 'SERIALIZED_DEVICE', 'inspection_required' => true,
            'expected_count' => 10, 'registered_count' => 10, 'remaining_count' => 0,
            'inspection_cleared_count' => 0, 'inspection_open_count' => 10,
            'inspection_failed_count' => 0, 'is_whole_unit' => true, 'default_unit_acquisition_cost' => 1800,
        ];
        $dell = (object) [
            'id' => 702, 'product_id' => 202, 'variation_id' => 302,
            'product_name' => 'Dell Latitude', 'variation_name' => 'Default',
            'tracking_mode' => 'SERIALIZED_DEVICE', 'inspection_required' => true,
            'expected_count' => 5, 'registered_count' => 3, 'remaining_count' => 2,
            'inspection_cleared_count' => 0, 'inspection_open_count' => 3,
            'inspection_failed_count' => 0, 'is_whole_unit' => true, 'default_unit_acquisition_cost' => 1600,
        ];
        $mouse = (object) [
            'id' => 703, 'product_id' => 203, 'variation_id' => 303,
            'product_name' => 'Wireless Mouse', 'variation_name' => 'Default',
            'tracking_mode' => 'BULK', 'inspection_required' => false,
            'expected_count' => 0, 'registered_count' => 0, 'remaining_count' => 0,
            'inspection_cleared_count' => 0, 'inspection_open_count' => 0,
            'inspection_failed_count' => 0, 'is_whole_unit' => true, 'default_unit_acquisition_cost' => 25,
        ];

        $html = $this->renderRecommerceView('recommerce::receiving.index', [
            'purchaseContext' => [
                'purchase' => (object) [
                    'id' => 600, 'ref_no' => 'PO-MIXED', 'invoice_no' => null,
                    'supplier_business_name' => 'Mixed Supplier', 'supplier_name' => null,
                    'location_name' => 'Branch A', 'transaction_date' => '2026-08-31',
                ],
                'lines' => collect([$thinkpad, $dell, $mouse]), 'selected_line' => $dell,
                'expected_count' => 15, 'registered_count' => 13, 'remaining_count' => 2,
                'inspection_cleared_count' => 0, 'inspection_open_count' => 13,
            ],
            'locationId' => 101, 'postEnabled' => true, 'canOverrideCost' => false,
            'canViewInspection' => true, 'registeredDevices' => collect(), 'reconciliationRecordEnabled' => false,
        ]);

        $this->assertStringContainsString('ThinkPad T14', $html);
        $this->assertStringContainsString('10 / 10', $html);
        $this->assertStringContainsString('Dell Latitude', $html);
        $this->assertStringContainsString('3 / 5', $html);
        $this->assertStringContainsString('2 remaining', $html);
        $this->assertStringContainsString('Wireless Mouse', $html);
        $this->assertStringContainsString('Received · ordinary stock · no device identification required', $html);
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
