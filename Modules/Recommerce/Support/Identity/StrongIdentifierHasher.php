<?php

namespace Modules\Recommerce\Support\Identity;

use InvalidArgumentException;
use RuntimeException;

class StrongIdentifierHasher
{
    public static function normalize(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Identifier is empty or contains control characters.');
        }

        $normalized = strtoupper(trim($value));

        if ($normalized === '') {
            throw new InvalidArgumentException('Identifier is empty or contains control characters.');
        }

        $normalized = preg_replace('/[\s_-]+/', '', $normalized);

        if (! is_string($normalized) || $normalized === '' || strlen($normalized) > 255) {
            throw new InvalidArgumentException('Identifier is outside the supported format.');
        }

        return $normalized;
    }

    public static function hash(string $normalizedValue): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Application key is required for identifier hashing.');
        }

        return hash_hmac('sha256', $normalizedValue, $key);
    }
}
