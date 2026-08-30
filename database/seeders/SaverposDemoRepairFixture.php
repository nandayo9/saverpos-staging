<?php

namespace Database\Seeders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\OwnershipPeriod;
use Modules\Recommerce\Entities\RepairChecklistItem;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairStateTransition;
use Modules\Recommerce\Services\DeviceEventRecorder;
use Modules\Recommerce\Services\RepairJobTransitionService;
use Modules\Recommerce\Services\RepairPublicLookupService;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Support\RepairJobStateMachine;
use Ramsey\Uuid\Uuid;

/**
 * Fictional customer-repair queue for the disposable SAVERPOS demo estate.
 *
 * Without it the demo database contains zero repair jobs, so the repair queue,
 * repair record, quote, parts, and collection screens cannot be walked at all.
 *
 * Only CUSTOMER_REPAIR jobs are seeded, deliberately. A customer-owned device
 * carries `stock_participation = NONE`, so nothing here changes core stock or
 * the tracked reconciliation counts that the staging smoke depends on. An
 * internal refurbishment job would have to consume one of the cohort-variation
 * units those flows already use.
 *
 * Rows are written directly, as the surrounding demo seeders already do for
 * devices, movements, and periods: a seeder is a data fixture, not an actor,
 * and it must not hold or grant the cohort/permission gate that
 * RepairJobIntakeService enforces for real intake. Everything that can be
 * borrowed from the module itself is: the state machine asserts each job is
 * legally formed, the real transition service performs every state advance,
 * and the checklist labels come from config rather than a copy.
 */
class SaverposDemoRepairFixture
{
    /** Namespace for the deterministic command UUIDs that make this idempotent. */
    private const COMMAND_NAMESPACE = 'saverpos-demo/repair-fixture/';

    /**
     * Fictional jobs, one per demo customer, spread across the states a real
     * counter queue holds. `checklist` overrides default PASS outcomes;
     * `advance_to` lists the states walked after intake, in order.
     */
    private const JOBS = [
        [
            'key' => 'cracked-display',
            'contact_reference' => 'CUS-DEMO-002',
            'category_code' => 'PHONE',
            'brand' => 'SaverBro',
            'model' => 'Demo Phone X1',
            'reported_fault' => 'Screen cracked after a drop. Touch responds on the left half only.',
            'cosmetic_condition' => 'Deep crack across the upper display; frame scuffed at two corners.',
            'access_status' => 'CUSTOMER_WILL_UNLOCK',
            'priority' => 'NORMAL',
            'customer_facing_update' => 'Device received. We will update you after diagnosis.',
            'due_in_days' => 3,
            'assigned' => false,
            'checklist' => ['display' => 'FAIL'],
            'advance_to' => [],
        ],
        [
            'key' => 'battery-drain',
            'contact_reference' => 'CUS-DEMO-003',
            'category_code' => 'LAPTOP',
            'brand' => 'SaverBro',
            'model' => 'Demo Laptop L14',
            'reported_fault' => 'Battery drains overnight while the lid is closed.',
            'cosmetic_condition' => 'Light wear on the palm rest. No impact damage.',
            'access_status' => 'NO_LOCK',
            'priority' => 'NORMAL',
            'customer_facing_update' => 'Diagnosis in progress. We will confirm the cause before any work starts.',
            'due_in_days' => 5,
            'assigned' => true,
            'checklist' => [],
            'advance_to' => [RepairJobStateMachine::STATE_DIAGNOSIS],
        ],
        [
            'key' => 'charging-port',
            'contact_reference' => 'CUS-DEMO-004',
            'category_code' => 'PHONE',
            'brand' => 'SaverBro',
            'model' => 'Demo Phone X2',
            'reported_fault' => 'Charging cable only holds at one angle; the device charges intermittently.',
            'cosmetic_condition' => 'Good condition. Charging port visibly loose.',
            'access_status' => 'CUSTOMER_WILL_UNLOCK',
            'priority' => 'HIGH',
            'customer_facing_update' => 'Diagnosis complete. We have sent a quote for your approval.',
            'due_in_days' => 2,
            'assigned' => true,
            'checklist' => ['buttons_ports' => 'FAIL'],
            'advance_to' => [
                RepairJobStateMachine::STATE_DIAGNOSIS,
                RepairJobStateMachine::STATE_AWAITING_APPROVAL,
            ],
        ],
        [
            'key' => 'no-display',
            'contact_reference' => 'CUS-DEMO-001',
            'category_code' => 'TABLET',
            'brand' => 'SaverBro',
            'model' => 'Demo Tablet T10',
            'reported_fault' => 'Powers on with sound but the screen stays black.',
            'cosmetic_condition' => 'Rear casing dented near the camera. Glass intact.',
            'access_status' => 'NO_LOCK',
            'priority' => 'URGENT',
            'customer_facing_update' => 'Approved work is underway. We will contact you when the device is ready.',
            'due_in_days' => 1,
            'assigned' => true,
            'checklist' => ['display' => 'FAIL', 'camera_audio' => 'NOT_APPLICABLE'],
            'advance_to' => [
                RepairJobStateMachine::STATE_DIAGNOSIS,
                RepairJobStateMachine::STATE_IN_REPAIR,
            ],
        ],
    ];

