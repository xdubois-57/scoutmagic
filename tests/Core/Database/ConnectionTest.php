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
    public function testTestConnectionReturnsTrueWithValidCredentials(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        $connection = new Connection($host, $port, $dbName, $user, $password);
        $result = $connection->testConnection();

        $this->assertTrue($result);
    }

    /**
     * @group database
     */
    public function testGetPdoReturnsConfiguredInstance(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        $connection = new Connection($host, $port, $dbName, $user, $password);
        $pdo = $connection->getPdo();

        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        $this->assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
        $this->assertFalse($pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
    }
}
