<?php

declare(strict_types=1);

namespace Tests\Modules\Registration;

class RegistrationTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE registration_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL,
            parent_name_encrypted BLOB NOT NULL,
            child_last_name_encrypted BLOB NOT NULL,
            child_first_name_encrypted BLOB NOT NULL,
            gender_encrypted BLOB NOT NULL,
            birth_date_encrypted BLOB NOT NULL,
            street_encrypted BLOB NOT NULL,
            number_encrypted BLOB NOT NULL,
            postal_code_encrypted BLOB NOT NULL,
            city_encrypted BLOB NOT NULL,
            email_encrypted BLOB NOT NULL,
            email_blind_index TEXT NOT NULL,
            phone1_encrypted BLOB NOT NULL,
            phone2_encrypted BLOB,
            remarks_encrypted BLOB,
            name_dob_blind_index TEXT NOT NULL,
            desired_section_id INTEGER,
            status TEXT NOT NULL DEFAULT "pending",
            received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tracking_token_hash TEXT NOT NULL,
            intended_section_id INTEGER,
            fee_category_id INTEGER,
            internal_notes_encrypted BLOB,
            linked_member_id INTEGER UNIQUE,
            accepted_email_sent_at TEXT,
            refused_email_sent_at TEXT,
            final_at TEXT,
            address_normalized_blind_index TEXT,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (desired_section_id) REFERENCES sections(id),
            FOREIGN KEY (intended_section_id) REFERENCES sections(id),
            FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id),
            FOREIGN KEY (linked_member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE registration_request_siblings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration_request_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            UNIQUE(registration_request_id, member_id),
            FOREIGN KEY (registration_request_id) REFERENCES registration_requests(id),
            FOREIGN KEY (member_id) REFERENCES members(id)
        )');

        $pdo->exec('CREATE TABLE registration_secondary_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration_request_id INTEGER NOT NULL,
            email_encrypted BLOB NOT NULL,
            email_blind_index TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            confirmation_token_hash TEXT,
            confirmation_expires_at TEXT,
            last_confirmation_sent_at TEXT,
            confirmed_at TEXT,
            deactivated_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(registration_request_id, email_blind_index),
            FOREIGN KEY (registration_request_id) REFERENCES registration_requests(id)
        )');

        $pdo->exec('CREATE TABLE registration_slot_capacities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            age_branch_id INTEGER NOT NULL,
            year_in_branch INTEGER NOT NULL,
            -- Nullable, exactly like modules/registration/schema.sql: NULL is
            -- "pas de limite", 0 is "branche fermée", and a test database that
            -- refused NULL would make the distinction untestable.
            capacity INTEGER NULL,
            UNIQUE(age_branch_id, year_in_branch),
            FOREIGN KEY (age_branch_id) REFERENCES age_branches(id)
        )');

        $pdo->exec('CREATE TABLE registration_year_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scout_year_id INTEGER NOT NULL UNIQUE,
            code TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
        )');

        $pdo->exec('CREATE TABLE registration_section_transfers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            target_scout_year_id INTEGER NOT NULL,
            destination_section_id INTEGER NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(member_id, target_scout_year_id),
            FOREIGN KEY (member_id) REFERENCES members(id),
            FOREIGN KEY (target_scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (destination_section_id) REFERENCES sections(id)
        )');

        $pdo->exec('CREATE TABLE registration_reenrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            decision TEXT NOT NULL,
            preferred_section_id INTEGER,
            family_comment_encrypted BLOB,
            answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            answered_by_user_account_id INTEGER,
            applied_leaving INTEGER
        )');
        $pdo->exec('CREATE UNIQUE INDEX idx_rre_member_year ON registration_reenrollments (member_id, scout_year_id)');

        $pdo->exec('CREATE TABLE registration_friend_wishes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reenrollment_id INTEGER NOT NULL,
            position INTEGER NOT NULL,
            raw_name_encrypted BLOB NOT NULL,
            matched_member_id INTEGER,
            match_state TEXT NOT NULL
        )');
        $pdo->exec('CREATE INDEX idx_rfw_reenrollment ON registration_friend_wishes (reenrollment_id)');

        $pdo->exec('CREATE TABLE registration_request_friend_wishes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration_request_id INTEGER NOT NULL,
            position INTEGER NOT NULL,
            raw_name_encrypted BLOB NOT NULL,
            matched_member_id INTEGER,
            match_state TEXT NOT NULL
        )');
        $pdo->exec('CREATE INDEX idx_rrfw_request ON registration_request_friend_wishes (registration_request_id)');

        $pdo->exec('CREATE TABLE registration_passage_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            scout_year_id INTEGER NOT NULL,
            preferred_section_id INTEGER,
            staff_note_encrypted BLOB,
            ai_source_hash TEXT,
            ai_suggestion_encrypted BLOB,
            ai_confirmed INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by_user_account_id INTEGER
        )');
        $pdo->exec('CREATE UNIQUE INDEX idx_rpn_member_year ON registration_passage_notes (member_id, scout_year_id)');
    }

    /**
     * @return array{id: int}
     */
    public static function insertScoutYear(\PDO $pdo, string $label, string $startDate, string $endDate): int
    {
        $stmt = $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)');
        $stmt->execute([$label, $startDate, $endDate]);

        return (int) $pdo->lastInsertId();
    }

    public static function insertAgeBranch(\PDO $pdo, string $deskCode, string $label, int $sortOrder): int
    {
        $stmt = $pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $label, $sortOrder]);

        return (int) $pdo->lastInsertId();
    }

    public static function insertMember(\PDO $pdo, string $deskId): int
    {
        $stmt = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * IT-18's optimiser, wired out of real collaborators — a double would
     * only ever confirm that the test and the test agree about where the
     * children went.
     */
    public static function passageOptimization(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption,
        \Core\Config\SettingService $settingService
    ): \Modules\Registration\Service\PassageOptimizationService {
        return new \Modules\Registration\Service\PassageOptimizationService(
            self::passageService($pdo, $encryption),
            self::projectedPopulation($pdo, $encryption, $settingService),
            new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $encryption),
            new \Modules\Registration\Repository\ReenrollmentRepository($pdo, $encryption),
            new \Modules\Registration\Repository\PassageNoteRepository($pdo, $encryption),
            new \Modules\Registration\Repository\SectionTransferRepository($pdo),
            new \Core\Import\MemberYearRepository($pdo),
            $settingService,
            $pdo
        );
    }

    /**
     * The module's own PassageService, wired out of real collaborators.
     */
    public static function passageService(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption
    ): \Modules\Registration\Service\PassageService {
        return new \Modules\Registration\Service\PassageService(
            $pdo,
            $encryption,
            new \Core\Member\SectionService(
                \Core\Database\Connection::withPdo($pdo),
                $encryption,
                new \Core\Badge\MemberBadgeRepository($pdo)
            ),
            new \Modules\Registration\Repository\SectionTransferRepository($pdo),
            new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $encryption),
            new \Modules\Registration\Repository\AgeBracketRepository($pdo)
        );
    }

    /**
     * IT-17's planning view model, wired out of real collaborators — what
     * the Passage page shows beside each line once families have answered.
     */
    public static function passagePlanning(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption,
        \Core\Config\SettingService $settingService,
        \Modules\Registration\Service\ReenrollmentService $reenrollmentService
    ): \Modules\Registration\Service\PassagePlanningService {
        return new \Modules\Registration\Service\PassagePlanningService(
            new \Modules\Registration\Repository\ReenrollmentRepository($pdo, $encryption),
            new \Modules\Registration\Repository\PassageNoteRepository($pdo, $encryption),
            new \Core\Member\SectionService(
                \Core\Database\Connection::withPdo($pdo),
                $encryption,
                new \Core\Badge\MemberBadgeRepository($pdo)
            ),
            $reenrollmentService
        );
    }

    /**
     * The whole reenrollment service, wired out of real collaborators —
     * what a page test needs when it only cares that the page renders.
     */
    public static function reenrollmentService(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption,
        \Core\Config\SettingService $settingService
    ): \Modules\Registration\Service\ReenrollmentService {
        $connection = \Core\Database\Connection::withPdo($pdo);

        return new \Modules\Registration\Service\ReenrollmentService(
            new \Modules\Registration\Repository\ReenrollmentRepository($pdo, $encryption),
            $settingService,
            new \Core\Member\MemberService(new \Core\Import\MemberYearRepository($pdo), $encryption, $connection),
            self::departureLink($pdo, $encryption, $settingService),
            self::projectedPopulation($pdo, $encryption, $settingService),
            new \Core\Member\SectionService($connection, $encryption, new \Core\Badge\MemberBadgeRepository($pdo))
        );
    }

    /**
     * The module's own projection, wired out of real collaborators.
     *
     * IT-17 scopes name matching to the projected population of the
     * arrival branch, so a double here would only ever confirm that the
     * test and the test agree about who will be where next year.
     */
    public static function projectedPopulation(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption,
        \Core\Config\SettingService $settingService
    ): \Modules\Registration\Service\ProjectedPopulationService {
        $connection = \Core\Database\Connection::withPdo($pdo);
        $sectionService = new \Core\Member\SectionService(
            $connection,
            $encryption,
            new \Core\Badge\MemberBadgeRepository($pdo)
        );
        $requestRepository = new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $encryption);
        $ageBracketRepository = new \Modules\Registration\Repository\AgeBracketRepository($pdo);

        $passageService = new \Modules\Registration\Service\PassageService(
            $pdo,
            $encryption,
            $sectionService,
            new \Modules\Registration\Repository\SectionTransferRepository($pdo),
            $requestRepository,
            $ageBracketRepository
        );

        return new \Modules\Registration\Service\ProjectedPopulationService(
            new \Modules\Registration\Service\ForecastService($pdo, $encryption, $sectionService, $passageService),
            new \Modules\Registration\Service\SlotService(
                $pdo,
                $encryption,
                $settingService,
                $ageBracketRepository,
                new \Modules\Registration\Repository\SlotCapacityRepository($pdo),
                $requestRepository
            ),
            new \Core\Config\ScoutYearService($pdo),
            $sectionService,
            $requestRepository,
            new \Modules\Registration\Repository\ProjectedMemberEmailRepository($pdo, $encryption)
        );
    }

    /**
     * The real IT-16 link between an answer and the « Départs » box, wired
     * out of real collaborators.
     *
     * Real rather than a double because what it is asked to prove is a
     * rule about two tables at once — the answer's `applied_leaving` and
     * `member_years.leaving` — and a double would only ever confirm that
     * the test and the test agree.
     */
    public static function departureLink(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption,
        \Core\Config\SettingService $settingService
    ): \Modules\Registration\Service\ReenrollmentDepartureService {
        return new \Modules\Registration\Service\ReenrollmentDepartureService(
            new \Modules\Registration\Repository\ReenrollmentRepository($pdo, $encryption),
            new \Core\Import\MemberYearRepository($pdo),
            new \Core\Member\DepartureService(
                new \Core\Member\DepartureRepository($pdo, $encryption),
                new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($pdo))
            ),
            new \Core\Config\ScoutYearService($pdo),
            $settingService
        );
    }
}
