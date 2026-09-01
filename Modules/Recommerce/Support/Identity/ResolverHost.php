<?php

namespace Modules\Recommerce\Support\Identity;

/**
 * Resolves the one approved HTTPS origin used by opaque Device QR labels.
 *
 * A dedicated resolver host remains the preferred override. When it has not
 * been configured, the canonical application URL is safe to use: unlike a
 * request Host header, it is server-side deployment configuration.
 */
final class ResolverHost
{
    public static function value(): ?string
    {
        $configuredHost = config('recommerce.resolver_host');

        if (is_string($configuredHost) && trim($configuredHost) !== '') {
            return self::hostFromConfiguredValue($configuredHost);
        }

        $appUrl = config('app.url');

        return is_string($appUrl) ? self::hostFromCanonicalAppUrl($appUrl) : null;
    }

    private static function hostFromConfiguredValue(string $configuredHost): ?string
    {
        // This setting is deliberately a host[:port], not an arbitrary URL.
        // Prefixing the known scheme lets parse_url validate that boundary.
        $parts = parse_url('https://'.trim($configuredHost));

        if (! is_array($parts)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '')) {
            return null;
        }

        return self::formatHost($parts);
    }

    private static function hostFromCanonicalAppUrl(string $appUrl): ?string
    {
        $parts = parse_url(trim($appUrl));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return self::formatHost($parts);
    }

    private static function formatHost(array $parts): ?string
    {
        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? null;

        if ($port !== null && (! is_int($port) || $port < 1 || $port > 65535)) {
            return null;
        }

        $formattedHost = str_contains($host, ':') ? '['.$host.']' : $host;

        return $formattedHost.($port !== null ? ':'.$port : '');
    }
}
