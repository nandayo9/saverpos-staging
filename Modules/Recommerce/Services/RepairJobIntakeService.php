<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\RepairChecklistItem;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairStateTransition;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\RepairJobStateMachine;

class RepairJobIntakeService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected ?CustomerRepairDeviceService $deviceService = null,
        protected ?RepairPublicLookupService $lookupService = null
    )
    {
    }

    public function create(User $user, array $data): RepairJob
    {
        $businessId = (int) $user->business_id;
        $locationId = (int) $data['location_id'];

        try {
            $commandHash = hash('sha256', json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        } catch (\JsonException $exception) {
            throw new LogicException('Repair intake contains unsupported text encoding.', 0, $exception);
        }

        if (! User::can_access_this_location($locationId, $businessId)
            || ! $this->authorizationGate->allowsWriteLocation(
                $user,
                'recommerce.repair.intake',
                $businessId,
                $locationId
            )) {
            throw new AuthorizationException();
        }

        return DB::transaction(function () use ($user, $data, $businessId, $locationId, $commandHash): RepairJob {
            DB::table('business')->where('id', $businessId)->lockForUpdate()->first();

            $existing = RepairJob::query()
                ->where('business_id', $businessId)
                ->where('command_uuid', $data['command_uuid'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->command_hash !== null
                    && ! hash_equals((string) $existing->command_hash, $commandHash)) {
                    throw new LogicException('Idempotency key was reused for a different repair intake.');
                }

                return $existing;
            }

            if (($data['assigned_to'] ?? null) !== null) {
                $technician = User::query()
                    ->where('business_id', $businessId)
                    ->whereKey((int) $data['assigned_to'])
                    ->first();
                $permittedLocations = $technician?->permitted_locations($businessId);
                if (! $technician || ($permittedLocations !== 'all'
                    && ! in_array($locationId, (array) $permittedLocations, true))) {
                    throw new LogicException('Choose a technician permitted to work at this location.');
                }
            }

            if (($data['job_type'] ?? null) === RepairJobStateMachine::TYPE_CUSTOMER_REPAIR) {
                $allowedChecks = collect(config('recommerce.repair_intake_checklist', []))->keyBy('key');
                $seenChecks = [];
                foreach ($data['checklist'] ?? [] as $item) {
                    $key = (string) ($item['check_key'] ?? '');
                    if (! in_array(($item['outcome'] ?? null), ['PASS', 'FAIL', 'NOT_APPLICABLE'], true)) {
                        throw new LogicException('Choose PASS, FAIL, or NOT APPLICABLE for every checklist item.');
                    }
                    if (! isset($allowedChecks[$key]) || in_array($key, $seenChecks, true)
                        || $allowedChecks[$key]['label'] !== $item['label']) {
                        throw new LogicException('Choose each checklist item from the approved intake checklist.');
                    }
                    $seenChecks[] = $key;
                }
                if ($allowedChecks->isNotEmpty() && count($seenChecks) !== $allowedChecks->count()) {
                    throw new LogicException('Complete every approved pre-repair checklist item before submitting.');
                }

                $device = ($this->deviceService ?: app(CustomerRepairDeviceService::class))->resolveOrCreate($user, $data);
            } elseif (($data['job_type'] ?? null) === RepairJobStateMachine::TYPE_INTERNAL_REFURBISHMENT) {
                $device = Device::query()
                    ->where('business_id', $businessId)
                    ->whereKey((int) ($data['device_id'] ?? 0))
                    ->lockForUpdate()
                    ->first();
                if (! $device || (int) $device->current_location_id !== $locationId) {
                    throw new LogicException('Device is outside the approved Repair intake scope.');
                }
                if ($device->ownership_kind !== 'BUSINESS') {
                    throw new LogicException('Internal refurbishment requires a business-owned Device.');
                }
                if (! $device->variation_id || ! $this->authorizationGate->allowsWrite(
                    $user,
                    'recommerce.repair.intake',
                    $businessId,
                    $locationId,
                    (int) $device->variation_id
                )) {
                    throw new AuthorizationException();
                }
            } else {
                throw new LogicException('Unsupported Repair job type.');
            }

            if (($data['job_type'] ?? null) === RepairJobStateMachine::TYPE_CUSTOMER_REPAIR
                && ($device->ownership_kind !== 'CUSTOMER'
                    || (int) $device->current_owner_contact_id !== (int) $data['contact_id'])) {
                throw new LogicException('The selected device must be owned by the selected customer.');
            }

            $jobUuid = (string) Str::uuid();
            $job = new RepairJob([
                'business_id' => $businessId,
                'location_id' => $locationId,
                'device_id' => (int) $device->id,
                'contact_id' => $data['contact_id'] ?? null,
                'job_type' => $data['job_type'],
                'command_hash' => $commandHash,
                'priority' => $data['priority'] ?? 'NORMAL',
                'assigned_to' => $data['assigned_to'] ?? null,
                'intake_snapshot_json' => $data['intake_snapshot_json'] ?? null,
                'reported_fault' => $data['reported_fault'] ?? null,
                'cosmetic_condition' => $data['cosmetic_condition'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'estimated_quote_amount' => $data['estimated_quote_amount'] ?? null,
                'warranty_json' => $data['warranty_json'] ?? null,
                'access_status' => $data['access_status'] ?? 'NO_LOCK',
                'customer_facing_update' => $data['customer_facing_update']
                    ?? 'Device received. We will update you after diagnosis.',
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'opened_at' => now(),
                'created_by' => $user->getAuthIdentifier(),
                'updated_by' => $user->getAuthIdentifier(),
            ]);
            $job->job_uuid = $jobUuid;
            $job->command_uuid = (string) $data['command_uuid'];
            $job->job_code = 'SB-RP-'.strtoupper(str_replace('-', '', $jobUuid));
            $job->state = RepairJobStateMachine::STATE_RECEIVED;
            $job->lock_version = 1;

            RepairJobStateMachine::assertNewJob($job);
            $job->save();

            foreach (($data['checklist'] ?? []) as $item) {
                RepairChecklistItem::create([
                    'business_id' => $businessId,
                    'location_id' => $locationId,
                    'repair_job_id' => $job->id,
                    'check_key' => $item['check_key'],
                    'label' => $item['label'],
                    'outcome' => $item['outcome'],
                    'notes' => $item['notes'] ?? null,
                    'observed_by' => $user->id,
                    'observed_at' => now(),
                ]);
            }

            RepairStateTransition::create([
                'business_id' => $businessId,
                'location_id' => $locationId,
                'repair_job_id' => $job->id,
                'transition_uuid' => (string) Str::uuid(),
                'command_uuid' => $job->command_uuid,
                'to_state' => RepairJobStateMachine::STATE_RECEIVED,
                'evidence_json' => ['reason' => $job->isCustomerRepair()
                    ? 'CUSTOMER_REPAIR_INTAKE'
                    : 'INTERNAL_REFURBISHMENT_INTAKE'],
                'actor_id' => $user->id,
                'occurred_at' => now(),
            ]);

            if ($job->isCustomerRepair()) {
                [$lookupToken, $rawToken] = ($this->lookupService ?: app(RepairPublicLookupService::class))
                    ->issue($job, (int) $user->id);
                $job->setRelation('lookupToken', $lookupToken);
                $job->lookup_raw_token = $rawToken;
            }

            return $job;
        });
    }
}
