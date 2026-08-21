<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

class Connection
{
    private ?\PDO $pdo = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $dbName,
        private string $user,
        private string $password
    ) {
    }

    /**
     * Build a Connection backed by an existing PDO instance instead of connecting
     * lazily from credentials. Intended for tests (e.g. an in-memory SQLite PDO).
     */
    public static function withPdo(\PDO $pdo): self
    {
        $connection = new self('', 0, '', '', '');
        $connection->pdo = $pdo;

        return $connection;
    }

    /**
     * Get the PDO instance (lazy connection — connects on first call).
     */
    public function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->dbName);

            $this->pdo = new \PDO($dsn, $this->user, $this->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $this->pdo->exec('SET NAMES utf8mb4');
        }

        return $this->pdo;
    }

    /**
     * The credentials this connection was built from, for the one caller
     * shape that genuinely needs them: Core\Database\DatabaseDumper opens
     * its own PDO connection from a DSN rather than reusing ours.
     *
     * Deliberately a narrow, named accessor rather than five getters — and
     * it replaces a `ReflectionClass` read of these same private properties
     * in Core\Maintenance\BackupService, which was strictly worse: it
     * bypassed every visibility guarantee to obtain exactly this, with
     * nothing to grep for.
     *
     * @return array{host: string, port: int, dbName: string, user: string, password: string}
     */
    public function dumpCredentials(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'dbName' => $this->dbName,
            'user' => $this->user,
            'password' => $this->password,
        ];
    }

    /**
     * Test the connection. Returns true on success, error message string on failure.
     */
    public function testConnection(): bool|string
    {
        try {
            $this->getPdo();
            return true;
        } catch (\PDOException $e) {
            $this->pdo = null;
            return $e->getMessage();
        }
    }
}
