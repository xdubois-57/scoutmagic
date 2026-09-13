<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Service\RecipientEmailLink;
use Modules\MassMail\Task\PurgeMergeAudiencesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * RGPD retention for imported mail-merge audiences: purged 18 months
 * after the send (merge_retention_months, read-only), orphans after 7
 * days, and never while a draft/test/sending email still references the
 * audience. The tracking history must survive the purge.
 *
 * The « Nouvel email » notifications of those merges go on the same
 * horizon and in the same pass (issue #292), so they are tested here
 * rather than beside the core retention purge: what is asserted is that
 * one task erases both, without skew.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgeMergeAudiencesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AudienceRepository $audienceRepository;
    private int $sectionId;
    private int $memberId;
    private int $scoutYearId;
    private int $memberYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->audienceRepository = new AudienceRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $this->sectionId = (int) $this->pdo->lastInsertId();

        (new SettingService(new SettingRepository($this->pdo)))
            ->register('merge_retention_months', '18', 'number', 'label', 'desc', 'mass_mail', null, null, false);

        // One member with a profile for one year: what a notification's
        // deep link is built from, and therefore what the purge needs in
        // order to rebuild it.
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('2020-2021', '2020-09-01', '2021-08-31')"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Kaa', 'member_years.first_name'),
            $this->encryption->encrypt('Python', 'member_years.last_name'),
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();
    }

    private function buildContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }

    private function createAudience(string $createdAt): int
    {
        $id = $this->audienceRepository->createAudience('f.xlsx', 'Feuille1', ['Prenom'], 1, null);
        $this->pdo->prepare('UPDATE mass_mail_audiences SET created_at = ? WHERE id = ?')->execute([$createdAt, $id]);
        return $id;
    }

    private function createEmail(?int $audienceId, string $status, ?string $sentAt): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, audience_id, status, sent_at)
             VALUES ('S', '<p>B</p>', ?, 'mail_merge', ?, ?, ?)"
        );
        $stmt->execute([$this->sectionId, $audienceId, $status, $sentAt]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createRecipient(int $emailId, ?int $audienceRowId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO mass_mail_recipients (email_id, member_id, scout_year_id, audience_row_id, status)
             VALUES (?, ?, ?, ?, 'sent')"
        );
        $stmt->execute([$emailId, $this->memberId, $this->scoutYearId, $audienceRowId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * One whole merge, as the send leaves it behind: an audience, a sent
     * email referencing it, the row the recipient was frozen from, and the
     * « Nouvel email » notification carrying the substituted subject — at
     * the exact url Task\SendBatchHandler writes, which is what the purge
     * rebuilds to find it again.
     *
     * @return array{audienceId: int, notificationId: int}
     */
    private function createMergeRecipientWithNotification(string $subject, string $sentAt): array
    {
        $audienceId = $this->createAudience('2020-01-01 00:00:00');
        $emailId = $this->createEmail($audienceId, 'sent', $sentAt);
        $rowId = $this->audienceRepository->createRow($audienceId, 0, null, 'a@test.be', ['Prenom' => 'Kaa']);
        $recipientId = $this->createRecipient($emailId, $rowId);

        return [
            'audienceId' => $audienceId,
            'notificationId' => $this->insertNotification(
                'mass_mail.email_received',
                $subject,
                $sentAt,
                RecipientEmailLink::url($this->memberYearId, $recipientId)
            ),
        ];
    }

    private function insertNotification(string $typeId, string $body, string $createdAt, ?string $url): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_account_id, type_id, title, body, url, created_at, read_at)
             VALUES (1, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([
            $typeId,
            $this->encryption->encrypt('Nouvel email', 'notifications.title'),
            $this->encryption->encrypt($body, 'notifications.body'),
            $url,
            $createdAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Reads `body` the way NotificationRepository::hydrate() does — the
     * column is a BLOB, and a driver may hand it back as a stream rather
     * than a string.
     *
     * @return array<int, string>
     */
    private function notificationBodies(): array
    {
        $rows = $this->pdo->query('SELECT body FROM notifications')->fetchAll(\PDO::FETCH_COLUMN);

        return array_map(
            fn (mixed $body): string => $this->encryption->decrypt(
                is_resource($body) ? (string) stream_get_contents($body) : (string) $body,
                'notifications.body'
            ),
            $rows
        );
    }

    /**
     * @return array<int, int>
     */
    private function notificationIds(): array
    {
        return array_map(
            'intval',
            $this->pdo->query('SELECT id FROM notifications ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    public function testPurgesAudiencesSentLongerAgoThanRetentionAndKeepsRecentOnes(): void
    {
        $oldAudience = $this->createAudience('2020-01-01 00:00:00');
        $oldEmail = $this->createEmail($oldAudience, 'sent', '2020-06-01 00:00:00');
        $oldRowId = $this->audienceRepository->createRow($oldAudience, 2, null, 'a@test.be', ['Prenom' => 'A']);
        $this->pdo->prepare(
            "INSERT INTO mass_mail_recipients (email_id, audience_row_id, status) VALUES (?, ?, 'sent')"
        )->execute([$oldEmail, $oldRowId]);

        $recentAudience = $this->createAudience('2020-01-01 00:00:00');
        $this->createEmail($recentAudience, 'sent', (new \DateTimeImmutable('-1 month'))->format('Y-m-d H:i:s'));

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $this->assertNull($this->audienceRepository->findById($oldAudience));
        $this->assertNotNull($this->audienceRepository->findById($recentAudience));

        // The sent email and its tracking history survive, unlinked.
        $stmt = $this->pdo->prepare('SELECT audience_id FROM mass_mail_emails WHERE id = ?');
        $stmt->execute([$oldEmail]);
        $this->assertNull($stmt->fetchColumn() ?: null);
        $row = $this->pdo->query('SELECT audience_row_id, status FROM mass_mail_recipients')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['audience_row_id']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_audience_rows')->fetchColumn());
    }

    public function testNeverPurgesAnAudienceStillReferencedByADraft(): void
    {
        $audience = $this->createAudience('2020-01-01 00:00:00');
        $this->createEmail($audience, 'draft', null);

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $this->assertNotNull($this->audienceRepository->findById($audience));
    }

    public function testPurgesOrphanAudiencesAfterSevenDaysButKeepsFreshOnes(): void
    {
        $oldOrphan = $this->createAudience('2020-01-01 00:00:00');
        $freshOrphan = $this->createAudience((new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'));

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $this->assertNull($this->audienceRepository->findById($oldOrphan));
        $this->assertNotNull($this->audienceRepository->findById($freshOrphan));
    }

    /**
     * The notification a merge leaves behind carries the SENT subject —
     * « Camp de Kaa » — and `notifications.body` is written once at
     * dispatch and never recomputed. The core retention purge only ever
     * deletes rows somebody has READ, so an unread one was immortal, and
     * that is exactly why the subject used to be scrubbed before it got
     * there (issue #292).
     *
     * Unread is the whole point of this test: a read one was already
     * covered by the core purge.
     */
    public function testPurgesTheUnreadNotificationOfAPurgedAudiencesRecipient(): void
    {
        $purged = $this->createMergeRecipientWithNotification('Camp de Kaa', '2020-06-01 00:00:00');
        $kept = $this->createMergeRecipientWithNotification(
            'Camp de Baloo',
            (new \DateTimeImmutable('-1 month'))->format('Y-m-d H:i:s')
        );

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $bodies = $this->notificationBodies();
        $this->assertNotContains('Camp de Kaa', $bodies, 'A substituted subject may not outlive its audience.');
        $this->assertContains('Camp de Baloo', $bodies, 'A notification whose audience survives is left alone.');
        $this->assertSame([$kept['notificationId']], $this->notificationIds());
        $this->assertNull($this->audienceRepository->findById($purged['audienceId']));
    }

    /**
     * The reason this purge names its rows instead of deleting a whole
     * `type_id` older than the cutoff.
     *
     * Task\SendBatchHandler dispatches `mass_mail.email_received` for
     * EVERY send, not just merges — the merge branch only substitutes the
     * subject, it does not gate the notification. So a type-wide purge
     * would also delete the notification of an ordinary list send: unread,
     * carrying the one subject everybody got, nothing to erase, and under
     * a setting whose own label is about imported publipostage files. The
     * site-wide promise is that an unread notification is never purged,
     * and this is where it has to keep holding.
     */
    public function testLeavesAnOrdinaryListSendsNotificationAloneHoweverOldItIs(): void
    {
        // A merge IS purged in the same run — without one the handler has
        // nothing to delete and this test would pass on the broken code
        // too.
        $merge = $this->createMergeRecipientWithNotification('Camp de Kaa', '2020-06-01 00:00:00');

        // No audience_row_id: a criteria-resolved recipient of a plain list
        // send, the case the schema calls out as NULL. Same type, same age,
        // same member — only the audience row tells them apart.
        $email = $this->createEmail(null, 'sent', '2020-06-01 00:00:00');
        $recipientId = $this->createRecipient($email, null);
        $this->insertNotification(
            'mass_mail.email_received',
            'Réunion de rentrée',
            '2020-06-01 00:00:00',
            RecipientEmailLink::url($this->memberYearId, $recipientId)
        );

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $bodies = $this->notificationBodies();
        $this->assertContains('Réunion de rentrée', $bodies, 'A plain list send carries nothing to erase.');
        $this->assertNotContains('Camp de Kaa', $bodies, 'The merge beside it is still purged.');
        $this->assertNull($this->audienceRepository->findById($merge['audienceId']));
    }

    /**
     * An audience a draft still references is never purged, however old —
     * and now neither is its notification. The two erasures are one
     * decision rather than two that happen to agree.
     */
    public function testKeepsTheNotificationOfAnAudienceADraftStillHolds(): void
    {
        // Again, a merge that IS purged in the same run, so the assertion
        // below is about the draft and not about a handler that did
        // nothing at all.
        $this->createMergeRecipientWithNotification('Camp de Baloo', '2020-06-01 00:00:00');

        $audience = $this->createAudience('2020-01-01 00:00:00');
        $this->createEmail($audience, 'draft', null);
        $rowId = $this->audienceRepository->createRow($audience, 0, null, 'a@test.be', ['Prenom' => 'Kaa']);
        $recipientId = $this->createRecipient($this->createEmail($audience, 'sent', '2020-06-01 00:00:00'), $rowId);
        $this->insertNotification(
            'mass_mail.email_received',
            'Camp de Kaa',
            '2020-06-01 00:00:00',
            RecipientEmailLink::url($this->memberYearId, $recipientId)
        );

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $bodies = $this->notificationBodies();
        $this->assertContains('Camp de Kaa', $bodies, 'A draft still holds this audience, so nothing is erased.');
        $this->assertNotContains('Camp de Baloo', $bodies, 'The merge beside it is still purged.');
    }

    /**
     * The purge is scoped to the type this module owns. Deleting somebody
     * else's unread notifications would be a site-wide change of
     * behaviour smuggled in as a module's retention — and a url alone is
     * not enough, since a url belongs to a route and not to a type.
     */
    public function testLeavesOtherTypesAloneHoweverOldTheyAre(): void
    {
        $audience = $this->createAudience('2020-01-01 00:00:00');
        $rowId = $this->audienceRepository->createRow($audience, 0, null, 'a@test.be', ['Prenom' => 'Kaa']);
        $recipientId = $this->createRecipient($this->createEmail($audience, 'sent', '2020-06-01 00:00:00'), $rowId);

        // Same url, another type: still not this module's to delete.
        $this->insertNotification(
            'calendar.event_created',
            'Réunion de 2019',
            '2019-01-01 00:00:00',
            RecipientEmailLink::url($this->memberYearId, $recipientId)
        );

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $this->assertContains('Réunion de 2019', $this->notificationBodies());
    }

    /**
     * More recipients than one statement may bind.
     *
     * `Connection` sets `ATTR_EMULATE_PREPARES => false`, so MySQL refuses
     * a statement with more than 65 535 parameters — and the list here is
     * as long as the audiences being erased, which nothing bounds. The
     * failure would land exactly when there is most to erase, and after
     * the audience rows that are the only way to rebuild these urls had
     * already gone.
     *
     * The batch size is deliberately not reached here: 600 rows crosses
     * one boundary of NotificationRepository's 500, which is what proves
     * the loop sums rather than returns on its first pass. Binding 65 536
     * parameters to prove the real ceiling would be a slow test of MySQL
     * rather than of this code.
     */
    public function testPurgesMoreNotificationsThanOneStatementCanBind(): void
    {
        $audienceId = $this->createAudience('2020-01-01 00:00:00');
        $emailId = $this->createEmail($audienceId, 'sent', '2020-06-01 00:00:00');

        $expected = 600;
        for ($i = 0; $i < $expected; $i++) {
            $rowId = $this->audienceRepository->createRow($audienceId, $i, null, 'a@test.be', ['Prenom' => 'Kaa']);
            $this->insertNotification(
                'mass_mail.email_received',
                'Camp de Kaa ' . $i,
                '2020-06-01 00:00:00',
                RecipientEmailLink::url($this->memberYearId, $this->createRecipient($emailId, $rowId))
            );
        }
        $this->assertSame($expected, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());

        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());

        // And the count reached the journal, which is the only place an
        // administrator sees that this happened at all — a loop returning
        // after its first batch would purge everything and report 500.
        $context = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'merge_audiences_purged'"
        )->fetchColumn();
        $this->assertStringContainsString('"notifications":' . $expected, (string) $context);
    }

    /**
     * Read, delete, purge is a sequence whose FIRST step destroys the key
     * the LAST one needs: `deleteById()` nulls `audience_row_id`. A failure
     * in between, committed, would leave notification bodies with nothing
     * left to correlate them by — unpurgeable rather than merely unpurged,
     * and the scheduler marks the task failed rather than retrying it.
     *
     * So the whole erasure is one transaction. Here the notification purge
     * is made to fail by dropping the table out from under it; what is
     * asserted is that the audience is still there afterwards, ready for
     * the next daily run.
     */
    public function testARollbackLeavesTheAudienceForTheNextRun(): void
    {
        $merge = $this->createMergeRecipientWithNotification('Camp de Kaa', '2020-06-01 00:00:00');

        $this->pdo->exec('DROP TABLE notifications');

        try {
            (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());
            $this->fail('The purge must not swallow a failure of its own erasure.');
        } catch (\Throwable $e) {
            $this->assertFalse($this->pdo->inTransaction(), 'The transaction must not be left open.');
        }

        $this->assertNotNull(
            $this->audienceRepository->findById($merge['audienceId']),
            'A rolled-back purge leaves the audience — and its audience_row_id — for the next run.'
        );
    }

    public function testReschedulesItselfDaily(): void
    {
        (new PurgeMergeAudiencesHandler())->handle([], $this->buildContext());

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'purge_merge_audiences' AND status = 'pending'"
        );
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
