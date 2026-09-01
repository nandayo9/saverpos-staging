<?php

namespace Modules\Recommerce\Support;

use InvalidArgumentException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Support\Identity\ResolverHost;

class LabelPayloadBuilder
{
    public function forDevice(Device $device, string $rawToken): array
    {
        if (! DeviceCode::isValid($device->device_code)) {
            throw new InvalidArgumentException('Device has no valid human code.');
        }

        if (preg_match('/^[A-Fa-f0-9]{64}$/D', $rawToken) !== 1) {
            throw new InvalidArgumentException('Label requires a valid opaque scan token.');
        }

        $resolverHost = ResolverHost::value();

        if (! is_string($resolverHost) || $resolverHost === '') {
            throw new InvalidArgumentException('Resolver host is not configured.');
        }

        $description = $device->relationLoaded('product') && $device->product
            ? $device->product->name
            : 'SaverBro device';

        return [
            'device_code' => DeviceCode::normalize($device->device_code),
            'code128_value' => DeviceCode::normalize($device->device_code),
            'qr_url' => 'https://'.$resolverHost.'/s/d/'.rawurlencode($rawToken),
            'safe_description' => $this->safeLabelText($description),
            'template_version' => (string) config('recommerce.label_template_version', 'alpha-1'),
        ];
    }

    protected function safeLabelText($value): string
    {
        $value = is_string($value) ? $value : 'SaverBro device';
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', trim($value));

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 80);
        }

        return substr($value, 0, 80);
    }
}
