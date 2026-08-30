<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Database\Seeders\SaverposDemoExpansionSeeder;
use Database\Seeders\SaverposDemoRuntimeSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class SaverposDemoRuntimeSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('currencies', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code');
        });
        Schema::create('business', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('currency_id');
            $table->timestamps();
        });
        DB::table('currencies')->insert([
            ['id' => 1, 'code' => 'ALL'],
            ['id' => 75, 'code' => 'MYR'],
        ]);
        DB::table('business')->insert([
            'id' => 1,
            'currency_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::create('business_locations', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->text('default_payment_accounts')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function test_demo_currency_is_explicitly_myr_instead_of_seed_order_dependent(): void
    {
        $currencyMethod = new ReflectionMethod(SaverposDemoRuntimeSeeder::class, 'demoCurrencyId');
        $currencyMethod->setAccessible(true);

        $this->assertSame(75, $currencyMethod->invoke(new SaverposDemoRuntimeSeeder()));
    }

    public function test_existing_demo_business_currency_is_repaired_to_myr(): void
    {
        $currencyMethod = new ReflectionMethod(SaverposDemoExpansionSeeder::class, 'syncDemoCurrency');
        $currencyMethod->setAccessible(true);

        $currencyMethod->invoke(new SaverposDemoExpansionSeeder(), (object) [
            'id' => 1,
            'currency_id' => 1,
        ]);

        $this->assertSame(75, (int) DB::table('business')->where('id', 1)->value('currency_id'));
    }

    public function test_demo_locations_include_the_complete_pos_payment_account_shape(): void
    {
        $locationMethod = new ReflectionMethod(SaverposDemoRuntimeSeeder::class, 'location');
        $locationMethod->setAccessible(true);

        $location = $locationMethod->invoke(
            new SaverposDemoRuntimeSeeder(),
            1,
            'Branch B',
            1,
            1,
            Carbon::parse('2026-08-30 00:00:00')
        );

        $paymentAccounts = json_decode($location['default_payment_accounts'], true, 512, JSON_THROW_ON_ERROR);
        $expectedPaymentTypes = [
            'cash', 'card', 'cheque', 'bank_transfer', 'other',
            'custom_pay_1', 'custom_pay_2', 'custom_pay_3', 'custom_pay_4',
            'custom_pay_5', 'custom_pay_6', 'custom_pay_7',
        ];

        $this->assertSame($expectedPaymentTypes, array_keys($paymentAccounts));

        foreach ($paymentAccounts as $paymentAccount) {
            $this->assertSame(1, $paymentAccount['is_enabled']);
            $this->assertNull($paymentAccount['account']);
        }
    }

    public function test_existing_demo_locations_without_payment_accounts_are_repaired(): void
    {
        $this->location(1, 1, null);

        $this->syncDemoPaymentAccounts(1);

        $repaired = json_decode(
            (string) DB::table('business_locations')->where('id', 1)->value('default_payment_accounts'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(array_keys(SaverposDemoRuntimeSeeder::demoPaymentAccounts()), array_keys($repaired));
        $this->assertSame(['is_enabled' => 1, 'account' => null], $repaired['cash']);
    }

    public function test_repairing_payment_accounts_preserves_what_the_location_already_configured(): void
    {
        $this->location(1, 1, json_encode([
            'cash' => ['is_enabled' => 0, 'account' => 7],
        ]));

        $this->syncDemoPaymentAccounts(1);

        $repaired = json_decode(
            (string) DB::table('business_locations')->where('id', 1)->value('default_payment_accounts'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(['is_enabled' => 0, 'account' => 7], $repaired['cash']);
        $this->assertSame(['is_enabled' => 1, 'account' => null], $repaired['card']);
        $this->assertCount(count(SaverposDemoRuntimeSeeder::demoPaymentAccounts()), $repaired);
    }

    public function test_repairing_payment_accounts_leaves_a_complete_location_untouched(): void
    {
        $complete = json_encode(SaverposDemoRuntimeSeeder::demoPaymentAccounts());
        $this->location(1, 1, $complete);
        DB::table('business_locations')->where('id', 1)->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->syncDemoPaymentAccounts(1);

        $location = DB::table('business_locations')->where('id', 1)->first();
        $this->assertSame($complete, $location->default_payment_accounts);
        $this->assertSame('2020-01-01 00:00:00', $location->updated_at);
    }

    public function test_repairing_payment_accounts_ignores_other_businesses_and_deleted_locations(): void
    {
        $this->location(1, 2, null);
        $this->location(2, 1, null, '2026-08-30 00:00:00');

        $this->syncDemoPaymentAccounts(1);

        $this->assertNull(DB::table('business_locations')->where('id', 1)->value('default_payment_accounts'));
        $this->assertNull(DB::table('business_locations')->where('id', 2)->value('default_payment_accounts'));
    }

    private function location(int $id, int $businessId, ?string $paymentAccounts, ?string $deletedAt = null): void
    {
        DB::table('business_locations')->insert([
            'id' => $id,
            'business_id' => $businessId,
            'default_payment_accounts' => $paymentAccounts,
            'deleted_at' => $deletedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function syncDemoPaymentAccounts(int $businessId): void
    {
        $method = new ReflectionMethod(SaverposDemoExpansionSeeder::class, 'syncDemoPaymentAccounts');
        $method->setAccessible(true);
        $method->invoke(new SaverposDemoExpansionSeeder(), $businessId);
    }
}
