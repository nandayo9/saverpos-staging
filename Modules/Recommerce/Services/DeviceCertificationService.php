<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceCertification;
use Modules\Recommerce\Entities\DeviceSaleDisposition;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Keeps the public QR projection deliberately smaller than the internal
 * Device record. Publishing never changes ownership, stock, a POS sale, or
 * accounting; it only certifies an already-sold physical Device.
 */
class DeviceCertificationService
{
    public function __construct(protected AuthorizationGate $authorizationGate)
    {
    }

    public function publish(User $user, Device $device, array $input): DeviceCertification
    {
        $this->assertPublishScope($user, $device);
        $data = $this->normalise($input);

        return DB::transaction(function () use ($user, $device, $data) {
            $lockedDevice = Device::query()
                ->where('id', $device->id)
                ->where('business_id', $user->business_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPublishScope($user, $lockedDevice);

            return DeviceCertification::query()->updateOrCreate(
                ['device_id' => $lockedDevice->id],
                $data + [
                    'business_id' => $lockedDevice->business_id,
                    'status' => 'ACTIVE',
                    'published_at' => now(),
                    'published_by' => $user->id,
                ]
            );
        });
    }

    /**
     * Returns an allowlisted customer projection. It intentionally excludes
     * ownership/custody, financials, identifiers, event history, and tokens.
     */
    public function publicProfile(Device $device): ?array
    {
        $certificate = $device->relationLoaded('certification')
            ? $device->certification
            : $device->certification()->first();

        if (! $certificate || $certificate->status !== 'ACTIVE') {
            return null;
        }

        return [
            'device_name' => $this->safeDeviceName($device),
            'masked_serial' => $this->maskSerial($device->manufacturer_serial_display),
            'grade' => $certificate->grade,
            'qc_passed' => (bool) $certificate->qc_passed,
            'battery_health_percent' => $certificate->battery_health_percent,
            'purchased_at' => $certificate->purchased_at,
            'warranty_expires_at' => $certificate->warranty_expires_at,
            'warranty_active' => $certificate->warranty_expires_at->copy()->endOfDay()->isFuture(),
            'warranty_service_url' => $this->warrantyServiceUrl(),
        ];
    }

    protected function assertPublishScope(User $user, Device $device): void
    {
        $saleDisposition = DeviceSaleDisposition::query()
            ->where('device_id', $device->id)
            ->whereNotNull('active_sale_key')
            ->orderByDesc('id')
            ->first();
        $saleLocationId = $saleDisposition
            ? DB::table('transactions')->where('id', $saleDisposition->sale_transaction_id)->value('location_id')
            : null;
        if ((string) $device->business_id !== (string) $user->business_id
            || empty($device->sold_at)
            || $device->lifecycle_state !== 'SOLD'
            || empty($saleLocationId)
            || ! $this->authorizationGate->allowsWrite(
                $user,
                'recommerce.device.certify',
                $user->business_id,
                $saleLocationId,
                $device->variation_id
            )) {
            throw new AuthorizationException('Device certification scope denied.');
        }
    }

    protected function normalise(array $input): array
    {
        $grade = strtoupper(trim((string) ($input['grade'] ?? '')));
        $batteryHealth = filter_var($input['battery_health_percent'] ?? null, FILTER_VALIDATE_INT);
        $purchasedAt = $this->date($input['purchased_at'] ?? null, 'Purchase date is required.');
        $warrantyExpiresAt = $this->date($input['warranty_expires_at'] ?? null, 'Warranty expiry is required.');

        if (! in_array($grade, ['A', 'B', 'C', 'D'], true)) {
            throw new InvalidArgumentException('Device grade is invalid.');
        }
        if ($batteryHealth === false || $batteryHealth < 0 || $batteryHealth > 100) {
            throw new InvalidArgumentException('Battery health must be between 0 and 100.');
        }
        if (($input['qc_passed'] ?? false) !== true) {
            throw new InvalidArgumentException('Only QC-passed devices may be certified publicly.');
        }
        if ($warrantyExpiresAt->lessThanOrEqualTo($purchasedAt)) {
            throw new InvalidArgumentException('Warranty expiry must be after the purchase date.');
        }

        return [
            'grade' => $grade,
            'qc_passed' => true,
            'battery_health_percent' => $batteryHealth,
            'purchased_at' => $purchasedAt,
            'warranty_expires_at' => $warrantyExpiresAt,
        ];
    }

    protected function date($value, string $message)
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($message);
        }

        try {
            return now()->parse($value)->startOfDay();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException($message, 0, $exception);
        }
    }

    protected function safeDeviceName(Device $device): string
    {
        $name = $device->relationLoaded('product') && $device->product
            ? $device->product->name
            : null;
        $name = is_string($name) ? preg_replace('/[\x00-\x1F\x7F]/', '', trim($name)) : '';

        if ($name === '') {
            return 'SaverBro Certified Device';
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
    }

    protected function maskSerial($serial): ?string
    {
        if (! is_string($serial)) {
            return null;
        }

        $serial = preg_replace('/[\x00-\x1F\x7F\s]/', '', $serial);
        if ($serial === '') {
            return null;
        }

        return '****'.substr($serial, -min(4, strlen($serial)));
    }

    protected function warrantyServiceUrl(): ?string
    {
        $url = config('recommerce.public_warranty_service_url');
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}
