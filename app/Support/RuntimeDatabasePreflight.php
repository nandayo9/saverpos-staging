<?php

namespace App\Support;

use PDO;
use Throwable;

final class RuntimeDatabasePreflight
{
    private const REQUIRED_TABLES = [
        'system',
        'users',
        'business',
        'business_locations',
        'transactions',
        'walk_ins',
        'permissions',
    ];

    /**
     * Check the minimum persistent schema needed to load and use Walk-In UI.
     *
     * @param array<string, mixed> $config
     * @return array{ok: bool, message: string, missing_tables: array<int, string>}
     */
    public function inspect(array $config): array
    {
        $connection = strtolower((string) ($config['connection'] ?? ''));

        try {
            $tables = match ($connection) {
                'sqlite' => $this->sqliteTables((string) ($config['database'] ?? '')),
                'mysql' => $this->mysqlTables($config),
                default => throw new \InvalidArgumentException('Unsupported DB_CONNECTION: '.($connection ?: '(not set)')),
            };
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $this->safeMessage($connection, $exception),
                'missing_tables' => [],
            ];
        }

        $missingTables = array_values(array_diff(self::REQUIRED_TABLES, $tables));

        if (! empty($missingTables)) {
            return [
                'ok' => false,
                'message' => 'Database is reachable but its local SAVERPOS schema is incomplete.',
                'missing_tables' => $missingTables,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Database is reachable and contains the core Walk-In UI schema.',
            'missing_tables' => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sqliteTables(string $database): array
    {
        if ($database === '' || $database === ':memory:' || ! is_file($database)) {
            throw new \RuntimeException('The configured SQLite database file is unavailable.');
        }

        $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return array_map(
            fn (array $row): string => (string) $row['name'],
            $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function mysqlTables(array $config): array
    {
        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw new \RuntimeException('The configured MySQL database name is unavailable.');
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        return array_map(
            fn (array $row): string => (string) $row['table_name'],
            $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function safeMessage(string $connection, Throwable $exception): string
    {
        if ($exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException) {
            return $exception->getMessage();
        }

        return 'Could not connect to the configured '.($connection ?: 'database').' database.';
    }
}
