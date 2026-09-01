<?php

namespace Tests\Feature;

use App\User;
use App\Product;
use App\Events\PurchaseCreatedOrModified;
use App\Transaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use InvalidArgumentException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceIdentifier;
use Modules\Recommerce\Entities\DeviceEvent;
use Modules\Recommerce\Entities\DeviceReturnDisposition;
use Modules\Recommerce\Entities\DeviceSaleDisposition;
use Modules\Recommerce\Entities\DeviceTransferAssignment;
use Modules\Recommerce\Entities\DeviceTransferException;
use Modules\Recommerce\Entities\ScanToken;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Exceptions\ReceivingInProgressException;
use Modules\Recommerce\Exceptions\ReceivingReconciliationBlockedException;
use Modules\Recommerce\Http\Controllers\LabelController;
use Modules\Recommerce\Http\Controllers\PosDeviceLookupController;
use Modules\Recommerce\Http\Controllers\ReconciliationController;
use Modules\Recommerce\Http\Controllers\ReceivingController;
use Modules\Recommerce\Http\Controllers\ScanController;
use Modules\Recommerce\Http\Controllers\DeviceController;
use Modules\Recommerce\Providers\RouteServiceProvider;
use Modules\Recommerce\Services\ScanTokenIssuanceService;
use Modules\Recommerce\Services\BulkDeviceLabelPrintService;
use Modules\Recommerce\Services\LabelRenderer;
use Modules\Recommerce\Services\ReconciliationRunService;
use Modules\Recommerce\Services\StockReconciliationService;
use Modules\Recommerce\Services\TrackedReceivingService;
use Modules\Recommerce\Services\UltimatePosPurchaseWriter;
use Modules\Recommerce\Services\CustomerRepairDeviceService;
use Modules\Recommerce\Services\RepairJobIntakeService;
use Modules\Recommerce\Services\RepairPublicLookupService;
use Modules\Recommerce\Services\RepairPartService;
use Modules\Recommerce\Services\UltimatePosStockAdjustmentWriter;
use Modules\Recommerce\Services\DiagnosticTemplateService;
use Modules\Recommerce\Services\DeviceCertificationService;
use Modules\Recommerce\Services\DeviceLifecycleService;
use Modules\Recommerce\Services\DeviceIdentityResolver;
use Modules\Recommerce\Services\DeviceReceivingProgressService;
use Modules\Recommerce\Services\DeviceRegistryQuery;
use Modules\Recommerce\Services\DeviceEventTimelineService;
use Modules\Recommerce\Services\ProductTrackingPolicyService;
use Modules\Recommerce\Services\DeviceTransferExceptionService;
use Modules\Recommerce\Entities\DiagnosticCheck;
use Modules\Recommerce\Entities\DiagnosticTemplate;
use Modules\Recommerce\Entities\DiagnosticTemplateVersion;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;
use Modules\Recommerce\Support\LabelPayloadBuilder;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecommerceReceivingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.permissions' => [
                'recommerce.receiving.post',
                'recommerce.receiving.prepare',
                'recommerce.inspection.view',
                'recommerce.inspection.assign',
                'recommerce.inspection.complete',
                'recommerce.device.override_acquisition_cost',
                'recommerce.device.view',
                'recommerce.device.print_label',
                'recommerce.device.rotate_token',
                'recommerce.device.certify',
                'recommerce.device.sell',
                'recommerce.device.transfer',
                'recommerce.device.return',
                'recommerce.device.reverse_disposition',
                'recommerce.device.view_economics',
                'recommerce.stock.reconcile',
                'recommerce.stock.reconcile.record',
                'recommerce.audit.view',
                'recommerce.repair.view',
                'recommerce.repair.intake',
                'recommerce.repair.parts.reserve',
                'recommerce.repair.parts.use',
                'recommerce.repair.parts.resolve',
            ],
            'recommerce.resolver_host' => 'scan.saverbro.example',
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 101,
            'recommerce.cohort.location_ids' => [101, 102],
            'recommerce.cohort.variation_ids' => [303],
            'recommerce.repair_intake_checklist' => [
                ['key' => 'powers_on', 'label' => 'Powers on'],
                ['key' => 'display', 'label' => 'Display / screen'],
                ['key' => 'buttons_ports', 'label' => 'Buttons and ports'],
                ['key' => 'camera_audio', 'label' => 'Camera and audio'],
                ['key' => 'physical_condition', 'label' => 'Physical condition recorded'],
                ['key' => 'accessories', 'label' => 'Accessories recorded'],
            ],
        ]);

        DB::purge('sqlite');
        $schema = Schema::connection('sqlite');

        $schema->create('system', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key');
            $table->text('value')->nullable();
        });
        $schema->create('business', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        $schema->create('users', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
        });
        $schema->create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        $schema->create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        $schema->create('model_has_roles', function (Blueprint $table) {
            $table->unsignedInteger('role_id');
            $table->unsignedInteger('model_id');
            $table->string('model_type');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        $schema->create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        $schema->create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('model_id');
            $table->string('model_type');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        $schema->create('contacts', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('supplier_business_name')->nullable();
            $table->string('type', 20)->nullable();
            $table->string('contact_id', 64)->nullable();
            $table->string('mobile', 32)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('business_locations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
        });
        $schema->create('brands', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('products', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->unsignedInteger('brand_id')->nullable();
        });
        $schema->create('variations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('product_id');
            $table->string('name')->nullable();
            $table->string('sub_sku')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('tax_rates', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('return_parent_id')->nullable();
            $table->unsignedInteger('transfer_parent_id')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->unsignedInteger('tax_id')->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 22, 4)->nullable();
            $table->text('shipping_details')->nullable();
            $table->decimal('shipping_charges', 22, 4)->nullable();
            $table->text('additional_notes')->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->decimal('exchange_rate', 22, 4)->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });
        $schema->create('purchase_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('quantity', 22, 4);
            $table->decimal('purchase_price_inc_tax', 22, 4)->nullable();
        });
        $schema->create('transaction_sell_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_price', 22, 4)->nullable();
            $table->decimal('unit_price_inc_tax', 22, 4)->nullable();
            $table->timestamps();
        });
        $schema->create('variation_location_details', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('qty_available', 22, 4);
        });

        DB::table('business')->insert(['id' => 7]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        DB::table('contacts')->insert(['id' => 404, 'business_id' => 7]);
        DB::table('contacts')->insert([
            'id' => 405,
            'business_id' => 7,
            'name' => 'Fixture customer',
            'type' => 'customer',
            'contact_id' => 'CUS-FIXTURE-405',
            'mobile' => '0123456789',
        ]);
        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7, 'name' => 'Kota Kinabalu']);
        DB::table('business_locations')->updateOrInsert(['id' => 102], ['business_id' => 7, 'name' => 'Sandakan']);
        DB::table('brands')->insert(['id' => 1, 'business_id' => 7, 'name' => 'SaverBro']);
        DB::table('products')->insert(['id' => 202, 'business_id' => 7, 'brand_id' => 1, 'name' => 'Refurbished laptop']);
        DB::table('variations')->insert(['id' => 303, 'product_id' => 202]);
        DB::table('variations')->insert(['id' => 304, 'product_id' => 202]);
        DB::table('tax_rates')->insert(['id' => 505, 'business_id' => 7]);
        DB::table('transactions')->insert([
            'id' => 606,
            'business_id' => 7,
            'location_id' => 101,
            'type' => 'purchase',
            'status' => 'received',
            'payment_status' => 'due',
            'contact_id' => 404,
            'ref_no' => 'PUR-SEED-0606',
            'transaction_date' => '2026-08-27 00:00:00',
            'total_before_tax' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'final_total' => 0,
            'created_by' => 900,
            'exchange_rate' => 1,
            'source' => 'test',
        ]);
        DB::table('purchase_lines')->insert([
            'id' => 707,
            'transaction_id' => 606,
            'product_id' => 202,
            'variation_id' => 303,
            'quantity' => 1,
            'purchase_price_inc_tax' => 1850,
        ]);

        $migration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php');
        $migration->up();
        $stableLabelMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_31_000001_add_encrypted_material_to_recommerce_scan_tokens.php');
        $stableLabelMigration->up();
        $reconciliationMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000003_create_recommerce_reconciliation_tables.php');
        $reconciliationMigration->up();
        $eventIdentityMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000004_harden_recommerce_event_identity.php');
        $eventIdentityMigration->up();
        $labelJobMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000005_create_recommerce_label_job_tables.php');
        $labelJobMigration->up();
        $ownershipMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000006_create_recommerce_ownership_periods.php');
        $ownershipMigration->up();
        $custodyMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000007_create_recommerce_custody_periods.php');
        $custodyMigration->up();
        $repairMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000008_create_recommerce_repair_jobs.php');
        $repairMigration->up();
        $diagnosticsMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000009_create_recommerce_diagnostics.php');
        $diagnosticsMigration->up();
        $repairPartsMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000010_create_recommerce_repair_parts.php');
        $repairPartsMigration->up();
        $repairCostMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000011_create_recommerce_repair_cost_entries.php');
        $repairCostMigration->up();
        $permissionMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000013_register_recommerce_permissions.php');
        $permissionMigration->up();
        $repairIntakeMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000012_enhance_customer_repair_intake.php');
        $repairIntakeMigration->up();
        $certificationMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000014_create_recommerce_device_certifications.php');
        $certificationMigration->up();
        $lifecycleMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000015_create_recommerce_device_lifecycle_tables.php');
        $lifecycleMigration->up();
        $transferStatusMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000016_add_transfer_assignment_status.php');
        $transferStatusMigration->up();
        $transferExceptionMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000022_create_recommerce_transfer_exceptions.php');
        $transferExceptionMigration->up();
        $transferV2Migration = require base_path('Modules/Recommerce/Database/Migrations/2026_09_01_000001_add_v2_transfer_state.php');
        $transferV2Migration->up();
        $intakePolicyMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_31_000034_add_device_intake_policy_to_serialization_profiles.php');
        $intakePolicyMigration->up();
        $intakeOperationsMigration = require base_path('Modules/Recommerce/Database/Migrations/2026_08_31_000036_create_recommerce_device_intake_operations.php');
        $intakeOperationsMigration->up();

        DB::table('recommerce_serialization_profiles')->insert([
            'id' => 909,
            'business_id' => 7,
            'product_id' => 202,
            'variation_id' => 303,
            'mode' => 'TRACKED_REQUIRED',
            'version' => 1,
            'effective_at' => '2026-08-27 00:00:00',
            'configured_by' => 900,
            'approval_reference' => 'TEST-APPROVED-TRACKED-303',
        ]);

        Auth::setUser($this->authorizedUser());
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        parent::tearDown();
    }

    public function test_tracked_device_survives_sale_void_and_return_with_one_history(): void
    {
        $device = Device::create([
            'business_id' => 7,
            'device_uuid' => 'b4068cc7-0f29-4d22-8f45-4f9a29de1101',
            'device_code' => 'SB-DV-00000001-9',
            'ownership_kind' => 'BUSINESS',
            'custody_kind' => 'LOCATION',
            'current_location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'lifecycle_state' => 'AVAILABLE',
            'stock_participation' => 'ON_HAND',
            'lock_version' => 1,
            'created_by' => 900,
            'updated_by' => 900,
        ]);
        $sale = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell',
            'status' => 'final', 'contact_id' => 405, 'transaction_date' => now(), 'created_by' => 900,
        ]);
        $sale->sell_lines()->create([
            'product_id' => 202, 'variation_id' => 303, 'quantity' => 1,
            'unit_price' => 2500, 'unit_price_inc_tax' => 2500,
        ]);
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        $selection = [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
        ]];

        $service->synchroniseFinalSale($this->authorizedUser(), $sale->fresh(), $selection);
        $this->assertSame('SOLD', $device->fresh()->lifecycle_state);
        $this->assertSame(1, DeviceSaleDisposition::query()->whereNotNull('active_sale_key')->count());

        $service->reverseSale($this->authorizedUser(), $sale->fresh(), 'TEST_VOID');
        $this->assertSame('AVAILABLE', $device->fresh()->lifecycle_state);
        $this->assertSame(0, DeviceSaleDisposition::query()->whereNotNull('active_sale_key')->count());

        $service->synchroniseFinalSale($this->authorizedUser(), $sale->fresh(), $selection);
        $return = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell_return',
            'status' => 'final', 'contact_id' => 405, 'return_parent_id' => $sale->id,
            'transaction_date' => now(), 'created_by' => 900,
        ]);
        $service->recordReturn($this->authorizedUser(), $return->fresh(), [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
            'recommerce_return_state' => 'RETURNED_PENDING_INSPECTION',
        ]]);

        $this->assertSame('RETURNED_PENDING_INSPECTION', $device->fresh()->lifecycle_state);
        $this->assertSame('ON_HAND', $device->fresh()->stock_participation);
        $this->assertSame(1, DeviceReturnDisposition::query()->count());
        $this->assertSame(3, DB::table('recommerce_device_events')->whereIn('event_type', ['SALE_DISPOSED', 'SALE_REVERSED', 'SALE_RETURN_RECORDED'])->distinct()->count('event_type'));
    }

    public function test_received_device_stays_out_of_pos_sale_until_inspection_clears_it(): void
    {
        $device = $this->receiveOneDevice();
        $sale = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell',
            'status' => 'final', 'contact_id' => 405, 'transaction_date' => now(), 'created_by' => 900,
        ]);
        $sale->sell_lines()->create([
            'product_id' => 202, 'variation_id' => 303, 'quantity' => 1,
            'unit_price' => 2000, 'unit_price_inc_tax' => 2000,
        ]);

        $this->expectException(LogicException::class);
        (new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder()))
            ->synchroniseFinalSale($this->authorizedUser(), $sale->fresh(), [[
                'product_id' => 202,
                'variation_id' => 303,
                'recommerce_device_codes' => $device->device_code,
            ]]);
    }

    public function test_purchase_received_device_has_assignment_observations_and_explicit_inspection_release(): void
    {
        $device = $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), [
            'business_id' => 7, 'location_id' => 101, 'product_id' => 202, 'variation_id' => 303,
            'purchase_transaction_id' => 606, 'purchase_line_id' => 707,
            'command_uuid' => '98989898-9898-4989-8989-989898989898',
            'units' => [[
                'identifier_type' => 'SERIAL', 'identifier_value' => 'SN-INSPECTION-01',
                'intake_observations' => [['type' => 'DAMAGED_PACKAGING', 'notes' => 'Outer carton torn.']],
            ]],
        ])['devices'][0];
        $passport = Device::query()->findOrFail($device['device_id']);
        $inspection = DB::table('recommerce_device_inspections')->where('device_id', $passport->id)->first();
        $this->assertSame('PENDING', $inspection->status);
        $this->assertSame(1, DB::table('recommerce_device_intake_observations')->where('device_id', $passport->id)->count());

        $service = new \Modules\Recommerce\Services\DeviceInspectionService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        $service->assign($this->authorizedUser(), [$passport->id], 900);
        $this->assertSame('ASSIGNED', DB::table('recommerce_device_inspections')->where('device_id', $passport->id)->value('status'));
        $service->start($this->authorizedUser(), $passport);
        $released = $service->complete($this->authorizedUser(), $passport, true, 'All required receiving checks passed.');

        $this->assertSame('AVAILABLE', $released->lifecycle_state);
        $this->assertSame('PASSED', DB::table('recommerce_device_inspections')->where('device_id', $passport->id)->value('status'));
        $this->assertSame(1, DB::table('recommerce_device_events')->where('event_type', 'INSPECTION_PASSED')->count());
    }

    public function test_failed_receiving_inspection_cannot_be_released_for_sale_or_transfer(): void
    {
        $device = $this->receiveOneDevice();
        $service = new \Modules\Recommerce\Services\DeviceInspectionService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        $failed = $service->complete($this->authorizedUser(), $device, false, 'Screen damage needs refurbishment.');
        $this->assertSame('REFURBISHMENT_REQUIRED', $failed->lifecycle_state);
        $this->assertSame('FAILED', DB::table('recommerce_device_inspections')->where('device_id', $device->id)->value('status'));

        $sale = Transaction::create(['business_id' => 7, 'location_id' => 101, 'type' => 'sell', 'status' => 'final', 'contact_id' => 405, 'transaction_date' => now(), 'created_by' => 900]);
        $sale->sell_lines()->create(['product_id' => 202, 'variation_id' => 303, 'quantity' => 1, 'unit_price' => 2000, 'unit_price_inc_tax' => 2000]);
        $this->expectException(LogicException::class);
        (new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder()))->synchroniseFinalSale($this->authorizedUser(), $sale->fresh(), [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
        ]]);
    }

    public function test_partial_return_requires_and_records_only_the_requested_exact_device(): void
    {
        $first = $this->availableDevice('SB-DV-00000008-5', 101);
        $second = $this->availableDevice('SB-DV-00000009-3', 101);
        $sale = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell',
            'status' => 'final', 'contact_id' => 405, 'transaction_date' => now(), 'created_by' => 900,
        ]);
        $sale->sell_lines()->create([
            'product_id' => 202, 'variation_id' => 303, 'quantity' => 2,
            'unit_price' => 2500, 'unit_price_inc_tax' => 2500,
        ]);
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        $service->synchroniseFinalSale($this->authorizedUser(), $sale->fresh(), [[
            'product_id' => 202, 'variation_id' => 303,
            'recommerce_device_codes' => $first->device_code.' '.$second->device_code,
        ]]);

        $return = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell_return',
            'status' => 'final', 'contact_id' => 405, 'return_parent_id' => $sale->id,
            'transaction_date' => now(), 'created_by' => 900,
        ]);
        $service->recordReturn($this->authorizedUser(), $return->fresh(), [[
            'product_id' => 202, 'variation_id' => 303, 'quantity' => 1,
            'recommerce_device_codes' => $second->device_code,
            'recommerce_return_state' => 'RETURNED_PENDING_INSPECTION',
        ]]);

        $this->assertSame('SOLD', $first->fresh()->lifecycle_state);
        $this->assertSame('RETURNED_PENDING_INSPECTION', $second->fresh()->lifecycle_state);
        $this->assertSame(1, DeviceReturnDisposition::query()->where('return_transaction_id', $return->id)->count());
    }

    public function test_tracked_transfer_reserves_completes_cancels_and_prevents_conflicts(): void
    {
        DB::table('variation_location_details')->insert([
            'id' => 821, 'product_id' => 202, 'location_id' => 101, 'variation_id' => 303, 'qty_available' => 1,
        ]);
        $device = $this->availableDevice('SB-DV-00000002-7', 101);
        [$transfer, $receipt] = $this->transferPair('pending');
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        $selection = [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
        ]];

        $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $selection);
        $this->assertSame('AVAILABLE', $device->fresh()->lifecycle_state);
        $this->assertSame('RESERVED', $device->fresh()->stock_participation);
        $this->assertSame('RESERVED', $device->fresh()->transfer_state);
        $this->assertSame(1, DeviceTransferAssignment::query()->where('status', 'RESERVED')->count());

        $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $selection);
        $this->assertSame(1, DeviceTransferAssignment::query()->where('status', 'RESERVED')->count());

        [$secondTransfer, $secondReceipt] = $this->transferPair('pending');
        try {
            $service->synchroniseTransferReservation($this->authorizedUser(), $secondTransfer->fresh(), $secondReceipt->fresh(), $selection);
            $this->fail('A device cannot be reserved by two transfers.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Recommerce transfer scope denied.', $exception->getMessage());
        }

        $service->dispatchTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh());
        $this->assertSame('IN_TRANSIT', $device->fresh()->transfer_state);
        $this->assertNull($device->fresh()->current_location_id);
        $service->receiveTransferDevice($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $device->fresh());
        $transfer->update(['status' => 'final']);
        $receipt->update(['status' => 'received']);
        $service->recordCompletedTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $selection);
        $this->assertSame(102, $device->fresh()->current_location_id);
        $this->assertSame('AVAILABLE', $device->fresh()->lifecycle_state);
        $this->assertSame('COMPLETED', DeviceTransferAssignment::query()->first()->status);

        $cancelDevice = $this->availableDevice('SB-DV-00000003-5', 101);
        [$cancelTransfer, $cancelReceipt] = $this->transferPair('pending');
        $cancelSelection = [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $cancelDevice->device_code,
        ]];
        $service->synchroniseTransferReservation($this->authorizedUser(), $cancelTransfer->fresh(), $cancelReceipt->fresh(), $cancelSelection);
        $service->cancelTransfer($this->authorizedUser(), $cancelTransfer->fresh());
        $this->assertSame('AVAILABLE', $cancelDevice->fresh()->lifecycle_state);
        $this->assertSame('NONE', $cancelDevice->fresh()->transfer_state);
        $this->assertSame(101, $cancelDevice->fresh()->current_location_id);
        $this->assertSame('CANCELLED', DeviceTransferAssignment::query()->where('sell_transfer_transaction_id', $cancelTransfer->id)->value('status'));
    }

    public function test_transfer_rejects_wrong_branch_wrong_variation_and_count_mismatch(): void
    {
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        [$transfer, $receipt] = $this->transferPair('pending');
        foreach ([
            [$this->availableDevice('SB-DV-00000004-3', 102), 'wrong branch'],
            [Device::create([
                'business_id' => 7, 'device_uuid' => (string) \Illuminate\Support\Str::uuid(), 'device_code' => 'SB-DV-00000005-1',
                'ownership_kind' => 'BUSINESS', 'custody_kind' => 'LOCATION', 'current_location_id' => 101,
                'product_id' => 202, 'variation_id' => 304, 'lifecycle_state' => 'AVAILABLE', 'stock_participation' => 'ON_HAND',
                'lock_version' => 1, 'created_by' => 900, 'updated_by' => 900,
            ]), 'wrong variation'],
        ] as [$device, $label]) {
            try {
                $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), [[
                    'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
                ]]);
                $this->fail($label.' selection should fail.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Recommerce transfer scope denied.', $exception->getMessage());
            }
        }
        try {
            $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), [[
                'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => '',
            ]]);
            $this->fail('Tracked quantity mismatch should fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Tracked quantity must equal the number of selected devices.', $exception->getMessage());
        }
    }

    public function test_transfer_receiving_records_missing_exception_and_blocks_completion_until_resolved(): void
    {
        $device = $this->availableDevice('SB-DV-00000010-1', 101);
        [$transfer, $receipt] = $this->transferPair('in_transit');
        $lifecycle = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        $lifecycle->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
        ]]);

        $lifecycle->dispatchTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh());
        $this->assertSame('IN_TRANSFER', $device->fresh()->stock_participation);

        $transfer->update(['status' => 'final']);
        $receipt->update(['status' => 'received']);
        try {
            $lifecycle->recordCompletedTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), []);
            $this->fail('An unreceived Device must block completion.');
        } catch (LogicException $exception) {
            $this->assertSame('Every dispatched Device must be scanned at the destination before completion.', $exception->getMessage());
        }
        $this->assertNull($device->fresh()->current_location_id);
    }

    public function test_transfer_receiving_pairs_known_wrong_device_as_substitution_and_is_idempotent(): void
    {
        $expected = $this->availableDevice('SB-DV-00000011-8', 101);
        $observed = $this->availableDevice('SB-DV-00000012-6', 101);
        [$transfer, $receipt] = $this->transferPair('in_transit');
        $lifecycle = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        $lifecycle->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $expected->device_code,
        ]]);
        $lifecycle->dispatchTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh());
        try {
            $lifecycle->receiveTransferDevice($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $observed->fresh());
            $this->fail('A wrong Device cannot satisfy transfer receipt.');
        } catch (LogicException $exception) {
            $this->assertSame('Device is not expected on this transfer.', $exception->getMessage());
        }
        $this->assertSame('AVAILABLE', $observed->fresh()->lifecycle_state);
        $this->assertSame('IN_TRANSFER', $expected->fresh()->stock_participation);
        $this->assertSame('IN_TRANSIT', $expected->fresh()->transfer_state);
    }

    public function test_completed_transfer_sells_only_at_destination_and_reconciles_at_both_branches(): void
    {
        DB::table('variation_location_details')->insert([
            'id' => 822, 'product_id' => 202, 'location_id' => 101, 'variation_id' => 303, 'qty_available' => 1,
        ]);
        $device = $this->availableDevice('SB-DV-00000006-9', 101);
        [$transfer, $receipt] = $this->transferPair('pending');
        $selection = [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
        ]];
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());

        $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $selection);
        $service->dispatchTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh());
        $service->receiveTransferDevice($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $device->fresh());
        $transfer->update(['status' => 'final']);
        $receipt->update(['status' => 'received']);
        $service->recordCompletedTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), []);
        DB::table('variation_location_details')->where('id', 822)->update(['qty_available' => 0]);
        DB::table('variation_location_details')->insert([
            'id' => 823, 'product_id' => 202, 'location_id' => 102, 'variation_id' => 303, 'qty_available' => 1,
        ]);

        $reconciliation = new StockReconciliationService($this->gate());
        $this->assertSame('PASS', $reconciliation->forVariation($this->authorizedUser(), 7, 101, 303)['status']);
        $this->assertSame('PASS', $reconciliation->forVariation($this->authorizedUser(), 7, 102, 303)['status']);

        $sourceSale = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell', 'status' => 'final',
            'contact_id' => 405, 'transaction_date' => now(), 'created_by' => 900,
        ]);
        $sourceSale->sell_lines()->create(['product_id' => 202, 'variation_id' => 303, 'quantity' => 1, 'unit_price' => 2500, 'unit_price_inc_tax' => 2500]);
        try {
            $service->synchroniseFinalSale($this->authorizedUser(), $sourceSale->fresh(), $selection);
            $this->fail('The source branch must not sell a device after transfer completion.');
        } catch (LogicException $exception) {
            $this->assertSame('Selected device is not available at the selling branch.', $exception->getMessage());
        }

        $destinationSale = Transaction::create([
            'business_id' => 7, 'location_id' => 102, 'type' => 'sell', 'status' => 'final',
            'contact_id' => 405, 'transaction_date' => now(), 'created_by' => 900,
        ]);
        $destinationSale->sell_lines()->create(['product_id' => 202, 'variation_id' => 303, 'quantity' => 1, 'unit_price' => 2500, 'unit_price_inc_tax' => 2500]);
        $service->synchroniseFinalSale($this->authorizedUser(), $destinationSale->fresh(), $selection);
        $this->assertSame('SOLD', $device->fresh()->lifecycle_state);
        $this->assertSame($destinationSale->id, DeviceSaleDisposition::query()->where('device_id', $device->id)->value('sale_transaction_id'));

        try {
            $service->reverseCompletedTransfer($this->authorizedUser(), $transfer->fresh());
            $this->fail('A completed transfer must not reverse after the device is sold.');
        } catch (LogicException $exception) {
            $this->assertSame('Completed transfer cannot be reversed after the device has changed state.', $exception->getMessage());
        }
    }

    public function test_completed_transfer_reversal_is_append_only_and_restores_reconciliation(): void
    {
        DB::table('variation_location_details')->insert([
            'id' => 824, 'product_id' => 202, 'location_id' => 101, 'variation_id' => 303, 'qty_available' => 1,
        ]);
        $device = $this->availableDevice('SB-DV-00000007-7', 101);
        [$transfer, $receipt] = $this->transferPair('pending');
        $selection = [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
        ]];
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());

        $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $selection);
        $service->dispatchTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh());
        $service->receiveTransferDevice($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $device->fresh());
        $transfer->update(['status' => 'final']);
        $receipt->update(['status' => 'received']);
        $service->recordCompletedTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), []);
        DB::table('variation_location_details')->where('id', 824)->update(['qty_available' => 0]);
        DB::table('variation_location_details')->insert([
            'id' => 825, 'product_id' => 202, 'location_id' => 102, 'variation_id' => 303, 'qty_available' => 1,
        ]);

        $service->reverseCompletedTransfer($this->authorizedUser(), $transfer->fresh());
        DB::table('variation_location_details')->where('id', 825)->update(['qty_available' => 0]);
        DB::table('variation_location_details')->where('id', 824)->update(['qty_available' => 1]);

        $assignment = DeviceTransferAssignment::query()->firstOrFail();
        $this->assertSame('REVERSED', $assignment->status);
        $this->assertNotNull($assignment->reversed_at);
        $this->assertSame(101, $device->fresh()->current_location_id);
        $this->assertSame('AVAILABLE', $device->fresh()->lifecycle_state);
        $this->assertSame(1, DB::table('recommerce_device_movements')->where('movement_type', 'TRANSFER_REVERSAL')->count());
        $this->assertSame('PASS', (new StockReconciliationService($this->gate()))->forVariation($this->authorizedUser(), 7, 101, 303)['status']);
        $this->assertSame('PASS', (new StockReconciliationService($this->gate()))->forVariation($this->authorizedUser(), 7, 102, 303)['status']);
    }

    public function test_v2_transfer_requires_destination_scan_is_idempotent_and_preserves_lifecycle(): void
    {
        $device = $this->availableDevice('SB-DV-00000031-0', 101);
        $device->update(['lifecycle_state' => 'RECEIVED_PENDING_INSPECTION']);
        [$transfer, $receipt] = $this->transferPair('pending');
        $selection = [['product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code]];
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());

        $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $selection);
        $this->assertSame(101, $device->fresh()->current_location_id);
        $this->assertSame('RECEIVED_PENDING_INSPECTION', $device->fresh()->lifecycle_state);
        $service->dispatchTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh());
        $this->assertSame('IN_TRANSIT', $device->fresh()->custody_kind);
        $this->assertSame('IN_TRANSFER', $device->fresh()->stock_participation);

        $first = $service->receiveTransferDevice($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $device->fresh());
        $second = $service->receiveTransferDevice($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), $device->fresh());
        $this->assertSame('RECEIVED', $first['status']);
        $this->assertSame('ALREADY_RECEIVED', $second['status']);
        $this->assertSame(102, $device->fresh()->current_location_id, 'A scanned Device is physically at the destination even before native aggregate completion.');
        $this->assertSame('LOCATION', $device->fresh()->custody_kind);
        $this->assertSame('IN_TRANSFER', $device->fresh()->stock_participation, 'Partial receipt is never destination on-hand stock.');
        $this->assertSame(1, DB::table('recommerce_device_movements')->where('movement_type', 'TRANSFER_RECEIPT_SCAN')->count());
        $transfer->update(['status' => 'final']);
        $receipt->update(['status' => 'received']);
        $service->recordCompletedTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), []);

        $this->assertSame(102, $device->fresh()->current_location_id);
        $this->assertSame('RECEIVED_PENDING_INSPECTION', $device->fresh()->lifecycle_state);
        $this->assertSame('ON_HAND', $device->fresh()->stock_participation);
        $this->assertSame('NONE', $device->fresh()->transfer_state);
        $this->assertSame(1, DB::table('recommerce_device_movements')->where('movement_type', 'TRANSFER_RECEIPT_SCAN')->count());
        $this->assertSame(1, DeviceTransferAssignment::query()->where('status', 'COMPLETED')->count());
    }

    public function test_v2_transfer_selection_is_incremental_without_reissuing_existing_assignment_evidence(): void
    {
        $first = $this->availableDevice('SB-DV-00000032-5', 101);
        $second = $this->availableDevice('SB-DV-00000033-3', 101);
        [$transfer, $receipt] = $this->transferPair('pending');
        $transfer->sell_lines()->first()->update(['quantity' => 2]);
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());

        $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $first->device_code,
        ]], true);
        $firstAssignmentId = DeviceTransferAssignment::query()->where('device_id', $first->id)->value('id');
        $this->assertSame(1, DeviceTransferAssignment::query()->where('status', 'RESERVED')->count());

        $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $first->device_code.' '.$second->device_code,
        ]], true);

        $this->assertSame($firstAssignmentId, DeviceTransferAssignment::query()->where('device_id', $first->id)->value('id'));
        $this->assertSame(2, DeviceTransferAssignment::query()->where('status', 'RESERVED')->count());
        $this->assertSame('RESERVED', $first->fresh()->transfer_state);
        $this->assertSame('RESERVED', $second->fresh()->transfer_state);
    }

    public function test_v2_transfer_scan_identifies_open_transit_context_for_authorized_staff(): void
    {
        $device = $this->availableDevice('SB-DV-00000031-0', 101);
        [$transfer, $receipt] = $this->transferPair('pending');
        $transfer->update(['ref_no' => 'ST-V2-TRANSIT-01']);
        $service = new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder());
        $service->synchroniseTransferReservation($this->authorizedUser(), $transfer->fresh(), $receipt->fresh(), [[
            'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => $device->device_code,
        ]]);
        $service->dispatchTransfer($this->authorizedUser(), $transfer->fresh(), $receipt->fresh());
        $this->assertTrue(User::can_access_this_location(101, 7));
        $this->assertTrue(User::can_access_this_location(102, 7));
        $this->assertTrue($this->gate()->allowsRead(auth()->user(), 'recommerce.device.view', 7, 101, 303));
        $this->assertSame('IN_TRANSIT', DeviceTransferAssignment::query()->where('device_id', $device->id)->value('status'));
        $response = (new ScanController())->resolve(
            Request::create('/recommerce/scans/resolve', 'POST', ['value' => $device->device_code]),
            $this->gate(),
            new OpaqueScanToken()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('IN_TRANSIT', $response->getData(true)['transfer']['state']);
        $this->assertSame('ST-V2-TRANSIT-01', $response->getData(true)['transfer']['reference']);
        $this->assertSame('Kota Kinabalu', $response->getData(true)['transfer']['from_location']);
        $this->assertSame('Sandakan', $response->getData(true)['transfer']['to_location']);
    }

    public function test_receive_creates_exact_device_evidence_without_storing_raw_identifier(): void
    {
        $service = $this->service();
        $writerCalls = 0;
        $writerInputs = [];

        $result = $service->execute($this->authorizedUser(), $this->command([
            ['identifier_type' => 'SERIAL', 'identifier_value' => ' sn-01 ', 'unit_acquisition_cost' => 1850],
            ['identifier_type' => 'ASSET_TAG', 'identifier_value' => 'asset-02', 'unit_acquisition_cost' => 1850],
        ]), function (array $normalized) use (&$writerCalls, &$writerInputs): array {
            $writerCalls++;
            $writerInputs = $normalized['units'];

            return $this->coreReceipt(2);
        });

        $this->assertSame(1, $writerCalls);
        $this->assertSame(['SN01', 'ASSET02'], array_column($writerInputs, 'identifier_value'));
        $this->assertSame(2, $result['unit_count']);
        $this->assertSame(2, Device::query()->count());
        $this->assertSame(2, DB::table('recommerce_device_identifiers')->count());
        $this->assertSame(2, DB::table('recommerce_device_purchase_assignments')->count());
        $this->assertSame(2, DB::table('recommerce_device_ownership_periods')->count());
        $ownership = DB::table('recommerce_device_ownership_periods')->orderBy('id')->first();
        $this->assertSame('BUSINESS', $ownership->owner_kind);
        $this->assertNull($ownership->contact_id);
        $this->assertSame('PURCHASE', $ownership->reason);
        $this->assertSame((int) $ownership->device_id, (int) $ownership->open_period_key);
        $this->assertSame(606, (int) $ownership->acquisition_transaction_id);
        $this->assertSame(2, DB::table('recommerce_device_movements')->count());
        $this->assertSame(2, DB::table('recommerce_device_custody_periods')->count());
        $custody = DB::table('recommerce_device_custody_periods')->orderBy('id')->first();
        $this->assertSame('LOCATION', $custody->custody_kind);
        $this->assertSame(101, (int) $custody->location_id);
        $this->assertSame((int) $custody->device_id, (int) $custody->open_period_key);
        $movement = DB::table('recommerce_device_movements')->where('id', $custody->source_movement_id)->first();
        $this->assertSame((int) $custody->device_id, (int) $movement->device_id);
        $this->assertSame(2, DB::table('recommerce_device_events')->count());
        $this->assertSame(2, DB::table('recommerce_device_events')->distinct()->count('event_uuid'));
        $this->assertNull(DB::table('recommerce_device_identifiers')->value('raw_value_encrypted'));
        $event = DB::table('recommerce_device_events')->orderBy('id')->first();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $event->event_uuid);
        $this->assertSame(1, (int) $event->event_version);
        $this->assertSame('RECEIVE_POSTED', $event->event_type);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $event->source_command_uuid);
        $this->assertStringNotContainsString('SN01', json_encode($event));
        $this->assertSame(2, DB::table('recommerce_outbox_messages')->count());
        $outbox = DB::table('recommerce_outbox_messages')->orderBy('id')->first();
        $this->assertSame('recommerce.device.event', $outbox->topic);
        $this->assertSame('PENDING', $outbox->status);
        $this->assertSame((int) $event->id, (int) $outbox->event_id);
        $this->assertSame($event->event_uuid, json_decode($outbox->payload_json, true)['event_uuid']);
        $this->assertStringNotContainsString('SN01', $outbox->payload_json);
        $this->assertSame(
            StrongIdentifierHasher::hash('SN01'),
            DB::table('recommerce_device_identifiers')->where('identifier_type', 'SERIAL')->value('normalized_hash')
        );
    }

    public function test_received_pos_purchase_can_be_serialised_without_a_second_stock_or_accounting_write(): void
    {
        $transactionsBefore = DB::table('transactions')->count();
        $purchaseQuantityBefore = DB::table('purchase_lines')->where('id', 707)->value('quantity');
        $command = [
            'business_id' => 7,
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'purchase_transaction_id' => 606,
            'purchase_line_id' => 707,
            'command_uuid' => '99999999-9999-4999-8999-999999999999',
            'units' => [[
                'identifier_type' => 'SERIAL',
                'identifier_value' => 'SN-EXISTING-PURCHASE-01',
            ]],
        ];

        $result = $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), $command);
        $replayed = $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), $command);

        $this->assertFalse($result['core_stock_changed']);
        $this->assertSame($result, $replayed);
        $this->assertSame($transactionsBefore, DB::table('transactions')->count());
        $this->assertSame((float) $purchaseQuantityBefore, (float) DB::table('purchase_lines')->where('id', 707)->value('quantity'));
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('recommerce_device_purchase_assignments')->count());
        $this->assertSame('TRACKED_PURCHASE_ATTACH', DB::table('recommerce_stock_commands')->value('command_type'));
        $this->assertSame(606, (int) DB::table('recommerce_device_purchase_assignments')->value('transaction_id'));
        $this->assertSame(707, (int) DB::table('recommerce_device_purchase_assignments')->value('purchase_line_id'));
    }

    public function test_purchase_attachment_endpoint_returns_a_safe_core_unchanged_result(): void
    {
        $response = (new ReceivingController())->attachPurchase(
            Request::create('/recommerce/receiving/attach-purchase', 'POST', [
                'location_id' => 101,
                'product_id' => 202,
                'variation_id' => 303,
                'purchase_transaction_id' => 606,
                'purchase_line_id' => 707,
                'command_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'units' => [[
                    'identifier_type' => 'SERIAL',
                    'identifier_value' => 'SN-ATTACH-ENDPOINT-01',
                ]],
            ]),
            $this->service()
        );

        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('PURCHASE_SERIALISED', $data['status']);
        $this->assertFalse($data['result']['core_stock_changed']);
        $this->assertSame(606, $data['result']['transaction_id']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_received_pos_purchase_line_can_be_serialised_in_bounded_batches(): void
    {
        DB::table('purchase_lines')->where('id', 707)->update(['quantity' => 2]);
        $base = [
            'business_id' => 7,
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'purchase_transaction_id' => 606,
            'purchase_line_id' => 707,
        ];

        $first = $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), $base + [
            'command_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'units' => [['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-BATCH-01']],
        ]);
        $second = $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), $base + [
            'command_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'units' => [['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-BATCH-02']],
        ]);

        $this->assertSame(1, $first['remaining_unassigned_units']);
        $this->assertSame(0, $second['remaining_unassigned_units']);
        $this->assertSame(2, Device::query()->count());
        $this->assertSame(2, DB::table('recommerce_device_purchase_assignments')->count());
        $this->assertSame([1, 2], DB::table('recommerce_device_purchase_assignments')->orderBy('unit_ordinal')->pluck('unit_ordinal')->all());
        $this->assertSame(1, DB::table('transactions')->count());
        $this->assertSame(2.0, (float) DB::table('purchase_lines')->where('id', 707)->value('quantity'));
    }

    public function test_received_pos_purchase_line_accepts_a_practical_twenty_device_batch(): void
    {
        DB::table('purchase_lines')->where('id', 707)->update(['quantity' => 20]);
        $units = array_map(static fn (int $unit): array => [
            'identifier_type' => 'SERIAL',
            'identifier_value' => sprintf('SN-TWENTY-UNIT-%02d', $unit),
        ], range(1, 20));

        $result = $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), [
            'business_id' => 7,
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'purchase_transaction_id' => 606,
            'purchase_line_id' => 707,
            'command_uuid' => 'b1b1b1b1-b1b1-4b1b-8b1b-b1b1b1b1b1b1',
            'units' => $units,
        ]);

        $this->assertSame(20, $result['unit_count']);
        $this->assertSame(0, $result['remaining_unassigned_units']);
        $this->assertSame(20, Device::query()->count());
        $this->assertSame(20, DB::table('recommerce_device_purchase_assignments')->count());
        $this->assertSame(range(1, 20), DB::table('recommerce_device_purchase_assignments')->orderBy('unit_ordinal')->pluck('unit_ordinal')->all());
    }

    public function test_purchase_attachment_rejects_over_identification_with_operator_safe_guidance(): void
    {
        $base = [
            'location_id' => 101, 'product_id' => 202, 'variation_id' => 303,
            'purchase_transaction_id' => 606, 'purchase_line_id' => 707,
        ];
        $controller = new ReceivingController();
        $first = $controller->attachPurchase(
            Request::create('/recommerce/receiving/attach-purchase', 'POST', $base + [
                'command_uuid' => 'abababab-abab-4bab-8bab-abababababab',
                'units' => [['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-ONLY-UNIT']],
            ]),
            $this->service()
        );
        $second = $controller->attachPurchase(
            Request::create('/recommerce/receiving/attach-purchase', 'POST', $base + [
                'command_uuid' => 'acacacac-acac-4cac-8cac-acacacacacac',
                'units' => [['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-EXCESS-UNIT']],
            ]),
            $this->service()
        );

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(422, $second->getStatusCode());
        $this->assertSame(
            'This batch is larger than the number of devices still needing identification. Refresh to see the latest progress.',
            $second->getData(true)['message']
        );
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('recommerce_device_purchase_assignments')->count());
    }

    public function test_purchase_attachment_rejects_unauthorized_and_cross_location_operators(): void
    {
        $command = [
            'business_id' => 7, 'location_id' => 101, 'product_id' => 202, 'variation_id' => 303,
            'purchase_transaction_id' => 606, 'purchase_line_id' => 707,
            'command_uuid' => 'adadadad-adad-4dad-8dad-adadadadadad',
            'units' => [['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-DENIED-UNIT']],
        ];
        $deniedUser = new class extends User
        {
            public function can($ability, $arguments = []): bool
            {
                return false;
            }

            public function permitted_locations($business_id = null)
            {
                return [101];
            }
        };
        $deniedUser->id = 900;
        $deniedUser->business_id = 7;

        try {
            $this->service()->attachToExistingUltimatePosPurchase($deniedUser, $command);
            $this->fail('An operator without receiving permission must be denied.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Recommerce receiving scope denied.', $exception->getMessage());
        }

        try {
            $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), array_merge($command, [
                'location_id' => 102,
                'command_uuid' => 'aeaeaeae-aeae-4eae-8eae-aeaeaeaeaeae',
            ]));
            $this->fail('A purchase must not be identified from a different location.');
        } catch (LogicException $exception) {
            $this->assertSame('The selected POS purchase line is not an eligible received stock line.', $exception->getMessage());
        }

        $this->assertSame(0, Device::query()->count());
        $this->assertSame(0, DB::table('recommerce_device_purchase_assignments')->count());
    }

    public function test_identified_purchase_provenance_blocks_native_purchase_mutation(): void
    {
        $progress = new DeviceReceivingProgressService();
        $progress->assertPurchaseMayBeChanged(7, 606);

        $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), [
            'business_id' => 7, 'location_id' => 101, 'product_id' => 202, 'variation_id' => 303,
            'purchase_transaction_id' => 606, 'purchase_line_id' => 707,
            'command_uuid' => 'afafafaf-afaf-4faf-8faf-afafafafafaf',
            'units' => [['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-PROVENANCE-LOCK']],
        ]);

        foreach ([
            'edit' => 'cannot be edited',
            'delete' => 'cannot be deleted',
            'status' => 'status cannot change',
            'return' => 'cannot use the ordinary return screen',
        ] as $operation => $message) {
            try {
                $progress->assertPurchaseMayBeChanged(7, 606, $operation);
                $this->fail('Identified purchase mutation must remain blocked.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
                $this->assertStringContainsString('receiving record', $exception->getMessage());
            }
        }
    }

    public function test_purchase_led_progress_distinguishes_bulk_and_resumes_serialised_receiving(): void
    {
        DB::table('purchase_lines')->where('id', 707)->update([
            'quantity' => 3,
            'purchase_price_inc_tax' => 712.50,
        ]);
        DB::table('purchase_lines')->insert([
            'id' => 708,
            'transaction_id' => 606,
            'product_id' => 202,
            'variation_id' => 304,
            'quantity' => 4,
            'purchase_price_inc_tax' => 99.99,
        ]);

        $base = [
            'business_id' => 7,
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'purchase_transaction_id' => 606,
            'purchase_line_id' => 707,
        ];
        $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), $base + [
            'command_uuid' => '12121212-1212-4121-8121-121212121212',
            'units' => [['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-PROGRESS-01']],
        ]);

        $first = (new DeviceReceivingProgressService())->forPurchase(7, 606);
        $serialized = $first['lines']->firstWhere('id', 707);
        $bulk = $first['lines']->firstWhere('id', 708);
        $this->assertSame('SERIALIZED_DEVICE', $serialized->tracking_mode);
        $this->assertSame(3, $serialized->expected_count);
        $this->assertSame(1, $serialized->registered_count);
        $this->assertSame(2, $serialized->remaining_count);
        $this->assertSame(712.50, $serialized->default_unit_acquisition_cost);
        $this->assertSame('BULK', $bulk->tracking_mode);
        $this->assertSame(0, $bulk->expected_count);

        $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), $base + [
            'command_uuid' => '13131313-1313-4131-8131-131313131313',
            'units' => [
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-PROGRESS-02'],
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-PROGRESS-03'],
            ],
        ]);

        $completed = (new DeviceReceivingProgressService())->forPurchase(7, 606);
        $line = $completed['lines']->firstWhere('id', 707);
        $this->assertSame(0, $line->remaining_count);
        $this->assertSame([1, 2, 3], DB::table('recommerce_device_purchase_assignments')->orderBy('unit_ordinal')->pluck('unit_ordinal')->all());
        $this->assertSame(1, DB::table('transactions')->count());
    }

    public function test_authorised_product_policy_persists_individual_device_and_quantity_modes(): void
    {
        config(['recommerce.cohort.allow_approved_product_policies' => true]);
        DB::table('products')->insert(['id' => 203, 'business_id' => 7, 'brand_id' => 1, 'name' => 'ThinkPad T14']);
        DB::table('variations')->insert(['id' => 305, 'product_id' => 203]);
        $service = new ProductTrackingPolicyService($this->gate());
        $product = Product::query()->findOrFail(203);

        $service->sync($product, ProductTrackingPolicyService::INDIVIDUAL_DEVICE, $this->authorizedUser());

        $profile = DB::table('recommerce_serialization_profiles')->where('variation_id', 305)->first();
        $this->assertSame('SERIALIZED_DEVICE', $profile->inventory_tracking_mode);
        $this->assertSame(1, (int) $profile->inspection_required);
        $this->assertSame(900, (int) $profile->configured_by);
        $this->assertTrue((new CohortPolicy())->allowsReadVariation(7, 101, 305));

        $service->sync($product, ProductTrackingPolicyService::QUANTITY, $this->authorizedUser());

        $this->assertSame('BULK', $service->modeForProduct($product));
        $this->assertSame(0, (int) DB::table('recommerce_serialization_profiles')->where('variation_id', 305)->value('inspection_required'));
        $this->assertFalse((new CohortPolicy())->allowsReadVariation(7, 101, 305));
    }

    public function test_authorised_product_policy_is_available_when_the_optional_environment_override_is_absent(): void
    {
        $this->assertTrue(config('recommerce.cohort.allow_approved_product_policies'));

        $service = new ProductTrackingPolicyService($this->gate());

        $this->assertTrue($service->availableFor($this->authorizedUser(), 7));
    }

    public function test_product_tracking_mode_cannot_change_after_purchase_history_exists(): void
    {
        config(['recommerce.cohort.allow_approved_product_policies' => true]);
        DB::table('products')->insert(['id' => 204, 'business_id' => 7, 'brand_id' => 1, 'name' => 'Tablet']);
        DB::table('variations')->insert(['id' => 306, 'product_id' => 204]);
        $service = new ProductTrackingPolicyService($this->gate());
        $product = Product::query()->findOrFail(204);
        $service->sync($product, ProductTrackingPolicyService::INDIVIDUAL_DEVICE, $this->authorizedUser());
        DB::table('purchase_lines')->insert([
            'id' => 709, 'transaction_id' => 606, 'product_id' => 204, 'variation_id' => 306,
            'quantity' => 1, 'purchase_price_inc_tax' => 500,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Tracking cannot be changed');

        $service->sync($product, ProductTrackingPolicyService::QUANTITY, $this->authorizedUser());
    }

    public function test_purchase_attachment_defaults_cost_and_requires_permission_for_override(): void
    {
        DB::table('purchase_lines')->where('id', 707)->update(['quantity' => 2, 'purchase_price_inc_tax' => 712.50]);
        $base = [
            'business_id' => 7,
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'purchase_transaction_id' => 606,
            'purchase_line_id' => 707,
        ];
        $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), $base + [
            'command_uuid' => '14141414-1414-4141-8141-141414141414',
            'units' => [['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-COST-DEFAULT']],
        ]);
        $this->assertSame(712.50, (float) DB::table('recommerce_device_purchase_assignments')->value('unit_acquisition_cost'));

        $this->service()->attachToExistingUltimatePosPurchase($this->authorizedUser(), $base + [
            'command_uuid' => '15151515-1515-4151-8151-151515151515',
            'units' => [[
                'identifier_type' => 'SERIAL',
                'identifier_value' => 'SN-COST-OVERRIDE',
                'unit_acquisition_cost' => 700.00,
                'cost_override_reason_code' => 'INVOICE_CORRECTION',
                'cost_override_reason_notes' => 'Supplier issued corrected invoice.',
            ]],
        ]);
        $this->assertSame(700.00, (float) DB::table('recommerce_device_purchase_assignments')->orderByDesc('id')->value('unit_acquisition_cost'));
        $override = DB::table('recommerce_device_cost_override_events')->orderByDesc('id')->first();
        $this->assertSame(712.50, (float) $override->previous_unit_acquisition_cost);
        $this->assertSame(700.00, (float) $override->new_unit_acquisition_cost);
        $this->assertSame('INVOICE_CORRECTION', $override->reason_code);
    }

    public function test_module_migration_registers_named_permissions_for_native_role_editor(): void
    {
        $registered = DB::table('permissions')
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $expected = collect(config('recommerce.permissions'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $registered);
    }

    public function test_custody_open_period_constraint_blocks_a_second_active_period(): void
    {
        $device = $this->receiveOneDevice();

        try {
            DB::table('recommerce_device_custody_periods')->insert([
                'device_id' => $device->id,
                'business_id' => 7,
                'custody_kind' => 'LOCATION',
                'location_id' => 101,
                'starts_at' => now(),
                'open_period_key' => $device->id,
                'reason' => 'DUPLICATE_ACTIVE_PERIOD_TEST',
                'recorded_by' => 900,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected the open custody-period key to remain unique per Device.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE', strtoupper($exception->getMessage()));
        }

        $this->assertSame(1, DB::table('recommerce_device_custody_periods')->count());
    }

    public function test_completed_command_replay_returns_original_result_without_second_core_write(): void
    {
        $service = $this->service();
        $writerCalls = 0;
        $command = $this->command([
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-REPLAY-01', 'unit_acquisition_cost' => 1850],
        ]);
        $writer = function () use (&$writerCalls): array {
            $writerCalls++;

            return $this->coreReceipt(1);
        };

        $first = $service->execute($this->authorizedUser(), $command, $writer);
        $second = $service->execute($this->authorizedUser(), $command, $writer);

        $this->assertSame($first, $second);
        $this->assertSame(1, $writerCalls);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('recommerce_stock_commands')->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());
        $this->assertSame(1, DB::table('recommerce_outbox_messages')->count());
    }

    public function test_conflicting_idempotency_key_is_rejected_without_second_core_write(): void
    {
        $service = $this->service();
        $writerCalls = 0;
        $writer = function () use (&$writerCalls): array {
            $writerCalls++;

            return $this->coreReceipt(1);
        };
        $command = $this->command([
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-CONFLICT-01', 'unit_acquisition_cost' => 1850],
        ]);

        $service->execute($this->authorizedUser(), $command, $writer);

        try {
            $service->execute($this->authorizedUser(), $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-CONFLICT-02', 'unit_acquisition_cost' => 1850],
            ], $command['command_uuid']), $writer);
            $this->fail('Expected conflicting idempotency reuse to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('reused for a different request', $exception->getMessage());
        }

        $this->assertSame(1, $writerCalls);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('recommerce_stock_commands')->count());
    }

    public function test_malformed_utf8_command_is_rejected_before_core_write(): void
    {
        $writerCalls = 0;

        try {
            $this->service()->execute(
                $this->authorizedUser(),
                $this->command([
                    ['identifier_type' => 'SERIAL', 'identifier_value' => "SN-INVALID\xFF", 'unit_acquisition_cost' => 1850],
                ]),
                function () use (&$writerCalls): array {
                    $writerCalls++;

                    return $this->coreReceipt(1);
                }
            );
            $this->fail('Expected malformed UTF-8 to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('unsupported text encoding', $exception->getMessage());
        }

        $this->assertSame(0, $writerCalls);
        $this->assertSame(0, DB::table('recommerce_stock_commands')->count());
        $this->assertSame(0, Device::query()->count());
    }

    public function test_existing_identifier_of_same_type_is_rejected_before_second_core_write(): void
    {
        $service = $this->service();
        $writerCalls = 0;
        $writer = function () use (&$writerCalls): array {
            $writerCalls++;

            return $this->coreReceipt(1);
        };
        $firstCommand = $this->command([
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-UNIQUE-01', 'unit_acquisition_cost' => 1850],
        ]);

        $service->execute($this->authorizedUser(), $firstCommand, $writer);

        try {
            $service->execute($this->authorizedUser(), $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'sn_unique_01', 'unit_acquisition_cost' => 1850],
            ], '22222222-2222-4222-8222-222222222222'), $writer);
            $this->fail('Expected the existing identifier to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already registered', $exception->getMessage());
        }

        $this->assertSame(1, $writerCalls);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('recommerce_stock_commands')->count());
    }

    public function test_same_normalized_text_is_allowed_in_a_different_identifier_type_namespace(): void
    {
        $service = $this->service();
        $writerCalls = 0;
        $writer = function () use (&$writerCalls): array {
            $writerCalls++;

            return $this->coreReceipt(1, $writerCalls === 1 ? 606 : 608, $writerCalls === 1 ? 707 : 709);
        };

        DB::table('transactions')->insert([
            'id' => 608,
            'business_id' => 7,
            'location_id' => 101,
            'type' => 'purchase',
            'status' => 'received',
            'payment_status' => 'due',
            'contact_id' => 404,
            'ref_no' => 'PUR-SEED-0608',
            'transaction_date' => '2026-08-27 00:00:00',
            'total_before_tax' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'final_total' => 0,
            'created_by' => 900,
            'exchange_rate' => 1,
            'source' => 'test',
        ]);
        DB::table('purchase_lines')->insert([
            'id' => 709,
            'transaction_id' => 608,
            'product_id' => 202,
            'variation_id' => 303,
            'quantity' => 1,
        ]);

        $service->execute($this->authorizedUser(), $this->command([
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-CROSS-TYPE-01', 'unit_acquisition_cost' => 1850],
        ]), $writer);
        $service->execute($this->authorizedUser(), $this->command([
            ['identifier_type' => 'ASSET_TAG', 'identifier_value' => 'sn_cross_type_01', 'unit_acquisition_cost' => 1850],
        ], '33333333-3333-4333-8333-333333333333'), $writer);

        $this->assertSame(2, $writerCalls);
        $this->assertSame(2, Device::query()->count());
        $this->assertSame(2, DB::table('recommerce_device_identifiers')->count());
    }

    public function test_core_quantity_mismatch_rolls_back_all_tracked_receive_rows(): void
    {
        $service = $this->service();

        try {
            $service->execute($this->authorizedUser(), $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-ROLLBACK-01', 'unit_acquisition_cost' => 1850],
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-ROLLBACK-02', 'unit_acquisition_cost' => 1850],
            ]), fn (): array => $this->coreReceipt(1));
            $this->fail('Expected the mismatched core quantity to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('exact expected receipt scope and quantity', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('recommerce_stock_commands')->count());
        $this->assertSame(0, Device::query()->count());
        $this->assertSame(0, DB::table('recommerce_device_identifiers')->count());
        $this->assertSame(0, DB::table('recommerce_device_purchase_assignments')->count());
        $this->assertSame(0, DB::table('recommerce_device_ownership_periods')->count());
        $this->assertSame(0, DB::table('recommerce_device_custody_periods')->count());
        $this->assertSame(0, DB::table('recommerce_device_movements')->count());
        $this->assertSame(0, DB::table('recommerce_device_events')->count());
        $this->assertSame(0, DB::table('recommerce_outbox_messages')->count());
    }

    public function test_receiving_prepare_masks_identifiers_and_exposes_no_write_url_when_writes_are_off(): void
    {
        config(['recommerce.writes_enabled' => false]);
        $rawFirst = 'SN-CONTROLLER-001';
        $rawSecond = 'asset_controller_002';

        $response = (new ReceivingController())->prepare(
            Request::create('/recommerce/receiving/prepare', 'POST', [
                'location_id' => 101,
                'product_id' => 202,
                'variation_id' => 303,
                'units' => [
                    ['identifier_type' => 'SERIAL', 'identifier_value' => $rawFirst],
                    ['identifier_type' => 'ASSET_TAG', 'identifier_value' => $rawSecond],
                ],
            ]),
            $this->gate()
        );
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('PREPARED_NO_WRITE', $data['status']);
        $this->assertNull($data['post_url']);
        $this->assertSame(2, $data['unit_count']);
        $this->assertSame(['SERIAL', 'ASSET_TAG'], array_column($data['identifiers'], 'identifier_type'));
        $this->assertStringNotContainsString($rawFirst, json_encode($data));
        $this->assertStringNotContainsString($rawSecond, json_encode($data));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_receiving_prepare_does_not_echo_short_identifiers(): void
    {
        config(['recommerce.writes_enabled' => false]);

        $response = (new ReceivingController())->prepare(
            Request::create('/recommerce/receiving/prepare', 'POST', [
                'location_id' => 101,
                'product_id' => 202,
                'variation_id' => 303,
                'units' => [
                    ['identifier_type' => 'SERIAL', 'identifier_value' => 'AB'],
                ],
            ]),
            $this->gate()
        );

        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('**', $data['identifiers'][0]['identifier_hint']);
        $this->assertStringNotContainsString('AB', json_encode($data));
    }

    public function test_receiving_prepare_route_enforces_write_gate_over_http(): void
    {
        $payload = [
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'units' => [
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-HTTP-PRE-001'],
                ['identifier_type' => 'ASSET_TAG', 'identifier_value' => 'ASSET-HTTP-002'],
            ],
        ];

        (new RouteServiceProvider(app()))->map();
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
        $this->assertNotNull(collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === 'recommerce.receiving.post'));

        config(['recommerce.writes_enabled' => false]);
        $safeResponse = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/receiving/prepare', $payload);
        $safeData = $safeResponse->getData(true);

        $safeResponse->assertOk()
            ->assertJsonPath('status', 'PREPARED_NO_WRITE')
            ->assertJsonPath('unit_count', 2);
        $this->assertNull($safeData['post_url']);
        $this->assertSame(['SERIAL', 'ASSET_TAG'], array_column($safeData['identifiers'], 'identifier_type'));
        $this->assertStringNotContainsString('SN-HTTP-PRE-001', $safeResponse->getContent());
        $this->assertStringNotContainsString('ASSET-HTTP-002', $safeResponse->getContent());
        $this->assertStringContainsString('no-store', (string) $safeResponse->headers->get('Cache-Control'));
        $safeResponse->assertHeader('Referrer-Policy', 'no-referrer');

        config(['recommerce.writes_enabled' => true]);
        $enabledResponse = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/receiving/prepare', $payload);
        $enabledData = $enabledResponse->getData(true);

        $enabledResponse->assertOk()
            ->assertJsonPath('status', 'PREPARED_NO_WRITE')
            ->assertJsonPath('unit_count', 2);
        $this->assertIsString($enabledData['post_url']);
        $this->assertStringContainsString('/recommerce/receiving/post', $enabledData['post_url']);
    }

    public function test_receiving_prepare_returns_422_for_duplicate_or_malformed_identifier_input(): void
    {
        config(['recommerce.writes_enabled' => false]);
        $controller = new ReceivingController();
        $base = [
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
        ];

        $duplicateResponse = $controller->prepare(
            Request::create('/recommerce/receiving/prepare', 'POST', array_merge($base, [
                'units' => [
                    ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-DUPLICATE-01'],
                    ['identifier_type' => 'SERIAL', 'identifier_value' => 'sn_duplicate_01'],
                ],
            ])),
            $this->gate()
        );
        $malformedResponse = $controller->prepare(
            Request::create('/recommerce/receiving/prepare', 'POST', array_merge($base, [
                'units' => [[
                    'identifier_type' => 'SERIAL',
                    'identifier_value' => "SN-MALFORMED\x00",
                ]],
            ])),
            $this->gate()
        );

        $this->assertSame(422, $duplicateResponse->getStatusCode());
        $this->assertSame(422, $malformedResponse->getStatusCode());
        $this->assertSame('no-referrer', $duplicateResponse->headers->get('Referrer-Policy'));
        $this->assertSame('no-referrer', $malformedResponse->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('duplicate', strtolower($duplicateResponse->getData(true)['message']));
        $this->assertStringContainsString('invalid identifier', strtolower($malformedResponse->getData(true)['message']));
        $this->assertStringContainsString('no-store', (string) $malformedResponse->headers->get('Cache-Control'));
    }

    public function test_receiving_prepare_allows_same_normalized_text_in_a_different_identifier_type_namespace(): void
    {
        config(['recommerce.writes_enabled' => false]);

        $response = (new ReceivingController())->prepare(
            Request::create('/recommerce/receiving/prepare', 'POST', [
                'location_id' => 101,
                'product_id' => 202,
                'variation_id' => 303,
                'units' => [
                    ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-PREPARE-TYPE-01'],
                    ['identifier_type' => 'ASSET_TAG', 'identifier_value' => 'sn_prepare_type_01'],
                ],
            ]),
            $this->gate()
        );

        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('PREPARED_NO_WRITE', $data['status']);
        $this->assertSame(2, $data['unit_count']);
    }

    public function test_ultimate_pos_writer_reuses_core_purchase_sequence_and_adjusts_overselling(): void
    {
        Event::fake();
        session(['business.date_format' => 'm/d/Y', 'business.time_format' => 24]);
        $productUtil = Mockery::mock(ProductUtil::class);
        $transactionUtil = Mockery::mock(TransactionUtil::class);
        $productUtil->shouldReceive('setAndGetReferenceCount')->once()->with('purchase', 7)->andReturn(8);
        $productUtil->shouldReceive('generateReferenceNumber')->once()->with('purchase', 8, 7)->andReturn('PUR-2026/0008');
        $productUtil->shouldReceive('uf_date')->once()->with('08/27/2026 00:00', true)->andReturn('2026-08-27 00:00:00');
        $transactionUtil->shouldReceive('purchaseCurrencyDetails')->once()->with(7)->andReturn([
            'purchase_in_diff_currency' => false,
            'p_exchange_rate' => 1,
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'symbol' => 'RM',
        ]);
        $productUtil->shouldReceive('createOrUpdatePurchaseLines')->once()->withArgs(function ($transaction, $purchaseLines, $currencyDetails, $editing) {
            $this->assertInstanceOf(Transaction::class, $transaction);
            $this->assertCount(1, $purchaseLines);
            $this->assertFalse($editing);
            $this->assertSame(2, (int) $purchaseLines[0]['quantity']);
            DB::table('purchase_lines')->insert([
                'transaction_id' => $transaction->id,
                'product_id' => $purchaseLines[0]['product_id'],
                'variation_id' => $purchaseLines[0]['variation_id'],
                'quantity' => $purchaseLines[0]['quantity'],
            ]);

            return true;
        });
        $transactionUtil->shouldReceive('createOrUpdatePaymentLines')->once()->withArgs(function ($transaction, $payments, $businessId, $userId, $ufData) {
            return $transaction instanceof Transaction
                && $payments === []
                && $businessId === 7
                && $userId === 900
                && $ufData === false;
        });
        $transactionUtil->shouldReceive('updatePaymentStatus')->once()->withArgs(function ($transactionId, $finalAmount) {
            return $transactionId > 0 && (float) $finalAmount === 3700.0;
        })->andReturn('due');
        $productUtil->shouldReceive('adjustStockOverSelling')->once()->withArgs(fn ($transaction) => $transaction instanceof Transaction && $transaction->type === 'purchase');
        $transactionUtil->shouldReceive('activityLog')->once()->withArgs(function ($transaction, $action, $before, $properties, $logChanges, $businessId) {
            return $transaction instanceof Transaction
                && $action === 'added'
                && $before === null
                && $properties === []
                && $logChanges === true
                && $businessId === 7;
        });

        $writer = new UltimatePosPurchaseWriter($productUtil, $transactionUtil);
        $result = $writer->write($this->authorizedUser(), $this->command([
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-ADAPTER-01', 'unit_acquisition_cost' => 1850],
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-ADAPTER-02', 'unit_acquisition_cost' => 1850],
        ]));

        $this->assertSame(2.0, $result['quantity']);
        $this->assertSame(2, (int) DB::table('purchase_lines')->where('transaction_id', $result['transaction_id'])->value('quantity'));
        $this->assertSame('recommerce', DB::table('transactions')->where('id', $result['transaction_id'])->value('source'));
        $this->assertSame('received', DB::table('transactions')->where('id', $result['transaction_id'])->value('status'));
        Event::assertDispatched(PurchaseCreatedOrModified::class);
    }

    public function test_ultimate_pos_writer_rejects_missing_business_date_context_before_transaction_creation(): void
    {
        $productUtil = Mockery::mock(ProductUtil::class);
        $transactionUtil = Mockery::mock(TransactionUtil::class);
        $productUtil->shouldReceive('uf_date')->once()->with('2026-08-27', true)->andReturn(null);
        $writer = new UltimatePosPurchaseWriter($productUtil, $transactionUtil);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('business-session date format');

        try {
            $writer->write($this->authorizedUser(), $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-DATE-01', 'unit_acquisition_cost' => 1850],
            ]));
        } finally {
            $this->assertSame(1, DB::table('transactions')->count());
        }
    }

    public function test_label_issue_returns_safe_payload_and_rotation_replaces_old_token(): void
    {
        $device = $this->receiveOneDevice();
        $tokenService = new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken());
        $response = (new LabelController())->issue(
            Request::create('/recommerce/devices/'.$device->id.'/label', 'POST'),
            $device->id,
            $tokenService,
            new LabelPayloadBuilder()
        );
        $responseData = $response->getData(true);
        $payload = $responseData['label'];

        $this->assertSame('v2.2-3', $payload['template_version']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('READY_TO_PRINT', $responseData['status']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($device->device_code, $payload['device_code']);
        $this->assertStringStartsWith('https://scan.saverbro.example/s/d/', $payload['qr_url']);
        $this->assertSame('Refurbished laptop', $payload['safe_description']);
        $this->assertArrayNotHasKey('raw_token', $payload);
        $this->assertArrayNotHasKey('token_hash', $payload);
        $this->assertSame(1, ScanToken::query()->where('status', 'ACTIVE')->count());
        $this->assertSame(64, strlen((string) DB::table('recommerce_scan_tokens')->value('token_hash')));
        $this->assertNotSame($payload['qr_url'], DB::table('recommerce_scan_tokens')->value('raw_token_encrypted'));
        $this->assertSame(2, DB::table('recommerce_device_events')->count());
        $this->assertSame('LABEL_TOKEN_ISSUED', DB::table('recommerce_device_events')->orderByDesc('id')->value('event_type'));
        $this->assertSame(2, DB::table('recommerce_outbox_messages')->count());

        $second = $tokenService->issue($this->authorizedUser(), $device->fresh(), true);

        $this->assertSame(64, strlen($second['raw_token']));
        $this->assertSame(1, ScanToken::query()->where('status', 'ACTIVE')->count());
        $this->assertSame(1, ScanToken::query()->where('status', 'REPLACED')->count());
        $this->assertSame('ROTATION', DB::table('recommerce_scan_tokens')->where('token_hash', (new OpaqueScanToken())->hash($second['raw_token']))->value('reason'));
        $this->assertSame(3, DB::table('recommerce_device_events')->count());
        $this->assertSame('LABEL_TOKEN_ROTATED', DB::table('recommerce_device_events')->orderByDesc('id')->value('event_type'));
        $this->assertSame(3, DB::table('recommerce_outbox_messages')->count());
        $this->assertStringNotContainsString($second['raw_token'], json_encode(DB::table('recommerce_device_events')->get()));
    }

    public function test_label_failure_after_receive_is_retryable_without_new_device(): void
    {
        $device = $this->receiveOneDevice();
        config(['recommerce.resolver_host' => '']);
        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/devices/'.$device->id.'/label');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Label request was rejected.', $response->getData(true)['message']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());
    }

    public function test_label_payload_builder_failure_rolls_back_issued_token_and_timeline_event(): void
    {
        $device = $this->receiveOneDevice();
        $failingBuilder = new class extends LabelPayloadBuilder
        {
            public function forDevice(Device $device, string $rawToken): array
            {
                throw new LogicException('payload builder unavailable');
            }
        };

        $response = (new LabelController())->issue(
            Request::create('/recommerce/devices/'.$device->id.'/label', 'POST'),
            $device->id,
            new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()),
            $failingBuilder
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Label request was rejected.', $response->getData(true)['message']);
        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());
        $this->assertSame(1, DB::table('recommerce_outbox_messages')->count());

        $retry = (new LabelController())->issue(
            Request::create('/recommerce/devices/'.$device->id.'/label', 'POST'),
            $device->id,
            new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()),
            new LabelPayloadBuilder()
        );

        $this->assertSame(200, $retry->getStatusCode());
        $this->assertSame('READY_TO_PRINT', $retry->getData(true)['status']);
        $this->assertSame(1, ScanToken::query()->count());
        $this->assertSame(2, DB::table('recommerce_device_events')->count());
        $this->assertSame(2, DB::table('recommerce_outbox_messages')->count());
    }

    public function test_label_renderer_failure_rolls_back_issued_token_and_timeline_event(): void
    {
        $this->app['view']->addNamespace(
            'recommerce',
            base_path('Modules/Recommerce/Resources/views')
        );
        $device = $this->receiveOneDevice();
        $failingRenderer = new class extends LabelRenderer
        {
            public function render(array $payload): array
            {
                throw new LogicException('renderer unavailable');
            }
        };

        $response = (new LabelController())->print(
            Request::create('/recommerce/devices/'.$device->id.'/label/print', 'POST'),
            $device->id,
            new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()),
            new LabelPayloadBuilder(),
            $failingRenderer
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Label request was rejected.', $response->getData(true)['message']);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());
        $this->assertSame(1, DB::table('recommerce_outbox_messages')->count());
        $this->assertSame(0, DB::table('recommerce_label_jobs')->count());
        $this->assertSame(0, DB::table('recommerce_label_job_items')->count());

        $retry = (new LabelController())->print(
            Request::create('/recommerce/devices/'.$device->id.'/label/print', 'POST'),
            $device->id,
            new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()),
            new LabelPayloadBuilder(),
            new LabelRenderer()
        );

        $this->assertSame(200, $retry->getStatusCode());
        $this->assertStringContainsString('aria-label="Opaque QR code"', $retry->getContent());
        $this->assertStringContainsString('aria-label="Code 128 barcode"', $retry->getContent());
        $this->assertSame(1, ScanToken::query()->count());
        $this->assertSame(2, DB::table('recommerce_device_events')->count());
        $this->assertSame(2, DB::table('recommerce_outbox_messages')->count());
        $this->assertSame(1, DB::table('recommerce_label_jobs')->count());
        $this->assertSame(1, DB::table('recommerce_label_job_items')->count());
        $labelJob = DB::table('recommerce_label_jobs')->first();
        $this->assertSame('PRINT_VIEW_OPENED', $labelJob->status);
        $this->assertSame(1, (int) $labelJob->item_count);
        $this->assertSame($device->device_code, json_decode($labelJob->request_json, true)['device_code']);
        $this->assertStringNotContainsString('raw_token', $labelJob->request_json);
    }

    public function test_label_issue_rechecks_locked_device_scope_before_token_commit(): void
    {
        $device = $this->receiveOneDevice();
        DB::table('business_locations')->insert(['id' => 999, 'business_id' => 7]);
        $staleDevice = $device->fresh();
        Device::query()->whereKey($device->id)->update(['current_location_id' => 999]);

        try {
            (new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()))
                ->issue($this->authorizedUser(), $staleDevice);
            $this->fail('Issuance should reject the locked Device after its scope changes.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Recommerce token issuance scope denied.', $exception->getMessage());
        }

        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());
    }

    public function test_label_print_path_records_atomic_print_and_reprint_evidence(): void
    {
        $this->app['view']->addNamespace(
            'recommerce',
            base_path('Modules/Recommerce/Resources/views')
        );
        $device = $this->receiveOneDevice();
        $response = (new LabelController())->print(
            Request::create('/recommerce/devices/'.$device->id.'/label/print', 'POST'),
            $device->id,
            new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()),
            new LabelPayloadBuilder(),
            new LabelRenderer()
        );

        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('aria-label="Opaque QR code"', $content);
        $this->assertStringContainsString('aria-label="Code 128 barcode"', $content);
        $this->assertStringContainsString($device->device_code, $content);
        $this->assertStringContainsString('Refurbished laptop', $content);
        $this->assertStringNotContainsString('/s/d/', $content);
        $this->assertStringNotContainsString('token_hash', $content);
        $this->assertStringNotContainsString('https://scan.saverbro.example/s/d/', $content);
        $this->assertStringContainsString('<svg', $content);
        $this->assertStringContainsString('@page { size: 50mm 20mm;', $content);
        $this->assertStringContainsString('.label { width: 50mm; min-height: 20mm;', $content);
        $this->assertMatchesRegularExpression('/aria-label="Opaque QR code">[\s\S]*?<svg width="164" height="164" viewBox="-16 -16 196 196"/', $content);
        $this->assertStringContainsString('.qr { width: 19mm; height: 19mm;', $content);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame(1, DB::table('recommerce_label_jobs')->count());
        $this->assertSame(1, DB::table('recommerce_label_job_items')->count());

        $firstItem = DB::table('recommerce_label_job_items')->first();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', ScanToken::query()->firstOrFail()->raw_token_encrypted);
        $reprint = (new LabelController())->print(
            Request::create('/recommerce/devices/'.$device->id.'/label/print', 'POST'),
            $device->id,
            new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()),
            new LabelPayloadBuilder(),
            new LabelRenderer()
        );

        $this->assertSame(200, $reprint->getStatusCode());
        $this->assertSame(2, DB::table('recommerce_label_jobs')->count());
        $this->assertSame(2, DB::table('recommerce_label_job_items')->count());
        $this->assertSame(1, DB::table('recommerce_label_job_items')->distinct()->count('scan_token_id'));
        $this->assertSame(
            1,
            DB::table('recommerce_label_job_items')->distinct()->count('device_id')
        );
        $reprintJob = DB::table('recommerce_label_jobs')->orderByDesc('id')->first();
        $this->assertSame('REPRINT', json_decode($reprintJob->request_json, true)['reason']);
        $this->assertStringNotContainsString('raw_token', $reprintJob->request_json);
        $this->assertSame($firstItem->scan_token_id, DB::table('recommerce_label_job_items')->orderByDesc('id')->value('scan_token_id'));
    }

    public function test_permanent_device_label_reprint_keeps_one_qr_identity_and_one_device(): void
    {
        $this->app['view']->addNamespace('recommerce', base_path('Modules/Recommerce/Resources/views'));
        (new RouteServiceProvider(app()))->map();

        $device = $this->receiveOneDevice();
        $deviceId = $device->id;
        $deviceCode = $device->device_code;
        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->post('/recommerce/devices/'.$deviceId.'/label/print')
            ->assertOk();

        $initialToken = ScanToken::query()->where('status', 'ACTIVE')->firstOrFail();
        $tokenId = $initialToken->id;
        $tokenValue = $initialToken->raw_token_encrypted;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokenValue);
        $this->assertSame($deviceId, (int) $initialToken->device_id);
        $this->assertSame('INITIAL_PRINT', json_decode(DB::table('recommerce_label_jobs')->value('request_json'), true)['reason']);

        $firstResolution = $this->actingAs($user)->postJson('/recommerce/scans/resolve', [
            'value' => 'https://scan.saverbro.example/s/d/'.$tokenValue,
        ]);
        $firstResolution->assertOk()->assertJsonPath('device_code', $deviceCode);

        $this->actingAs($user)
            ->postJson('/recommerce/devices/'.$deviceId.'/label/confirm')
            ->assertOk()->assertJsonPath('status', 'PRINT_CONFIRMED');

        $this->actingAs($user)
            ->post('/recommerce/devices/'.$deviceId.'/label/print')
            ->assertOk();

        $reprintToken = ScanToken::query()->where('status', 'ACTIVE')->firstOrFail();
        $this->assertSame($tokenId, $reprintToken->id);
        $this->assertSame($tokenValue, $reprintToken->raw_token_encrypted);
        $this->assertSame(1, ScanToken::query()->count());
        $this->assertSame(1, Device::query()->count());
        $this->assertSame($deviceCode, Device::query()->findOrFail($deviceId)->device_code);

        $secondResolution = $this->actingAs($user)->postJson('/recommerce/scans/resolve', [
            'value' => 'https://scan.saverbro.example/s/d/'.$reprintToken->raw_token_encrypted,
        ]);
        $secondResolution->assertOk()->assertJsonPath('device_code', $deviceCode);

        $jobs = DB::table('recommerce_label_jobs')->orderBy('id')->get();
        $this->assertCount(2, $jobs);
        $this->assertSame('PRINT_CONFIRMED', $jobs[0]->status);
        $this->assertSame('INITIAL_PRINT', json_decode($jobs[0]->request_json, true)['reason']);
        $this->assertSame('PRINT_VIEW_OPENED', $jobs[1]->status);
        $this->assertSame('REPRINT', json_decode($jobs[1]->request_json, true)['reason']);
        $this->assertSame(1, DB::table('recommerce_label_job_items')->distinct()->count('scan_token_id'));
        $this->assertSame(1, DB::table('recommerce_label_job_items')->distinct()->count('device_id'));
    }

    public function test_bulk_label_print_renders_one_safe_batch_and_reuses_permanent_qr_identities(): void
    {
        $this->app['view']->addNamespace('recommerce', base_path('Modules/Recommerce/Resources/views'));
        (new RouteServiceProvider(app()))->map();

        $first = $this->receiveOneDevice();
        $second = $this->availableDevice(DeviceCode::forDeviceId($first->id + 1), 101);
        $user = $this->authorizedUser();

        $this->actingAs($user)
            ->post('/recommerce/devices/'.$first->id.'/label/print')
            ->assertOk();
        $firstToken = ScanToken::query()->where('device_id', $first->id)->where('status', 'ACTIVE')->firstOrFail();

        $response = $this->actingAs($user)->post('/recommerce/devices/labels/print', [
            'device_ids' => [$first->id, $second->id],
        ]);

        $response->assertOk()
            ->assertSee($first->device_code, false)
            ->assertSee($second->device_code, false)
            ->assertSee('Print 2 SAVERBRO labels', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $content = $response->getContent();
        $this->assertStringContainsString('break-after: page', $content);
        $this->assertStringContainsString('aria-label="Opaque QR code"', $content);
        $this->assertStringContainsString('aria-label="Code 128 barcode"', $content);
        $this->assertStringNotContainsString($firstToken->raw_token_encrypted, $content);
        $this->assertStringNotContainsString('/s/d/', $content);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $batch = DB::table('recommerce_label_jobs')->orderByDesc('id')->first();
        $request = json_decode($batch->request_json, true);
        $this->assertSame('BULK_PRINT', $request['reason']);
        $this->assertSame(2, (int) $batch->item_count);
        $this->assertSame(1, $request['initial_issuance_count']);
        $this->assertSame(1, $request['reprint_count']);
        $this->assertSame([$first->device_code, $second->device_code], $request['device_codes']);
        $this->assertStringNotContainsString('raw_token', $batch->request_json);
        $this->assertSame('PRINT_VIEW_OPENED', $batch->status);
        $this->assertSame(2, DB::table('recommerce_label_job_items')->where('label_job_id', $batch->id)->count());
        $this->assertSame(2, ScanToken::query()->where('status', 'ACTIVE')->count());
        $this->assertSame($firstToken->id, ScanToken::query()->where('device_id', $first->id)->value('id'));
        $this->assertSame($firstToken->raw_token_encrypted, ScanToken::query()->where('device_id', $first->id)->value('raw_token_encrypted'));

        $resolution = $this->actingAs($user)->postJson('/recommerce/scans/resolve', [
            'value' => 'https://scan.saverbro.example/s/d/'.$firstToken->raw_token_encrypted,
        ]);
        $resolution->assertOk()->assertJsonPath('device_code', $first->device_code);
    }

    public function test_bulk_label_print_aborts_the_entire_selection_for_foreign_or_stale_devices(): void
    {
        $user = $this->authorizedUser();
        $this->actingAs($user);
        $allowed = $this->receiveOneDevice();

        DB::table('business')->insert(['id' => 8]);
        DB::table('business_locations')->insert(['id' => 801, 'business_id' => 8, 'name' => 'Foreign branch']);
        $foreign = Device::create([
            'business_id' => 8, 'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'device_code' => DeviceCode::forDeviceId($allowed->id + 1), 'ownership_kind' => 'BUSINESS', 'custody_kind' => 'LOCATION',
            'current_location_id' => 801, 'product_id' => 202, 'variation_id' => 303,
            'lifecycle_state' => 'AVAILABLE', 'stock_participation' => 'ON_HAND', 'lock_version' => 1,
            'created_by' => 900, 'updated_by' => 900,
        ]);

        try {
            $this->bulkLabelPrintService()->render($user, [$allowed->id, $foreign->id]);
            $this->fail('A bulk print may not render an authorized subset.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Recommerce label scope denied.', $exception->getMessage());
        }

        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(0, DB::table('recommerce_label_jobs')->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());

        DB::table('business_locations')->insert(['id' => 999, 'business_id' => 7, 'name' => 'No longer permitted']);
        Device::query()->whereKey($allowed->id)->update(['current_location_id' => 999]);

        try {
            $this->bulkLabelPrintService()->render($user, [$allowed->id]);
            $this->fail('A Device made inaccessible after Registry selection must be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Recommerce token issuance scope denied.', $exception->getMessage());
        }

        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(0, DB::table('recommerce_label_jobs')->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());
    }

    public function test_bulk_label_print_rechecks_print_permission_without_writing_any_subset(): void
    {
        $device = $this->receiveOneDevice();
        $deniedUser = new class extends User
        {
            public function can($ability, $arguments = []): bool
            {
                return false;
            }

            public function permitted_locations($business_id = null)
            {
                return [101, 102];
            }
        };
        $deniedUser->id = 900;
        $deniedUser->business_id = 7;
        $this->actingAs($deniedUser);

        try {
            $this->bulkLabelPrintService()->render($deniedUser, [$device->id]);
            $this->fail('A permission change must block bulk printing.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Recommerce token issuance scope denied.', $exception->getMessage());
        }

        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(0, DB::table('recommerce_label_jobs')->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());
    }

    public function test_bulk_label_print_returns_a_safe_error_for_an_invalid_selection(): void
    {
        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/devices/labels/print', ['device_ids' => ['not-a-device']]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Selected Devices could not be printed. Refresh the Registry and review their label status.')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(0, DB::table('recommerce_label_jobs')->count());
    }

    public function test_bulk_label_print_redirects_a_normal_browser_request_back_with_a_safe_error(): void
    {
        (new RouteServiceProvider(app()))->map();

        $this->actingAs($this->authorizedUser())
            ->post('/recommerce/devices/labels/print', ['device_ids' => ['not-a-device']])
            ->assertRedirect('/recommerce/devices')
            ->assertSessionHas('status', 'Selected Devices could not be printed. Refresh the Registry and review their label status.');

        $this->assertSame(0, ScanToken::query()->count());
        $this->assertSame(0, DB::table('recommerce_label_jobs')->count());
    }

    public function test_bulk_label_print_scales_bounded_batches_of_one_ten_and_fifty_without_per_device_reads(): void
    {
        $user = $this->authorizedUser();
        $this->actingAs($user);
        $service = $this->bulkLabelPrintService();

        foreach ([1, 10, 50] as $quantity) {
            $devices = [];
            for ($position = 0; $position < $quantity; $position++) {
                $nextId = (int) Device::query()->max('id') + 1;
                $devices[] = $this->availableDevice(DeviceCode::forDeviceId($nextId), 101);
            }

            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $result = $service->render($user, array_map(fn (Device $device): int => (int) $device->id, $devices));
            $queries = DB::connection()->getQueryLog();
            DB::connection()->disableQueryLog();

            $this->assertCount($quantity, $result['labels']);
            $deviceSelects = count(array_filter($queries, static function (array $query): bool {
                $sql = strtolower($query['query']);

                return str_starts_with($sql, 'select') && str_contains($sql, 'recommerce_devices');
            }));
            $tokenSelects = count(array_filter($queries, static function (array $query): bool {
                $sql = strtolower($query['query']);

                return str_starts_with($sql, 'select') && str_contains($sql, 'recommerce_scan_tokens');
            }));
            $this->assertSame(1, $deviceSelects, 'Bulk printing must load selected Devices in one scoped query.');
            $this->assertSame(1, $tokenSelects, 'Bulk printing must load active label tokens in one scoped query.');
        }

        $this->assertSame(3, DB::table('recommerce_label_jobs')->count());
        $this->assertSame(61, DB::table('recommerce_label_job_items')->count());
        $this->assertSame(61, ScanToken::query()->where('status', 'ACTIVE')->count());
    }

    public function test_enabled_label_print_route_runs_authenticated_http_path(): void
    {
        $this->app['view']->addNamespace(
            'recommerce',
            base_path('Modules/Recommerce/Resources/views')
        );
        $device = $this->receiveOneDevice();
        config([
            'app.url' => 'https://pos.kkcctv.com.my',
            'recommerce.resolver_host' => null,
        ]);
        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->post('/recommerce/devices/'.$device->id.'/label/print');

        $response->assertOk()
            ->assertSee($device->device_code, false)
            ->assertSee('aria-label="Opaque QR code"', false)
            ->assertSee('aria-label="Code 128 barcode"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('/s/d/', $response->getContent());
        $this->assertSame(1, ScanToken::query()->where('status', 'ACTIVE')->count());
    }

    public function test_scan_resolves_human_code_and_active_qr_but_not_replaced_token(): void
    {
        $device = $this->receiveOneDevice();
        $opaqueToken = new OpaqueScanToken();
        $tokenService = new ScanTokenIssuanceService($this->gate(), $opaqueToken);
        $issued = $tokenService->issue($this->authorizedUser(), $device);
        $controller = new ScanController();

        $humanResponse = $controller->resolve(
            Request::create('/recommerce/scans/resolve', 'POST', ['value' => $device->device_code]),
            $this->gate(),
            $opaqueToken
        );
        $qrResponse = $controller->resolve(
            Request::create('/recommerce/scans/resolve', 'POST', ['value' => 'https://scan.saverbro.example/s/d/'.$issued['raw_token']]),
            $this->gate(),
            $opaqueToken
        );
        $serialResponse = $controller->resolve(
            Request::create('/recommerce/scans/resolve', 'POST', ['value' => 'SN-LABEL-01']),
            $this->gate(),
            $opaqueToken
        );
        $invalidResponse = $controller->resolve(
            Request::create('/recommerce/scans/resolve', 'POST', ['value' => 'https://other.example/s/d/'.$issued['raw_token']]),
            $this->gate(),
            $opaqueToken
        );

        $this->assertSame(200, $humanResponse->getStatusCode());
        $this->assertSame(200, $qrResponse->getStatusCode());
        $this->assertSame(200, $serialResponse->getStatusCode());
        $this->assertSame(404, $invalidResponse->getStatusCode());
        $this->assertSame(
            'No matching Device was found in your authorized scope. Check the code or QR label, then try the serial, IMEI, or service tag. No Device was created.',
            $invalidResponse->getData(true)['message']
        );
        $this->assertSame('no-referrer', $humanResponse->headers->get('Referrer-Policy'));
        $this->assertSame('no-referrer', $qrResponse->headers->get('Referrer-Policy'));
        $this->assertSame($device->device_code, $qrResponse->getData(true)['device_code']);
        $this->assertSame($device->device_code, $serialResponse->getData(true)['device_code']);
        $this->assertSame('VIEW_DEVICE', $qrResponse->getData(true)['actions'][0]['key']);
        $this->assertStringNotContainsString($issued['raw_token'], json_encode($qrResponse->getData(true)));

        $tokenService->issue($this->authorizedUser(), $device->fresh(), true);
        $replacedResponse = $controller->resolve(
            Request::create('/recommerce/scans/resolve', 'POST', ['value' => 'https://scan.saverbro.example/s/d/'.$issued['raw_token']]),
            $this->gate(),
            $opaqueToken
        );

        $this->assertSame(404, $replacedResponse->getStatusCode());
    }

    public function test_all_permanent_and_manufacturer_identities_resolve_the_same_device_exactly(): void
    {
        $device = $this->availableDevice('SB-DV-00000041-3', 101);
        foreach ([['SERIAL', 'ABC1234'], ['IMEI', '356938035643809'], ['SERVICE_TAG', 'DELL-5420-01']] as [$type, $value]) {
            DeviceIdentifier::create([
                'device_id' => $device->id, 'business_id' => 7, 'identifier_type' => $type,
                'raw_value_encrypted' => StrongIdentifierHasher::normalize($value),
                'normalized_hash' => StrongIdentifierHasher::hash(StrongIdentifierHasher::normalize($value)),
                'is_verified' => false,
            ]);
        }
        $tokens = new OpaqueScanToken();
        $issued = (new ScanTokenIssuanceService($this->gate(), $tokens))->issue($this->authorizedUser(), $device);
        $resolver = new DeviceIdentityResolver($tokens);

        foreach ([
            $device->device_code,
            ' abc-1234 ',
            '356938035643809',
            'dell_5420-01',
            'https://scan.saverbro.example/s/d/'.$issued['raw_token'],
        ] as $identity) {
            $this->assertSame($device->id, $resolver->resolve(7, $identity)?->id);
        }
        $this->assertNull($resolver->resolve(7, 'ABC1235'), 'Exact identifier lookup must not fuzzy-match a serial.');
    }

    public function test_pos_search_resolves_qr_label_barcode_serial_and_imei_to_one_sellable_device(): void
    {
        $device = $this->availableDevice('SB-DV-00000045-7', 101);
        foreach ([['SERIAL', 'POS-SERIAL-45'], ['IMEI', '356938035643811']] as [$type, $value]) {
            DeviceIdentifier::create([
                'device_id' => $device->id,
                'business_id' => 7,
                'identifier_type' => $type,
                'raw_value_encrypted' => StrongIdentifierHasher::normalize($value),
                'normalized_hash' => StrongIdentifierHasher::hash(StrongIdentifierHasher::normalize($value)),
                'is_verified' => false,
            ]);
        }

        $tokens = new OpaqueScanToken();
        $issued = (new ScanTokenIssuanceService($this->gate(), $tokens))->issue($this->authorizedUser(), $device);
        $controller = new PosDeviceLookupController();

        foreach ([
            $device->device_code, // Label Code128 and human-readable ID.
            'pos-serial-45',
            '356938035643811',
            'https://scan.saverbro.example/s/d/'.$issued['raw_token'],
        ] as $identity) {
            $response = $controller->resolve(
                Request::create('/recommerce/pos/resolve-device', 'POST', [
                    'value' => $identity,
                    'location_id' => 101,
                ]),
                $this->gate(),
                new DeviceIdentityResolver($tokens)
            );

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame([
                'status' => 'MATCHED',
                'variation_id' => 303,
                'device_code' => $device->device_code,
            ], $response->getData(true));
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        }

        $this->assertSame(1, Device::query()->count(), 'POS lookup must not create another Device.');
        $this->assertSame('AVAILABLE', $device->fresh()->lifecycle_state);
    }

    public function test_pos_search_hides_unknown_or_out_of_branch_device_identity(): void
    {
        $device = $this->availableDevice('SB-DV-00000046-5', 102);
        $controller = new PosDeviceLookupController();

        $unknown = $controller->resolve(
            Request::create('/recommerce/pos/resolve-device', 'POST', [
                'value' => 'SB-DV-00000001-9',
                'location_id' => 101,
            ]),
            $this->gate(),
            new DeviceIdentityResolver(new OpaqueScanToken())
        );
        $outOfBranch = $controller->resolve(
            Request::create('/recommerce/pos/resolve-device', 'POST', [
                'value' => $device->device_code,
                'location_id' => 101,
            ]),
            $this->gate(),
            new DeviceIdentityResolver(new OpaqueScanToken())
        );

        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame('DEVICE_NOT_REGISTERED', $unknown->getData(true)['code']);
        $this->assertSame(
            'SaverBro Device ID SB-DV-00000001-9 is not registered. Register it through Purchase Receiving before sale.',
            $unknown->getData(true)['message']
        );
        $this->assertSame(422, $outOfBranch->getStatusCode());
        $this->assertSame(
            'This Device is held at a different branch. Switch the POS branch or complete a Device transfer before sale.',
            $outOfBranch->getData(true)['message']
        );
        $this->assertStringNotContainsString($device->device_code, $outOfBranch->getContent());
    }

    public function test_sale_selection_resolves_serial_imei_and_qr_through_the_same_exact_device_validator(): void
    {
        $serialDevice = $this->availableDevice('SB-DV-00000042-1', 101);
        $imeiDevice = $this->availableDevice('SB-DV-00000043-X', 101);
        $qrDevice = $this->availableDevice('SB-DV-00000044-9', 101);
        foreach ([[$serialDevice, 'SERIAL', 'SALE-SERIAL-01'], [$imeiDevice, 'IMEI', '356938035643810']] as [$device, $type, $value]) {
            DeviceIdentifier::create([
                'device_id' => $device->id, 'business_id' => 7, 'identifier_type' => $type,
                'raw_value_encrypted' => StrongIdentifierHasher::normalize($value),
                'normalized_hash' => StrongIdentifierHasher::hash(StrongIdentifierHasher::normalize($value)),
                'is_verified' => false,
            ]);
        }
        $tokens = new OpaqueScanToken();
        $issued = (new ScanTokenIssuanceService($this->gate(), $tokens))->issue($this->authorizedUser(), $qrDevice);
        $sale = Transaction::create(['business_id' => 7, 'location_id' => 101, 'type' => 'sell', 'status' => 'final', 'contact_id' => 405, 'transaction_date' => now(), 'created_by' => 900]);
        $sale->sell_lines()->create(['product_id' => 202, 'variation_id' => 303, 'quantity' => 3, 'unit_price' => 2000, 'unit_price_inc_tax' => 2000]);

        (new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder(), new DeviceIdentityResolver($tokens)))
            ->synchroniseFinalSale($this->authorizedUser(), $sale->fresh(), [[
                'product_id' => 202, 'variation_id' => 303,
                'recommerce_device_codes' => 'SALE-SERIAL-01 356938035643810 https://scan.saverbro.example/s/d/'.$issued['raw_token'],
            ]]);

        $this->assertSame('SOLD', $serialDevice->fresh()->lifecycle_state);
        $this->assertSame('SOLD', $imeiDevice->fresh()->lifecycle_state);
        $this->assertSame('SOLD', $qrDevice->fresh()->lifecycle_state);
        $this->assertSame(3, DeviceSaleDisposition::query()->where('sale_transaction_id', $sale->id)->count());
        $this->assertSame(3, Device::query()->count(), 'POS resolution must not create another Device.');
    }

    public function test_unknown_pos_manufacturer_identifier_cannot_create_or_select_a_device(): void
    {
        $sale = Transaction::create(['business_id' => 7, 'location_id' => 101, 'type' => 'sell', 'status' => 'final', 'contact_id' => 405, 'transaction_date' => now(), 'created_by' => 900]);
        $sale->sell_lines()->create(['product_id' => 202, 'variation_id' => 303, 'quantity' => 1, 'unit_price' => 2000, 'unit_price_inc_tax' => 2000]);

        try {
            (new DeviceLifecycleService($this->gate(), new \Modules\Recommerce\Services\DeviceEventRecorder()))
                ->synchroniseFinalSale($this->authorizedUser(), $sale->fresh(), [[
                    'product_id' => 202, 'variation_id' => 303, 'recommerce_device_codes' => 'UNKNOWN-SERIAL-01',
                ]]);
            $this->fail('POS must not create an unknown Device from a manufacturer identifier.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('No registered Device matches this QR, SaverBro Device ID, serial, or IMEI.', $exception->getMessage());
        }

        $this->assertSame(0, Device::query()->count());
        $this->assertSame(0, DeviceSaleDisposition::query()->count());
    }

    public function test_label_attachment_confirmation_is_auditable_without_changing_device_identity(): void
    {
        $this->app['view']->addNamespace('recommerce', base_path('Modules/Recommerce/Resources/views'));
        $device = $this->receiveOneDevice();
        (new RouteServiceProvider(app()))->map();

        $this->actingAs($this->authorizedUser())
            ->post('/recommerce/devices/'.$device->id.'/label/print')
            ->assertOk();

        $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/devices/'.$device->id.'/label/confirm')
            ->assertOk()
            ->assertJsonPath('status', 'PRINT_CONFIRMED');

        $this->assertSame('PRINT_CONFIRMED', DB::table('recommerce_label_jobs')->value('status'));
        $this->assertSame('PRINT_CONFIRMED', DB::table('recommerce_label_job_items')->value('status'));
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, ScanToken::query()->where('status', 'ACTIVE')->count());
    }

    public function test_qr_opens_internal_detail_for_staff_and_a_customer_safe_certificate_for_public_scans(): void
    {
        $this->app['view']->addNamespace(
            'recommerce',
            base_path('Modules/Recommerce/Resources/views')
        );
        (new RouteServiceProvider(app()))->map();
        config(['recommerce.public_warranty_service_url' => 'https://support.saverbro.example/warranty']);

        $device = $this->receiveOneDevice();
        $device->update([
            'lifecycle_state' => 'SOLD',
            'sold_at' => '2026-08-11 09:30:00',
            'manufacturer_serial_display' => 'PF82AXXX',
        ]);
        $sale = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell', 'status' => 'final',
            'contact_id' => 405, 'transaction_date' => '2026-08-11 09:30:00', 'created_by' => 900,
        ]);
        $sellLine = $sale->sell_lines()->create([
            'product_id' => 202, 'variation_id' => 303, 'quantity' => 1,
            'unit_price' => 2500, 'unit_price_inc_tax' => 2500,
        ]);
        DeviceSaleDisposition::create([
            'device_id' => $device->id, 'business_id' => 7, 'sale_transaction_id' => $sale->id,
            'sell_line_id' => $sellLine->id, 'customer_contact_id' => 405,
            'sold_at' => '2026-08-11 09:30:00', 'active_sale_key' => $device->id, 'recorded_by' => 900,
        ]);
        $certificateService = new DeviceCertificationService($this->gate());
        $certificateService->publish($this->authorizedUser(), $device->fresh(), [
            'grade' => 'A',
            'qc_passed' => true,
            'battery_health_percent' => 91,
            'purchased_at' => '2026-08-11',
            'warranty_expires_at' => '2027-08-10',
        ]);
        $opaqueToken = new OpaqueScanToken();
        $issued = (new ScanTokenIssuanceService($this->gate(), $opaqueToken))
            ->issue($this->authorizedUser(), $device->fresh());
        $controller = new ScanController();

        Auth::logout();
        $public = $controller->device($issued['raw_token'], $opaqueToken, $this->gate(), $certificateService);
        $content = $public->getContent();

        $this->assertSame(200, $public->getStatusCode());
        $this->assertStringContainsString('SaverBro Certified Device', $content);
        $this->assertStringContainsString('Refurbished laptop', $content);
        $this->assertStringContainsString('****AXXX', $content);
        $this->assertStringContainsString('Battery health', $content);
        $this->assertStringContainsString('91%', $content);
        $this->assertStringContainsString('Valid until 10 Aug 2027', $content);
        $this->assertStringContainsString('Request Warranty Service', $content);
        $this->assertStringNotContainsString($device->device_code, $content);
        $this->assertStringNotContainsString($issued['raw_token'], $content);
        $this->assertStringNotContainsString('1850', $content);
        $this->assertSame('noindex, nofollow, noarchive', $public->headers->get('X-Robots-Tag'));

        Auth::setUser($this->authorizedUser());
        $staff = $controller->device($issued['raw_token'], $opaqueToken, $this->gate(), $certificateService);
        $this->assertSame(302, $staff->getStatusCode());
        $this->assertStringContainsString('/recommerce/devices/'.$device->device_code, $staff->headers->get('Location'));
    }

    public function test_unpublished_or_unknown_public_qr_shows_the_same_neutral_no_data_page(): void
    {
        $this->app['view']->addNamespace('recommerce', base_path('Modules/Recommerce/Resources/views'));
        (new RouteServiceProvider(app()))->map();

        $device = $this->receiveOneDevice();
        $issued = (new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()))
            ->issue($this->authorizedUser(), $device);

        Auth::logout();
        $unpublished = $this->get('/s/d/'.$issued['raw_token']);
        $unknown = $this->get('/s/d/'.str_repeat('f', 64));

        foreach ([$unpublished, $unknown] as $response) {
            $response->assertNotFound()
                ->assertSee('A public Device Passport is not available for this label.')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString($device->device_code, $response->getContent());
            $this->assertStringNotContainsString($issued['raw_token'], $response->getContent());
            $this->assertStringNotContainsString('1850', $response->getContent());
        }

        $this->assertSame($unpublished->getContent(), $unknown->getContent());
    }

    public function test_device_event_timeline_is_scoped_and_safe_over_http(): void
    {
        $device = $this->receiveOneDevice();
        $issued = (new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()))
            ->issue($this->authorizedUser(), $device);

        (new RouteServiceProvider(app()))->map();
        $response = $this->actingAs($this->authorizedUser())
            ->getJson('/recommerce/devices/'.$device->device_code.'/events');
        $data = $response->getData(true);

        $response->assertOk()
            ->assertJsonPath('device_code', $device->device_code)
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.event_uuid', $device->events()->orderBy('id')->first()->event_uuid)
            ->assertJsonPath('events.0.event_version', 1)
            ->assertJsonPath('events.0.event_type', 'RECEIVE_POSTED')
            ->assertJsonPath('events.1.event_type', 'LABEL_TOKEN_ISSUED')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('SN-LABEL-01', json_encode($data));
        $this->assertStringNotContainsString($issued['raw_token'], json_encode($data));
        $this->assertArrayNotHasKey('raw_value_encrypted', $data['events'][0]['metadata']);

        DeviceEvent::create([
            'device_id' => $device->id,
            'business_id' => 7,
            'actor_id' => 900,
            'event_type' => 'OUT_OF_SCOPE_HISTORY',
            'metadata_json' => ['location_id' => 999, 'device_code' => $device->device_code],
            'occurred_at' => now()->subHour(),
        ]);

        $scopedResponse = $this->actingAs($this->authorizedUser())
            ->getJson('/recommerce/devices/'.$device->device_code.'/events');

        $scopedResponse->assertOk()
            ->assertJsonCount(3, 'events');
        $this->assertContains(
            'OUT_OF_SCOPE_HISTORY',
            array_column($scopedResponse->json('events'), 'event_type')
        );
    }

    public function test_reconciliation_is_read_only_and_distinguishes_pass_mismatch_exception_and_unavailable(): void
    {
        $this->receiveTwoDevices();
        DB::table('variation_location_details')->insert([
            'id' => 808,
            'product_id' => 202,
            'location_id' => 101,
            'variation_id' => 303,
            'qty_available' => 2,
        ]);

        $service = new StockReconciliationService($this->gate());
        $user = $this->authorizedUser();
        $deviceCount = Device::query()->count();
        $pass = $service->forVariation($user, 7, 101, 303);

        $this->assertSame('PASS', $pass['status']);
        $this->assertSame(2.0, $pass['core_quantity']);
        $this->assertSame(2, $pass['tracked_device_count']);
        $this->assertSame(0, $pass['in_transfer_device_count']);
        $this->assertSame('APPROVED_PROFILE', $pass['reconciliation_evidence_status']);
        $this->assertSame(909, $pass['serialization_profile_id']);
        $this->assertNull($pass['legacy_balance_id']);

        Device::create([
            'business_id' => 7,
            'device_uuid' => '00000000-0000-4000-8000-000000000003',
            'device_code' => 'SB-DV-00000003-5',
            'ownership_kind' => 'BUSINESS',
            'custody_kind' => 'LOCATION',
            'product_id' => 202,
            'variation_id' => 303,
            'lifecycle_state' => 'RECEIVED',
            'stock_participation' => 'IN_TRANSFER',
        ]);
        $exception = $service->forVariation($user, 7, 101, 303);
        $this->assertSame('EXCEPTION', $exception['status']);
        $this->assertSame(2, $exception['tracked_device_count']);
        $this->assertSame(1, $exception['in_transfer_device_count']);

        Device::query()->where('stock_participation', 'IN_TRANSFER')->update([
            'stock_participation' => 'ON_HAND',
            'current_location_id' => 101,
        ]);
        DB::table('variation_location_details')->where('id', 808)->update(['qty_available' => 1]);
        $mismatch = $service->forVariation($user, 7, 101, 303);
        $this->assertSame('MISMATCH', $mismatch['status']);
        $this->assertSame(-2.0, $mismatch['difference']);

        DB::table('variation_location_details')->where('id', 808)->delete();
        $unavailable = $service->forVariation($user, 7, 101, 303);
        $this->assertSame('UNAVAILABLE', $unavailable['status']);
        $this->assertNull($unavailable['core_quantity']);
        $this->assertNull($unavailable['difference']);
        $this->assertSame('APPROVED_PROFILE', $unavailable['reconciliation_evidence_status']);
        $this->assertSame($deviceCount + 1, Device::query()->count());
    }

    public function test_reconciliation_controller_returns_scoped_read_only_result(): void
    {
        $this->receiveOneDevice();
        DB::table('variation_location_details')->insert([
            'id' => 818,
            'product_id' => 202,
            'location_id' => 101,
            'variation_id' => 303,
            'qty_available' => 1,
        ]);

        $response = (new ReconciliationController())->show(
            Request::create('/recommerce/reconciliation/303', 'GET', [
                'location_id' => 101,
            ]),
            303,
            new StockReconciliationService($this->gate())
        );
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('PASS', $data['status']);
        $this->assertSame(1, $data['core_quantity']);
        $this->assertSame(1, $data['tracked_device_count']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertArrayNotHasKey('actions', $data);
        $this->assertSame(1, DB::table('recommerce_devices')->count());
        $this->assertSame(1, DB::table('variation_location_details')->count());
    }

    public function test_reconciliation_run_records_signed_snapshot_and_issue_without_stock_mutation(): void
    {
        $this->receiveOneDevice();
        DB::table('variation_location_details')->insert([
            'id' => 817,
            'product_id' => 202,
            'location_id' => 101,
            'variation_id' => 303,
            'qty_available' => 1,
        ]);

        $service = new ReconciliationRunService(
            $this->gate(),
            new StockReconciliationService($this->gate())
        );
        $user = $this->authorizedUser();
        $deviceCount = DB::table('recommerce_devices')->count();
        $passResponse = (new ReconciliationController())->store(
            Request::create('/recommerce/reconciliation/303/runs', 'POST', ['location_id' => 101]),
            303,
            $service
        );
        $pass = $passResponse->getData(true);

        $this->assertSame(201, $passResponse->getStatusCode());
        $this->assertSame('PASS', $pass['status']);
        $this->assertNull($pass['issue_id']);
        $this->assertSame(1, DB::table('recommerce_reconciliation_runs')->where('status', 'PASS')->count());
        $this->assertSame(0, DB::table('recommerce_reconciliation_issues')->count());
        $passRun = DB::table('recommerce_reconciliation_runs')->where('run_uuid', $pass['run_uuid'])->first();
        $this->assertSame(64, strlen($passRun->result_hash));
        $this->assertSame($pass['result_hash'], $passRun->result_hash);
        $this->assertSame('PASS', json_decode($passRun->snapshot_json, true)['status']);

        DB::table('variation_location_details')->where('id', 817)->update(['qty_available' => 2]);
        $mismatch = $service->record($user, 7, 101, 303);

        $this->assertSame('MISMATCH', $mismatch['status']);
        $this->assertSame('STOCK_MISMATCH', DB::table('recommerce_reconciliation_issues')->value('issue_type'));
        $this->assertSame('BLOCKING', DB::table('recommerce_reconciliation_issues')->value('severity'));
        $this->assertSame(2, DB::table('recommerce_reconciliation_runs')->count());
        $this->assertSame(1, DB::table('recommerce_reconciliation_issues')->count());
        $this->assertSame('PASS', DB::table('recommerce_reconciliation_runs')->where('run_uuid', $pass['run_uuid'])->value('status'));
        $this->assertSame($deviceCount, DB::table('recommerce_devices')->count());
        $this->assertEquals(2, DB::table('variation_location_details')->where('id', 817)->value('qty_available'));
    }

    public function test_reconciliation_run_requires_the_separate_record_permission(): void
    {
        config([
            'recommerce.permissions' => [
                'recommerce.stock.reconcile',
            ],
        ]);

        $service = new ReconciliationRunService(
            $this->gate(),
            new StockReconciliationService($this->gate())
        );

        try {
            $service->record($this->authorizedUser(), 7, 101, 303);
            $this->fail('Recording reconciliation evidence should require its own permission.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('scope denied', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('recommerce_reconciliation_runs')->count());
    }

    public function test_tracked_receive_stops_on_existing_reconciliation_exception_before_core_write(): void
    {
        $this->receiveOneDevice();
        DB::table('variation_location_details')->insert([
            'id' => 819,
            'product_id' => 202,
            'location_id' => 101,
            'variation_id' => 303,
            'qty_available' => 2,
        ]);

        $writerCalls = 0;
        $service = new TrackedReceivingService(
            $this->gate(),
            null,
            null,
            new StockReconciliationService($this->gate())
        );

        try {
            $service->execute(
                $this->authorizedUser(),
                $this->command([
                    ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-BLOCKED-01', 'unit_acquisition_cost' => 1850],
                ], '55555555-5555-4555-8555-555555555555'),
                function () use (&$writerCalls): array {
                    $writerCalls++;

                    return $this->coreReceipt(1);
                }
            );
            $this->fail('A mismatched tracked receive should be blocked.');
        } catch (ReceivingReconciliationBlockedException $exception) {
            $this->assertStringContainsString('reconciliation exception', $exception->getMessage());
        }

        $this->assertSame(0, $writerCalls);
        $this->assertSame(1, DB::table('recommerce_stock_commands')->count());
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('variation_location_details')->count());
    }

    public function test_reconciliation_is_unavailable_without_approved_persisted_evidence(): void
    {
        $this->receiveOneDevice();
        DB::table('variation_location_details')->insert([
            'id' => 819,
            'product_id' => 202,
            'location_id' => 101,
            'variation_id' => 303,
            'qty_available' => 2,
        ]);
        DB::table('recommerce_serialization_profiles')->delete();

        $result = (new StockReconciliationService($this->gate()))
            ->forVariation($this->authorizedUser(), 7, 101, 303);

        $this->assertSame('UNAVAILABLE', $result['status']);
        $this->assertSame('MISSING_PROFILE', $result['reconciliation_evidence_status']);
        $this->assertNull($result['approved_legacy_balance']);
        $this->assertNull($result['difference']);
    }

    public function test_reconciliation_uses_only_the_scoped_persisted_legacy_balance(): void
    {
        $this->receiveOneDevice();
        DB::table('variation_location_details')->insert([
            'id' => 820,
            'product_id' => 202,
            'location_id' => 101,
            'variation_id' => 303,
            'qty_available' => 2.5,
        ]);
        DB::table('recommerce_serialization_profiles')->where('id', 909)->update([
            'mode' => 'LEGACY_MIXED',
        ]);
        DB::table('recommerce_legacy_stock_balances')->insert([
            'id' => 919,
            'serialization_profile_id' => 909,
            'business_id' => 7,
            'location_id' => 101,
            'variation_id' => 303,
            'legacy_unserialized_qty' => 1.5,
            'approved_at' => '2026-08-27 00:00:00',
            'approved_by' => null,
            'evidence_reference' => 'TEST-BALANCE-303-101',
        ]);

        $incomplete = (new StockReconciliationService($this->gate()))
            ->forVariation($this->authorizedUser(), 7, 101, 303);
        $this->assertSame('UNAVAILABLE', $incomplete['status']);
        $this->assertSame('INCOMPLETE_BALANCE', $incomplete['reconciliation_evidence_status']);

        DB::table('recommerce_legacy_stock_balances')->where('id', 919)->update(['approved_by' => 900]);
        $result = (new StockReconciliationService($this->gate()))
            ->forVariation($this->authorizedUser(), 7, 101, 303);

        $this->assertSame('PASS', $result['status']);
        $this->assertSame('APPROVED_BALANCE', $result['reconciliation_evidence_status']);
        $this->assertSame(1.5, $result['approved_legacy_balance']);
        $this->assertSame(919, $result['legacy_balance_id']);
        $this->assertSame(0.0, $result['difference']);

        DB::table('variation_location_details')->where('id', 820)->update(['qty_available' => 3.5]);
        $mismatch = (new StockReconciliationService($this->gate()))
            ->forVariation($this->authorizedUser(), 7, 101, 303);

        $this->assertSame('MISMATCH', $mismatch['status']);
        $this->assertSame(1.0, $mismatch['difference']);
    }

    public function test_reconciliation_controller_hides_out_of_scope_result(): void
    {
        $user = $this->authorizedUser();
        $user->business_id = 8;
        Auth::setUser($user);

        try {
            (new ReconciliationController())->show(
                Request::create('/recommerce/reconciliation/303', 'GET', [
                    'location_id' => 101,
                ]),
                303,
                new StockReconciliationService($this->gate())
            );
            $this->fail('Out-of-scope reconciliation should not return a result.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_reconciliation_controller_returns_generic_no_store_rejection(): void
    {
        $service = Mockery::mock(StockReconciliationService::class);
        $service->shouldReceive('forVariation')
            ->once()
            ->andThrow(new InvalidArgumentException('Do not expose internal validation detail.'));

        $response = (new ReconciliationController())->show(
            Request::create('/recommerce/reconciliation/303', 'GET', [
                'location_id' => 101,
            ]),
            303,
            $service
        );
        $data = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Reconciliation request was rejected.', $data['message']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_receiving_controller_posts_only_business_scoped_command(): void
    {
        $service = Mockery::mock(TrackedReceivingService::class);
        $service->shouldReceive('executeWithUltimatePosPurchase')
            ->once()
            ->withArgs(function (User $user, array $command): bool {
                return $user->business_id === 7
                    && $command['business_id'] === 7
                    && $command['location_id'] === 101
                    && $command['variation_id'] === 303;
            })
            ->andReturn([
                'command_uuid' => '11111111-1111-4111-8111-111111111111',
                'transaction_id' => 606,
                'purchase_line_id' => 707,
                'unit_count' => 1,
                'devices' => [],
            ]);

        $response = (new ReceivingController())->post(
            Request::create('/recommerce/receiving/post', 'POST', $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-CONTROLLER-01', 'unit_acquisition_cost' => 1850],
            ])),
            $service
        );
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('RECEIVED_TRACKED', $data['status']);
        $this->assertSame(606, $data['result']['transaction_id']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_receiving_index_returns_prepare_only_page_for_authorized_cohort(): void
    {
        config(['recommerce.writes_enabled' => false]);
        $authorizationGate = Mockery::mock(AuthorizationGate::class);
        $authorizationGate->shouldReceive('allowsRead')
            ->once()
            ->withArgs(fn ($user, string $permission, int $businessId, int $locationId): bool => $permission === 'recommerce.inspection.view'
                && $businessId === 7
                && $locationId === 101)
            ->andReturn(false);

        $viewName = null;
        $viewData = null;
        $responseFactory = Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $responseFactory->shouldReceive('view')
            ->once()
            ->withArgs(function (string $name, array $data) use (&$viewName, &$viewData): bool {
                $viewName = $name;
                $viewData = $data;

                return true;
            })
            ->andReturn(app(\Illuminate\Contracts\Routing\ResponseFactory::class)->make('captured'));
        $this->app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $responseFactory);

        $response = (new ReceivingController())->index($authorizationGate);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('recommerce::receiving.index', $viewName);
        $this->assertSame(7, $viewData['businessId']);
        $this->assertSame(101, $viewData['locationId']);
        $this->assertFalse($viewData['postEnabled']);
        $this->assertFalse($viewData['reconciliationRecordEnabled']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_receiving_controller_returns_generic_no_store_rejection(): void
    {
        $service = Mockery::mock(TrackedReceivingService::class);
        $service->shouldReceive('executeWithUltimatePosPurchase')
            ->once()
            ->andThrow(new LogicException('Do not expose internal receiving detail.'));

        $response = (new ReceivingController())->post(
            Request::create('/recommerce/receiving/post', 'POST', $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-CONTROLLER-02', 'unit_acquisition_cost' => 1850],
            ])),
            $service
        );
        $data = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Receiving command was rejected.', $data['message']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_receiving_controller_returns_conflict_only_for_in_progress_command(): void
    {
        $service = Mockery::mock(TrackedReceivingService::class);
        $service->shouldReceive('executeWithUltimatePosPurchase')
            ->once()
            ->andThrow(new ReceivingInProgressException('Internal state is not exposed.'));

        $response = (new ReceivingController())->post(
            Request::create('/recommerce/receiving/post', 'POST', $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-CONTROLLER-03', 'unit_acquisition_cost' => 1850],
            ])),
            $service
        );
        $data = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Receiving command is already being processed.', $data['message']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_receiving_controller_returns_stop_line_conflict_for_reconciliation_exception(): void
    {
        $service = Mockery::mock(TrackedReceivingService::class);
        $service->shouldReceive('executeWithUltimatePosPurchase')
            ->once()
            ->andThrow(new ReceivingReconciliationBlockedException('Internal reconciliation detail is not exposed.'));

        $response = (new ReceivingController())->post(
            Request::create('/recommerce/receiving/post', 'POST', $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-CONTROLLER-STOP-01', 'unit_acquisition_cost' => 1850],
            ])),
            $service
        );
        $data = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Receiving is blocked until reconciliation is resolved.', $data['message']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_receiving_controller_does_not_relabel_unexpected_runtime_failure_as_conflict(): void
    {
        $service = Mockery::mock(TrackedReceivingService::class);
        $service->shouldReceive('executeWithUltimatePosPurchase')
            ->once()
            ->andThrow(new \RuntimeException('Core purchase failure.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Core purchase failure.');

        (new ReceivingController())->post(
            Request::create('/recommerce/receiving/post', 'POST', $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-CONTROLLER-04', 'unit_acquisition_cost' => 1850],
            ])),
            $service
        );
    }

    public function test_enabled_reconciliation_route_runs_authenticated_http_path(): void
    {
        $this->receiveOneDevice();
        DB::table('variation_location_details')->insert([
            'id' => 828,
            'product_id' => 202,
            'location_id' => 101,
            'variation_id' => 303,
            'qty_available' => 1,
        ]);

        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->getJson('/recommerce/reconciliation/303?location_id=101');

        $response->assertOk()
            ->assertJsonPath('status', 'PASS')
            ->assertJsonPath('tracked_device_count', 1)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $recorded = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/reconciliation/303/runs', ['location_id' => 101]);

        $recorded->assertCreated()
            ->assertJsonPath('status', 'PASS')
            ->assertJsonPath('result.status', 'PASS')
            ->assertJsonMissingPath('result.raw_identifier')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $recorded->headers->get('Cache-Control'));
        $this->assertSame(1, DB::table('recommerce_reconciliation_runs')->count());
        $this->assertSame(0, DB::table('recommerce_reconciliation_issues')->count());
    }

    public function test_reconciliation_route_rejects_invalid_query_without_detail_over_http(): void
    {
        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->getJson('/recommerce/reconciliation/303?location_id=101&approved_legacy_balance=-1');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Reconciliation request was rejected.')
            ->assertJsonMissingPath('errors')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_receiving_prepare_route_rejects_invalid_payload_without_detail_over_http(): void
    {
        (new RouteServiceProvider(app()))->map();
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());

        $response = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/receiving/prepare', [
                'location_id' => 101,
                'product_id' => 202,
                'variation_id' => 303,
                'units' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Receiving request was rejected.')
            ->assertJsonMissingPath('errors')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_receiving_post_route_rejects_invalid_payload_without_detail_over_http(): void
    {
        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/receiving/post', []);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Receiving request was rejected.')
            ->assertJsonMissingPath('errors')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_enabled_receiving_route_runs_authenticated_http_path(): void
    {
        $service = Mockery::mock(TrackedReceivingService::class);
        $service->shouldReceive('executeWithUltimatePosPurchase')
            ->once()
            ->withArgs(function (User $user, array $command): bool {
                return $user->business_id === 7
                    && $command['business_id'] === 7
                    && $command['location_id'] === 101
                    && $command['variation_id'] === 303;
            })
            ->andReturn([
                'command_uuid' => '11111111-1111-4111-8111-111111111111',
                'transaction_id' => 606,
                'purchase_line_id' => 707,
                'unit_count' => 1,
                'devices' => [],
            ]);
        $this->app->instance(TrackedReceivingService::class, $service);

        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/receiving/post', $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-HTTP-01', 'unit_acquisition_cost' => 1850],
            ]));

        $response->assertOk()
            ->assertJsonPath('status', 'RECEIVED_TRACKED')
            ->assertJsonPath('result.transaction_id', 606)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_enabled_receiving_http_path_runs_real_service_and_adapter(): void
    {
        Event::fake();
        $productUtil = Mockery::mock(ProductUtil::class);
        $transactionUtil = Mockery::mock(TransactionUtil::class);
        $productUtil->shouldReceive('setAndGetReferenceCount')
            ->once()
            ->with('purchase', 7)
            ->andReturn(8);
        $productUtil->shouldReceive('generateReferenceNumber')
            ->once()
            ->with('purchase', 8, 7)
            ->andReturn('PUR-HTTP/0008');
        $productUtil->shouldReceive('uf_date')
            ->once()
            ->with('2026-08-27', true)
            ->andReturn('2026-08-27 00:00:00');
        $transactionUtil->shouldReceive('purchaseCurrencyDetails')
            ->once()
            ->with(7)
            ->andReturn([
                'purchase_in_diff_currency' => false,
                'p_exchange_rate' => 1,
                'thousand_separator' => ',',
                'decimal_separator' => '.',
                'symbol' => 'RM',
            ]);
        $productUtil->shouldReceive('createOrUpdatePurchaseLines')
            ->once()
            ->withArgs(function ($transaction, $purchaseLines, $currencyDetails, $editing): bool {
                DB::table('purchase_lines')->insert([
                    'transaction_id' => $transaction->id,
                    'product_id' => $purchaseLines[0]['product_id'],
                    'variation_id' => $purchaseLines[0]['variation_id'],
                    'quantity' => $purchaseLines[0]['quantity'],
                ]);

                return $transaction instanceof Transaction
                    && count($purchaseLines) === 1
                    && (int) $purchaseLines[0]['quantity'] === 1
                    && $editing === false;
            });
        $transactionUtil->shouldReceive('createOrUpdatePaymentLines')
            ->once()
            ->withArgs(function ($transaction, $payments, $businessId, $userId, $ufData): bool {
                return $transaction instanceof Transaction
                    && $payments === []
                    && $businessId === 7
                    && $userId === 900
                    && $ufData === false;
            });
        $transactionUtil->shouldReceive('updatePaymentStatus')
            ->once()
            ->withArgs(function ($transactionId, $finalAmount): bool {
                return $transactionId > 0 && (float) $finalAmount === 1850.0;
            })
            ->andReturn('due');
        $productUtil->shouldReceive('adjustStockOverSelling')
            ->once()
            ->withArgs(fn ($transaction): bool => $transaction instanceof Transaction && $transaction->type === 'purchase');
        $transactionUtil->shouldReceive('activityLog')
            ->once()
            ->withArgs(function ($transaction, $action, $before, $properties, $logChanges, $businessId): bool {
                return $transaction instanceof Transaction
                    && $action === 'added'
                    && $before === null
                    && $properties === []
                    && $logChanges === true
                    && $businessId === 7;
            });

        $this->app->instance(
            TrackedReceivingService::class,
            new TrackedReceivingService(
                $this->gate(),
                new UltimatePosPurchaseWriter($productUtil, $transactionUtil)
            )
        );
        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/receiving/post', $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-HTTP-REAL-01', 'unit_acquisition_cost' => 1850],
            ]));
        $data = $response->getData(true);

        $response->assertOk()
            ->assertJsonPath('status', 'RECEIVED_TRACKED')
            ->assertJsonPath('result.unit_count', 1);
        $this->assertSame(2, DB::table('transactions')->count());
        $this->assertSame(1, DB::table('transactions')->where('source', 'recommerce')->count());
        $this->assertSame(2, DB::table('purchase_lines')->count());
        $this->assertSame(1, DB::table('recommerce_devices')->count());
        $this->assertSame('RECEIVED_PENDING_INSPECTION', DB::table('recommerce_devices')->value('lifecycle_state'));
        $this->assertSame(1, DB::table('recommerce_stock_commands')->count());
        $this->assertStringNotContainsString('SN-HTTP-REAL-01', json_encode($data));
    }

    public function test_first_vertical_slice_runs_receive_label_scan_reconcile_over_http(): void
    {
        $service = new class($this->gate()) extends TrackedReceivingService
        {
            public function __construct(AuthorizationGate $authorizationGate)
            {
                parent::__construct($authorizationGate);
            }

            public function executeWithUltimatePosPurchase(User $user, array $command): array
            {
                $quantity = count($command['units']);

                return $this->execute(
                    $user,
                    $command,
                    static fn (array $normalized): array => [
                        'transaction_id' => 606,
                        'purchase_line_id' => 707,
                        'quantity' => $quantity,
                        'business_id' => $normalized['business_id'],
                        'location_id' => $normalized['location_id'],
                        'product_id' => $normalized['product_id'],
                        'variation_id' => $normalized['variation_id'],
                    ]
                );
            }
        };
        $this->app->instance(TrackedReceivingService::class, $service);
        (new RouteServiceProvider(app()))->map();

        $receiveResponse = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/receiving/post', $this->command([
                ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-VERTICAL-01', 'unit_acquisition_cost' => 1850],
            ]));
        $receiveResponse->assertOk()
            ->assertJsonPath('status', 'RECEIVED_TRACKED')
            ->assertJsonPath('result.unit_count', 1);
        $receiveData = $receiveResponse->getData(true);
        $deviceCode = $receiveData['result']['devices'][0]['device_code'];

        $labelResponse = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/devices/'.$receiveData['result']['devices'][0]['device_id'].'/label');
        $labelResponse->assertOk()
            ->assertJsonPath('status', 'READY_TO_PRINT')
            ->assertJsonPath('label.device_code', $deviceCode)
            ->assertJsonMissingPath('label.raw_token');

        $scanResponse = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/scans/resolve', ['value' => $deviceCode]);
        $scanResponse->assertOk()
            ->assertJsonPath('type', 'DEVICE')
            ->assertJsonPath('device_code', $deviceCode)
            ->assertJsonPath('actions.0.key', 'VIEW_DEVICE');

        DB::table('variation_location_details')->insert([
            'id' => 838,
            'product_id' => 202,
            'location_id' => 101,
            'variation_id' => 303,
            'qty_available' => 1,
        ]);
        $reconciliationResponse = $this->actingAs($this->authorizedUser())
            ->getJson('/recommerce/reconciliation/303?location_id=101');
        $reconciliationResponse->assertOk()
            ->assertJsonPath('status', 'PASS')
            ->assertJsonPath('core_quantity', 1)
            ->assertJsonPath('tracked_device_count', 1)
            ->assertJsonMissingPath('actions');

        $this->assertSame(1, DB::table('recommerce_devices')->count());
        $this->assertSame(1, DB::table('recommerce_scan_tokens')->count());
        $this->assertSame(2, DB::table('recommerce_device_events')->count());
        $this->assertSame(1, DB::table('recommerce_device_identifiers')->count());
    }

    public function test_enabled_label_and_scan_routes_run_authenticated_http_path(): void
    {
        $device = $this->receiveOneDevice();

        (new RouteServiceProvider(app()))->map();

        $labelResponse = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/devices/'.$device->id.'/label');

        $labelResponse->assertOk()
            ->assertJsonPath('status', 'READY_TO_PRINT')
            ->assertJsonMissingPath('label.raw_token')
            ->assertJsonMissingPath('label.token_hash')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $scanResponse = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/scans/resolve', [
                'value' => $device->device_code,
            ]);

        $scanResponse->assertOk()
            ->assertJsonPath('type', 'DEVICE')
            ->assertJsonPath('device_code', $device->device_code)
            ->assertJsonPath('actions.0.key', 'VIEW_DEVICE');
        $this->assertStringContainsString('no-store', (string) $scanResponse->headers->get('Cache-Control'));
        $scanResponse->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_approved_qr_scan_and_public_resolver_run_over_http(): void
    {
        $this->app['view']->addNamespace(
            'recommerce',
            base_path('Modules/Recommerce/Resources/views')
        );
        $device = $this->receiveOneDevice();
        $issued = (new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()))
            ->issue($this->authorizedUser(), $device);

        (new RouteServiceProvider(app()))->map();

        $qrResponse = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/scans/resolve', [
                'value' => 'https://scan.saverbro.example/s/d/'.$issued['raw_token'],
            ]);

        $qrResponse->assertOk()
            ->assertJsonPath('type', 'DEVICE')
            ->assertJsonPath('device_code', $device->device_code)
            ->assertJsonPath('actions.0.key', 'VIEW_DEVICE');
        $this->assertStringNotContainsString($issued['raw_token'], $qrResponse->getContent());
        $this->assertStringContainsString('no-store', (string) $qrResponse->headers->get('Cache-Control'));
        $qrResponse->assertHeader('Referrer-Policy', 'no-referrer');

        Auth::logout();
        $publicResponse = $this->get('/s/d/'.$issued['raw_token']);

        $publicResponse->assertNotFound()
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $publicResponse->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($issued['raw_token'], $publicResponse->getContent());

        $unknownResponse = $this->get('/s/d/'.str_repeat('f', 64));

        $unknownResponse->assertNotFound()
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $unknownResponse->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($issued['raw_token'], $unknownResponse->getContent());
    }

    public function test_disabled_recommerce_routes_are_unreachable_over_http(): void
    {
        config(['recommerce.enabled' => false]);

        (new RouteServiceProvider(app()))->map();

        $response = $this->get('/recommerce/reconciliation/303?location_id=101');
        $receivingResponse = $this->postJson('/recommerce/receiving/post', []);

        $response->assertNotFound();
        $receivingResponse->assertNotFound();
    }

    public function test_protected_recommerce_routes_require_authentication_over_http(): void
    {
        (new RouteServiceProvider(app()))->map();
        Auth::logout();

        $reconciliationResponse = $this->getJson('/recommerce/reconciliation/303?location_id=101');
        $scanResponse = $this->postJson('/recommerce/scans/resolve', [
            'value' => 'SB-DV-00000001-9',
        ]);

        $reconciliationResponse->assertUnauthorized();
        $scanResponse->assertUnauthorized();
    }

    public function test_concurrent_same_identifier_receives_have_one_commit_in_file_sqlite_runtime(): void
    {
        $databasePath = sys_get_temp_dir().'/saverbro-recommerce-concurrency-'.bin2hex(random_bytes(8)).'.sqlite';
        $startPath = sys_get_temp_dir().'/saverbro-recommerce-start-'.bin2hex(random_bytes(8));
        $resultPaths = [
            sys_get_temp_dir().'/saverbro-recommerce-result-'.bin2hex(random_bytes(8)),
            sys_get_temp_dir().'/saverbro-recommerce-result-'.bin2hex(random_bytes(8)),
        ];
        $pids = [];

        try {
            $safeDatabasePath = str_replace("'", "''", $databasePath);
            DB::statement("VACUUM INTO '{$safeDatabasePath}'");

            foreach ($resultPaths as $index => $resultPath) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Could not fork the disposable concurrency worker.');
                }

                if ($pid === 0) {
                    $result = ['status' => 'error', 'error' => 'worker did not start'];

                    try {
                        $deadline = microtime(true) + 5;
                        while (! file_exists($startPath) && microtime(true) < $deadline) {
                            usleep(10000);
                        }

                        if (! file_exists($startPath)) {
                            throw new RuntimeException('Concurrency start barrier timed out.');
                        }

                        config(['database.connections.sqlite.database' => $databasePath]);
                        DB::purge('sqlite');

                        $this->service()->execute(
                            $this->authorizedUser(),
                            $this->command([
                                [
                                    'identifier_type' => 'SERIAL',
                                    'identifier_value' => 'SN-CONCURRENT-01',
                                    'unit_acquisition_cost' => 1850,
                                ],
                            ], $index === 0
                                ? '33333333-3333-4333-8333-333333333333'
                                : '44444444-4444-4444-8444-444444444444'),
                            fn (): array => $this->coreReceipt(1)
                        );
                        $result = ['status' => 'success'];
                    } catch (\Throwable $exception) {
                        $result = [
                            'status' => 'error',
                            'error' => get_class($exception),
                        ];
                    }

                    file_put_contents($resultPath, json_encode($result), LOCK_EX);
                    exit(0);
                }

                $pids[] = $pid;
            }

            file_put_contents($startPath, 'go', LOCK_EX);
            $deadline = microtime(true) + 8;
            while (count(array_filter($resultPaths, 'file_exists')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }

            $this->assertSame(2, count(array_filter($resultPaths, 'file_exists')));
            $results = [];
            foreach ($resultPaths as $resultPath) {
                $results[] = json_decode((string) file_get_contents($resultPath), true);
            }

            config(['database.connections.sqlite.database' => $databasePath]);
            DB::purge('sqlite');

            $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['status'] === 'success')));
            $this->assertSame(1, Device::query()->count());
            $this->assertSame(1, DB::table('recommerce_device_identifiers')->count());
            $this->assertSame(1, DB::table('recommerce_stock_commands')->count());
            $this->assertSame(1, DB::table('recommerce_device_events')->count());
            $this->assertSame(1, DB::table('recommerce_device_ownership_periods')->count());
            $this->assertSame(1, DB::table('recommerce_device_custody_periods')->count());
        } finally {
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }

            foreach (array_merge([$databasePath, $startPath], $resultPaths) as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_customer_repair_intake_creates_non_stock_device_checklist_job_and_public_token(): void
    {
        $service = new RepairJobIntakeService(
            $this->gate(),
            new CustomerRepairDeviceService($this->gate()),
            new RepairPublicLookupService(new OpaqueScanToken())
        );
        $payload = $this->customerRepairPayload();

        $job = $service->create($this->authorizedUser(), $payload);
        $rawToken = $job->lookup_raw_token;

        $this->assertInstanceOf(RepairJob::class, $job);
        $this->assertSame('CUSTOMER_REPAIR', $job->job_type);
        $this->assertSame('RECEIVED', $job->state);
        $this->assertMatchesRegularExpression('/^SB-RP-[A-F0-9]{32}$/', $job->job_code);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rawToken);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame('CUSTOMER', Device::query()->value('ownership_kind'));
        $this->assertSame('NONE', Device::query()->value('stock_participation'));
        $this->assertSame(1, DB::table('recommerce_repair_jobs')->count());
        $this->assertSame(count(config('recommerce.repair_intake_checklist')), DB::table('recommerce_repair_checklist_items')->count());
        $this->assertSame('RECEIVED', DB::table('recommerce_repair_state_transitions')->value('to_state'));
        $this->assertSame(1, DB::table('recommerce_repair_lookup_tokens')->count());
        $this->assertNotSame($rawToken, DB::table('recommerce_repair_lookup_tokens')->value('token_hash'));
        $this->assertSame(1, DB::table('recommerce_device_movements')->count());
        $this->assertSame('CUSTOMER_REPAIR_INTAKE', DB::table('recommerce_device_events')->value('event_type'));
        $this->assertStringNotContainsString($payload['identifier_value'], (string) DB::table('recommerce_device_identifiers')->value('raw_value_encrypted'));

        $resolved = (new RepairPublicLookupService(new OpaqueScanToken()))->resolve($job->job_code, $rawToken);
        $this->assertNotNull($resolved);
        $this->assertSame($job->id, $resolved->id);

        $replayed = $service->create($this->authorizedUser(), $payload);
        $this->assertSame($job->id, $replayed->id);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('recommerce_repair_jobs')->count());
        $this->assertSame(1, DB::table('recommerce_repair_lookup_tokens')->count());

        try {
            $service->create($this->authorizedUser(), array_merge($payload, [
                'reported_fault' => 'A different fault must not reuse this command UUID.',
            ]));
            $this->fail('Expected a changed repair payload with the same command UUID to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('reused for a different repair intake', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('recommerce_repair_jobs')->count());
    }

    public function test_customer_repair_existing_device_is_reused_and_wrong_location_is_denied(): void
    {
        $deviceService = new CustomerRepairDeviceService($this->gate());
        $payload = $this->customerRepairPayload();
        $first = $deviceService->resolveOrCreate($this->authorizedUser(), $payload);
        $second = $deviceService->resolveOrCreate($this->authorizedUser(), array_merge($payload, [
            'command_uuid' => '22222222-2222-4222-8222-222222222222',
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('recommerce_device_movements')->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->count());

        try {
            $deviceService->resolveOrCreate($this->authorizedUser(), array_merge($payload, [
                'location_id' => 999,
                'command_uuid' => '33333333-3333-4333-8333-333333333333',
            ]));
            $this->fail('Expected a customer repair outside the permitted location to be denied.');
        } catch (AuthorizationException $exception) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DB::table('recommerce_device_movements')->count());
    }

    public function test_customer_repair_public_lookup_is_sanitized_over_http(): void
    {
        $this->app['view']->addNamespace(
            'recommerce',
            base_path('Modules/Recommerce/Resources/views')
        );

        $service = new RepairJobIntakeService(
            $this->gate(),
            new CustomerRepairDeviceService($this->gate()),
            new RepairPublicLookupService(new OpaqueScanToken())
        );
        $job = $service->create($this->authorizedUser(), $this->customerRepairPayload());
        $rawToken = $job->lookup_raw_token;

        (new RouteServiceProvider(app()))->map();

        $response = $this->get('/recommerce/repair/status/'.$job->job_code.'/'.$rawToken);

        $response->assertOk()
            ->assertSee($job->job_code, false)
            ->assertSee('RECEIVED', false)
            ->assertSee('Fixture brand', false)
            ->assertDontSee('Fixture Customer', false)
            ->assertDontSee('Does not power on', false)
            ->assertDontSee('RM', false)
            ->assertDontSee('payment amount', false)
            ->assertDontSee($rawToken, false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get('/recommerce/repair/status/'.$job->job_code.'/'.str_repeat('f', 64))
            ->assertNotFound();
    }

    public function test_customer_repair_intake_returns_correctable_validation_errors(): void
    {
        (new RouteServiceProvider(app()))->map();

        $response = $this->actingAs($this->authorizedUser())
            ->postJson('/recommerce/repair/intake', [
                'job_type' => 'CUSTOMER_REPAIR',
                'location_id' => 101,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Please correct the highlighted intake fields.')
            ->assertJsonStructure(['errors' => ['command_uuid', 'contact_id', 'reported_fault', 'cosmetic_condition', 'access_status', 'category_code', 'brand', 'model', 'checklist']])
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(0, DB::table('recommerce_repair_jobs')->count());
        $this->assertSame(0, Device::query()->count());
    }

    public function test_customer_repair_credentials_are_rejected_before_device_creation(): void
    {
        $service = new CustomerRepairDeviceService($this->gate());

        try {
            $service->resolveOrCreate($this->authorizedUser(), array_merge($this->customerRepairPayload(), [
                'password' => 'must-never-be-stored',
            ]));
            $this->fail('Expected submitted credentials to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Access credentials are not accepted', $exception->getMessage());
        }

        $this->assertSame(0, Device::query()->count());
        $this->assertSame(0, DB::table('recommerce_device_identifiers')->count());
    }

    public function test_customer_repair_checklist_outcomes_are_server_validated(): void
    {
        $service = new RepairJobIntakeService($this->gate(), new CustomerRepairDeviceService($this->gate()));
        $payload = $this->customerRepairPayload();
        $payload['checklist'][0]['outcome'] = 'MAYBE';

        try {
            $service->create($this->authorizedUser(), $payload);
            $this->fail('Expected an unsupported checklist outcome to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Choose PASS, FAIL, or NOT APPLICABLE', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('recommerce_repair_jobs')->count());
        $this->assertSame(0, Device::query()->count());
    }

    public function test_internal_refurbishment_reuses_business_device_without_customer_artifacts(): void
    {
        $device = $this->receiveOneDevice();
        $service = new RepairJobIntakeService($this->gate());
        $payload = [
            'command_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'location_id' => 101,
            'device_id' => $device->id,
            'job_type' => 'INTERNAL_REFURBISHMENT',
            'priority' => 'HIGH',
            'intake_snapshot_json' => [
                'source' => 'internal_refurbishment_workbench',
                'work_summary' => 'Replace battery and run diagnostics.',
            ],
        ];

        $job = $service->create($this->authorizedUser(), $payload);

        $this->assertSame($device->id, $job->device_id);
        $this->assertSame('INTERNAL_REFURBISHMENT', $job->job_type);
        $this->assertSame('RECEIVED', $job->state);
        $this->assertNull($job->contact_id);
        $this->assertNull($job->lookup_raw_token);
        $this->assertSame(0, DB::table('recommerce_repair_checklist_items')->count());
        $this->assertSame(0, DB::table('recommerce_repair_lookup_tokens')->count());
        $this->assertSame(
            'INTERNAL_REFURBISHMENT_INTAKE',
            data_get(json_decode((string) DB::table('recommerce_repair_state_transitions')->value('evidence_json'), true), 'reason')
        );

        $replayed = $service->create($this->authorizedUser(), $payload);
        $this->assertSame($job->id, $replayed->id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('reused for a different repair intake');
        $service->create($this->authorizedUser(), array_merge($payload, ['priority' => 'URGENT']));
    }

    public function test_internal_refurbishment_rejects_business_device_outside_the_variation_cohort(): void
    {
        $device = $this->receiveOneDevice();
        // This test isolates the configured variation boundary rather than
        // the optional approved-product-policy expansion path.
        config([
            'recommerce.cohort.variation_ids' => [999],
            'recommerce.cohort.allow_approved_product_policies' => false,
        ]);

        $this->expectException(AuthorizationException::class);
        (new RepairJobIntakeService($this->gate()))->create($this->authorizedUser(), [
            'command_uuid' => 'acacacac-acac-4cac-8cac-acacacacacac',
            'location_id' => 101,
            'device_id' => $device->id,
            'job_type' => 'INTERNAL_REFURBISHMENT',
        ]);
    }

    public function test_internal_refurbishment_parts_follow_reserve_issue_install_and_pos_adjustment_seam(): void
    {
        $device = $this->receiveOneDevice();
        $job = (new RepairJobIntakeService($this->gate()))->create($this->authorizedUser(), [
            'command_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'location_id' => 101,
            'device_id' => $device->id,
            'job_type' => 'INTERNAL_REFURBISHMENT',
        ]);
        DB::table('variation_location_details')->insert([
            'id' => 889,
            'product_id' => 202,
            'variation_id' => 303,
            'location_id' => 101,
            'qty_available' => 3,
        ]);

        $writer = Mockery::mock(UltimatePosStockAdjustmentWriter::class);
        $writer->shouldReceive('write')
            ->once()
            ->with(Mockery::type('Modules\\Recommerce\\Entities\\RepairPartUsage'), 900, 'Battery replacement')
            ->andReturn(['transaction_id' => 701, 'line_id' => 702, 'actual_cost' => 125.50, 'quantity' => 1.0]);
        $parts = new RepairPartService($this->gate(), $writer);

        $reservation = $parts->reserve($this->authorizedUser(), $job, 303, 'efefefef-efef-4fef-8fef-efefefefefef', '1');
        $usage = $parts->issue($this->authorizedUser(), $reservation, 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'INTERNAL');
        $installed = $parts->install($this->authorizedUser(), $usage);
        $consumed = $parts->consumeInternal($this->authorizedUser(), $installed, 'Battery replacement');

        $this->assertSame('CONSUMED', $consumed->status);
        $this->assertSame('ADJUSTMENT', $consumed->source_type);
        $this->assertSame(701, $consumed->source_transaction_id);
        $this->assertSame('CONSUMED', $reservation->fresh()->status);
        $this->assertSame(1, DB::table('recommerce_repair_cost_entries')->count());
        $this->assertEquals(125.50, DB::table('recommerce_repair_cost_entries')->value('amount'));
        $this->assertSame(3.0, (float) DB::table('variation_location_details')->where('id', 889)->value('qty_available'));
    }

    public function test_internal_refurbishment_diagnostics_publish_snapshot_and_submit_required_evidence(): void
    {
        $device = $this->receiveOneDevice();
        $job = (new RepairJobIntakeService($this->gate()))->create($this->authorizedUser(), [
            'command_uuid' => 'abababab-abab-4bab-8bab-abababababab',
            'location_id' => 101,
            'device_id' => $device->id,
            'job_type' => 'INTERNAL_REFURBISHMENT',
        ]);
        $template = new DiagnosticTemplate([
            'business_id' => 7,
            'template_code' => 'REFURB-LAPTOP-01',
            'name' => 'Refurbishment quality check',
            'job_type' => 'INTERNAL_REFURBISHMENT',
            'status' => 'ACTIVE',
            'created_by' => 900,
        ]);
        $template->template_uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $template->save();
        $version = new DiagnosticTemplateVersion([
            'template_id' => $template->id,
            'business_id' => 7,
            'rubric_json' => ['grade' => 'PASS_REQUIRED'],
            'created_by' => 900,
        ]);
        $version->version_number = 1;
        $version->status = 'DRAFT';
        $version->save();
        DiagnosticCheck::create([
            'template_version_id' => $version->id,
            'business_id' => 7,
            'check_key' => 'battery_health',
            'label' => 'Battery health verified',
            'outcome_type' => 'STATUS',
            'allowed_outcomes_json' => ['PASS', 'FAIL'],
            'is_required' => true,
            'evidence_required' => true,
            'sort_order' => 1,
        ]);

        $service = new DiagnosticTemplateService(new AuthorizationGate(new CohortPolicy()));
        $published = $service->publish($version, 900);
        $session = $service->startSession($job, $published, 900);
        $submitted = $service->submitSession($session, [[
            'check_key' => 'battery_health',
            'outcome' => 'PASS',
            'evidence' => ['source' => 'bench-meter', 'reading' => '92%'],
            'notes' => 'Meets refurbishment threshold.',
        ]], 'PASS', null, 900);

        $this->assertSame('PUBLISHED', $published->status);
        $this->assertSame('SUBMITTED', $submitted->status);
        $this->assertSame('PASS', $submitted->grade_code);
        $this->assertSame(1, $submitted->observations->count());
        $this->assertSame('REFURB-LAPTOP-01', data_get($session->template_snapshot_json, 'template_code'));
    }

    public function test_device_registry_uses_exact_identity_resolution_database_filters_and_masked_quick_view(): void
    {
        config(['recommerce.enabled' => true]);
        $this->app['view']->addNamespace('recommerce', base_path('Modules/Recommerce/Resources/views'));
        $this->app['view']->getFinder()->prependLocation(base_path('tests/Fixtures/views'));
        $this->app['view']->flushFinderCache();
        (new RouteServiceProvider(app()))->map();
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
        $this->assertNotNull(app('router')->getRoutes()->getByName('recommerce.dashboard'));

        $device = $this->receiveOneDevice();
        $device->update([
            'lifecycle_state' => 'AVAILABLE',
            'category_code' => 'LAPTOP',
            'acquired_at' => now()->subDays(61),
        ]);
        DB::table('business')->insert(['id' => 8]);
        $other = $this->availableDevice('SB-DV-OUT-OF-BUSINESS-1', 101);
        $other->update(['business_id' => 8]);

        $registry = new DeviceRegistryQuery();
        $base = $registry->base(7, 101, [303]);
        $combined = $registry->apply(clone $base, [
            'state' => 'AVAILABLE', 'category' => 'LAPTOP', 'age_days' => 60,
            'label_status' => '', 'product_id' => 0, 'variation_id' => 0,
            'custody' => '', 'inventory' => '', 'grade' => '', 'inspection' => '',
            'received_from' => '', 'received_to' => '', 'has_repair' => false,
        ])->pluck('device_code')->all();
        $this->assertSame([$device->device_code], $combined);

        $controller = new DeviceController();
        $registryResponse = $controller->index(
            Request::create('/recommerce/devices', 'GET', ['location_id' => 101, 'q' => 'SN-LABEL-01']),
            $this->gate(),
            new DeviceIdentityResolver(),
            $registry
        );
        $registryHtml = $registryResponse->getContent();
        $this->assertStringContainsString($device->device_code, $registryHtml);
        $this->assertStringNotContainsString($other->device_code, $registryHtml);

        $quickView = $controller->quickView(
            $device->device_code,
            $this->gate(),
            new DeviceEventTimelineService()
        );
        $this->assertStringContainsString('View full record', $quickView->getContent());
        $this->assertStringContainsString($device->device_code, $quickView->getContent());
        $this->assertStringNotContainsString('SN-LABEL-01', $quickView->getContent());
        $this->assertStringContainsString('no-store', (string) $quickView->headers->get('Cache-Control'));
    }

    private function service(): TrackedReceivingService
    {
        return new TrackedReceivingService(new AuthorizationGate(new CohortPolicy()));
    }

    private function bulkLabelPrintService(): BulkDeviceLabelPrintService
    {
        return new BulkDeviceLabelPrintService(
            new ScanTokenIssuanceService($this->gate(), new OpaqueScanToken()),
            new LabelPayloadBuilder(),
            new LabelRenderer()
        );
    }

    private function gate(): AuthorizationGate
    {
        return new AuthorizationGate(new CohortPolicy());
    }

    private function receiveOneDevice(): Device
    {
        $this->service()->execute($this->authorizedUser(), $this->command([
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-LABEL-01', 'unit_acquisition_cost' => 1850],
        ]), fn (): array => $this->coreReceipt(1));

        return Device::query()->firstOrFail();
    }

    private function availableDevice(string $code, int $locationId): Device
    {
        return Device::create([
            'business_id' => 7, 'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'device_code' => $code, 'ownership_kind' => 'BUSINESS', 'custody_kind' => 'LOCATION',
            'current_location_id' => $locationId, 'product_id' => 202, 'variation_id' => 303,
            'lifecycle_state' => 'AVAILABLE', 'stock_participation' => 'ON_HAND', 'lock_version' => 1,
            'created_by' => 900, 'updated_by' => 900,
        ]);
    }

    /** @return array{0: Transaction, 1: Transaction} */
    private function transferPair(string $status): array
    {
        $transfer = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell_transfer',
            'status' => $status, 'transaction_date' => now(), 'created_by' => 900,
        ]);
        $transfer->sell_lines()->create([
            'product_id' => 202, 'variation_id' => 303, 'quantity' => 1,
            'unit_price' => 1850, 'unit_price_inc_tax' => 1850,
        ]);
        $receipt = Transaction::create([
            'business_id' => 7, 'location_id' => 102, 'type' => 'purchase_transfer',
            'status' => $status, 'transfer_parent_id' => $transfer->id,
            'transaction_date' => now(), 'created_by' => 900,
        ]);

        return [$transfer, $receipt];
    }

    private function receiveTwoDevices(): void
    {
        $this->service()->execute($this->authorizedUser(), $this->command([
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-RECON-01', 'unit_acquisition_cost' => 1850],
            ['identifier_type' => 'SERIAL', 'identifier_value' => 'SN-RECON-02', 'unit_acquisition_cost' => 1850],
        ]), fn (): array => $this->coreReceipt(2));
    }

    private function authorizedUser(): User
    {
        $user = new class extends User
        {
            public function can($ability, $arguments = []): bool
            {
                return in_array($ability, [
                    'recommerce.receiving.post',
                    'recommerce.receiving.prepare',
                    'recommerce.inspection.view',
                    'recommerce.inspection.assign',
                    'recommerce.inspection.complete',
                    'recommerce.device.override_acquisition_cost',
                    'recommerce.device.view',
                    'recommerce.device.print_label',
                    'recommerce.device.rotate_token',
                    'recommerce.device.certify',
                    'recommerce.device.sell',
                    'recommerce.device.transfer',
                    'recommerce.device.return',
                    'recommerce.device.reverse_disposition',
                    'recommerce.device.view_economics',
                    'recommerce.stock.reconcile',
                    'recommerce.stock.reconcile.record',
                    'recommerce.audit.view',
                    'recommerce.repair.view',
                    'recommerce.repair.intake',
                    'recommerce.repair.parts.reserve',
                    'recommerce.repair.parts.use',
                    'recommerce.repair.parts.resolve',
                ], true);
            }

            public function permitted_locations($business_id = null)
            {
                return [101, 102];
            }
        };

        $user->id = 900;
        $user->business_id = 7;

        return $user;
    }

    private function customerRepairPayload(string $commandUuid = '11111111-1111-4111-8111-111111111111'): array
    {
        return [
            'business_id' => 7,
            'location_id' => 101,
            'contact_id' => 405,
            'job_type' => 'CUSTOMER_REPAIR',
            'category_code' => 'MOBILE',
            'brand' => 'Fixture brand',
            'model' => 'Fixture model',
            'identifier_type' => 'SERIAL',
            'identifier_value' => 'SN-FIXTURE-REPAIR-01',
            'reported_fault' => 'Does not power on.',
            'cosmetic_condition' => 'Light wear on rear cover.',
            'access_status' => 'NO_LOCK',
            'priority' => 'NORMAL',
            'command_uuid' => $commandUuid,
            'checklist' => collect(config('recommerce.repair_intake_checklist', []))->map(function (array $check): array {
                return [
                    'check_key' => $check['key'],
                    'label' => $check['label'],
                    'outcome' => 'PASS',
                    'notes' => null,
                ];
            })->all(),
        ];
    }

    private function command(array $units, string $commandUuid = '11111111-1111-4111-8111-111111111111'): array
    {
        return [
            'business_id' => 7,
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'command_uuid' => $commandUuid,
            'purchase' => [
                'contact_id' => 404,
                'transaction_date' => '2026-08-27',
                'unit_purchase_price' => 1850,
                'unit_purchase_price_inc_tax' => 1850,
                'unit_item_tax' => 0,
            ],
            'units' => $units,
        ];
    }

    private function coreReceipt(int $quantity, int $transactionId = 606, int $purchaseLineId = 707): array
    {
        return [
            'transaction_id' => $transactionId,
            'purchase_line_id' => $purchaseLineId,
            'quantity' => $quantity,
            'business_id' => 7,
            'location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
        ];
    }
}
