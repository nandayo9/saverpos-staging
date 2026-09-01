<?php

namespace Database\Seeders;

use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A deliberately small, fictional estate for browser acceptance of native
 * Purchase -> Device Receiving. It is invoked only by the guarded companion
 * script, which accepts saverpos_demo_* databases exclusively.
 */
class SaverposDeviceAcceptanceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $userId = $this->insert('users', [
                'surname' => 'Acceptance', 'first_name' => 'SAVERPOS', 'last_name' => 'Operator',
                'username' => 'saverpos.acceptance', 'email' => 'acceptance@saverpos.test',
                'password' => Hash::make('demo-pass'), 'language' => 'en', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $currencyId = (int) DB::table('currencies')->where('code', 'MYR')->value('id');
            if (! $currencyId) {
                throw new \RuntimeException('The disposable acceptance fixture requires the MYR currency seed.');
            }
            $businessId = $this->insert('business', [
                'name' => 'SAVERPOS Device Acceptance', 'currency_id' => $currencyId, 'start_date' => $now->toDateString(),
                'tax_number_1' => 'ACCEPTANCE-ONLY', 'tax_label_1' => 'Tax', 'owner_id' => $userId,
                'time_zone' => 'Asia/Kuching', 'fy_start_month' => 1, 'accounting_method' => 'fifo',
                'sell_price_tax' => 'includes', 'default_profit_percent' => 0,
                'enabled_modules' => json_encode(['purchases', 'add_sale', 'pos_sale']),
                'ref_no_prefixes' => json_encode(['purchase' => 'PO']), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->update('users', $userId, ['business_id' => $businessId, 'updated_at' => $now]);
            $schemeId = $this->insert('invoice_schemes', [
                'business_id' => $businessId, 'name' => 'Acceptance invoice scheme', 'scheme_type' => 'blank',
                'prefix' => 'ACC-', 'start_number' => 1, 'total_digits' => 4, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $layoutId = $this->insert('invoice_layouts', [
                'business_id' => $businessId, 'name' => 'Acceptance invoice layout', 'is_default' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $locationId = $this->insert('business_locations', [
                'business_id' => $businessId, 'name' => 'Acceptance Branch', 'landmark' => 'Fictional local fixture',
                'country' => 'Malaysia', 'state' => 'Sabah', 'city' => 'Kota Kinabalu', 'zip_code' => '88000',
                'invoice_scheme_id' => $schemeId, 'invoice_layout_id' => $layoutId, 'sale_invoice_layout_id' => $layoutId,
                'is_active' => 1, 'receipt_printer_type' => 'browser',
                'default_payment_accounts' => json_encode(SaverposDemoRuntimeSeeder::demoPaymentAccounts()),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $unitId = $this->insert('units', [
                'business_id' => $businessId, 'actual_name' => 'Pieces', 'short_name' => 'pcs', 'allow_decimal' => 0,
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->product($businessId, $locationId, $unitId, $userId, $now, 'Acceptance Laptop', 'ACC-LAPTOP', 700, 1000, 'SERIALIZED_DEVICE');
            $this->product($businessId, $locationId, $unitId, $userId, $now, 'Acceptance USB-C Charger', 'ACC-CHARGER', 18, 30, 'BULK');
            $this->insert('contacts', [
                'business_id' => $businessId, 'type' => 'supplier', 'supplier_business_name' => 'Acceptance Technology Supplier',
                'name' => 'Acceptance Technology Supplier', 'contact_id' => 'SUP-ACCEPTANCE-001',
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);

            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            $role = Role::query()->firstOrCreate(['name' => 'saverpos-acceptance-admin', 'guard_name' => 'web', 'business_id' => $businessId]);
            $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
            User::query()->findOrFail($userId)->assignRole($role);
            $this->command?->info("SAVERPOS device acceptance fixture: business={$businessId}; branch={$locationId}; supplier=SUP-ACCEPTANCE-001; laptop=ACC-LAPTOP; charger=ACC-CHARGER.");
        });
    }

    private function product(int $businessId, int $locationId, int $unitId, int $userId, $now, string $name, string $sku, float $cost, float $sell, string $trackingMode): void
    {
        $productId = $this->insert('products', [
            'name' => $name, 'business_id' => $businessId, 'type' => 'single', 'unit_id' => $unitId,
            'tax_type' => 'inclusive', 'enable_stock' => 1, 'enable_sr_no' => $trackingMode === 'SERIALIZED_DEVICE' ? 1 : 0,
            'alert_quantity' => 0, 'sku' => $sku, 'barcode_type' => 'C128', 'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = $this->insert('product_variations', ['name' => 'Default', 'product_id' => $productId, 'is_dummy' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $variationId = $this->insert('variations', [
            'name' => 'Default', 'product_id' => $productId, 'product_variation_id' => $templateId, 'sub_sku' => $sku,
            'default_purchase_price' => $cost, 'dpp_inc_tax' => $cost, 'profit_percent' => 0,
            'default_sell_price' => $sell, 'sell_price_inc_tax' => $sell, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('product_locations')->insert(['product_id' => $productId, 'location_id' => $locationId]);
        DB::table('recommerce_serialization_profiles')->insert([
            'business_id' => $businessId, 'product_id' => $productId, 'variation_id' => $variationId,
            'mode' => 'TRACKED_REQUIRED', 'inventory_tracking_mode' => $trackingMode,
            'inspection_required' => $trackingMode === 'SERIALIZED_DEVICE', 'configured_by' => $userId,
            'approval_reference' => 'SAVERPOS-DEVICE-ACCEPTANCE', 'version' => 1, 'effective_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
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
