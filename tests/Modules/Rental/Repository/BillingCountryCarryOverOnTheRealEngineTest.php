<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalBookingRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The one thing about the billing-country carry-over that only the real
 * engine can answer: whether it moves `updated_at`.
 *
 * `RentalBookingRepository::adoptLegacyCountryColumn()` promises, in the
 * comment on its own UPDATE, that the timestamp is left alone — nothing
 * about the booking changed for its manager, and bumping it would put every
 * upgraded booking at the top of any list ordered by it. The first version
 * kept that promise by simply not naming the column, which is precisely how
 * to break it: `rental_bookings.updated_at` is declared
 * `ON UPDATE CURRENT_TIMESTAMP`, and MySQL bumps such a column on any
 * UPDATE that changes another one and does not assign it. The carry-over
 * changes two. So on the production engine every upgraded booking's
 * timestamp moved, and the race argument a few lines above it — which
 * twice leans on « with no `updated_at` to show it » — was resting on
 * something false.
 *
 * **No SQLite-backed test could see it.** SQLite has no
 * `ON UPDATE CURRENT_TIMESTAMP`, and `Tests\Modules\Rental\RentalTestHelper`
 * declares these columns as plain `TEXT`, so the divergence between the
 * test database and the real one was invisible to the whole suite — which
 * is why a reviewer found it and not `phpunit`. Hence this class, and hence
 * MySQL.
 *
 * **In a database of its own, created and dropped here**, and with a
 * reduced `rental_bookings` carrying only the columns the repository names:
 * the same arrangement, for the same reasons, as
 * DocumentTextLockOnTheRealEngineTest. The real table's foreign key would
 * drag the module's whole schema in for two assertions, and borrowing the
 * shared `TEST_DB_NAME` would make the result depend on what else the suite
 * happened to be doing.
 *
 * The `DATETIME … ON UPDATE CURRENT_TIMESTAMP` clause is copied from
 * `modules/rental/schema.sql` verbatim, because it IS the subject.
 */
#[Group('database')]
final class BillingCountryCarryOverOnTheRealEngineTest extends TestCase
{
    private \PDO $pdo;
    private \PDO $server;
    private string $schema = '';
    private RentalBookingRepository $repository;

    protected function setUp(): void
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
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised('Database connection not available: ' . $result);
        }

        $this->schema = 'sm_billing_country_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $dsn = sprintf(
            'mysql:host=%s;port=%d',
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306)
        );

        try {
            $this->server = new \PDO(
                $dsn,
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $this->server->exec('CREATE DATABASE `' . $this->schema . '`');
            $this->server->exec('USE `' . $this->schema . '`');
        } catch (\Throwable $e) {
            // A refused CREATE DATABASE FAILS wherever TEST_DB_* was
            // exported: this class is the only real-engine check of the
            // carry-over, so a silent skip would leave the build green
            // over the one thing it proves.
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised(
                'The MySQL database this class creates for itself could not be created: '
                . $e->getMessage()
            );
        }

        $this->pdo = $this->server;
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        // The retired plaintext column is here BECAUSE this is the upgraded
        // installation: it no longer appears in schema.sql, which is what
        // makes MigrationRunner leave it in place on a real upgrade.
        $this->pdo->exec(
            'CREATE TABLE rental_bookings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                billing_country VARCHAR(2) NULL,
                billing_name_encrypted BLOB NULL,
                billing_address_encrypted BLOB NULL,
                billing_country_encrypted BLOB NULL,
                billing_vat_number_encrypted BLOB NULL,
                billing_enterprise_number_encrypted BLOB NULL,
                billing_email_encrypted BLOB NULL,
                billing_reference_encrypted BLOB NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        (new \ReflectionProperty(RentalBookingRepository::class, 'legacyCountryAdopted'))
            ->setValue(null, false);
        (new \ReflectionProperty(RentalBookingRepository::class, 'legacyCountryColumnPresent'))
            ->setValue(null, null);

        $this->repository = new RentalBookingRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    protected function tearDown(): void
    {
        if ($this->schema !== '') {
            $this->server->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            $this->schema = '';
        }
    }

    /**
     * First, the engine's behaviour, measured rather than assumed.
     *
     * If a future MySQL — or a connection option added elsewhere — stopped
     * bumping an unassigned `ON UPDATE CURRENT_TIMESTAMP` column, then the
     * defect below would no longer be reachable here, and the test after it
     * would be asserting something the engine grants for free. A test that
     * silently stopped testing anything is worse than one that says so.
     */
    public function testThisEngineBumpsAnUnassignedUpdatedAtWhenAnotherColumnChanges(): void
    {
        $booking = $this->givenABookingWithALegacyClearCountry('FR');
        $this->pdo->exec("UPDATE rental_bookings SET updated_at = '2020-01-01 00:00:00' WHERE id = {$booking}");

        $this->pdo->exec("UPDATE rental_bookings SET billing_country = 'BE' WHERE id = {$booking}");

        $this->assertNotSame(
            '2020-01-01 00:00:00',
            $this->updatedAt($booking),
            'this engine leaves an unassigned ON UPDATE CURRENT_TIMESTAMP column alone, so the '
                . 'defect this class guards is not reachable here and the guarantee needs re-deriving'
        );
    }

    /**
     * And the carry-over, which must not move it.
     */
    public function testTheCarryOverLeavesUpdatedAtWhereItWas(): void
    {
        $booking = $this->givenABookingWithALegacyClearCountry('FR');
        $this->pdo->exec("UPDATE rental_bookings SET updated_at = '2020-01-01 00:00:00' WHERE id = {$booking}");

        // Any read of a billing identity triggers it.
        $this->assertSame('FR', $this->repository->findBillingIdentity($booking)['country']);

        $this->assertSame(
            '2020-01-01 00:00:00',
            $this->updatedAt($booking),
            'the carry-over moved the timestamp, so every upgraded booking jumps to the top of any '
                . 'list ordered by it — and the race argument that leans on « no updated_at to show '
                . 'it » does not hold'
        );
    }

    /**
     * The carry-over really did happen, so the test above cannot pass by
     * doing nothing at all.
     *
     * Without this, removing the UPDATE entirely would leave
     * testTheCarryOverLeavesUpdatedAtWhereItWas green: a statement that
     * never runs moves no timestamp.
     */
    public function testTheCarryOverDidCarryTheCountryOver(): void
    {
        $booking = $this->givenABookingWithALegacyClearCountry('FR');

        $this->repository->findBillingIdentity($booking);

        $row = $this->pdo->query(
            'SELECT billing_country, billing_country_encrypted FROM rental_bookings WHERE id = ' . $booking
        );
        $this->assertNotFalse($row);
        /** @var array<string, mixed> $stored */
        $stored = $row->fetch(\PDO::FETCH_ASSOC);

        $this->assertNull($stored['billing_country'], 'the clear country is still on disk');
        $this->assertNotNull($stored['billing_country_encrypted'], 'nothing was written to the new column');
    }

    private function givenABookingWithALegacyClearCountry(string $country): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO rental_bookings (billing_country) VALUES (?)');
        $stmt->execute([$country]);

        return (int) $this->pdo->lastInsertId();
    }

    private function updatedAt(int $bookingId): string
    {
        $stmt = $this->pdo->query('SELECT updated_at FROM rental_bookings WHERE id = ' . $bookingId);
        $this->assertNotFalse($stmt);

        return (string) $stmt->fetchColumn();
    }
}
