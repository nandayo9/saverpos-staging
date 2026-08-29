<?php

namespace Modules\Recommerce\Support\Identity;

use InvalidArgumentException;
use RuntimeException;

class OpaqueScanToken
{
    public function issue(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $rawToken): string
    {
        $this->assertToken($rawToken);

        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Application key is required for scan-token hashing.');
        }

        return hash_hmac('sha256', $rawToken, $key);
    }

    public function matches(string $rawToken, string $storedHash): bool
    {
        if (strlen($rawToken) !== 64 || ! ctype_xdigit($rawToken)
            || $storedHash === '' || ! ctype_xdigit($storedHash) || strlen($storedHash) !== 64) {
            return false;
        }

        return hash_equals($storedHash, $this->hash($rawToken));
    }

    private function assertToken(string $rawToken): void
    {
        if (strlen($rawToken) !== 64 || ! ctype_xdigit($rawToken)) {
            throw new InvalidArgumentException('Scan token must be a 256-bit hexadecimal value.');
        }
    }
}
