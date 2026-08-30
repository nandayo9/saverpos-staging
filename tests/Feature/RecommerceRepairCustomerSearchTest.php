<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The repair intake page seeds its customer select with the first 200 contacts
 * by name, and its search box used to filter only those options client-side --
 * so customer 201 onwards could not be found at all. The search now calls this
 * endpoint, which had shipped with no caller and no coverage.
 */
class RecommerceRepairCustomerSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.permissions' => ['recommerce.repair.intake'],
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 101,
            'recommerce.cohort.location_ids' => [101],
            'recommerce.cohort.variation_ids' => [303],
        ]);

        DB::purge('sqlite');
        $schema = Schema::connection('sqlite');
        $schema->create('contacts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('type', 20)->nullable();
            $table->string('contact_id')->nullable();
            $table->string('mobile')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        $this->contact(1, 7, 'Aisha Rahman', 'customer', 'CUS-0001', '0111111111');
        $this->contact(2, 7, 'Zainab Yusof', 'customer', 'CUS-0900', '0199999999');
        $this->contact(3, 7, 'Both Party', 'both', 'CUS-0002', '0122222222');
        $this->contact(4, 7, 'Demo Supplier', 'supplier', 'SUP-0001', '0133333333');
        $this->contact(5, 7, 'Deleted Customer', 'customer', 'CUS-0003', '0144444444', now());
        $this->contact(6, 8, 'Other Business Customer', 'customer', 'CUS-0004', '0155555555');
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_it_finds_a_customer_the_seeded_list_would_have_missed(): void
    {
        $response = $this->search('Zainab');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([2], $this->ids($response));
        $this->assertSame('CUS-0900', $response->json('data.0.reference'));
    }

    public function test_it_searches_contact_reference_and_mobile_as_the_help_text_promises(): void
    {
        $this->assertSame([1], $this->ids($this->search('CUS-0001')));
        $this->assertSame([2], $this->ids($this->search('0199999999')));
    }

    public function test_it_returns_customers_and_both_but_not_suppliers_or_deleted_contacts(): void
    {
        // Every fixture mobile starts 01, so only type/deleted/business scoping
        // can explain who is missing from the result.
        $this->assertSame([1, 3, 2], $this->ids($this->search('01')));
    }

    public function test_a_short_term_returns_nothing_rather_than_the_whole_book(): void
    {
        $this->assertSame([], $this->ids($this->search('A')));
        $this->assertSame([], $this->ids($this->search('')));
    }

    public function test_it_never_returns_another_business_contact(): void
    {
        $this->assertSame([], $this->ids($this->search('Other Business')));
    }

    /**
     * A user whose role was never granted the intake permission must be refused,
     * even though the permission is catalogued in config -- this double's can()
     * does not read that catalogue.
     */
    public function test_a_user_without_the_granted_permission_is_refused(): void
    {
        $this->mapRecommerceRoutes();

        $this->actingAs($this->user([]))
            ->getJson('/recommerce/repair/customers?q=Aisha')
            ->assertNotFound();
    }

    protected function search(string $term)
    {
        $this->mapRecommerceRoutes();

        return $this->actingAs($this->user(['recommerce.repair.intake']))
            ->getJson('/recommerce/repair/customers?q='.urlencode($term));
    }

    protected function ids($response): array
    {
        return array_map('intval', array_column($response->json('data') ?? [], 'id'));
    }

    protected function mapRecommerceRoutes(): void
    {
        (new \Modules\Recommerce\Providers\RouteServiceProvider(app()))->map();
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
    }

    protected function contact(int $id, int $businessId, string $name, string $type, string $reference, string $mobile, $deletedAt = null): void
    {
        DB::table('contacts')->insert([
            'id' => $id, 'business_id' => $businessId, 'name' => $name, 'type' => $type,
            'contact_id' => $reference, 'mobile' => $mobile, 'deleted_at' => $deletedAt,
        ]);
    }

    protected function user(array $granted): User
    {
        $user = new class extends User {
            public array $granted = [];

            public function can($ability, $arguments = []): bool
            {
                return in_array($ability, $this->granted, true);
            }

            public function permitted_locations($business_id = null)
            {
                return [101];
            }
        };
        $user->id = 900;
        $user->business_id = 7;
        $user->granted = $granted;

        return $user;
    }
}
