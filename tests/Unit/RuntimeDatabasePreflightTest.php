<?php

namespace Tests\Unit;

use App\Support\RuntimeDatabasePreflight;
use PDO;
use PHPUnit\Framework\TestCase;

class RuntimeDatabasePreflightTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'saverpos-preflight-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_it_reports_a_missing_sqlite_database_without_creating_it(): void
    {
        unlink($this->databasePath);

        $result = (new RuntimeDatabasePreflight())->inspect([
            'connection' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('The configured SQLite database file is unavailable.', $result['message']);
        $this->assertSame([], $result['missing_tables']);
        $this->assertFileDoesNotExist($this->databasePath);
    }

    public function test_it_reports_missing_core_tables(): void
    {
        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE system (id INTEGER PRIMARY KEY)');

        $result = (new RuntimeDatabasePreflight())->inspect([
            'connection' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('Database is reachable but its local SAVERPOS schema is incomplete.', $result['message']);
        $this->assertSame(['users', 'business', 'business_locations', 'transactions', 'walk_ins', 'permissions'], $result['missing_tables']);
    }

    public function test_it_accepts_a_complete_core_sqlite_schema(): void
    {
        $pdo = new PDO('sqlite:'.$this->databasePath);

        foreach (['system', 'users', 'business', 'business_locations', 'transactions', 'walk_ins', 'permissions'] as $table) {
            $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
        }

        $result = (new RuntimeDatabasePreflight())->inspect([
            'connection' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('Database is reachable and contains the core Walk-In UI schema.', $result['message']);
        $this->assertSame([], $result['missing_tables']);
    }
}
