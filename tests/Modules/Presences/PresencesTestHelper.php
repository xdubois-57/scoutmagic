<?php

declare(strict_types=1);

namespace Tests\Modules\Presences;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Service\PresenceAuthorizationService;
use Modules\Presences\Service\PresenceSheetService;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * Builds a small but REAL unit for the presences tests: two sections,
 * their calendars, their animés, their animateurs — through the same core
 * services the application uses, so a boundary test is exercising the
 * production rule rather than a mock of it.
 *
 * The section boundary is the whole risk of this module (a misplaced one
 * would hand an animateur another section's animés, comments included),
 * and a mocked `SectionStaffAuthorizationService` would agree with
 * whatever the test expected. So nothing here is mocked below the
 * calendar's own Api.
 */
class PresencesTestHelper
{
    /**
     * The SQLite mirror of `modules/presences/schema.sql`, translated only
     * where SQLite requires it: `INTEGER PRIMARY KEY AUTOINCREMENT` for
     * the identity column, `TEXT` where MySQL has `ENUM` — the same four
     * values held by a CHECK, so a test cannot store a state production
     * would refuse — and `TEXT` for the timestamps. The unique indexes,
     * `idx_presences_member` and the foreign-key actions are production's
     * own: a suite passing against a laxer schema than the server proves
     * less than it looks.
     *
     * Which is also why `short_code` carries a CHECK rather than only its
     * `VARCHAR(16)`: SQLite records a declared length and enforces none of
     * it, so without the CHECK the mirror would accept a code MySQL
     * truncates or rejects. Codes are six characters
     * (`Core\Url\ShortUrlService::CODE_LENGTH`) — the sixteen is headroom,
     * and headroom is exactly the kind of bound a test schema stops
     * checking without anybody noticing.
     */
    public static function createTables(\PDO $pdo): void
    {
        CalendarTestHelper::createTables($pdo);

        $pdo->exec('CREATE TABLE presences_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_event_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT \'unset\'
                CHECK (status IN (\'present\', \'excused\', \'absent\', \'unset\')),
            comment_encrypted BLOB,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INTEGER,
            UNIQUE(calendar_event_id, member_id),
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES user_accounts(id) ON DELETE SET NULL
        )');
        $pdo->exec('CREATE INDEX idx_presences_member ON presences_records (member_id)');

        $pdo->exec('CREATE TABLE presences_event_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_event_id INTEGER NOT NULL UNIQUE,
            short_code VARCHAR(16) NOT NULL UNIQUE CHECK (length(short_code) <= 16),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    public static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    public static function sectionService(\PDO $pdo, EncryptionService $encryption): SectionService
    {
        return new SectionService(
            Connection::withPdo($pdo),
            $encryption,
            new MemberBadgeRepository($pdo)
        );
    }

    public static function authorization(\PDO $pdo, EncryptionService $encryption): PresenceAuthorizationService
    {
        return new PresenceAuthorizationService(new SectionStaffAuthorizationService(
            Connection::withPdo($pdo),
            $encryption,
            self::sectionService($pdo, $encryption),
            new MemberEmailRepository($pdo, $encryption)
        ));
    }

    public static function sheetService(
        \PDO $pdo,
        EncryptionService $encryption,
        \Modules\Calendar\Api\SectionEventLookupInterface $lookup
    ): PresenceSheetService {
        return new PresenceSheetService(
            $lookup,
            self::authorization($pdo, $encryption),
            self::sectionService($pdo, $encryption),
            new PresenceRepository($pdo, $encryption)
        );
    }

    /**
     * The real CalendarService, wired on the same PDO — the presences
     * tests consume its Api\SectionEventLookupInterface rather than a
     * stub, so « an event on a supplementary calendar opens no sheet »
     * is verified against the code that actually decides it.
     */
    public static function calendarService(\PDO $pdo, EncryptionService $encryption)
        : \Modules\Calendar\Service\CalendarService
    {
        return new \Modules\Calendar\Service\CalendarService(
            new \Modules\Calendar\Repository\CalendarRepository($pdo, $encryption),
            new \Modules\Calendar\Repository\CalendarEventRepository($pdo),
            self::sectionService($pdo, $encryption),
            new \Modules\Calendar\Repository\CalendarUnitFeedTokenRepository($pdo, $encryption)
        );
    }

    public static function createScoutYear(\PDO $pdo, string $label, string $start, string $end): int
    {
        $stmt = $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute([$label, $start, $end]);

        return (int) $pdo->lastInsertId();
    }

    public static function createBranch(\PDO $pdo, string $deskCode, string $label, int $sortOrder): int
    {
        $stmt = $pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $label, $sortOrder]);

        return (int) $pdo->lastInsertId();
    }

    public static function createSection(\PDO $pdo, int $branchId, string $deskCode, ?string $name): int
    {
        $stmt = $pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * A calendar for a section, and one for nobody — the second is what
     * « an evening of the Animateurs calendar opens no sheet » is tested
     * against.
     */
    public static function createSectionCalendar(\PDO $pdo, int $sectionId): int
    {
        $stmt = $pdo->prepare("INSERT INTO calendar_calendars (section_id, visibility) VALUES (?, 'public')");
        $stmt->execute([$sectionId]);

        return (int) $pdo->lastInsertId();
    }

    public static function createSupplementaryCalendar(\PDO $pdo, string $name): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO calendar_calendars (section_id, name, is_default, visibility)
             VALUES (NULL, ?, 1, 'chief')"
        );
        $stmt->execute([$name]);

        return (int) $pdo->lastInsertId();
    }

    public static function createEvent(\PDO $pdo, int $calendarId, string $title, string $startDate): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO calendar_events (calendar_id, title, start_date, start_time) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$calendarId, $title, $startDate, '14:00']);

        return (int) $pdo->lastInsertId();
    }

    /**
     * One person, one scout year, one function in one section.
     *
     * $functionRole is what decides whether they are an animé or an
     * animateur: `Core\Member\SectionService` splits on
     * `functions.role IN ('chief', 'admin')` and nothing else.
     *
     * @return array{memberId: int, memberYearId: int}
     */
    public static function createMember(
        \PDO $pdo,
        EncryptionService $encryption,
        int $scoutYearId,
        string $firstName,
        string $lastName,
        string $functionRole,
        ?int $sectionId,
        ?string $email = null
    ): array {
        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([uniqid('desk', true)]);
        $memberId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $encryption->encrypt($firstName, 'member_years.first_name'),
            $encryption->encrypt($lastName, 'member_years.last_name'),
            $email !== null ? $encryption->blindIndex(strtolower(trim($email)), 'email') : null,
        ]);
        $memberYearId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES (?, ?, ?)')
            ->execute([$functionRole, $functionRole, $functionRole]);
        $lookup = $pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $lookup->execute([$functionRole]);
        $functionId = (int) $lookup->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return ['memberId' => $memberId, 'memberYearId' => $memberYearId];
    }

    /**
     * Take an animateur out of a section, the way an import that no
     * longer lists them does — the row goes, the person stays.
     */
    public static function removeFromSection(\PDO $pdo, int $memberYearId, int $sectionId): void
    {
        $stmt = $pdo->prepare('DELETE FROM member_functions WHERE member_year_id = ? AND section_id = ?');
        $stmt->execute([$memberYearId, $sectionId]);
    }
}
