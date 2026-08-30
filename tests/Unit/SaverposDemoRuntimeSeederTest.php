<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Database\Seeders\SaverposDemoRuntimeSeeder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SaverposDemoRuntimeSeederTest extends TestCase
{
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