    /**
     * Creates every missing fixture job for this demo business. Returns the
     * number created; an estate that already has them is left untouched.
     *
     * Failures are reported and skipped rather than thrown: a missing demo
     * customer must not abort a deployment's seeding step.
     */
    public static function apply(int $businessId, int $locationId, int $userId, ?Command $command = null): int
    {
        $created = 0;

        foreach (self::JOBS as $definition) {
            $commandUuid = self::commandUuid($definition['key']);

            $existing = RepairJob::query()
                ->where('business_id', $businessId)
                ->where('command_uuid', $commandUuid)
                ->exists();
            if ($existing) {
                continue;
            }

            $contactId = (int) DB::table('contacts')
                ->where('business_id', $businessId)
                ->where('contact_id', $definition['contact_reference'])
                ->whereNull('deleted_at')
                ->value('id');
            if ($contactId < 1) {
                $command?->warn(sprintf(
                    'Demo repair fixture skipped %s: customer %s is missing.',
                    $definition['key'],
                    $definition['contact_reference']
                ));
                continue;
            }

            $device = self::customerDevice($businessId, $locationId, $contactId, $userId, $definition, $commandUuid);
            $job = self::openJob($businessId, $locationId, $contactId, $userId, $device, $definition, $commandUuid);

            foreach ($definition['advance_to'] as $state) {
                $job = app(RepairJobTransitionService::class)->transition($job, $state, [], null, $userId);
            }

            $created++;
        }

        return $created;
    }

