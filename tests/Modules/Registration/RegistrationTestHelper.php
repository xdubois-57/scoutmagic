<?php

declare(strict_types=1);

namespace Tests\Modules\Registration;

class RegistrationTestHelper
{
    /**
     * Register this module's settings **from its own `module.json`**, the
     * way `Core\Module\ModuleManager::loadModule()` does on every request.
     *
     * A test that registers the keys it happens to need cannot notice a
     * key the code writes and the manifest never declares — and
     * `SettingService::setInternal()` refuses exactly that, so the gap
     * shows up in production and nowhere else. That is how the campaign
     * markers came to be undeclared: every automatic opening, closing and
     * email batch threw at the moment it recorded that it had run, while
     * the fixtures supplied the missing rows.
     */
    public static function registerManifestSettings(\Core\Config\SettingService $settings): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/modules/registration/module.json'),
            true
        );

        foreach (($manifest['settings'] ?? []) as $setting) {
            $settings->register(
                (string) $setting['key'],
                (string) $setting['default_value'],
                (string) $setting['type'],
                (string) $setting['label'],
                (string) $setting['description'],
                'registration',
                $setting['validation_regex'] ?? null,
                null,
                $setting['editable'] ?? true
            );
        }
    }

    /**
     * Switches the automatic sending of the decision e-mails on (issue
     * #843, IT-09) with a delay of $hours — its two settings registered
     * from the manifest, as production does, and written the way the box
     * writes them. A test that never calls this runs with it off, the
     * default.
     */
    public static function enableAutomaticDecisionEmails(
        \Core\Config\SettingService $settings,
        int $hours = 24,
        bool $enabled = true
    ): void {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/modules/registration/module.json'),
            true
        );
        $keys = [
            \Modules\Registration\Service\AutomaticDecisionEmailService::SETTING_ENABLED,
            \Modules\Registration\Service\AutomaticDecisionEmailService::SETTING_DELAY_HOURS,
        ];
        foreach (($manifest['settings'] ?? []) as $setting) {
            if (in_array($setting['key'], $keys, true)) {
                $settings->register(
                    (string) $setting['key'],
                    (string) $setting['default_value'],
                    (string) $setting['type'],
                    (string) $setting['label'],
                    (string) $setting['description'],
                    'registration',
                    $setting['validation_regex'] ?? null,
                    null,
                    $setting['editable'] ?? true
                );
            }
        }
        $settings->setManyInternal([
            $keys[0] => $enabled ? '1' : '0',
            $keys[1] => (string) $hours,
        ], 'registration');
    }

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
            refusal_reason TEXT,
            final_at TEXT,
            address_normalized_blind_index TEXT,
            previous_unit_answer TEXT,
            previous_unit_name_encrypted BLOB,
            FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
            FOREIGN KEY (desired_section_id) REFERENCES sections(id),
            FOREIGN KEY (intended_section_id) REFERENCES sections(id),
            FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id),
            FOREIGN KEY (linked_member_id) REFERENCES members(id)
        )');

        // modules/registration/schema.sql's registration_decision_emails
        // (issue #843, IT-05), as SQLite can hold it: the ENUMs become TEXT,
        // and the UNIQUE active_request_id keeps the claim's meaning — SQLite,
        // like MySQL, lets several rows carry NULL there.
        $pdo->exec('CREATE TABLE registration_decision_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            registration_request_id INTEGER NOT NULL,
            decision TEXT NOT NULL,
            state TEXT NOT NULL DEFAULT "scheduled",
            due_at TEXT,
            attempt INTEGER NOT NULL DEFAULT 1,
            is_resend INTEGER NOT NULL DEFAULT 0,
            attempted_at TEXT,
            sent_at TEXT,
            failed_at TEXT,
            failure_code TEXT,
            failure_message TEXT,
            cancelled_at TEXT,
            cancel_reason TEXT,
            superseded_at TEXT,
            active_request_id INTEGER UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (registration_request_id) REFERENCES registration_requests(id) ON DELETE CASCADE
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
            ai_section_id INTEGER,
            ai_friend_member_ids TEXT,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by_user_account_id INTEGER
        )');
        $pdo->exec('CREATE UNIQUE INDEX idx_rpn_member_year ON registration_passage_notes (member_id, scout_year_id)');
    }

    /**
     * Records that the $decision e-mail of a request went out, through the
     * real repository — what a fixture needs where it used to stamp a
     * column on the request (issue #843, IT-05). The request must hold that
     * decision, as for a real send.
     *
     * @return int the id of the sent e-mail
     */
    public static function recordDecisionEmailSent(
        \PDO $pdo,
        int $requestId,
        string $decision,
        ?\DateTimeImmutable $at = null
    ): int {
        $repository = new \Modules\Registration\Repository\DecisionEmailRepository($pdo);
        $at ??= new \DateTimeImmutable();
        $id = $repository->insert(
            $requestId,
            $decision,
            \Modules\Registration\Repository\DecisionEmail::STATE_SENDING,
            null,
            1,
            false,
            $at
        );
        if ($id === null || !$repository->markSent($id, $at)) {
            throw new \LogicException("Request {$requestId} cannot be sent a '{$decision}' e-mail.");
        }

        return $id;
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
            new \Modules\Registration\Repository\PassageRosterRepository($pdo, $encryption),
            $encryption,
            new \Core\Member\SectionService(
    new \Core\Member\Repository\SectionRepository(\Core\Database\Connection::withPdo($pdo)),
    new \Core\Member\Repository\MemberProfileRepository(\Core\Database\Connection::withPdo($pdo), $encryption, new \Core\Badge\MemberBadgeRepository($pdo))
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
    new \Core\Member\Repository\SectionRepository(\Core\Database\Connection::withPdo($pdo)),
    new \Core\Member\Repository\MemberProfileRepository(\Core\Database\Connection::withPdo($pdo), $encryption, new \Core\Badge\MemberBadgeRepository($pdo))
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
            new \Core\Member\MemberService(
    new \Core\Import\MemberYearRepository($pdo),
    new \Core\Member\Repository\MemberProfileRepository($connection, $encryption)
),
            self::departureLink($pdo, $encryption, $settingService),
            self::projectedPopulation($pdo, $encryption, $settingService),
            new \Core\Member\SectionService(
    new \Core\Member\Repository\SectionRepository($connection),
    new \Core\Member\Repository\MemberProfileRepository($connection, $encryption, new \Core\Badge\MemberBadgeRepository($pdo))
)
        );
    }

    /**
     * The slot capacities, wired out of real collaborators — the module's
     * one projection (ForecastService) included, since the occupancy of a
     * slot IS that projection (chantier #843, IT-02) and a double would
     * only ever confirm that the test and the test agree about who will be
     * where next year.
     */
    public static function slotService(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption,
        \Core\Config\SettingService $settingService
    ): \Modules\Registration\Service\SlotService {
        $connection = \Core\Database\Connection::withPdo($pdo);
        $sectionService = new \Core\Member\SectionService(
            new \Core\Member\Repository\SectionRepository($connection),
            new \Core\Member\Repository\MemberProfileRepository(
                $connection,
                $encryption,
                new \Core\Badge\MemberBadgeRepository($pdo)
            )
        );
        $roster = new \Modules\Registration\Repository\PassageRosterRepository($pdo, $encryption);

        return new \Modules\Registration\Service\SlotService(
            $roster,
            new \Core\Config\ScoutYearService($pdo),
            $settingService,
            new \Modules\Registration\Repository\AgeBracketRepository($pdo),
            new \Modules\Registration\Repository\SlotCapacityRepository($pdo),
            new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $encryption),
            new \Modules\Registration\Service\ForecastService(
                $roster,
                $encryption,
                $sectionService,
                self::passageService($pdo, $encryption)
            ),
            new \Modules\Registration\Repository\ReenrollmentRepository($pdo, $encryption)
        );
    }

    /**
     * The decision dialog's service (issue #843, IT-04), wired out of real
     * collaborators — the recommendation included, so a page rendered with
     * it shows what the chief would read. The e-mail service is the
     * caller's, since a test decides whether its templates are written,
     * and so may the repository be, for a test that makes a write fail.
     *
     * The automatic sending (IT-09) reads its switch and delay from
     * $settingService — off unless the test switched it on — and plans on
     * the same `registration_decision_emails` table as the e-mail service.
     */
    public static function decisionService(
        \PDO $pdo,
        \Core\Security\EncryptionService $encryption,
        \Core\Config\SettingService $settingService,
        \Modules\Registration\Service\RequestStatusService $statusService,
        \Modules\Registration\Service\RequestEmailService $emailService,
        ?\Modules\Registration\Repository\RegistrationRequestRepository $requestRepository = null
    ): \Modules\Registration\Service\RequestDecisionService {
        $journal = new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($pdo));
        $requestRepository ??= new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $encryption);
        $scoutYearService = new \Core\Config\ScoutYearService($pdo);
        $scoutYearResolver = new \Core\ScoutYear\ScoutYearResolver(
            $scoutYearService,
            $settingService,
            new \Core\Import\MemberYearRepository($pdo)
        );

        return new \Modules\Registration\Service\RequestDecisionService(
            $requestRepository,
            $statusService,
            new \Modules\Registration\Service\RecommendationService(
                $requestRepository,
                self::slotService($pdo, $encryption, $settingService),
                new \Modules\Registration\Repository\AgeBracketRepository($pdo),
                $scoutYearService,
                $scoutYearResolver,
                new \Modules\Registration\Service\PriorityOrderService($settingService)
            ),
            $emailService,
            $scoutYearService,
            $scoutYearResolver,
            $journal,
            self::automaticDecisionEmails($pdo, $settingService, $emailService)
        );
    }

    /**
     * The automatic sending of the decision e-mails (issue #843, IT-09)
     * over $settingService — the module's settings must be registered
     * (registerManifestSettings()) for its switch and delay to be read and
     * saved as in production.
     */
    public static function automaticDecisionEmails(
        \PDO $pdo,
        \Core\Config\SettingService $settingService,
        \Modules\Registration\Service\RequestEmailService $emailService
    ): \Modules\Registration\Service\AutomaticDecisionEmailService {
        return new \Modules\Registration\Service\AutomaticDecisionEmailService(
            $settingService,
            $emailService,
            new \Modules\Registration\Service\DecisionEmailService(
                new \Modules\Registration\Repository\DecisionEmailRepository($pdo),
                new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($pdo))
            )
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
    new \Core\Member\Repository\SectionRepository($connection),
    new \Core\Member\Repository\MemberProfileRepository($connection, $encryption, new \Core\Badge\MemberBadgeRepository($pdo))
);
        $requestRepository = new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $encryption);
        $ageBracketRepository = new \Modules\Registration\Repository\AgeBracketRepository($pdo);

        $passageService = new \Modules\Registration\Service\PassageService(
            new \Modules\Registration\Repository\PassageRosterRepository($pdo, $encryption),
            $encryption,
            $sectionService,
            new \Modules\Registration\Repository\SectionTransferRepository($pdo),
            $requestRepository,
            $ageBracketRepository
        );

        return new \Modules\Registration\Service\ProjectedPopulationService(
            new \Modules\Registration\Service\ForecastService(new \Modules\Registration\Repository\PassageRosterRepository($pdo, $encryption), $encryption, $sectionService, $passageService),
            self::slotService($pdo, $encryption, $settingService),
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
