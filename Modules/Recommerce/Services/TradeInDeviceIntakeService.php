<?php

namespace Modules\Recommerce\Services;

use App\Contact;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceIdentifier;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\OwnershipPeriod;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;

/** Creates or resolves the canonical customer-owned Device Passport for V2 intake. */
class TradeInDeviceIntakeService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected DeviceEventRecorder $eventRecorder
    ) {
    }

    public function resolveOrCreate(User $user, array $data): Device
    {
        $businessId = (int) $user->business_id;
        $locationId = (int) ($data['location_id'] ?? 0);
        $customerId = (int) ($data['customer_contact_id'] ?? 0);
        $variationId = (int) ($data['variation_id'] ?? 0);
        if ($locationId < 1 || $customerId < 1 || $variationId < 1) {
            throw new LogicException('Trade-in device intake requires an authorised branch, seller, and product match.');
        }
        if (! User::can_access_this_location($locationId, $businessId)
            || ! $this->authorizationGate->allowsWrite($user, TradeInService::PERMISSION_MANAGE, $businessId, $locationId, $variationId)) {
            throw new AuthorizationException('Trade-in device intake scope denied.');
        }

        return DB::transaction(function () use ($user, $data, $businessId, $locationId, $customerId): Device {
            DB::table('business')->where('id', $businessId)->lockForUpdate()->first();
            $customer = Contact::query()->where('business_id', $businessId)->whereKey($customerId)
                ->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $customer) {
                throw new LogicException('Select an active seller from this business.');
            }

            $device = $this->findDevice($businessId, $data);
            if ($device) {
                if ($device->ownership_kind !== 'CUSTOMER' || (int) $device->current_owner_contact_id !== $customerId
                    || $device->stock_participation !== 'NONE') {
                    throw new LogicException('This serial or Device Passport belongs to a different owner or is already in business stock.');
                }
                if ($device->current_location_id !== null && (int) $device->current_location_id !== $locationId) {
                    throw new LogicException('This customer Device is currently held at another branch.');
                }

                return $device;
            }

            $identifierType = strtoupper(trim((string) ($data['identifier_type'] ?? '')));
            $identifierValue = trim((string) ($data['identifier_value'] ?? ''));
            if (! in_array($identifierType, ['SERIAL', 'IMEI'], true) || $identifierValue === '') {
                throw new LogicException('A serial number or IMEI is required before creating a new Device Passport.');
            }
            $identifier = StrongIdentifierHasher::normalize($identifierValue);
            $alreadyExists = DeviceIdentifier::query()->where('business_id', $businessId)
                ->where('identifier_type', $identifierType)
                ->where('normalized_hash', StrongIdentifierHasher::hash($identifier))->exists();
            if ($alreadyExists) {
                throw new LogicException('This serial or IMEI is already registered. Search and use the existing Device Passport.');
            }

            $brand = $this->text($data['brand'] ?? null, 'Device brand', 100);
            $model = $this->text($data['model'] ?? null, 'Device model', 160);
            $device = Device::create([
                'business_id' => $businessId,
                'device_uuid' => (string) Str::uuid(),
                'device_code' => 'SB-DV-TEMP-'.Str::random(24),
                'category_code' => strtoupper(trim((string) ($data['category_code'] ?? 'LAPTOP'))),
                'ownership_kind' => 'CUSTOMER',
                'current_owner_contact_id' => $customerId,
                'custody_kind' => 'LOCATION',
                'current_location_id' => $locationId,
                'product_id' => (int) $data['product_id'],
                'variation_id' => (int) $data['variation_id'],
                'lifecycle_state' => 'UNDER_EVALUATION',
                'stock_participation' => 'NONE',
                'specifications_json' => (array) ($data['specifications'] ?? []) + ['brand' => $brand, 'model' => $model],
                'manufacturer_serial_display' => $identifierType === 'SERIAL' ? '••••'.mb_substr($identifier, -4) : null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $device->device_code = DeviceCode::forDeviceId((int) $device->id);
            $device->save();
            DeviceIdentifier::create([
                'device_id' => $device->id,
                'business_id' => $businessId,
                'identifier_type' => $identifierType,
                'raw_value_encrypted' => $identifier,
                'normalized_hash' => StrongIdentifierHasher::hash($identifier),
                'is_verified' => false,
            ]);
            OwnershipPeriod::create([
                'device_id' => $device->id, 'business_id' => $businessId, 'owner_kind' => 'CONTACT',
                'contact_id' => $customerId, 'starts_at' => now(), 'open_period_key' => $device->id,
                'reason' => 'TRADE_IN_EVALUATION', 'recorded_by' => $user->id,
            ]);
            $movement = DeviceMovement::create([
                'device_id' => $device->id, 'business_id' => $businessId, 'movement_type' => 'TRADE_IN_INTAKE',
                'to_custody_kind' => 'LOCATION', 'to_location_id' => $locationId,
                'command_uuid' => (string) $data['command_uuid'], 'occurred_at' => now(), 'recorded_by' => $user->id,
                'reason' => 'Customer-owned device received for trade-in evaluation',
            ]);
            CustodyPeriod::create([
                'device_id' => $device->id, 'business_id' => $businessId, 'custody_kind' => 'LOCATION',
                'location_id' => $locationId, 'starts_at' => now(), 'open_period_key' => $device->id,
                'source_movement_id' => $movement->id, 'reason' => 'TRADE_IN_EVALUATION', 'recorded_by' => $user->id,
            ]);
            $this->eventRecorder->recordLifecycle($device->fresh(), 'TRADE_IN_DEVICE_REGISTERED', (int) $user->id, null, [
                'identifier_type' => $identifierType,
                'intake_kind' => 'NEW_DEVICE_PASSPORT',
            ]);

            return $device->fresh();
        });
    }

    protected function findDevice(int $businessId, array $data): ?Device
    {
        if ((int) ($data['device_id'] ?? 0) > 0) {
            return Device::query()->where('business_id', $businessId)->whereKey((int) $data['device_id'])->lockForUpdate()->first();
        }
        $type = strtoupper(trim((string) ($data['identifier_type'] ?? '')));
        $value = trim((string) ($data['identifier_value'] ?? ''));
        if ($type === 'DEVICE_CODE' && $value !== '') {
            return Device::query()->where('business_id', $businessId)->where('device_code', DeviceCode::normalize($value))->lockForUpdate()->first();
        }
        if (in_array($type, ['SERIAL', 'IMEI'], true) && $value !== '') {
            $normalized = StrongIdentifierHasher::normalize($value);
            return Device::query()->where('business_id', $businessId)->whereHas('identifiers', function ($query) use ($businessId, $type, $normalized) {
                $query->where('business_id', $businessId)->where('identifier_type', $type)
                    ->where('normalized_hash', StrongIdentifierHasher::hash($normalized));
            })->lockForUpdate()->first();
        }

        return null;
    }

    protected function text($value, string $label, int $limit): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $limit) {
            throw new LogicException($label.' is required.');
        }

        return $value;
    }
}
