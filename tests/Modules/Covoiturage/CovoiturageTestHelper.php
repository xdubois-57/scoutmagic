<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Covoiturage\Repository\CarpoolEvent;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolViewer;

/**
 * The carpool module's tables as SQLite, mirroring
 * modules/covoiturage/schema.sql (ENUMs become TEXT), on top of
 * Tests\DatabaseTestHelper's core tables — plus the small builders every
 * test of the module needs.
 */
final class CovoiturageTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE carpools (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            address TEXT NOT NULL,
            latitude REAL NULL,
            longitude REAL NULL,
            coordinates_are_manual INTEGER NOT NULL DEFAULT 0,
            geocoded_at TEXT NULL,
            outbound_date TEXT NOT NULL,
            return_date TEXT NULL,
            section_id INTEGER NULL,
            created_by_user_account_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE carpool_events (
            carpool_id INTEGER NOT NULL REFERENCES carpools(id) ON DELETE CASCADE,
            calendar_event_id INTEGER NOT NULL UNIQUE,
            event_title TEXT NOT NULL,
            section_id INTEGER NULL,
            section_name TEXT NULL,
            PRIMARY KEY (carpool_id, calendar_event_id)
        )');
        $pdo->exec('CREATE TABLE carpool_offers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            carpool_id INTEGER NOT NULL REFERENCES carpools(id) ON DELETE CASCADE,
            direction TEXT NOT NULL,
            departure_time TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            seats INTEGER NOT NULL,
            driver_user_account_id INTEGER NOT NULL,
            driver_name_encrypted BLOB NOT NULL,
            phone_encrypted BLOB NOT NULL,
            note_encrypted BLOB NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE carpool_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            offer_id INTEGER NOT NULL REFERENCES carpool_offers(id) ON DELETE CASCADE,
            requester_user_account_id INTEGER NOT NULL,
            requester_name_encrypted BLOB NOT NULL,
            passenger_names_encrypted BLOB NOT NULL,
            passenger_count INTEGER NOT NULL,
            phone_encrypted BLOB NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\',
            decided_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    public static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    public static function day(int $offset): string
    {
        return (new \DateTimeImmutable('today'))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    /**
     * @param list<CarpoolEvent> $events
     */
    public static function carpool(
        \PDO $pdo,
        int $outboundInDays = 10,
        ?int $returnInDays = null,
        array $events = [],
        ?int $sectionId = null,
        string $address = 'Gîte de Han-sur-Lesse, rue des Grottes 12'
    ): int {
        $repo = new CarpoolRepository($pdo);
        $id = $repo->create(
            $address,
            self::day($outboundInDays),
            $returnInDays !== null ? self::day($returnInDays) : null,
            $events === [] ? $sectionId : null,
            null
        );
        $repo->replaceEvents($id, $events);

        return $id;
    }

    public static function offer(
        \PDO $pdo,
        int $carpoolId,
        int $driverAccountId,
        int $seats = 4,
        string $direction = 'outbound'
    ): int {
        return (new OfferRepository($pdo, self::encryption()))->create(
            $carpoolId,
            $direction,
            '08:30',
            'Parking des locaux',
            $seats,
            $driverAccountId,
            'Sophie Martin',
            '0478 12 34 56',
            null
        );
    }

    /**
     * @param list<string> $names
     */
    public static function request(\PDO $pdo, int $offerId, int $requesterAccountId, array $names, string $status = 'pending'): int
    {
        $repo = new SeatRequestRepository($pdo, self::encryption());
        $id = $repo->create($offerId, $requesterAccountId, 'Famille Leroy', $names, '0495 88 77 66');
        if ($status !== 'pending') {
            $repo->setStatus($id, $status);
        }

        return $id;
    }

    /**
     * @param list<int> $staffed
     */
    public static function viewer(int $accountId, Role $role = Role::IDENTIFIED, array $staffed = []): CarpoolViewer
    {
        return new CarpoolViewer($accountId, 'account' . $accountId . '@test.be', $role, 1, $staffed);
    }
}
