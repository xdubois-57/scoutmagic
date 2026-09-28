<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Import\MemberYearRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
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
            reminded_at TEXT NULL,
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
            // Both, since issue #650: a carpool carries its creator's section
            // AND its events' — the shape the code has to handle. Passing
            // null for $sectionId gives the pre-#650 shape a test needs to
            // pin that those carpools did not change.
            $sectionId,
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
            $repo->transition($id, 'pending', $status);
        }

        return $id;
    }

    /**
     * @param list<int> $staffed
     */
    public static function sections(\PDO $pdo): SectionService
    {
        return new SectionService(
            new SectionRepository(Connection::withPdo($pdo)),
            self::profiles($pdo)
        );
    }

    public static function members(\PDO $pdo): MemberService
    {
        return new MemberService(new MemberYearRepository($pdo), self::profiles($pdo));
    }

    private static function profiles(\PDO $pdo): MemberProfileRepository
    {
        return new MemberProfileRepository(
            Connection::withPdo($pdo),
            self::encryption(),
            new MemberBadgeRepository($pdo)
        );
    }

    /**
     * An account whose address is linked to ONE member holding ONE function
     * in $sectionId — which is all Core\View\SectionPickerHelper needs to
     * call that section the account's own, and therefore all
     * CarpoolService::creatorSectionId() needs (issue #650).
     *
     * $sectionId null gives the other case the rule turns on: an animateur
     * with no section of their own, whose carpools must be saved with no
     * section rather than with somebody else's.
     *
     * The address is the one viewer() builds for the same account id, so a
     * test names the account once and both sides agree.
     */
    public static function linkAccountToSection(
        \PDO $pdo,
        int $accountId,
        ?int $sectionId,
        int $scoutYearId = 1
    ): void {
        $pdo->prepare(
            'INSERT OR IGNORE INTO scout_years (id, label, start_date, end_date, is_current)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([$scoutYearId, '2025-2026', '2025-09-01', '2026-08-31']);

        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([uniqid('desk', true)]);
        $memberId = (int) $pdo->lastInsertId();

        $encryption = self::encryption();
        $pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([
            $memberId,
            $scoutYearId,
            $encryption->encrypt('Animateur', 'member_years.first_name'),
            $encryption->encrypt('Test', 'member_years.last_name'),
            $encryption->blindIndex(self::emailOf($accountId), 'email'),
        ]);
        $memberYearId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES (?, ?, ?)')
            ->execute(['chief', 'Animateur', 'chief']);
        $lookup = $pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $lookup->execute(['chief']);

        $pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)')
            ->execute([$memberYearId, (int) $lookup->fetchColumn(), $sectionId]);
    }

    public static function emailOf(int $accountId): string
    {
        return 'account' . $accountId . '@test.be';
    }

    public static function viewer(int $accountId, Role $role = Role::IDENTIFIED, array $staffed = []): CarpoolViewer
    {
        return new CarpoolViewer($accountId, self::emailOf($accountId), $role, 1, $staffed);
    }
}
