<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\UnsettledReportRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Groups\GroupsTestHelper;
use Tests\UsesProductionEngine;

/**
 * `UnsettledReportRepository::countsByGroup()` on the engine production
 * runs: a derived table over a UNION ALL of two `EXISTS` subqueries, which
 * is the kind of statement AGENTS.md § Database sends to the real engine
 * rather than trusting SQLite with — and on the real schema, foreign keys
 * and cascades included.
 */
#[Group('database')]
final class UnsettledReportRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testReportedPostsAndRepliesAreCountedPerGroupUntilAnswered(): void
    {
        $pdo = $this->productionEngine();
        // The fewest rows the foreign keys accept: an author account, and
        // two members — the author and the one who reports.
        $encryption = GroupsTestHelper::testEncryption();
        $pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, first_name_encrypted, last_name_encrypted, is_super_admin)
             VALUES (?, ?, ?, ?, 0)'
        )->execute([
            $encryption->encrypt('chef@example.org', 'user_accounts.email'),
            $encryption->blindIndex('chef@example.org', 'email'),
            $encryption->encrypt('Ada', 'user_accounts.first_name'),
            $encryption->encrypt('Chef', 'user_accounts.last_name'),
        ]);
        $account = (int) $pdo->lastInsertId();
        $member = static function (string $deskId) use ($pdo): int {
            $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);

            return (int) $pdo->lastInsertId();
        };
        $author = $member('A1');
        $reporter = $member('R1');

        $groups = new GroupRepository($pdo);
        $louveteaux = $groups->create('Louveteaux', null, null, 1);
        $eclaireurs = $groups->create('Éclaireurs', null, null, 1);

        $post = GroupsTestHelper::createPostAt($pdo, $louveteaux, 'hello', '2026-01-01 10:00:00', $account, $author);
        $cleared = GroupsTestHelper::createPostAt($pdo, $louveteaux, 'ok', '2026-01-01 11:00:00', $account, $author);
        $other = GroupsTestHelper::createPostAt($pdo, $eclaireurs, 'salut', '2026-01-02 10:00:00', $account, $author);
        $pdo->prepare(
            'INSERT INTO discussion_group_replies (post_id, author_user_account_id, author_member_id, body, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$post, $account, $author, 'réponse', '2026-01-01 10:05:00']);
        $reply = (int) $pdo->lastInsertId();

        $report = static function (string $table, string $column, int $id) use ($pdo, $reporter): void {
            $pdo->prepare("INSERT INTO {$table} ({$column}, reporter_member_id) VALUES (?, ?)")->execute([$id, $reporter]);
        };
        $report('discussion_group_post_reports', 'post_id', $post);
        $report('discussion_group_post_reports', 'post_id', $cleared);
        $report('discussion_group_post_reports', 'post_id', $other);
        $report('discussion_group_reply_reports', 'reply_id', $reply);
        $pdo->prepare('UPDATE discussion_group_posts SET moderation_cleared = 1 WHERE id = ?')->execute([$cleared]);

        $repository = new UnsettledReportRepository($pdo);
        $this->assertSame(
            [
                ['group_id' => $louveteaux, 'group_name' => 'Louveteaux', 'items' => 2],
                ['group_id' => $eclaireurs, 'group_name' => 'Éclaireurs', 'items' => 1],
            ],
            $repository->countsByGroup()
        );

        // Deleting an item takes its reports with it (ON DELETE CASCADE):
        // that answers them, on the real schema.
        $pdo->prepare('DELETE FROM discussion_group_posts WHERE id = ?')->execute([$other]);
        $this->assertSame(
            [['group_id' => $louveteaux, 'group_name' => 'Louveteaux', 'items' => 2]],
            $repository->countsByGroup()
        );
    }
}
