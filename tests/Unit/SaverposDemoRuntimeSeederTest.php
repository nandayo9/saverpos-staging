<?php

namespace Tests\Unit;

use Carbon\Carbon;
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
        DB::table('currencies')->insert([
            ['id' => 1, 'code' => 'ALL'],
            ['id' => 75, 'code' => 'MYR'],
        ]);
    }

    public function test_demo_currency_is_explicitly_myr_instead_of_seed_order_dependent(): void
    {
        $currencyMethod = new ReflectionMethod(SaverposDemoRuntimeSeeder::class, 'demoCurrencyId');
        $currencyMethod->setAccessible(true);

        $this->assertSame(75, $currencyMethod->invoke(new SaverposDemoRuntimeSeeder()));
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
}
