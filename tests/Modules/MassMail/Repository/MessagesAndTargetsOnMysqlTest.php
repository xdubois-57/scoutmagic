<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\NotifiedAccountRepository;
use Modules\MassMail\Repository\TargetRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Messages, targets and notified accounts (issue #999) on the engine
 * production runs, against the schema as the migration builds it.
 *
 * What the engine decides here and SQLite does not:
 * - the unique key of mass_mail_notified_accounts, and telling its
 *   violation (1062) from a foreign-key refusal (1452), which share
 *   SQLSTATE 23000 — NotifiedAccountRepository::claim() swallows only the
 *   first;
 * - `audience_row_key`, which this schema's tooling cannot declare as a
 *   generated column (schema.sql): a plain NOT NULL column, and the premise
 *   below proves the migration really built it that way;
 * - the referential actions SQLite's test tables do not enforce: messages
 *   and targets cascade with their email and go to NULL with their file
 *   row, the notified rows CASCADE with it — which is what keeps a purge of
 *   two children notified to one account from breaking the unique key;
 * - the history's correlated subqueries inside a derived table, and its
 *   order on a `sent_at` the server fills in.
 */
#[Group('database')]
final class MessagesAndTargetsOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private DeliveryRepository $deliveries;
    private TargetRepository $targets;
    private NotifiedAccountRepository $notified;
    private AudienceRepository $audiences;
    private int $sectionId;
    private int $yearId;
    private int $memberId;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->deliveries = new DeliveryRepository($this->pdo, $this->encryption);
        $this->targets = new TargetRepository($this->pdo);
        $this->notified = new NotifiedAccountRepository($this->pdo);
        $this->audiences = new AudienceRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOU', 'Louveteaux')");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'LOU01', 'Meute')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->yearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->accountId = (new UserAccountRepository($this->pdo, $this->encryption))->create('parent@x.be')->id;
    }

    public function testTheSchemaIsBuiltAsTheRoadmapSays(): void
    {
        $this->assertSame(
            ['email_id', 'audience_row_key', 'user_account_id'],
            array_map('strval', $this->pdo->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mass_mail_notified_accounts'
                   AND INDEX_NAME = 'idx_mmna_once' AND NON_UNIQUE = 0
                 ORDER BY SEQ_IN_INDEX"
            )->fetchAll(\PDO::FETCH_COLUMN)),
            'the unique key, on the key column rather than on the NULL-able one'
        );
        $this->assertSame(
            'NO',
            (string) $this->pdo->query(
                "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mass_mail_notified_accounts'
                   AND COLUMN_NAME = 'audience_row_key'"
            )->fetchColumn(),
            'a NULL key would make the unique index hold nothing'
        );

        $rules = [];
        foreach ($this->pdo->query(
            "SELECT CONSTRAINT_NAME, DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN
                ('fk_mmd_email', 'fk_mmd_audience_row', 'fk_mmt_email', 'fk_mmt_audience_row',
                 'fk_mmdt_delivery', 'fk_mmdt_target', 'fk_mmna_email', 'fk_mmna_audience_row', 'fk_mmna_account')"
        )->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rules[(string) $row['CONSTRAINT_NAME']] = (string) $row['DELETE_RULE'];
        }
        ksort($rules);
        $this->assertSame([
            'fk_mmd_audience_row' => 'SET NULL',
            'fk_mmd_email' => 'CASCADE',
            'fk_mmdt_delivery' => 'CASCADE',
            'fk_mmdt_target' => 'CASCADE',
            'fk_mmna_account' => 'CASCADE',
            'fk_mmna_audience_row' => 'CASCADE',
            'fk_mmna_email' => 'CASCADE',
            'fk_mmt_audience_row' => 'SET NULL',
            'fk_mmt_email' => 'CASCADE',
        ], $rules);
    }

    public function testAnAccountIsClaimedOncePerCommunicationAndOncePerRow(): void
    {
        $email = $this->email();
        $audienceId = $this->audiences->createAudience('f.xlsx', 'F', ['Prenom'], 2, null);
        $rowA = $this->audiences->createRow($audienceId, 2, $this->memberId, null, ['Prenom' => 'A']);
        $rowB = $this->audiences->createRow($audienceId, 3, $this->memberId, null, ['Prenom' => 'B']);

        $this->assertTrue($this->notified->claim($email, null, $this->accountId));
        $this->assertFalse($this->notified->claim($email, null, $this->accountId), 'a second message, or a resend');
        $this->assertTrue($this->notified->claim($email, $rowA, $this->accountId));
        $this->assertTrue($this->notified->claim($email, $rowB, $this->accountId), 'another line notifies again');
        $this->assertFalse($this->notified->claim($email, $rowB, $this->accountId));
        $this->assertSame(3, $this->notified->countForEmail($email));
    }

    /**
     * The race the read cannot see: the row is there although the read
     * found none. Only the duplicate (1062) means « already notified ».
     */
    public function testOnlyTheDuplicateIsTakenForAlreadyNotified(): void
    {
        $email = $this->email();
        $this->pdo->exec(
            "INSERT INTO mass_mail_notified_accounts (email_id, audience_row_id, audience_row_key, user_account_id)
             VALUES ({$email}, NULL, 0, {$this->accountId})"
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO mass_mail_notified_accounts (email_id, audience_row_id, audience_row_key, user_account_id)
             VALUES (?, NULL, 0, ?)'
        );
        try {
            $statement->execute([$email, $this->accountId]);
            $this->fail('the premise: this engine refuses the duplicate');
        } catch (\PDOException $e) {
            $this->assertSame(1062, $e->errorInfo[1] ?? null, 'the premise: a duplicate is 1062 here');
        }

        $this->assertFalse($this->notified->claim($email, null, $this->accountId));

        // An account that does not exist is a foreign-key refusal, 1452:
        // never « already notified ».
        $this->expectException(\PDOException::class);
        $this->notified->claim($email, null, $this->accountId + 999);
    }

    /**
     * Two children of one family on two lines, notified to the same parent:
     * the purge deletes the file rows, the notified rows go with them (a SET
     * NULL would bring both to (email, NULL, parent) and break the key),
     * messages and targets survive unlinked.
     */
    public function testPurgingTheFileRowsOfTwoChildrenNotifiedToOneAccountGoesThrough(): void
    {
        $email = $this->email();
        $audienceId = $this->audiences->createAudience('f.xlsx', 'F', ['Prenom'], 2, null);
        foreach (['Kaa', 'Baloo'] as $index => $child) {
            $row = $this->audiences->createRow($audienceId, $index + 2, $this->memberId, null, ['Prenom' => $child]);
            $target = $this->targets->create($email, $this->memberId, $this->yearId, $row);
            $this->deliveries->linkTargets($this->deliveries->create($email, 'parent@x.be', 'sent', null, null, $row), [$target]);
            $this->assertTrue($this->notified->claim($email, $row, $this->accountId));
        }

        // A bare DELETE, as the schema alone must handle it.
        $this->pdo->prepare('DELETE FROM mass_mail_audience_rows WHERE audience_id = ?')->execute([$audienceId]);

        $this->assertSame(0, $this->notified->countForEmail($email));
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_deliveries WHERE audience_row_id IS NULL')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_targets WHERE audience_row_id IS NULL')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_delivery_targets')->fetchColumn());

        // And the repository's own deletion agrees.
        $this->audiences->deleteById($audienceId);
        $this->assertNull($this->audiences->findById($audienceId));
    }

    /** Deleting an email takes its messages, targets, links and notified rows. */
    public function testAnEmailCascadesEverythingFrozenFromIt(): void
    {
        $email = $this->email();
        $target = $this->targets->create($email, $this->memberId, $this->yearId);
        $this->deliveries->linkTargets($this->deliveries->create($email, 'parent@x.be'), [$target]);
        $this->notified->claim($email, null, $this->accountId);

        $this->pdo->prepare('DELETE FROM mass_mail_emails WHERE id = ?')->execute([$email]);

        foreach (['mass_mail_deliveries', 'mass_mail_targets', 'mass_mail_delivery_targets', 'mass_mail_notified_accounts'] as $table) {
            $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        }
    }

    /**
     * The history on this engine: one entry per target, « Non envoyée » when
     * nothing left, hidden while still going out, newest first by a
     * `sent_at` the server writes, the limit counting entries.
     */
    public function testTheHistoryOnThisEngine(): void
    {
        $older = $this->email('Ancien');
        $this->sendTo($older, ['a@x.be', 'b@x.be', 'c@x.be'], ['sent', 'sent', 'error']);
        $this->pdo->prepare("UPDATE mass_mail_deliveries SET sent_at = '2025-10-01 10:00:00' WHERE email_id = ? AND status = 'sent'")
            ->execute([$older]);
        $notSent = $this->email('Rien parti');
        $this->sendTo($notSent, ['a@x.be'], ['error']);
        $this->pdo->prepare("UPDATE mass_mail_targets SET created_at = '2025-11-01 10:00:00' WHERE email_id = ?")->execute([$notSent]);
        $noAddress = $this->email('Sans adresse');
        $this->sendTo($noAddress, [], []);
        $this->pdo->prepare("UPDATE mass_mail_targets SET created_at = '2025-09-15 10:00:00' WHERE email_id = ?")->execute([$noAddress]);
        $going = $this->email('En cours', 'sending');
        $this->sendTo($going, ['a@x.be'], ['pending']);
        $recent = $this->email('Récent');
        $this->sendTo($recent, ['a@x.be'], ['sent']);

        $this->assertNotNull(
            $this->pdo->query("SELECT sent_at FROM mass_mail_deliveries WHERE email_id = {$recent}")->fetchColumn(),
            'the premise: the server filled sent_at in'
        );

        $entries = $this->targets->findRecentEntriesForMember($this->memberId, 10);

        $this->assertSame(['Récent', 'Rien parti', 'Ancien', 'Sans adresse'], array_column($entries, 'subject'));
        $this->assertSame([false, true, false, true], array_column($entries, 'not_sent'));
        $this->assertSame('2025-10-01 10:00:00', $entries[2]['sent_at']);
        $this->assertCount(2, $this->targets->findRecentEntriesForMember($this->memberId, 2));

        $first = $entries[0];
        $this->assertNotNull($this->targets->findEntryForMember($first['id'], $this->memberId));
        $this->assertNull($this->targets->findEntryForMember($first['id'], $this->memberId + 1));
    }

    private function email(string $subject = 'Sujet', string $status = 'sent'): int
    {
        $this->pdo->prepare(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES (?, '<p>x</p>', ?, 'default_active_members', ?)"
        )->execute([$subject, $this->sectionId, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param string[] $addresses
     * @param string[] $statuses
     */
    private function sendTo(int $email, array $addresses, array $statuses): void
    {
        $target = $this->targets->create($email, $this->memberId, $this->yearId);
        foreach ($addresses as $index => $address) {
            $delivery = $this->deliveries->create($email, $address);
            if ($statuses[$index] === 'sent') {
                $this->deliveries->recordSendSuccess($delivery);
            } elseif ($statuses[$index] === 'error') {
                $this->deliveries->recordSendFailure($delivery, 'Boîte pleine');
            }
            $this->deliveries->linkTargets($delivery, [$target]);
        }
    }
}
