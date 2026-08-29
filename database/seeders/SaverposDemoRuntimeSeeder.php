<?php

namespace Database\Seeders;

use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\OwnershipPeriod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Deliberately small, fictional demo estate for the SAVERPOS P0 browser run.
 * It is only intended for a fresh `saverpos_demo_*` database created by the
 * companion shell script; it never reads or copies production data.
 */
class SaverposDemoRuntimeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $userId = $this->insert('users', [
                'surname' => 'Demo', 'first_name' => 'SaverPOS', 'last_name' => 'Administrator',
                'username' => 'saverpos.demo', 'email' => 'demo@saverpos.test',
                'password' => Hash::make('demo-pass'), 'language' => 'en',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $currencyId = (int) DB::table('currencies')->orderBy('id')->value('id');
            $businessId = $this->insert('business', [
                'name' => 'SAVERPOS Demo', 'currency_id' => $currencyId, 'start_date' => now()->toDateString(),
                'tax_number_1' => 'DEMO-ONLY', 'tax_label_1' => 'Tax', 'owner_id' => $userId,
                'time_zone' => 'Asia/Kuching', 'fy_start_month' => 1, 'accounting_method' => 'fifo',
                'sell_price_tax' => 'includes', 'default_profit_percent' => 0,
                'enabled_modules' => json_encode(['purchases', 'add_sale', 'pos_sale', 'stock_transfers', 'stock_adjustment']),
                'ref_no_prefixes' => json_encode(['purchase' => 'PO', 'stock_transfer' => 'ST', 'sell' => 'SL']),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->update('users', $userId, ['business_id' => $businessId, 'updated_at' => $now]);

            $invoiceSchemeId = $this->insert('invoice_schemes', [
                'business_id' => $businessId, 'name' => 'Demo invoice scheme', 'scheme_type' => 'blank',
                'prefix' => 'INV-', 'start_number' => 1, 'total_digits' => 4, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $invoiceLayoutId = $this->insert('invoice_layouts', [
                'business_id' => $businessId, 'name' => 'Demo invoice layout', 'is_default' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $branchA = $this->insert('business_locations', $this->location($businessId, 'Branch A', $invoiceSchemeId, $invoiceLayoutId, $now));
            $branchB = $this->insert('business_locations', $this->location($businessId, 'Branch B', $invoiceSchemeId, $invoiceLayoutId, $now));

            $unitId = $this->insert('units', [
                'business_id' => $businessId, 'actual_name' => 'Pieces', 'short_name' => 'pcs',
                'allow_decimal' => 0, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $productId = $this->insert('products', [
                'name' => 'SaverBro Demo Device', 'business_id' => $businessId, 'type' => 'single', 'unit_id' => $unitId,
                'tax_type' => 'inclusive', 'enable_stock' => 1, 'alert_quantity' => 0,
                'sku' => 'SAVERPOS-DEMO-DEVICE', 'barcode_type' => 'C128', 'created_by' => $userId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $productVariationId = $this->insert('product_variations', [
                'name' => 'Default', 'product_id' => $productId, 'is_dummy' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $variationId = $this->insert('variations', [
                'name' => 'Default', 'product_id' => $productId, 'product_variation_id' => $productVariationId,
                'sub_sku' => 'SAVERPOS-DEMO-DEVICE', 'default_purchase_price' => 1000,
                'dpp_inc_tax' => 1000, 'profit_percent' => 0, 'default_sell_price' => 1200,
                'sell_price_inc_tax' => 1200, 'created_at' => $now, 'updated_at' => $now,
            ]);
            // Core product discovery is location-scoped.  Both ends of the
            // disposable transfer route must be catalogued before their stock
            // can be selected in the normal transfer/POS UI.
            DB::table('product_locations')->insert([
                ['product_id' => $productId, 'location_id' => $branchA],
                ['product_id' => $productId, 'location_id' => $branchB],
            ]);

            $supplierId = $this->insert('contacts', [
                'business_id' => $businessId, 'type' => 'supplier', 'supplier_business_name' => 'Demo Supplier',
                'name' => 'Demo Supplier', 'contact_id' => 'SUP-DEMO-001', 'created_by' => $userId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->insert('contacts', [
                'business_id' => $businessId, 'type' => 'customer', 'name' => 'Demo Customer',
                'contact_id' => 'CUS-DEMO-001', 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $purchaseId = $this->insert('transactions', [
                'business_id' => $businessId, 'location_id' => $branchA, 'type' => 'purchase', 'status' => 'received',
                'payment_status' => 'due', 'contact_id' => $supplierId, 'ref_no' => 'PO-DEMO-001',
                'transaction_date' => $now, 'total_before_tax' => 1000, 'final_total' => 1000,
                'exchange_rate' => 1, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->insert('purchase_lines', [
                'transaction_id' => $purchaseId, 'product_id' => $productId, 'variation_id' => $variationId,
                'quantity' => 1, 'quantity_sold' => 0, 'purchase_price' => 1000, 'purchase_price_inc_tax' => 1000,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->insert('variation_location_details', [
                'product_id' => $productId, 'product_variation_id' => $productVariationId,
                'variation_id' => $variationId, 'location_id' => $branchA, 'qty_available' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $this->insert('recommerce_serialization_profiles', [
                'business_id' => $businessId, 'product_id' => $productId, 'variation_id' => $variationId,
                'mode' => 'TRACKED_REQUIRED', 'configured_by' => $userId,
                'approval_reference' => 'SAVERPOS-DEMO-P0', 'effective_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $device = Device::create([
                'business_id' => $businessId, 'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'device_code' => 'SB-DV-00000001-9', 'ownership_kind' => 'BUSINESS', 'custody_kind' => 'LOCATION',
                'current_location_id' => $branchA, 'product_id' => $productId, 'variation_id' => $variationId,
                'lifecycle_state' => 'AVAILABLE', 'stock_participation' => 'ON_HAND', 'lock_version' => 1,
                'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $movement = DeviceMovement::create([
                'device_id' => $device->id, 'business_id' => $businessId, 'movement_type' => 'RECEIVE',
                'from_custody_kind' => 'SUPPLIER', 'to_custody_kind' => 'LOCATION', 'to_location_id' => $branchA,
                'source_transaction_id' => $purchaseId, 'source_line_type' => 'purchase_line', 'occurred_at' => $now, 'recorded_by' => $userId,
            ]);
            OwnershipPeriod::create([
                'device_id' => $device->id, 'business_id' => $businessId, 'owner_kind' => 'BUSINESS',
                'starts_at' => $now, 'open_period_key' => $device->id, 'reason' => 'DEMO_RECEIVE', 'recorded_by' => $userId,
            ]);
            CustodyPeriod::create([
                'device_id' => $device->id, 'business_id' => $businessId, 'custody_kind' => 'LOCATION',
                'location_id' => $branchA, 'starts_at' => $now, 'open_period_key' => $device->id,
                'source_movement_id' => $movement->id, 'reason' => 'DEMO_RECEIVE', 'recorded_by' => $userId,
            ]);

            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            foreach ([
                'purchase.create', 'stock_transfer.view', 'stock_transfer.create', 'stock_transfer.update', 'stock_transfer.delete',
                'sell.create', 'direct_sell.access', 'pos.access', 'access_sell_return',
            ] as $permissionName) {
                Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            }
            $role = Role::query()->firstOrCreate([
                'name' => 'saverpos-demo-admin', 'guard_name' => 'web', 'business_id' => $businessId,
            ]);
            $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
            User::query()->findOrFail($userId)->assignRole($role);

            $this->command?->info("SAVERPOS demo fixture: business={$businessId}; branch_a={$branchA}; branch_b={$branchB}; variation={$variationId}; device=SB-DV-00000001-9");
        });
    }

    private function location(int $businessId, string $name, int $schemeId, int $layoutId, $now): array
    {
        return [
            'business_id' => $businessId, 'name' => $name, 'landmark' => 'Demo only', 'country' => 'Malaysia',
            'state' => 'Sabah', 'city' => 'Kota Kinabalu', 'zip_code' => '88000',
            'invoice_scheme_id' => $schemeId, 'invoice_layout_id' => $layoutId, 'sale_invoice_layout_id' => $layoutId,
            'is_active' => 1, 'receipt_printer_type' => 'browser', 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function insert(string $table, array $values): int
    {
        $columns = array_flip(Schema::getColumnListing($table));
        return (int) DB::table($table)->insertGetId(array_intersect_key($values, $columns));
    }

    private function update(string $table, int $id, array $values): void
    {
        $columns = array_flip(Schema::getColumnListing($table));
        DB::table($table)->where('id', $id)->update(array_intersect_key($values, $columns));
    }
}
