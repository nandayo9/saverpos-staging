<?php

namespace Modules\Recommerce\Support\Identity;

class ScanInput
{
    public static function parse(string $input): ?array
    {
        // Check the original bytes before trim(); PHP trim() removes NUL and
        // other control bytes by default, which could otherwise turn a
        // malformed scan into a valid human Device code.
        if (preg_match('/[\x00-\x1F\x7F]/', $input) === 1) {
            return null;
        }

        $value = trim($input);

        if ($value === '' || strlen($value) > 512) {
            return null;
        }

        if (DeviceCode::isValid($value)) {
            return [
                'type' => 'DEVICE_CODE',
                'value' => DeviceCode::normalize($value),
            ];
        }

        $parts = parse_url($value);
        $resolverHost = ResolverHost::value();
        $resolverParts = $resolverHost !== null
            ? parse_url('https://'.$resolverHost)
            : false;

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_array($resolverParts)
            || ! is_string($resolverParts['host'] ?? null)
            || strtolower($parts['host'] ?? '') !== strtolower($resolverParts['host'])
            || ($parts['port'] ?? null) !== ($resolverParts['port'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($resolverParts['user'])
            || isset($resolverParts['pass'])
            || isset($resolverParts['path'])
            || isset($resolverParts['query'])
            || isset($resolverParts['fragment'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        if (preg_match('#^/s/d/([A-Fa-f0-9]{64})$#D', $parts['path'] ?? '', $matches) !== 1) {
            return null;
        }

        return [
            'type' => 'DEVICE_TOKEN',
            'value' => strtolower($matches[1]),
        ];
    }
}
