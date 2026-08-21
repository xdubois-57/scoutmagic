<?php

declare(strict_types=1);

namespace Tests\Modules\Groups;

/**
 * SQLite mirrors of modules/groups/schema.sql, same precedent as
 * Tests\Modules\Retro\RetroTestHelper — the real schema is MySQL (it has
 * to be: `groups` is a reserved word there, which is why every table is
 * prefixed `discussion_group`), so the test suite keeps a portable copy.
 */
class GroupsTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE discussion_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NULL,
            scout_year_id INTEGER NULL,
            section_id INTEGER NULL,
            closed_at TEXT NULL,
            last_activity_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by_member_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            gallery_album_id INTEGER NULL
        )');

        $pdo->exec('CREATE TABLE discussion_group_sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(group_id, section_id),
            FOREIGN KEY (group_id) REFERENCES discussion_groups(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            is_moderator INTEGER NOT NULL DEFAULT 0,
            invited_by_member_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(group_id, member_id),
            FOREIGN KEY (group_id) REFERENCES discussion_groups(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            author_user_account_id INTEGER NOT NULL,
            author_member_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            is_pinned INTEGER NOT NULL DEFAULT 0,
            edited_at TEXT NULL,
            hidden_at TEXT NULL,
            moderation_cleared INTEGER NOT NULL DEFAULT 0,
            calendar_event_id INTEGER NULL,
            last_activity_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (group_id) REFERENCES discussion_groups(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_post_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            gallery_media_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(post_id, gallery_media_id),
            FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_post_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            title TEXT NULL,
            description TEXT NULL,
            image_file_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(post_id),
            FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_link_fetch_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE discussion_group_replies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            author_user_account_id INTEGER NOT NULL,
            author_member_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            gallery_media_id INTEGER NULL,
            edited_at TEXT NULL,
            hidden_at TEXT NULL,
            moderation_cleared INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_post_reactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            reaction_key TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(post_id, member_id),
            FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_reply_reactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reply_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            reaction_key TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(reply_id, member_id),
            FOREIGN KEY (reply_id) REFERENCES discussion_group_replies(id) ON DELETE CASCADE
        )');

        // The UNIQUE index is the one-report-per-member rule itself, not a
        // performance hint: Repository\ReportRepository::record() relies on
        // it raising SQLSTATE 23000 rather than checking first, which is
        // what makes a double submit safe under concurrency.
        $pdo->exec('CREATE TABLE discussion_group_post_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            reporter_member_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(post_id, reporter_member_id),
            FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_reply_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reply_id INTEGER NOT NULL,
            reporter_member_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(reply_id, reporter_member_id),
            FOREIGN KEY (reply_id) REFERENCES discussion_group_replies(id) ON DELETE CASCADE
        )');

        // Debounce state for the reaction notification — timing only,
        // never a copy of what core sent (ARCHITECTURE.md §8.24). Two
        // tables for the same reason the reaction tables are two: a
        // polymorphic one could carry no foreign key.
        $pdo->exec('CREATE TABLE discussion_group_post_reaction_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            notified_at TEXT NOT NULL,
            UNIQUE(post_id),
            FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_reply_reaction_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reply_id INTEGER NOT NULL,
            notified_at TEXT NOT NULL,
            UNIQUE(reply_id),
            FOREIGN KEY (reply_id) REFERENCES discussion_group_replies(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_reads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            last_read_at TEXT NOT NULL,
            UNIQUE(group_id, member_id),
            FOREIGN KEY (group_id) REFERENCES discussion_groups(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_polls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            question TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(post_id),
            FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_poll_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            position INTEGER NOT NULL,
            FOREIGN KEY (poll_id) REFERENCES discussion_group_polls(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_poll_votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            option_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(poll_id, member_id),
            FOREIGN KEY (poll_id) REFERENCES discussion_group_polls(id) ON DELETE CASCADE,
            FOREIGN KEY (option_id) REFERENCES discussion_group_poll_options(id) ON DELETE CASCADE
        )');

        $pdo->exec('CREATE TABLE discussion_group_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            action_type TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    /**
     * A RateLimitService over a real (in-memory) table — every caller of
     * PostService/ReplyService needs one, and none of them is testing the
     * limit itself, so they all take this rather than a double.
     */
    /**
     * The REAL Service\MemberIdentityService, against the in-memory test
     * database — not a stub.
     *
     * Every name this module shows now goes through it ("Marie Dupont
     * (Akéla)"), and what it resolves is a blind-index join between
     * user_accounts and member_years. A stub would assert that the callers
     * ask it politely; only the real thing asserts that the join finds
     * anybody. Tests\DatabaseTestHelper's schema carries both tables, so
     * there is nothing to fake.
     *
     * The keys are the fixed test keys every other groups test already
     * uses, so a row seeded by seedAccountWithMembers() below decrypts
     * here and nowhere else.
     */
    public static function identityService(\PDO $pdo): \Modules\Groups\Service\MemberIdentityService
    {
        $encryption = self::testEncryption();

        return new \Modules\Groups\Service\MemberIdentityService(
            new \Core\Security\UserAccountRepository($pdo, $encryption),
            new \Core\Import\MemberYearRepository($pdo),
            new \Core\Member\MemberService(
                new \Core\Import\MemberYearRepository($pdo),
                $encryption,
                \Core\Database\Connection::withPdo($pdo)
            )
        );
    }

    /**
     * Give an EXISTING member a member_years row and the account that
     * shares its address — what makes Service\MemberIdentityService able
     * to name them at all.
     *
     * Separate from seedAccountWithMembers() because a controller fixture
     * usually creates its members first (with their section periods, for
     * Service\GroupAccessService) and only then needs them nameable.
     *
     * @param int|null $accountId force the account's id, for a fixture
     *        whose posts already reference one by number
     */
    public static function giveMemberAnAccount(
        \PDO $pdo,
        int $memberId,
        string $email,
        string $accountFirstName,
        string $accountLastName,
        string $memberFirstName,
        ?string $totem = null,
        int $scoutYearId = 1,
        ?int $accountId = null
    ): int {
        $encryption = self::testEncryption();
        $normalised = strtolower(trim($email));
        $blindIndex = $encryption->blindIndex($normalised, 'email');

        if ($accountId !== null) {
            $statement = $pdo->prepare(
                'INSERT INTO user_accounts (id, email_encrypted, email_blind_index, first_name_encrypted, last_name_encrypted, is_super_admin)
                 VALUES (?, ?, ?, ?, ?, 0)'
            );
            $statement->execute([
                $accountId,
                $encryption->encrypt($normalised, 'user_accounts.email'),
                $blindIndex,
                $encryption->encrypt($accountFirstName, 'user_accounts.first_name'),
                $encryption->encrypt($accountLastName, 'user_accounts.last_name'),
            ]);
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO user_accounts (email_encrypted, email_blind_index, first_name_encrypted, last_name_encrypted, is_super_admin)
                 VALUES (?, ?, ?, ?, 0)'
            );
            $statement->execute([
                $encryption->encrypt($normalised, 'user_accounts.email'),
                $blindIndex,
                $encryption->encrypt($accountFirstName, 'user_accounts.first_name'),
                $encryption->encrypt($accountLastName, 'user_accounts.last_name'),
            ]);
            $accountId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $memberId,
            $scoutYearId,
            $encryption->encrypt($memberFirstName, 'member_years.first_name'),
            $encryption->encrypt($accountLastName, 'member_years.last_name'),
            $totem !== null ? $encryption->encrypt($totem, 'member_years.totem') : null,
            $blindIndex,
        ]);

        return $accountId;
    }

    public static function testEncryption(): \Core\Security\EncryptionService
    {
        return new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    /**
     * One account and the members it is linked to, joined the way
     * production joins them: by the blind index of a shared email address.
     *
     * @param array<int, array{first_name: string, totem?: string}> $members
     * @return array{account_id: int, member_ids: int[]}
     */
    public static function seedAccountWithMembers(
        \PDO $pdo,
        string $email,
        string $accountFirstName,
        string $accountLastName,
        array $members,
        int $scoutYearId = 1
    ): array {
        $encryption = self::testEncryption();
        $normalised = strtolower(trim($email));
        $blindIndex = $encryption->blindIndex($normalised, 'email');

        // A real scout_years row, because the member -> account lookup
        // joins one to order a member's addresses by year
        // (Core\Import\MemberYearRepository::
        // findMostRecentEmailBlindIndexesForMembers()). Without it the
        // join matches nothing and every seeded member silently resolves
        // to no account at all.
        $existing = $pdo->prepare('SELECT id FROM scout_years WHERE id = ?');
        $existing->execute([$scoutYearId]);
        if ($existing->fetchColumn() === false) {
            $pdo->prepare('INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (?, ?, ?, ?, 1)')
                ->execute([$scoutYearId, '2025-2026', '2025-09-01', '2026-08-31']);
        }

        $statement = $pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, first_name_encrypted, last_name_encrypted, is_super_admin)
             VALUES (?, ?, ?, ?, 0)'
        );
        $statement->execute([
            $encryption->encrypt($normalised, 'user_accounts.email'),
            $blindIndex,
            $encryption->encrypt($accountFirstName, 'user_accounts.first_name'),
            $encryption->encrypt($accountLastName, 'user_accounts.last_name'),
        ]);
        $accountId = (int) $pdo->lastInsertId();

        $memberIds = [];
        foreach ($members as $index => $member) {
            $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')
                ->execute(['SEED-' . $blindIndex . '-' . $index]);
            $memberId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO member_years
                    (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_blind_index, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            )->execute([
                $memberId,
                $scoutYearId,
                $encryption->encrypt($member['first_name'], 'member_years.first_name'),
                $encryption->encrypt($accountLastName, 'member_years.last_name'),
                isset($member['totem']) ? $encryption->encrypt($member['totem'], 'member_years.totem') : null,
                $blindIndex,
            ]);
            $memberIds[] = $memberId;
        }

        return ['account_id' => $accountId, 'member_ids' => $memberIds];
    }

    public static function rateLimitService(\PDO $pdo): \Modules\Groups\Service\RateLimitService
    {
        return new \Modules\Groups\Service\RateLimitService(
            new \Modules\Groups\Repository\RateLimitRepository($pdo)
        );
    }

    /**
     * The reaction-notification debounce over the real (in-memory)
     * tables, wired the way public/index.php wires it.
     */
    public static function reactionNoticeThrottle(
        \PDO $pdo,
        \Core\Config\SettingService $settingService
    ): \Modules\Groups\Service\ReactionNoticeThrottle {
        return new \Modules\Groups\Service\ReactionNoticeThrottle(
            \Modules\Groups\Repository\ReactionNoticeRepository::forPosts($pdo),
            \Modules\Groups\Repository\ReactionNoticeRepository::forReplies($pdo),
            $settingService
        );
    }

    /**
     * A ReportService wired the way public/index.php wires it.
     */
    public static function reportService(
        \PDO $pdo,
        \Core\Config\SettingService $settingService,
        \Core\Journal\JournalService $journalService
    ): \Modules\Groups\Service\ReportService {
        return new \Modules\Groups\Service\ReportService(
            \Modules\Groups\Repository\ReportRepository::forPosts($pdo),
            \Modules\Groups\Repository\ReportRepository::forReplies($pdo),
            new \Modules\Groups\Repository\PostRepository($pdo),
            new \Modules\Groups\Repository\ReplyRepository($pdo),
            $settingService,
            $journalService
        );
    }

    /**
     * The reply/reaction service trio, wired the same way public/index.php
     * wires it. Built here rather than in each test file so the three that
     * need a GroupFeedService cannot drift from the composition root — or
     * from each other.
     *
     * @return array{
     *     replyRepository: \Modules\Groups\Repository\ReplyRepository,
     *     replyService: \Modules\Groups\Service\ReplyService,
     *     reactionService: \Modules\Groups\Service\ReactionService,
     *     reportService: \Modules\Groups\Service\ReportService,
     *     replyPresenter: \Modules\Groups\Service\ReplyPresenter
     * }
     */
    public static function replyStack(
        \PDO $pdo,
        \Modules\Groups\Service\GroupActivityService $activityService,
        \Modules\Groups\Service\PostMediaService $postMediaService,
        \Modules\Groups\Service\PostAuthorResolver $authorResolver,
        ?\Modules\Groups\Service\ReportService $reportService = null
    ): array {
        $reportService ??= self::reportService(
            $pdo,
            new \Core\Config\SettingService(new \Core\Config\SettingRepository($pdo)),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($pdo))
        );
        $replyRepository = new \Modules\Groups\Repository\ReplyRepository($pdo);
        $replyService = new \Modules\Groups\Service\ReplyService(
            $replyRepository,
            $activityService,
            $postMediaService,
            self::rateLimitService($pdo)
        );
        $reactionService = new \Modules\Groups\Service\ReactionService(
            \Modules\Groups\Repository\ReactionRepository::forPosts($pdo),
            \Modules\Groups\Repository\ReactionRepository::forReplies($pdo),
            $activityService
        );

        return [
            'replyRepository' => $replyRepository,
            'replyService' => $replyService,
            'reactionService' => $reactionService,
            'reportService' => $reportService,
            'replyPresenter' => new \Modules\Groups\Service\ReplyPresenter(
                $authorResolver,
                $replyService,
                $reactionService,
                $reportService
            ),
        ];
    }

    /**
     * A reply with an explicit created_at — how every edit-window and
     * pagination test builds the exact state it needs without waiting for
     * the clock, same shape as createPostAt() above.
     */
    public static function createReplyAt(
        \PDO $pdo,
        int $postId,
        string $body,
        string $at,
        int $accountId = 1,
        int $memberId = 1,
        ?int $galleryMediaId = null
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO discussion_group_replies
                (post_id, author_user_account_id, author_member_id, body, gallery_media_id, edited_at, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([$postId, $accountId, $memberId, $body, $galleryMediaId, $at]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * A post whose created_at and last_activity_at are set explicitly —
     * how every ordering, pagination and edit-window test builds the
     * exact state it needs without waiting for the clock.
     */
    public static function createPostAt(
        \PDO $pdo,
        int $groupId,
        string $body,
        string $at,
        int $accountId = 1,
        int $memberId = 1,
        bool $pinned = false
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO discussion_group_posts
                (group_id, author_user_account_id, author_member_id, body, is_pinned, edited_at, last_activity_at, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
        );
        $stmt->execute([$groupId, $accountId, $memberId, $body, $pinned ? 1 : 0, $at, $at]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * A member with a membership period in $sectionId for $scoutYearId —
     * i.e. a derived member of any group linked to that section for that
     * year.
     */
    public static function createMemberWithPeriod(\PDO $pdo, string $deskId, int $sectionId, int $scoutYearId): int
    {
        $stmt = $pdo->prepare('INSERT INTO members (desk_id, created_at) VALUES (?, ?)');
        $stmt->execute([$deskId, '2026-01-01 00:00:00']);
        $memberId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date, created_at)
             VALUES (?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([$memberId, $sectionId, $scoutYearId, '2025-09-01', '2026-01-01 00:00:00']);

        return $memberId;
    }

    public static function createMember(\PDO $pdo, string $deskId): int
    {
        $stmt = $pdo->prepare('INSERT INTO members (desk_id, created_at) VALUES (?, ?)');
        $stmt->execute([$deskId, '2026-01-01 00:00:00']);

        return (int) $pdo->lastInsertId();
    }

    public static function createSection(\PDO $pdo, string $deskCode, string $name): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, 1)'
        );
        $stmt->execute(['BR-' . $deskCode, 'Branche ' . $name]);
        $ageBranchId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO sections (age_branch_id, desk_code, name, is_visible, is_active, created_at) VALUES (?, ?, ?, 1, 1, ?)'
        );
        $stmt->execute([$ageBranchId, $deskCode, $name, '2026-01-01 00:00:00']);

        return (int) $pdo->lastInsertId();
    }

    public static function createScoutYear(\PDO $pdo, string $label, bool $isCurrent): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $start = substr($label, 0, 4) . '-09-01';
        $end = (string) (((int) substr($label, 0, 4)) + 1) . '-08-31';
        $stmt->execute([$label, $start, $end, $isCurrent ? 1 : 0, '2026-01-01 00:00:00']);

        return (int) $pdo->lastInsertId();
    }
}
