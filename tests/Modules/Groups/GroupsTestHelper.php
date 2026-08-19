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
            scout_year_id INTEGER NULL,
            section_id INTEGER NULL,
            closed_at TEXT NULL,
            last_activity_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by_member_id INTEGER NOT NULL,
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
