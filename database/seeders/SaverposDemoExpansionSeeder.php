<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\OwnershipPeriod;

/**
 * Adds the larger fictional catalog to an already-created SAVERPOS Demo
 * database. It is deliberately idempotent and refuses non-demo businesses.
 */
class SaverposDemoExpansionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $business = DB::table('business')->where('name', 'SAVERPOS Demo')->first();
            if (! $business) {
                $this->command?->info('Demo expansion skipped: no SAVERPOS Demo business found.');
                return;
            }

            $business = $this->syncDemoCurrency($business);
            $this->syncDemoPaymentAccounts((int) $business->id);
            $now = now();
            $userId = (int) (DB::table('users')->where('business_id', $business->id)->orderBy('id')->value('id') ?: $business->owner_id);
            $locationIds = DB::table('business_locations')->where('business_id', $business->id)->orderBy('id')->pluck('id')->values();
            if ($locationIds->isEmpty() || ! $userId) {
                $this->command?->warn('Demo expansion skipped: the demo business has no usable user or location.');
                return;
            }
            $branchA = (int) $locationIds->first();
            $branchB = (int) ($locationIds->get(1) ?: $branchA);
            $unitId = (int) DB::table('units')->where('business_id', $business->id)->orderBy('id')->value('id');
            if (! $unitId) {
                $this->command?->warn('Demo expansion skipped: the demo business has no unit.');
                return;
            }

            $products = [
                ['name' => 'SaverBro Demo Smartphone', 'sku' => 'SAVERPOS-DEMO-PHONE', 'cost' => 650, 'sell' => 799, 'qty' => 3],
                ['name' => 'SaverBro Demo Laptop', 'sku' => 'SAVERPOS-DEMO-LAPTOP', 'cost' => 1800, 'sell' => 2199, 'qty' => 2],
                ['name' => 'SaverBro Demo CCTV Camera', 'sku' => 'SAVERPOS-DEMO-CAMERA', 'cost' => 220, 'sell' => 299, 'qty' => 6],
                ['name' => 'SaverBro Demo Network Router', 'sku' => 'SAVERPOS-DEMO-ROUTER', 'cost' => 140, 'sell' => 199, 'qty' => 5],
            ];
            $purchaseTotal = 0;
            $purchaseLines = [];
            foreach ($products as $index => $product) {
                $productId = (int) DB::table('products')->where('business_id', $business->id)->where('sku', $product['sku'])->value('id');
                if (! $productId) {
                    $productId = $this->insert('products', [
                        'name' => $product['name'], 'business_id' => $business->id, 'type' => 'single', 'unit_id' => $unitId,
                        'tax_type' => 'inclusive', 'enable_stock' => 1, 'alert_quantity' => 1,
                        'sku' => $product['sku'], 'barcode_type' => 'C128', 'created_by' => $userId,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $variationTemplateId = (int) DB::table('product_variations')->where('product_id', $productId)->orderBy('id')->value('id');
                if (! $variationTemplateId) {
                    $variationTemplateId = $this->insert('product_variations', [
                        'name' => 'Default', 'product_id' => $productId, 'is_dummy' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $variationId = (int) DB::table('variations')->where('product_id', $productId)->orderBy('id')->value('id');
                if (! $variationId) {
                    $variationId = $this->insert('variations', [
                        'name' => 'Default', 'product_id' => $productId, 'product_variation_id' => $variationTemplateId,
                        'sub_sku' => $product['sku'], 'default_purchase_price' => $product['cost'],
                        'dpp_inc_tax' => $product['cost'], 'profit_percent' => 0,
                        'default_sell_price' => $product['sell'], 'sell_price_inc_tax' => $product['sell'],
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                foreach ([$branchA, $branchB] as $locationId) {
                    DB::table('product_locations')->updateOrInsert(
                        ['product_id' => $productId, 'location_id' => $locationId],
                        []
                    );
                }
                $detail = DB::table('variation_location_details')
                    ->where(['product_id' => $productId, 'product_variation_id' => $variationTemplateId, 'variation_id' => $variationId, 'location_id' => $branchA])
                    ->exists();
                if (! $detail) {
                    $this->insert('variation_location_details', [
                        'product_id' => $productId, 'product_variation_id' => $variationTemplateId, 'variation_id' => $variationId,
                        'location_id' => $branchA, 'qty_available' => $product['qty'], 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                DB::table('recommerce_serialization_profiles')->updateOrInsert(
                    ['business_id' => $business->id, 'product_id' => $productId, 'variation_id' => $variationId],
                    ['mode' => 'TRACKED_REQUIRED', 'configured_by' => $userId, 'approval_reference' => 'SAVERPOS-DEMO-CATALOG', 'effective_at' => $now, 'updated_at' => $now]
                );
                $purchaseTotal += $product['cost'] * $product['qty'];
                $purchaseLines[] = [$productId, $variationId, $product['cost'], $product['qty']];
            }

            $supplierId = $this->contact($business->id, 'supplier', 'SUP-DEMO-002', 'Demo Technology Supplier', $userId, $now);
            foreach ([['Aisha Rahman', 'CUS-DEMO-002'], ['Daniel Lim', 'CUS-DEMO-003'], ['Mei Tan', 'CUS-DEMO-004']] as $customer) {
                $this->contact($business->id, 'customer', $customer[1], $customer[0], $userId, $now);
            }
            $purchase = DB::table('transactions')->where('business_id', $business->id)->where('type', 'purchase')->where('ref_no', 'PO-DEMO-002')->first();
            $purchaseId = $purchase?->id ?: $this->insert('transactions', [
                'business_id' => $business->id, 'location_id' => $branchA, 'type' => 'purchase', 'status' => 'received',
                'payment_status' => 'due', 'contact_id' => $supplierId, 'ref_no' => 'PO-DEMO-002',
                'transaction_date' => $now, 'total_before_tax' => $purchaseTotal, 'final_total' => $purchaseTotal,
                'exchange_rate' => 1, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($purchaseLines as $line) {
                $lineExists = DB::table('purchase_lines')->where('transaction_id', $purchaseId)->where('variation_id', $line[1])->exists();
                if (! $lineExists) {
                    $this->insert('purchase_lines', [
                        'transaction_id' => $purchaseId, 'product_id' => $line[0], 'variation_id' => $line[1],
                        'quantity' => $line[3], 'quantity_sold' => 0, 'purchase_price' => $line[2], 'purchase_price_inc_tax' => $line[2],
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            foreach ($purchaseLines as $index => $line) {
                for ($unit = 1; $unit <= $line[3]; $unit++) {
                    $deviceCode = sprintf('SB-DV-%08d-%d', $index + 2, $unit);
                    $device = Device::query()->firstOrCreate(
                        ['device_code' => $deviceCode],
                        [
                            'business_id' => $business->id, 'device_uuid' => (string) Str::uuid(),
                            'ownership_kind' => 'BUSINESS', 'custody_kind' => 'LOCATION', 'current_location_id' => $branchA,
                            'product_id' => $line[0], 'variation_id' => $line[1], 'lifecycle_state' => 'AVAILABLE',
                            'stock_participation' => 'ON_HAND', 'lock_version' => 1, 'created_by' => $userId, 'updated_by' => $userId,
                        ]
                    );
                    if (! $device->wasRecentlyCreated) {
                        continue;
                    }
                    $movement = DeviceMovement::create([
                        'device_id' => $device->id, 'business_id' => $business->id, 'movement_type' => 'RECEIVE',
                        'from_custody_kind' => 'SUPPLIER', 'to_custody_kind' => 'LOCATION', 'to_location_id' => $branchA,
                        'source_transaction_id' => $purchaseId, 'source_line_type' => 'purchase_line', 'occurred_at' => $now, 'recorded_by' => $userId,
                    ]);
                    OwnershipPeriod::create([
                        'device_id' => $device->id, 'business_id' => $business->id, 'owner_kind' => 'BUSINESS',
                        'starts_at' => $now, 'open_period_key' => $device->id, 'reason' => 'DEMO_RECEIVE', 'recorded_by' => $userId,
                    ]);
                    CustodyPeriod::create([
                        'device_id' => $device->id, 'business_id' => $business->id, 'custody_kind' => 'LOCATION',
                        'location_id' => $branchA, 'starts_at' => $now, 'open_period_key' => $device->id,
                        'source_movement_id' => $movement->id, 'reason' => 'DEMO_RECEIVE', 'recorded_by' => $userId,
                    ]);
                }
            }
            // Reaching the already-deployed estate matters as much as the
            // fresh one: the runtime seeder only ever runs against an empty
            // database, so a fixture added there alone never arrives on staging.
            $repairJobs = SaverposDemoRepairFixture::apply((int) $business->id, $branchA, $userId, $this->command);
            SaverposDemoDiagnosticFixture::apply((int) $business->id, $userId, $this->command);

            $this->command?->info("SAVERPOS demo expansion ready: products=5; devices=17; repair_jobs={$repairJobs}; purchase=PO-DEMO-002.");
        });
    }

    private function syncDemoCurrency(object $business): object
    {
        $currencyId = DB::table('currencies')->where('code', 'MYR')->value('id');
        if ($currencyId === null) {
            throw new \RuntimeException('The SAVERPOS demo requires the MYR currency seed.');
        }

        if ((int) $business->currency_id !== (int) $currencyId) {
            DB::table('business')->where('id', $business->id)->update([
                'currency_id' => $currencyId,
                'updated_at' => now(),
            ]);
            $business->currency_id = $currencyId;
        }

        return $business;
    }

    /**
     * Demo branches created before the payment-account fixture existed have a
     * null `default_payment_accounts`, and Ultimate POS reads that as "no
     * payment type is enabled here" — the register then offers none at all.
     * Fill in only the missing types so anything already configured stands.
     */
    private function syncDemoPaymentAccounts(int $businessId): void
    {
        $defaults = SaverposDemoRuntimeSeeder::demoPaymentAccounts();
        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->get(['id', 'default_payment_accounts']);

        foreach ($locations as $location) {
            $configured = json_decode((string) $location->default_payment_accounts, true);
            if (! is_array($configured)) {
                $configured = [];
            }

            $repaired = $configured + $defaults;
            if ($repaired === $configured) {
                continue;
            }

            DB::table('business_locations')->where('id', $location->id)->update([
                'default_payment_accounts' => json_encode($repaired),
                'updated_at' => now(),
            ]);
        }
    }

    private function contact(int $businessId, string $type, string $contactId, string $name, int $userId, $now): int
    {
        $existing = DB::table('contacts')->where('business_id', $businessId)->where('contact_id', $contactId)->first();
        if ($existing) {
            return (int) $existing->id;
        }
        return $this->insert('contacts', [
            'business_id' => $businessId, 'type' => $type,
            'supplier_business_name' => $type === 'supplier' ? $name : null,
            'name' => $name, 'contact_id' => $contactId, 'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function insert(string $table, array $values): int
    {
        $columns = array_flip(Schema::getColumnListing($table));
        return (int) DB::table($table)->insertGetId(array_intersect_key($values, $columns));
    }
}
