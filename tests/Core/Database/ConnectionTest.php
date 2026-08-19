<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\Connection;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    public function testConstructorStoresParameters(): void
    {
        $connection = new Connection('localhost', 3306, 'test_db', 'user', 'pass');

        // Verify via reflection that parameters are stored
        $reflection = new \ReflectionClass($connection);

        $hostProp = $reflection->getProperty('host');
        $this->assertSame('localhost', $hostProp->getValue($connection));

        $portProp = $reflection->getProperty('port');
        $this->assertSame(3306, $portProp->getValue($connection));

        $dbNameProp = $reflection->getProperty('dbName');
        $this->assertSame('test_db', $dbNameProp->getValue($connection));
    }

    public function testTestConnectionReturnsErrorStringWithInvalidCredentials(): void
    {
        $connection = new Connection('invalid.invalid.host', 9999, 'nonexistent', 'nobody', 'wrong');

        $result = $connection->testConnection();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testTestConnectionReturnsTrueWithValidCredentials(): void
    {
        $connection = $this->connectionFromEnvironment();
        $result = $connection->testConnection();

        $this->assertTrue($result);
    }

    /**
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testGetPdoReturnsConfiguredInstance(): void
    {
        $connection = $this->connectionFromEnvironment();
        $pdo = $connection->getPdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        $this->assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
        $this->assertFalse($pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
    }

    /**
     * The MySQL server the TEST_DB_* variables point at, skipping the test
     * when it isn't reachable — the same contract as
     * Tests\Core\Database\MigrationRunnerTest and SchemaIntrospectorTest,
     * which this class was the only @group database holdout from. CI's
     * database-tests job provides the server; a local or Claude-on-the-web
     * checkout usually has none, and these two tests hard-failing there is
     * what made `phpunit --group database` unrunnable outside CI.
     */
    private function connectionFromEnvironment(): Connection
    {
        $connection = new Connection(
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306),
            getenv('TEST_DB_NAME') ?: 'test_db',
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASSWORD') ?: ''
        );

        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database connection not available: ' . $result);
        }

        return $connection;
    }

}
