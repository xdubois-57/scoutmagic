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

            // InstrumentedPdo: the same \PDO, plus a tally of every statement
            // executed and the time it took (Core\Database\QueryCounter), which
            // a ?debug=1 timeline stamps on each of its checkpoints.
            $this->pdo = new InstrumentedPdo($dsn, $this->user, $this->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $this->pdo->exec('SET NAMES utf8mb4');
            self::alignSessionTimeZone($this->pdo);
        }

        return $this->pdo;
    }

    /**
     * Make the server's idea of "now" the same as PHP's.
     *
     * Half of this codebase writes a timestamp from PHP (`date('Y-m-d H:i:s')`,
     * `(new \DateTimeImmutable())->format(...)`) and the other half lets a
     * column's `DEFAULT CURRENT_TIMESTAMP` — or a `NOW()` in a query — do it.
     * Every rate limiter, retention cutoff and scheduler query in the tree then
     * compares the two against each other. As long as both sides sat on UTC
     * that worked by accident; the moment the application declares a local
     * default zone (`Core\Config\AppClock`) it stops working, because only
     * one of the two sides moves: a rate-limit window whose lower
     * bound is computed in PHP lands one or two hours in the future relative to
     * every row the database wrote, and the limiter silently never fires again.
     *
     * So the session's time zone is set, at connect time, to whatever numeric
     * offset PHP's own default zone is on right now. A **numeric** offset, not
     * the zone name: `SET time_zone = 'Europe/Brussels'` requires the server's
     * `mysql.time_zone*` tables to be populated, which on shared hosting they
     * routinely are not — it fails with SQLSTATE HY000 / 1298 and would take
     * the whole install down. The offset is computed per connection, and a PHP
     * process serving one request (or one cron pass) never spans a DST switch
     * in practice.
     *
     * SQLite has no session time zone at all — its `CURRENT_TIMESTAMP` is UTC,
     * always — so nothing is executed there. Tests running on SQLite must write
     * their timestamps from PHP rather than leaning on a column default; that
     * is the same rule module authors get in docs/module-development.md.
     */
    private static function alignSessionTimeZone(\PDO $pdo): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }

        $statement = $pdo->prepare('SET time_zone = ?');
        $statement->execute([(new \DateTimeImmutable('now'))->format('P')]);
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
     * Test the connection. Returns `true` on success, or — on failure — a
     * short FRENCH sentence naming what the operator should check.
     *
     * The contract used to be "the PDOException's own message", which every
     * caller displayed as-is: Core\Http\Controller\SetupController shows it
     * in two JSON responses and one flash message. That put the DSN, the
     * MySQL driver's English text and the account name it tried straight
     * onto the setup page. The cut is made here rather than at the three
     * display sites so there is one place to get it right, and so a fourth
     * caller cannot reintroduce the leak by simply echoing the return value.
     *
     * The raw driver message is not lost — it goes to the PHP error log.
     * The journal is deliberately not used: there is usually no working
     * database to write it to at the moment this fails.
     */
    public function testConnection(): bool|string
    {
        try {
            $this->getPdo();
            return true;
        } catch (\PDOException $e) {
            $this->pdo = null;
            error_log('ScoutMagic database connection test failed: ' . $e->getMessage());

            return self::describeConnectionFailure($e);
        }
    }

    /**
     * Turns a PDO connection failure into the French sentence the operator
     * sees. The three cases below cover what an operator typing database
     * credentials into the setup form actually gets wrong; anything else
     * falls through to the generic sentence rather than being guessed at.
     */
    private static function describeConnectionFailure(\PDOException $e): string
    {
        // getCode() is the SQLSTATE for a connection failure; the driver
        // code (1045, 1049, 2002…) is only in errorInfo[1], which is null
        // for some failures — hence matching on both, defensively.
        $driverCode = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;

        return match (true) {
            $driverCode === 1045 || (string) $e->getCode() === '28000'
                => 'Identifiant ou mot de passe de base de données refusé par le serveur.',
            $driverCode === 1049
                => 'La base de données indiquée n\'existe pas sur ce serveur — créez-la, ou corrigez son nom.',
            $driverCode === 2002 || $driverCode === 2005
                => 'Le serveur de base de données est injoignable — vérifiez l\'hôte et le port.',
            default
                => 'La connexion à la base de données a échoué — vérifiez l\'hôte, le port, le nom de la base '
                    . 'et les identifiants.',
        };
    }
}
