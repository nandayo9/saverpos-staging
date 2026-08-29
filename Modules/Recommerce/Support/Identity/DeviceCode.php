<?php

namespace Modules\Recommerce\Support\Identity;

use InvalidArgumentException;

class DeviceCode
{
    private const PREFIX = 'SB-DV-';

    private const ID_LENGTH = 8;

    public static function forDeviceId(int $deviceId): string
    {
        if ($deviceId < 1 || $deviceId > 99999999) {
            throw new InvalidArgumentException('Device ID is outside the supported code range.');
        }

        $digits = str_pad((string) $deviceId, self::ID_LENGTH, '0', STR_PAD_LEFT);

        return self::PREFIX . $digits . '-' . self::checkCharacter($digits);
    }

    public static function normalize(string $value): string
    {
        return strtoupper(trim($value));
    }

    public static function isValid(string $value): bool
    {
        $normalized = self::normalize($value);

        if (preg_match('/^SB-DV-(\d{8})-([0-9X])$/D', $normalized, $matches) !== 1) {
            return false;
        }

        return self::forDeviceId((int) $matches[1]) === $normalized;
    }

    private static function checkCharacter(string $digits): string
    {
        $sum = 0;
        $weight = 2;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $sum += ((int) $digits[$index]) * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $remainder = (11 - ($sum % 11)) % 11;

        return $remainder === 10 ? 'X' : (string) $remainder;
    }
}