    /**
     * Stable per-job idempotency key. The intake service treats command_uuid as
     * the natural key of an intake, so deriving it from the fixture key means a
     * re-run recognises its own rows instead of duplicating the queue.
     */
    private static function commandUuid(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::COMMAND_NAMESPACE.$key)->toString();
    }

    /**
     * The customer-owned device an intake creates, mirroring
     * CustomerRepairDeviceService: contact ownership, a location custody period
     * opened by a CUSTOMER_REPAIR_INTAKE movement, and no stock participation.
     * No identifier is recorded — the fixture holds no serial or IMEI to hash.
     */
    private static function customerDevice(
        int $businessId,
        int $locationId,
        int $contactId,
        int $userId,
        array $definition,
        string $commandUuid
    ): Device {
        $device = Device::create([
            'business_id' => $businessId,
            'device_uuid' => (string) Str::uuid(),
            'device_code' => 'SB-DV-TEMP-'.Str::random(24),
            'category_code' => $definition['category_code'],
            'ownership_kind' => 'CUSTOMER',
            'current_owner_contact_id' => $contactId,
            'custody_kind' => 'LOCATION',
            'current_location_id' => $locationId,
            'lifecycle_state' => 'RECEIVED',
            'stock_participation' => 'NONE',
            'specifications_json' => [
                'brand' => $definition['brand'],
                'model' => $definition['model'],
            ],
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $device->device_code = DeviceCode::forDeviceId((int) $device->id);
        $device->save();

        OwnershipPeriod::create([
            'device_id' => $device->id,
            'business_id' => $businessId,
            'owner_kind' => 'CONTACT',
            'contact_id' => $contactId,
            'starts_at' => now(),
            'open_period_key' => $device->id,
            'reason' => 'CUSTOMER_REPAIR_INTAKE',
            'recorded_by' => $userId,
        ]);

        $movement = DeviceMovement::create([
            'device_id' => $device->id,
            'business_id' => $businessId,
            'movement_type' => 'CUSTOMER_REPAIR_INTAKE',
            'to_custody_kind' => 'LOCATION',
            'to_location_id' => $locationId,
            'command_uuid' => $commandUuid,
            'occurred_at' => now(),
            'recorded_by' => $userId,
            'reason' => 'Customer-owned device accepted for repair',
        ]);

        CustodyPeriod::create([
            'device_id' => $device->id,
            'business_id' => $businessId,
            'custody_kind' => 'LOCATION',
            'location_id' => $locationId,
            'starts_at' => $movement->occurred_at,
            'open_period_key' => $device->id,
            'source_movement_id' => $movement->id,
            'reason' => 'CUSTOMER_REPAIR_INTAKE',
            'recorded_by' => $userId,
        ]);

        app(DeviceEventRecorder::class)->recordCustomerRepairIntake($device, $commandUuid, $userId);

        return $device->fresh();
    }

    /**
     * The RECEIVED job an intake opens, with its checklist, its opening
     * transition, and its public lookup token.
     *
     * `command_hash` stays null on purpose. The service derives it from the
     * submitted intake payload so a reused idempotency key cannot silently
     * accept different data; no payload was submitted here, and the service
     * already treats a null hash as "nothing to compare".
     */
    private static function openJob(
        int $businessId,
        int $locationId,
        int $contactId,
        int $userId,
        Device $device,
        array $definition,
        string $commandUuid
    ): RepairJob {
        $jobUuid = (string) Str::uuid();
        $job = new RepairJob([
            'business_id' => $businessId,
            'location_id' => $locationId,
            'device_id' => (int) $device->id,
            'contact_id' => $contactId,
            'job_type' => RepairJobStateMachine::TYPE_CUSTOMER_REPAIR,
            'priority' => $definition['priority'],
            'assigned_to' => $definition['assigned'] ? $userId : null,
            'intake_snapshot_json' => ['source' => 'saverpos_demo_fixture'],
            'reported_fault' => $definition['reported_fault'],
            'cosmetic_condition' => $definition['cosmetic_condition'],
            'due_at' => now()->addDays($definition['due_in_days'])->toDateString(),
            'access_status' => $definition['access_status'],
            'customer_facing_update' => $definition['customer_facing_update'],
            'opened_at' => now(),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $job->job_uuid = $jobUuid;
        $job->command_uuid = $commandUuid;
        $job->job_code = 'SB-RP-'.strtoupper(str_replace('-', '', $jobUuid));
        $job->state = RepairJobStateMachine::STATE_RECEIVED;
        $job->lock_version = 1;

        RepairJobStateMachine::assertNewJob($job);
        $job->save();

        foreach ((array) config('recommerce.repair_intake_checklist', []) as $check) {
            RepairChecklistItem::create([
                'business_id' => $businessId,
                'location_id' => $locationId,
                'repair_job_id' => $job->id,
                'check_key' => $check['key'],
                'label' => $check['label'],
                'outcome' => $definition['checklist'][$check['key']] ?? 'PASS',
                'observed_by' => $userId,
                'observed_at' => now(),
            ]);
        }

        RepairStateTransition::create([
            'business_id' => $businessId,
            'location_id' => $locationId,
            'repair_job_id' => $job->id,
            'transition_uuid' => (string) Str::uuid(),
            'command_uuid' => $commandUuid,
            'to_state' => RepairJobStateMachine::STATE_RECEIVED,
            'evidence_json' => ['reason' => 'CUSTOMER_REPAIR_INTAKE'],
            'actor_id' => $userId,
            'occurred_at' => now(),
        ]);

        app(RepairPublicLookupService::class)->issue($job, $userId);

        return $job;
    }
}
