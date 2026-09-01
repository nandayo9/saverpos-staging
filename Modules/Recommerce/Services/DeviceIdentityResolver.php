<?php

namespace Modules\Recommerce\Services;

use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceIdentifier;
use Modules\Recommerce\Entities\ScanToken;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;
use Modules\Recommerce\Support\Identity\ScanInput;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;

/**
 * Resolves a physical-device identifier to the one canonical Device Passport.
 * Resolution is exact after the approved identifier normalization only; this
 * class deliberately never creates, edits, or fuzzy-matches Device identity.
 */
class DeviceIdentityResolver
{
    public function __construct(protected ?OpaqueScanToken $scanTokens = null)
    {
        $this->scanTokens ??= new OpaqueScanToken();
    }

    public function resolve(int $businessId, string $input, bool $lock = false): ?Device
    {
        $parsed = ScanInput::parse($input);
        if ($parsed && $parsed['type'] === 'DEVICE_CODE') {
            return $this->deviceQuery($businessId, $lock)->where('device_code', $parsed['value'])->first();
        }

        // Preserve exact lookup for historical SaverBro codes issued before
        // the current check-character format. This remains an exact match and
        // never turns arbitrary text into a new Device identity.
        $exactCode = strtoupper(trim($input));
        if ($exactCode !== '') {
            $device = $this->deviceQuery($businessId, $lock)->where('device_code', $exactCode)->first();
            if ($device) {
                return $device;
            }
        }

        if ($parsed && $parsed['type'] === 'DEVICE_TOKEN') {
            try {
                $token = ScanToken::query()
                    ->where('business_id', $businessId)
                    ->where('subject_type', 'DEVICE')
                    ->where('status', 'ACTIVE')
                    ->where('token_hash', $this->scanTokens->hash($parsed['value']))
                    ->first();
            } catch (\Throwable) {
                return null;
            }

            return $token ? $this->deviceQuery($businessId, $lock)->whereKey($token->device_id)->first() : null;
        }

        try {
            $hash = StrongIdentifierHasher::hash(StrongIdentifierHasher::normalize($input));
        } catch (\Throwable) {
            return null;
        }

        // A single raw identifier might legitimately be recorded under two
        // identifier types on the same Device. It must never select between
        // different Devices, so only an unambiguous Device result is returned.
        $deviceIds = DeviceIdentifier::query()
            ->where('business_id', $businessId)
            ->where('normalized_hash', $hash)
            ->pluck('device_id')
            ->unique()
            ->values();

        if ($deviceIds->count() !== 1) {
            return null;
        }

        return $this->deviceQuery($businessId, $lock)->whereKey((int) $deviceIds->first())->first();
    }

    private function deviceQuery(int $businessId, bool $lock)
    {
        $query = Device::query()->where('business_id', $businessId)->with('product');

        return $lock ? $query->lockForUpdate() : $query;
    }
}
