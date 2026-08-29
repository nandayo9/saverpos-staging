<?php

namespace Modules\Recommerce\Services;

use App\Contact;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceIdentifier;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\OwnershipPeriod;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;

class CustomerRepairDeviceService
{
    public function __construct(protected AuthorizationGate $authorizationGate)
    {
    }

    public function resolveOrCreate(User $user, array $data): Device
    {
        $businessId = (int) $user->business_id;
        $locationId = (int) $data['location_id'];
        $contactId = (int) $data['contact_id'];

        if (! User::can_access_this_location($locationId, $businessId)
            || ! $this->authorizationGate->allowsWriteLocation(
                $user,
                'recommerce.repair.intake',
                $businessId,
                $locationId
            )) {
            throw new AuthorizationException();
        }

        foreach (['password', 'pin', 'pattern', 'credentials', 'access_secret'] as $forbidden) {
            if (array_key_exists($forbidden, $data) && trim((string) $data[$forbidden]) !== '') {
                throw new LogicException('Access credentials are not accepted. Record only the device access status.');
            }
        }

        return DB::transaction(function () use ($user, $data, $businessId, $locationId, $contactId): Device {
            DB::table('business')->where('id', $businessId)->lockForUpdate()->first();

            $contact = Contact::query()
                ->where('business_id', $businessId)
                ->whereIn('type', ['customer', 'both'])
                ->where('id', $contactId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if (! $contact) {
                throw new LogicException('Select an active customer from this business before continuing.');
            }

            $identifier = null;
            if (! empty($data['identifier_value'])) {
                if (empty($data['identifier_type'])) {
                    throw new LogicException('Choose whether the identifier is a serial, IMEI, or SaverBro device code.');
                }
                $identifier = $data['identifier_type'] === 'DEVICE_CODE'
                    ? DeviceCode::normalize((string) $data['identifier_value'])
                    : StrongIdentifierHasher::normalize((string) $data['identifier_value']);
            }

            $device = null;
            if (($data['identifier_type'] ?? null) === 'DEVICE_CODE' && $identifier !== null) {
                $device = Device::query()
                    ->where('business_id', $businessId)
                    ->where('device_code', $identifier)
                    ->lockForUpdate()
                    ->first();
            } elseif ($identifier !== null) {
                $device = Device::query()
                    ->where('business_id', $businessId)
                    ->whereHas('identifiers', function ($query) use ($businessId, $data, $identifier) {
                        $query->where('business_id', $businessId)
                            ->where('identifier_type', $data['identifier_type'])
                            ->where('normalized_hash', StrongIdentifierHasher::hash($identifier));
                    })
                    ->lockForUpdate()
                    ->first();
            }

            if (! $device && $identifier === null && ! empty($data['device_id'])) {
                $device = Device::query()
                    ->where('business_id', $businessId)
                    ->whereKey((int) $data['device_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $device) {
                    throw new LogicException('The selected existing device could not be found in this customer scope.');
                }
            }

            if (! $device && ($data['identifier_type'] ?? null) === 'DEVICE_CODE') {
                throw new LogicException('No matching SaverBro device code was found. Search by serial or IMEI to register a new customer device.');
            }

            if ($device) {
                if ($device->ownership_kind !== 'CUSTOMER'
                    || (int) $device->current_owner_contact_id !== $contactId
                    || (int) $device->current_location_id !== $locationId
                    || $device->stock_participation !== 'NONE') {
                    throw new LogicException('This device is already registered to another owner or location. Review the device record before intake.');
                }

                return $device;
            }

            $device = Device::create([
                'business_id' => $businessId,
                'device_uuid' => (string) Str::uuid(),
                'device_code' => 'SB-DV-TEMP-'.Str::random(24),
                'category_code' => $data['category_code'],
                'ownership_kind' => 'CUSTOMER',
                'current_owner_contact_id' => $contactId,
                'custody_kind' => 'LOCATION',
                'current_location_id' => $locationId,
                'lifecycle_state' => 'RECEIVED',
                'stock_participation' => 'NONE',
                'specifications_json' => [
                    'brand' => $data['brand'],
                    'model' => $data['model'],
                ],
                'created_by' => $user->id,
                'updated_by' => $user->id,
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
                'recorded_by' => $user->id,
            ]);

            $movement = DeviceMovement::create([
                'device_id' => $device->id,
                'business_id' => $businessId,
                'movement_type' => 'CUSTOMER_REPAIR_INTAKE',
                'to_custody_kind' => 'LOCATION',
                'to_location_id' => $locationId,
                'command_uuid' => $data['command_uuid'],
                'occurred_at' => now(),
                'recorded_by' => $user->id,
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
                'recorded_by' => $user->id,
            ]);

            if ($identifier !== null && ($data['identifier_type'] ?? null) !== 'DEVICE_CODE') {
                DeviceIdentifier::create([
                    'device_id' => $device->id,
                    'business_id' => $businessId,
                    'identifier_type' => $data['identifier_type'],
                    'raw_value_encrypted' => $identifier,
                    'normalized_hash' => StrongIdentifierHasher::hash($identifier),
                    'is_verified' => false,
                ]);
            }

            app(DeviceEventRecorder::class)->recordCustomerRepairIntake(
                $device,
                $data['command_uuid'],
                (int) $user->id
            );

            return $device->fresh();
        });
    }
}
