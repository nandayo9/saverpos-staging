<?php

namespace Tests\Unit;

use LogicException;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Modules\Recommerce\Providers\RouteServiceProvider;
use Modules\Recommerce\Http\Controllers\DataController;
use Modules\Recommerce\Services\TrackedReceivingService;
use App\User;
use Tests\TestCase;

class RecommerceBoundaryTest extends TestCase
{
    public function test_default_off_denies_matching_read_and_write_cohort()
    {
        config([
            'recommerce.enabled' => false,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [13],
        ]);

        $policy = new CohortPolicy();

        $this->assertFalse($policy->allowsReadVariation(7, 11, 13));
        $this->assertFalse($policy->allowsVariation(7, 11, 13));
    }

    public function test_read_cohort_does_not_require_write_switch_but_requires_variation_scope()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => false,
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [13],
        ]);

        $policy = new CohortPolicy();

        $this->assertTrue($policy->allowsReadLocation(7, 11));
        $this->assertTrue($policy->allowsReadVariation(7, 11, 13));
        $this->assertFalse($policy->allowsReadVariation(7, 11, 99));
        $this->assertFalse($policy->allowsVariation(7, 11, 13));
    }

    /**
     * CohortPolicy promises in its docblock that "empty or incomplete cohort
     * configuration always denies access", and RC-006 requires deny-by-default.
     * Nothing pinned that promise: a mutation making `matchesConfiguredId()`
     * return true for unconfigured ids survived the entire suite. An unset
     * `RECOMMERCE_COHORT_BUSINESS_ID` must never read as "matches anything".
     */
    public function test_unconfigured_cohort_denies_every_scope()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => null,
            'recommerce.cohort.location_id' => null,
            'recommerce.cohort.location_ids' => [],
            'recommerce.cohort.variation_ids' => [],
        ]);

        $policy = new CohortPolicy();

        $this->assertFalse($policy->allowsBusiness(7), 'Unconfigured business must deny.');
        $this->assertFalse($policy->allowsLocation(7, 11), 'Unconfigured location must deny.');
        $this->assertFalse($policy->allowsReadLocation(7, 11), 'Unconfigured read location must deny.');
        $this->assertFalse($policy->allowsReadVariation(7, 11, 13), 'Unconfigured read variation must deny.');
        $this->assertFalse($policy->allowsVariation(7, 11, 13), 'Unconfigured write variation must deny.');
    }

    /**
     * The same guarantee for blank configuration, which is what an env var
     * that is present but empty (`RECOMMERCE_COHORT_BUSINESS_ID=`) produces.
     */
    public function test_blank_cohort_configuration_denies_every_scope()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => '',
            'recommerce.cohort.location_id' => '',
            'recommerce.cohort.location_ids' => [],
            'recommerce.cohort.variation_ids' => [],
        ]);

        $policy = new CohortPolicy();

        $this->assertFalse($policy->allowsBusiness(7));
        $this->assertFalse($policy->allowsLocation(7, 11));
        $this->assertFalse($policy->allowsReadLocation(7, 11));
    }

    /**
     * A caller passing a null/blank subject id must be denied even when the
     * cohort itself is fully configured, so a missing tenant id can never be
     * read as a wildcard.
     */
    public function test_missing_subject_id_is_denied_against_a_configured_cohort()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [13],
        ]);

        $policy = new CohortPolicy();

        $this->assertFalse($policy->allowsBusiness(null), 'Null business id must deny.');
        $this->assertFalse($policy->allowsBusiness(''), 'Blank business id must deny.');
        $this->assertFalse($policy->allowsLocation(7, null), 'Null location id must deny.');
        $this->assertFalse($policy->allowsReadLocation(7, ''), 'Blank location id must deny.');
    }

    /**
     * An empty `variation_ids` list is an unconfigured variation scope, not an
     * open one. A mutation returning true for the empty list also survived the
     * suite, so both halves of that branch are pinned here.
     */
    public function test_empty_variation_list_denies_variation_scope_but_not_location_scope()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [],
        ]);

        $policy = new CohortPolicy();

        $this->assertTrue($policy->allowsReadLocation(7, 11), 'Location scope is still configured.');
        $this->assertFalse($policy->allowsReadVariation(7, 11, 13), 'Empty variation list must deny reads.');
        $this->assertFalse($policy->allowsVariation(7, 11, 13), 'Empty variation list must deny writes.');
    }

    public function test_authorization_requires_declared_permission_and_user_can_result()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [13],
            'recommerce.permissions' => ['recommerce.device.view'],
        ]);

        $user = new class {
            public function can($permission): bool
            {
                return $permission === 'recommerce.device.view';
            }
        };
        $gate = new AuthorizationGate(new CohortPolicy());

        $this->assertTrue($gate->allowsRead($user, 'recommerce.device.view', 7, 11, 13));
        $this->assertFalse($gate->allowsRead($user, 'recommerce.audit.view', 7, 11, 13));
        $this->assertFalse($gate->allowsRead(null, 'recommerce.device.view', 7, 11, 13));
    }

    /**
     * A permission being catalogued in `recommerce.permissions` must never, on
     * its own, authorize anything. The catalogue says a permission EXISTS; only
     * `$user->can()` says it was GRANTED. Conflating the two is how an endpoint
     * ends up open to every authenticated user in the cohort, so this pins the
     * distinction at the single point every Recommerce endpoint depends on.
     */
    public function test_catalogued_permission_is_not_granted_permission()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [13],
            'recommerce.permissions' => ['recommerce.device.view', 'recommerce.device.transfer'],
        ]);

        // A role that exists in the catalogue but was never granted to this user.
        $ungranted = new class {
            public function can($permission): bool
            {
                return false;
            }
        };
        $gate = new AuthorizationGate(new CohortPolicy());

        $this->assertFalse(
            $gate->allowsRead($ungranted, 'recommerce.device.view', 7, 11, 13),
            'Catalogued-but-ungranted permission must not authorize a read.'
        );
        $this->assertFalse(
            $gate->allowsWrite($ungranted, 'recommerce.device.transfer', 7, 11, 13),
            'Catalogued-but-ungranted permission must not authorize a write.'
        );
        $this->assertFalse(
            $gate->allowsWriteLocation($ungranted, 'recommerce.device.transfer', 7, 11),
            'Catalogued-but-ungranted permission must not authorize a location write.'
        );
    }

    /**
     * The mirror of the case above: a permission the user genuinely holds is
     * still refused unless it is catalogued, so an ad-hoc permission string
     * cannot widen the module's surface without appearing in config.
     */
    public function test_granted_permission_is_still_refused_when_not_catalogued()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [13],
            'recommerce.permissions' => ['recommerce.device.view'],
        ]);

        $grantsEverything = new class {
            public function can($permission): bool
            {
                return true;
            }
        };
        $gate = new AuthorizationGate(new CohortPolicy());

        $this->assertTrue($gate->allowsRead($grantsEverything, 'recommerce.device.view', 7, 11, 13));
        $this->assertFalse(
            $gate->allowsRead($grantsEverything, 'recommerce.audit.view', 7, 11, 13),
            'Uncatalogued permission must be refused even for a user that can() everything.'
        );
        $this->assertFalse(
            $gate->allowsWrite($grantsEverything, 'recommerce.device.transfer', 7, 11, 13),
            'Uncatalogued write permission must be refused even for a user that can() everything.'
        );
    }

    /**
     * Both halves must hold together, and cohort scope is still applied on top:
     * the right user with the right granted permission is denied out of scope.
     */
    public function test_granted_and_catalogued_permission_is_still_cohort_scoped()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [13],
            'recommerce.permissions' => ['recommerce.device.transfer'],
        ]);

        $user = new class {
            public function can($permission): bool
            {
                return $permission === 'recommerce.device.transfer';
            }
        };
        $gate = new AuthorizationGate(new CohortPolicy());

        $this->assertTrue($gate->allowsWrite($user, 'recommerce.device.transfer', 7, 11, 13));
        $this->assertFalse($gate->allowsWrite($user, 'recommerce.device.transfer', 8, 11, 13), 'Other business.');
        $this->assertFalse($gate->allowsWrite($user, 'recommerce.device.transfer', 7, 12, 13), 'Other location.');
        $this->assertFalse($gate->allowsWrite($user, 'recommerce.device.transfer', 7, 11, 99), 'Other variation.');
    }

    public function test_native_role_editor_metadata_exposes_every_catalogued_recommerce_permission()
    {
        config(['recommerce.permissions' => [
            'recommerce.device.view',
            'recommerce.repair.intake',
            'recommerce.repair.parts.resolve',
        ]]);

        $permissions = (new DataController())->user_permissions();

        $this->assertSame([
            'recommerce.device.view',
            'recommerce.repair.intake',
            'recommerce.repair.parts.resolve',
        ], array_column($permissions, 'name'));
        $this->assertSame('View Recommerce devices', $permissions[0]['label']);
        $this->assertSame('Resolve repair part consumption', $permissions[2]['label']);
    }

    public function test_native_role_editor_has_a_human_label_for_every_catalogued_permission(): void
    {
        $catalogued = array_values(config('recommerce.permissions', []));
        $metadata = (new DataController())->user_permissions();
        $labels = [];

        foreach ($metadata as $permission) {
            $labels[$permission['name']] = $permission['label'];
        }

        $this->assertSame($catalogued, array_column($metadata, 'name'));
        foreach ($catalogued as $permission) {
            $this->assertArrayHasKey($permission, $labels);
            $this->assertIsString($labels[$permission]);
            $this->assertNotSame('', trim($labels[$permission]));
            $this->assertNotSame($permission, $labels[$permission], $permission.' is missing a human-readable role-editor label.');
        }
    }

    public function test_native_role_editor_metadata_matches_the_generic_permission_control_shape(): void
    {
        config(['recommerce.permissions' => ['recommerce.tradein.view']]);

        $permission = (new DataController())->user_permissions()[0];

        $this->assertSame('recommerce.tradein.view', $permission['name']);
        $this->assertSame('recommerce.tradein.view', $permission['value']);
        $this->assertFalse($permission['default']);
    }

    public function test_disabled_route_provider_registers_no_recommerce_routes()
    {
        config(['recommerce.enabled' => false]);
        $routesBefore = app('router')->getRoutes()->count();

        (new RouteServiceProvider(app()))->map();

        $this->assertSame($routesBefore, app('router')->getRoutes()->count());
    }

    public function test_explicitly_enabled_route_provider_registers_guarded_contract_without_implying_writes()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => false,
        ]);

        (new RouteServiceProvider(app()))->map();

        $routes = collect(app('router')->getRoutes()->getRoutes());
        $postRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.receiving.post');
        $labelPrintRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.devices.label.print');
        $prepareRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.receiving.prepare');
        $receivingIndexRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.receiving.index');
        $reconciliationRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.reconciliation.show');
        $reconciliationIndexRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.reconciliation.index');
        $dashboardRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.dashboard');
        $deviceIndexRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.devices.index');
        $internalRepairRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.repair.internal.create');
        $eventsRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.devices.events');
        $policy = new CohortPolicy();

        $this->assertNotNull($postRoute);
        $this->assertSame('recommerce/receiving/post', $postRoute->uri());
        $this->assertContains('POST', $postRoute->methods());
        $this->assertContains('web', $postRoute->middleware());
        $this->assertContains('auth', $postRoute->middleware());
        $this->assertContains('SetSessionData', $postRoute->middleware());
        $this->assertNotNull($labelPrintRoute);
        $this->assertSame('recommerce/devices/{deviceId}/label/print', $labelPrintRoute->uri());
        $this->assertContains('POST', $labelPrintRoute->methods());
        $this->assertContains('auth', $labelPrintRoute->middleware());
        $this->assertContains('throttle:10,1', $labelPrintRoute->middleware());
        $this->assertNotNull($prepareRoute);
        $this->assertNotNull($receivingIndexRoute);
        $this->assertSame('recommerce/receiving', $receivingIndexRoute->uri());
        $this->assertContains('GET', $receivingIndexRoute->methods());
        $this->assertContains('web', $receivingIndexRoute->middleware());
        $this->assertContains('auth', $receivingIndexRoute->middleware());
        $this->assertContains('SetSessionData', $receivingIndexRoute->middleware());
        $this->assertContains('AdminSidebarMenu', $receivingIndexRoute->middleware());
        $this->assertContains('throttle:30,1', $receivingIndexRoute->middleware());
        $this->assertNotNull($reconciliationRoute);
        $this->assertNotNull($reconciliationIndexRoute);
        $this->assertSame('recommerce/reconciliation', $reconciliationIndexRoute->uri());
        $this->assertNotNull($dashboardRoute);
        $this->assertSame('recommerce', $dashboardRoute->uri());
        $this->assertContains('AdminSidebarMenu', $dashboardRoute->middleware());
        $this->assertNotNull($deviceIndexRoute);
        $this->assertSame('recommerce/devices', $deviceIndexRoute->uri());
        $this->assertNotNull($internalRepairRoute);
        $this->assertSame('recommerce/repair/internal/new', $internalRepairRoute->uri());
        $this->assertNotNull($eventsRoute);
        $this->assertSame('recommerce/devices/{deviceCode}/events', $eventsRoute->uri());
        $this->assertContains('GET', $eventsRoute->methods());
        $this->assertContains('web', $eventsRoute->middleware());
        $this->assertContains('auth', $eventsRoute->middleware());
        $this->assertContains('throttle:60,1', $eventsRoute->middleware());
        $this->assertSame('recommerce/reconciliation/{variationId}', $reconciliationRoute->uri());
        $this->assertContains('GET', $reconciliationRoute->methods());
        $this->assertContains('auth', $reconciliationRoute->middleware());
        $this->assertContains('throttle:60,1', $reconciliationRoute->middleware());
        $this->assertFalse($policy->allowsVariation(7, 11, 13));

        $healthRoute = $routes->first(fn ($route) => $route->getName() === 'recommerce.health');
        $this->assertNotNull($healthRoute);
        $this->assertSame('recommerce/health', $healthRoute->uri());
        $this->assertContains('GET', $healthRoute->methods());
        $healthPayload = ($healthRoute->getAction('uses'))()->getData(true);
        $this->assertSame('native-pos-integrated', $healthPayload['status']);
        $this->assertSame('ultimate-pos-admin-sidebar', $healthPayload['navigation']);
        $this->assertSame('cohort-and-permission-gated', $healthPayload['operational_writes']);
    }

    public function test_ultimate_pos_seam_requires_a_writer_before_execution()
    {
        $service = new TrackedReceivingService(new AuthorizationGate(new CohortPolicy()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('writer is not available');

        $service->executeWithUltimatePosPurchase(new User(), []);
    }

    public function test_invalid_receiving_command_is_rejected_before_core_callback()
    {
        $service = new TrackedReceivingService(new AuthorizationGate(new CohortPolicy()));
        $coreCallbackCalled = false;

        try {
            $service->execute(new User(), [], function () use (&$coreCallbackCalled): array {
                $coreCallbackCalled = true;

                return [];
            });
            $this->fail('Expected the receiving command to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('valid business_id', $exception->getMessage());
        }

        $this->assertFalse($coreCallbackCalled);
    }

    public function test_invalid_identifier_is_rejected_before_core_callback()
    {
        config(['app.key' => 'test-only-app-key']);
        $service = new TrackedReceivingService(new AuthorizationGate(new CohortPolicy()));
        $coreCallbackCalled = false;

        try {
            $service->execute(new User(), array_merge($this->validReceivingCommand(), [
                'units' => [[
                    'identifier_type' => 'SERIAL',
                    'identifier_value' => "SN-INVALID\x00",
                    'unit_acquisition_cost' => 10,
                ]],
            ]), function () use (&$coreCallbackCalled): array {
                $coreCallbackCalled = true;

                return [];
            });
            $this->fail('Expected the invalid identifier to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('invalid identifier', $exception->getMessage());
        }

        $this->assertFalse($coreCallbackCalled);
    }

    public function test_business_scope_denial_happens_before_core_callback()
    {
        $service = new TrackedReceivingService(new AuthorizationGate(new CohortPolicy()));
        $user = new User();
        $user->business_id = 7;
        $coreCallbackCalled = false;

        $this->expectException(AuthorizationException::class);

        try {
            $service->execute($user, $this->validReceivingCommand(8), function () use (&$coreCallbackCalled): array {
                $coreCallbackCalled = true;

                return [];
            });
        } finally {
            $this->assertFalse($coreCallbackCalled);
        }
    }

    public function test_write_permission_denial_happens_before_core_callback()
    {
        config([
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.permissions' => [],
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 11,
            'recommerce.cohort.variation_ids' => [13],
        ]);

        $service = new TrackedReceivingService(new AuthorizationGate(new CohortPolicy()));
        $user = new User();
        $user->business_id = 7;
        $coreCallbackCalled = false;

        $this->expectException(AuthorizationException::class);

        try {
            $service->execute($user, $this->validReceivingCommand(), function () use (&$coreCallbackCalled): array {
                $coreCallbackCalled = true;

                return [];
            });
        } finally {
            $this->assertFalse($coreCallbackCalled);
        }
    }

    private function validReceivingCommand(int $businessId = 7): array
    {
        return [
            'business_id' => $businessId,
            'location_id' => 11,
            'product_id' => 12,
            'variation_id' => 13,
            'command_uuid' => '11111111-1111-4111-8111-111111111111',
            'purchase' => [
                'contact_id' => 14,
                'transaction_date' => '2026-08-27',
                'unit_purchase_price' => 10,
                'unit_purchase_price_inc_tax' => 10,
                'unit_item_tax' => 0,
            ],
            'units' => [[
                'identifier_type' => 'SERIAL',
                'identifier_value' => 'SN-CONTRACT-001',
                'unit_acquisition_cost' => 10,
            ]],
        ];
    }
}
