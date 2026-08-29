#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\RuntimeDatabasePreflight;
use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);

require $projectRoot.'/vendor/autoload.php';

Dotenv::createImmutable($projectRoot)->safeLoad();

$value = static function (string $key, ?string $default = null): ?string {
    $environment = getenv($key);

    if ($environment !== false && $environment !== '') {
        return $environment;
    }

    return $_ENV[$key] ?? $default;
};

$result = (new RuntimeDatabasePreflight())->inspect([
    'connection' => $value('DB_CONNECTION'),
    'database' => $value('DB_DATABASE'),
    'host' => $value('DB_HOST'),
    'port' => $value('DB_PORT'),
    'username' => $value('DB_USERNAME'),
    'password' => $value('DB_PASSWORD'),
    'charset' => $value('DB_CHARSET', 'utf8mb4'),
]);

fwrite(STDOUT, "SAVERPOS runtime preflight\n");
fwrite(STDOUT, ($result['ok'] ? 'PASS: ' : 'FAIL: ').$result['message']."\n");

if (! empty($result['missing_tables'])) {
    fwrite(STDOUT, 'Missing tables: '.implode(', ', $result['missing_tables'])."\n");
}

if (! $result['ok']) {
    fwrite(STDOUT, "No database changes were made. Configure or restore a complete disposable local SAVERPOS fixture before browser testing.\n");
}

exit($result['ok'] ? 0 : 1);
