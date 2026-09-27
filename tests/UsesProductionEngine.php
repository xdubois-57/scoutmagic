<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaFiles;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use PHPUnit\Framework\Attributes\AfterClass;

/**
 * A test on the engine production runs, rather than on SQLite.
 *
 * Almost the whole suite runs on `DatabaseTestHelper::createTestDatabase()`,
 * an in-memory SQLite laid out by hand — in every job, the
 * `database-mariadb` one included. That is right for most of it and blind to
 * the statements whose meaning depends on the engine: a subquery MySQL
 * refuses (the `LIMIT` inside an `IN (SELECT …)` that raised error 1235), an
 * upsert deciding from `rowCount()`, a lock, a date computed by the server,
 * a comparison the collation decides. Issue #481 chose to judge those, and
 * only those, on the real engine, and this is what makes that cheap to write.
 *
 * Two levels:
 *
 * - {@see productionEngine()} — **a database of this class's own**, created
 *   on the first test, holding the whole declared schema as the real
 *   {@see MigrationRunner} builds it from `schema/core.sql` and every module's
 *   `schema.sql` — no table written out by hand, so the columns, defaults,
 *   indexes and foreign keys are the ones an installed site has — and
 *   emptied before every test. It is dropped after the class. A database of
 *   its own because `TEST_DB_NAME` is shared with every other class that
 *   reaches the server, and a result that depended on what they left behind
 *   would depend on the order the suite ran in.
 * - {@see productionEngineConnection()} — only the connection, to
 *   `TEST_DB_NAME` or to a database the test names, for the classes whose
 *   subject is the schema itself (the migration runner, the introspector,
 *   the installer, backup and restore) and that lay out their own.
 *
 * The connection is a `Core\Database\Connection`, so it opens with the
 * attributes the site opens with — no `MYSQL_ATTR_FOUND_ROWS`, no emulated
 * prepares, the session time zone aligned on PHP's. A test that built its
 * own `\PDO` with different ones would judge a connection the site never
 * makes; `Tests\Core\Mail\Feedback\Bounce\BounceSendReceiptMysqlTest` exists
 * because `rowCount()` answers differently without that attribute.
 *
 * A refused connection goes through
 * `DatabaseTestHelper::skipOnlyWhenNoServerWasPromised()`: skipped on a
 * laptop with nothing on 3306, a failure wherever `TEST_DB_HOST` or `CI`
 * promised a server.
 *
 * The rule of when to reach for it is in `AGENTS.md` § Database.
 */
trait UsesProductionEngine
{
    /** The database {@see productionEngine()} built for this class, '' until then. */
    private static string $productionEngineDatabase = '';

    private static ?Connection $productionEngineSchema = null;

    /** @var list<string> */
    private static array $productionEngineTables = [];

    /**
     * The five `TEST_DB_*` variables, with the defaults every class reaching
     * the server has always used.
     *
     * `?:` rather than `??`, for the reason
     * `DatabaseTestHelper::skipOnlyWhenNoServerWasPromised()` gives: an
     * exported-but-empty `TEST_DB_HOST` is the default host, not a promise.
     *
     * @return array{host: string, port: int, dbName: string, user: string, password: string}
     */
    protected static function productionEngineCredentials(): array
    {
        return [
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'dbName' => getenv('TEST_DB_NAME') ?: 'test_db',
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASSWORD') ?: '',
        ];
    }

    /**
     * A connection to `TEST_DB_NAME`, or to `$database` when given, opened
     * the way the site opens one.
     *
     * Connected before it is returned, so a refused connection is decided
     * here — skipped or failed — rather than at the test's first query,
     * where it would read as the test's own error.
     */
    protected static function productionEngineConnection(?string $database = null): Connection
    {
        $credentials = self::productionEngineCredentials();
        $connection = new Connection(
            $credentials['host'],
            $credentials['port'],
            $database ?? $credentials['dbName'],
            $credentials['user'],
            $credentials['password']
        );

        try {
            $connection->getPdo();
        } catch (\PDOException $e) {
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised(
                'No MySQL/MariaDB server answered from TEST_DB_*: ' . $e->getMessage()
            );
        }

        return $connection;
    }

    /**
     * This class's own database, with the whole production schema, and
     * empty.
     *
     * Call it from `setUp()`. The first call creates the database and
     * migrates it — well under a second for the whole schema — and every
     * later one empties each table, which is what « a clean state between
     * two tests » costs when the DDL already ran. Emptied rather than
     * rebuilt: `CREATE TABLE` commits any open transaction on this engine,
     * so a rebuild could not be rolled back either, and it is the slow half.
     *
     * The rows are deleted rather than truncated — `TRUNCATE` on two hundred
     * empty tables is twenty times slower — so auto-increment counters carry
     * on from one test to the next. A test must not assume an id.
     */
    protected function productionEngine(): \PDO
    {
        if (self::$productionEngineSchema === null) {
            self::$productionEngineSchema = self::createProductionEngineDatabase();
        } else {
            self::emptyProductionEngineTables(self::$productionEngineSchema->getPdo());
        }

        $pdo = self::$productionEngineSchema->getPdo();
        // Back on PHP's offset, as `Connection` opened it: a test that
        // skewed the clock (below) does not skew the next one's.
        $pdo->prepare('SET time_zone = ?')->execute([(new \DateTimeImmutable('now'))->format('P')]);

        return $pdo;
    }

    /**
     * Put the server's clock six hours away from PHP's for this session,
     * and return the gap it now reports, in seconds.
     *
     * `Connection` aligns the session time zone on PHP's offset, so on the
     * test engine `NOW()` and PHP's clock agree to the second — and a test
     * meaning to prove a timestamp is PHP's, not a column default's or a
     * `NOW()`, would pass either way (the first review of issue #481's
     * part D caught three that did). Skewed, the two are hours apart and
     * a stored value says which clock wrote it. The next
     * {@see productionEngine()} puts the offset back.
     */
    protected function skewProductionEngineClock(): int
    {
        $pdo = $this->productionEngineSchemaConnection()->getPdo();
        $php = new \DateTimeImmutable('now');
        $skewed = $php->getOffset() + ($php->getOffset() >= 0 ? -6 : 6) * 3600;
        $hours = intdiv(abs($skewed), 3600);
        $minutes = intdiv(abs($skewed) % 3600, 60);
        $pdo->prepare('SET time_zone = ?')
            ->execute([sprintf('%s%02d:%02d', $skewed < 0 ? '-' : '+', $hours, $minutes)]);

        $now = $pdo->prepare('SELECT NOW()');
        $now->execute();
        $server = new \DateTimeImmutable((string) $now->fetchColumn());

        return abs($server->getTimestamp() - (new \DateTimeImmutable('now'))->getTimestamp());
    }

    /**
     * The same `Connection`, for the code under test that takes one rather
     * than a `\PDO`. Only after {@see productionEngine()}.
     */
    protected function productionEngineSchemaConnection(): Connection
    {
        if (self::$productionEngineSchema === null) {
            throw new \LogicException('Call productionEngine() first: it is what builds the database.');
        }

        return self::$productionEngineSchema;
    }

    #[AfterClass]
    public static function dropProductionEngineDatabase(): void
    {
        if (self::$productionEngineDatabase === '') {
            return;
        }

        $database = self::$productionEngineDatabase;
        self::$productionEngineDatabase = '';
        self::$productionEngineSchema = null;
        self::$productionEngineTables = [];

        self::productionEngineConnection()->getPdo()->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }

    private static function createProductionEngineDatabase(): Connection
    {
        // A short class name keeps the whole under MySQL's 64 characters;
        // the pid and random suffix keep two runs on one server apart.
        //
        // Concatenated into the DDL below, which SECURITY.md otherwise
        // forbids: an identifier cannot be a bound parameter, and every
        // character of this one is generated here — `[a-z0-9_]` from the
        // class name, the pid and hex — so nothing outside reaches it.
        $short = strtolower((string) preg_replace('/\W/', '', substr(strrchr('\\' . self::class, '\\'), 1)));
        $database = 'sm_' . substr($short, 0, 32) . '_' . getmypid() . '_' . bin2hex(random_bytes(3));

        try {
            self::productionEngineConnection()->getPdo()->exec(
                'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (\PDOException $e) {
            // Reached only once the server answered: a user without
            // CREATE DATABASE is a broken runner, not a laptop.
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised(
                'The MySQL database this class creates for itself could not be created: ' . $e->getMessage()
            );
        }
        self::$productionEngineDatabase = $database;

        try {
            $connection = self::productionEngineConnection($database);
            self::migrateProductionSchema($connection, $database);
        } catch (\Throwable $e) {
            // Dropped at once: left in place, every later test of the class
            // would create another one beside it, and only the last would
            // be dropped after the class.
            self::dropProductionEngineDatabase();

            throw $e;
        }
        $pdo = $connection->getPdo();

        self::$productionEngineTables = array_map(
            'strval',
            $pdo->query(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            )->fetchAll(\PDO::FETCH_COLUMN)
        );
        // The migration left its schema hash in `settings`; a test starts
        // from nothing at all.
        self::emptyProductionEngineTables($pdo);

        return $connection;
    }

    /**
     * The real migration, run until it reports complete.
     *
     * `MigrationRunner` takes one advisory lock for the whole server
     * (`scoutmagic_schema_migration`), whatever the database, and a caller
     * that finds it held gets `complete: false` with nothing to say — the
     * site's own requests simply ask again. Two test processes building
     * their database at the same moment meet exactly that, so this asks
     * again too, within a deadline, instead of reporting an empty failure.
     */
    private static function migrateProductionSchema(Connection $connection, string $database): void
    {
        $pdo = $connection->getPdo();
        $runner = new MigrationRunner(
            $connection,
            new SchemaIntrospector($pdo),
            new SchemaComparator(),
            new SqlParser(),
            // The default budget is a web request's. Here the migration is
            // the fixture, and one left half done would fail every test
            // with a missing table rather than say what happened.
            timeBudgetSeconds: 600
        );
        $deadline = microtime(true) + 120;

        do {
            $result = $runner->migrate(SchemaFiles::all(dirname(__DIR__)));
            if ($result->warnings !== []) {
                throw new \RuntimeException(
                    'The production schema did not migrate cleanly into ' . $database . ': '
                    . implode('; ', $result->warnings)
                );
            }
            if ($result->complete) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException(
            'The production schema could not be migrated into ' . $database . ' within two minutes: '
            . 'another process held the migration lock the whole time.'
        );
    }

    private static function emptyProductionEngineTables(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::$productionEngineTables as $table) {
                $pdo->exec('DELETE FROM `' . $table . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
