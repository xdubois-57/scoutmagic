<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\StorageQuotaService;
use Modules\InboundMail\Task\PurgeUnlinkedMessagesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * What makes storing everything defensible.
 *
 * This module keeps every message it reads. That is only tenable because a
 * message nothing points at eventually goes, because the disk has a
 * ceiling, and because both say so out loud. This file pins the first two.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RetentionAndQuotaTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private PurgeUnlinkedMessagesHandler $purge;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);

        $this->messages = new InboundMessageRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->purge = new PurgeUnlinkedMessagesHandler();
        $this->now = new \DateTimeImmutable('2027-07-12 10:00:00');
    }

    // ── The retention (D1) ──────────────────────────────────────────────

    public function testAMessageNobodyPointsAtGoesOnceItIsOldEnough(): void
    {
        $old = $this->storeMessage('old@example.be', '-100 days');
        $recent = $this->storeMessage('recent@example.be', '-10 days');

        $this->assertSame(1, $this->runPurge());

        $this->assertNull($this->messages->findAnyForAnalysis($old));
        $this->assertNotNull($this->messages->findAnyForAnalysis($recent));
    }

    public function testAnAssociatedMessageIsNeverPurgedHoweverOldItIs(): void
    {
        $id = $this->storeMessage('old@example.be', '-5 years');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $this->assertSame(0, $this->runPurge());
        $this->assertNotNull($this->messages->findAnyForAnalysis($id));
    }

    public function testAPropositionStillStandingProtectsTheMessage(): void
    {
        $id = $this->storeMessage('old@example.be', '-5 years');
        $this->messages->addCandidate($id, 'camps', new MessageCandidate(
            'camp-3',
            'Un séjour',
            'sender_window',
            'Parce que.'
        ));

        $this->assertSame(0, $this->runPurge());
        $this->assertNotNull($this->messages->findAnyForAnalysis($id));
    }

    public function testAPropositionSomebodySetAsideProtectsNothing(): void
    {
        // A3: `dismissed_at` records a decision that this message is not
        // that module's business. Treating it as a reason to keep the
        // message would make « écarter » mean the opposite of what it says.
        $id = $this->storeMessage('old@example.be', '-5 years');
        $this->messages->addCandidate($id, 'camps', new MessageCandidate('camp-3', 'S', 'sender_window', 'x'));
        $this->messages->dismissCandidate($id, 'camps', 'camp-3', 0, $this->now);

        $this->assertSame(1, $this->runPurge());
        $this->assertNull($this->messages->findAnyForAnalysis($id));
    }

    public function testTheClockRunsFromTheMessagesOwnDateNotFromWhenItWasDetached(): void
    {
        // Otherwise detaching would be a way to keep things indefinitely:
        // a 2024 message detached today would earn a fresh 90 days.
        $id = $this->storeMessage('old@example.be', '-5 years');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE);
        $this->messages->removeLink($id, 'rental', 'LOC-1', $this->now);

        // Still inside the 30-day floor, so not yet.
        $this->assertSame(0, $this->runPurge());

        // Past the floor, and the message's own date was always old
        // enough: it goes, without a second 90 days.
        $this->assertSame(1, $this->runPurge($this->now->modify('+31 days')));
    }

    public function testDetachingAnOldMessageByMistakeLeavesAWindowToNoticeIt(): void
    {
        // A4's floor. Without it, a mis-click on a three-year-old message
        // makes it disappear on the very next nightly purge.
        $id = $this->storeMessage('old@example.be', '-5 years');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE);
        $this->messages->removeLink($id, 'rental', 'LOC-1', $this->now);

        $this->assertSame(0, $this->runPurge($this->now->modify('+29 days')));
        $this->assertNotNull($this->messages->findAnyForAnalysis($id));
    }

    public function testThePurgeTakesTheAttachmentFilesWithIt(): void
    {
        $id = $this->storeMessage('old@example.be', '-100 days');
        $files = new FileRepository($this->pdo);
        $fileId = $files->create('inbound_mail/a.pdf', 'a.pdf', 'application/pdf', 10, 'intendant', 'inbound_mail', null);
        $this->messages->addAttachment($id, $fileId, 'a.pdf', 'application/pdf', 10, 'hash');

        $this->assertSame(1, $this->runPurge());
        $this->assertNull($files->findById($fileId));
    }

    public function testAReclassifiedFileOutlivesTheMessageItArrivedIn(): void
    {
        // The whole reason detach() releases a preserved file. Without the
        // release, this purge deletes a booking's signed contract ninety
        // days after the email it arrived in — and the booking's document
        // row would point at bytes that are gone.
        $id = $this->storeMessage('old@example.be', '-100 days');
        $files = new FileRepository($this->pdo);
        $contract = $files->create(
            'inbound_mail/contrat.pdf',
            'contrat.pdf',
            'application/pdf',
            10,
            'intendant',
            'inbound_mail',
            null
        );
        $this->messages->addAttachment($id, $contract, 'contrat.pdf', 'application/pdf', 10, 'hash');
        $this->assertSame(1, $this->messages->releaseAttachmentFile($id, $contract));

        $this->assertSame(1, $this->runPurge());
        $this->assertNull($this->messages->findAnyForAnalysis($id), 'the message itself still goes');
        $this->assertNotNull($files->findById($contract), 'but not the file a consumer took over');
    }

    public function testThePurgeIsBounded(): void
    {
        // poor_mans_cron runs inside a page view: a purge that walked five
        // years of mail in one request would be killed halfway.
        for ($i = 0; $i < 3; $i++) {
            $this->storeMessage('m' . $i . '@example.be', '-100 days');
        }

        $this->assertCount(2, $this->messages->findPurgeableMessageIds($this->now, 90, 2));
    }

    // ── The Camps reprise (A8) ──────────────────────────────────────────

    public function testAFreshInstallationKeepsNinetyDays(): void
    {
        $this->assertSame(90, $this->purge->retentionDays($this->settingsWith([])));
    }

    public function testAnInstallationThatHadCampsOwnSettingInheritsOneHundredAndEighty(): void
    {
        // Not shortened to 90 in silence: a unit that configured six months
        // of unsorted camp mail expects to find six months of it, and
        // quietly erasing three would be the module deleting data nobody
        // asked it to delete. Copied ONCE now, rather than read live:
        // Camps no longer reads that setting at all since its `unsorted`
        // reference went (IT-07), and a module declaring a setting nothing
        // in it reads is a promise its configuration page does not keep.
        $settings = $this->settingsWith([
            ['camps', 'camps_unsorted_retention_months', '6'],
            ['inbound_mail', PurgeUnlinkedMessagesHandler::SETTING_RETENTION_DAYS, ''],
        ]);

        $this->assertTrue(
            PurgeUnlinkedMessagesHandler::inheritCampsRetention($settings, new SettingRepository($this->pdo))
        );
        $this->assertSame(180, $this->purge->retentionDays($settings));
    }

    public function testAStatedDurationIsNeverOverwrittenByTheInheritedOne(): void
    {
        $settings = $this->settingsWith([
            ['camps', 'camps_unsorted_retention_months', '6'],
            ['inbound_mail', PurgeUnlinkedMessagesHandler::SETTING_RETENTION_DAYS, '30'],
        ]);

        $this->assertFalse(
            PurgeUnlinkedMessagesHandler::inheritCampsRetention($settings, new SettingRepository($this->pdo))
        );
        $this->assertSame(30, $this->purge->retentionDays($settings));
    }

    public function testThereIsNothingToInheritWhenCampsNeverHadTheSetting(): void
    {
        $settings = $this->settingsWith([
            ['inbound_mail', PurgeUnlinkedMessagesHandler::SETTING_RETENTION_DAYS, ''],
        ]);

        $this->assertFalse(
            PurgeUnlinkedMessagesHandler::inheritCampsRetention($settings, new SettingRepository($this->pdo))
        );
        $this->assertSame(90, $this->purge->retentionDays($settings));
    }

    // ── The quota (D5) ──────────────────────────────────────────────────

    public function testTheQuotaRefusesAWriteThatWouldCrossTheCeiling(): void
    {
        $quota = $this->quotaWith('1');
        $this->storeStoredBytes(1024 * 1024);

        $this->assertFalse($quota->accepts(1));
        $this->assertTrue($this->quotaWith('2')->accepts(1));
    }

    public function testAQuotaOfZeroMeansNoCeilingAtAll(): void
    {
        // Deliberately expressible: a unit on its own server has no reason
        // to carry one.
        $this->storeStoredBytes(50 * 1024 * 1024);

        $this->assertTrue($this->quotaWith('0')->accepts(PHP_INT_MAX - 1));
    }

    public function testTheSameFileSharedByTwoMessagesCountsOnce(): void
    {
        // Deduplication means several rows legitimately point at one stored
        // file. Counting it per row would inflate the figure until the
        // quota fired on space nobody uses.
        $first = $this->storeMessage('a@example.be');
        $second = $this->storeMessage('b@example.be');
        $this->messages->addAttachment($first, 7, 'a.pdf', 'application/pdf', 1000, 'shared');
        $this->messages->addAttachment($second, 7, 'a.pdf', 'application/pdf', 1000, 'shared');

        $this->assertSame(1000, $this->messages->totalStoredBytes());
    }

    public function testAnOmittedAttachmentIsNotCountedAgainstTheQuota(): void
    {
        // Otherwise a box already refusing writes would look ever fuller,
        // and the quota would never let go.
        $id = $this->storeMessage('a@example.be');
        $this->messages->addOmittedAttachment($id, 'big.mp4', 'video/mp4', 99_000_000, 'h', 'too_large');

        $this->assertSame(0, $this->messages->totalStoredBytes());
    }

    public function testGoingOverQuotaPurgesTheOldestUnclaimedAndAlertsOnceADay(): void
    {
        $this->storeMessage('old@example.be', '-1 day');
        $kept = $this->storeMessage('kept@example.be', '-1 day');
        $this->messages->addLink($kept, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $alerts = 0;
        $quota = $this->quotaWith('1', function () use (&$alerts): void {
            $alerts++;
        });

        $deleted = [];
        $purged = $quota->handleOverQuota(function (int $id) use (&$deleted): void {
            $deleted[] = $id;
            $this->messages->deleteMessage($id);
        }, $this->now);

        // The associated message is never a candidate for the emergency
        // purge, however old.
        $this->assertSame(1, $purged);
        $this->assertNotContains($kept, $deleted);
        $this->assertSame(1, $alerts);

        // Second event the same day: purge again, but do not mail again —
        // a notice per refused attachment on a busy box is unsubscribed
        // from within a week, which leaves nobody told at all.
        $quota->handleOverQuota(static function (int $id): void {
        }, $this->now->modify('+1 hour'));
        $this->assertSame(1, $alerts);

        $quota->handleOverQuota(static function (int $id): void {
        }, $this->now->modify('+25 hours'));
        $this->assertSame(2, $alerts);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function runPurge(?\DateTimeImmutable $at = null): int
    {
        return $this->purge->purge($this->messages, new FileRepository($this->pdo), 90, $at ?? $this->now);
    }

    // ── The task as the scheduler actually runs it ──────────────────────

    public function testTheScheduledRunRemovesWhatThePurgeWouldAndSaysSoWithACountAlone(): void
    {
        // The journal line is the sensitive part. Naming a sender or a
        // subject there would write down exactly what the retention exists
        // to stop keeping (§7.9), so the entry carries a number and
        // nothing else.
        // handle() reads the real clock rather than this file's frozen
        // 2027, so this one message is dated against it.
        $old = $this->storeRealTimeMessage('vieux@example.be', '-100 days');

        $this->runHandler();

        $this->assertNull($this->messages->findAnyForAnalysis($old));

        $entry = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'inbound_messages_purged'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($entry);
        $row = json_encode($entry, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($row);
        $this->assertStringNotContainsString('vieux@example.be', $row);
        $this->assertStringNotContainsString('Sujet', $row);
        $this->assertStringContainsString('1', $row);
    }

    public function testARunThatRemovedNothingWritesNoJournalEntryAtAll(): void
    {
        // A daily task that logs every time it finds nothing turns the
        // journal into noise nobody reads.
        $this->storeRealTimeMessage('recent@example.be', '-10 days');

        $this->runHandler();

        $this->assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM event_log WHERE event_type = 'inbound_messages_purged'"
            )->fetchColumn()
        );
    }

    public function testTheTaskPutsItselfBackOnTheScheduleEvenWhenItPurgedNothing(): void
    {
        // Unconditional, and not through bootstrap(): the runner marks this
        // task done only after handle() returns, so a guard would find it
        // still pending, skip, and end the chain after one run.
        $this->runHandler();

        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM scheduled_actions
                  WHERE task_key = '" . PurgeUnlinkedMessagesHandler::TASK_KEY . "'"
            )->fetchColumn()
        );
    }

    public function testAUnitThatConfiguredCampsRetentionKeepsItRatherThanBeingCutTo90(): void
    {
        // A unit that asked for six months of unsorted camp mail expects to
        // find six months of it; shortening that in silence would be the
        // module deleting data nobody asked it to delete (A8).
        $this->declareSetting('camps', PurgeUnlinkedMessagesHandler::CAMPS_LEGACY_SETTING, '6');

        $this->assertSame(
            PurgeUnlinkedMessagesHandler::CAMPS_LEGACY_RETENTION_DAYS,
            $this->purge->retentionDays(new SettingService(new SettingRepository($this->pdo)))
        );
    }

    public function testAFreshInstallationStartsAtTheDefault(): void
    {
        $this->assertSame(
            PurgeUnlinkedMessagesHandler::DEFAULT_RETENTION_DAYS,
            $this->purge->retentionDays(new SettingService(new SettingRepository($this->pdo)))
        );
    }

    private function storeRealTimeMessage(string $messageId, string $age): int
    {
        static $uid = 900;

        return $this->messages->create(
            mailboxId: 1,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: null,
            subject: 'Sujet',
            fromEmail: 'jeanne@example.be',
            fromName: null,
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: (new \DateTimeImmutable())->modify($age)
        );
    }

    private function runHandler(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->purge->handle([], new \Core\Scheduler\TaskContext(
            \Core\Database\Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(\Core\Mail\MailService::class),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new \Core\Security\UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        ));
    }

    /**
     * Written straight into `settings`: SettingService::set() refuses a key
     * no module registration has declared, and these cases are about the
     * retention rather than about the settings machinery.
     */
    private function declareSetting(string $moduleId, string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$moduleId, $key, $value, '', 'text', 'Réglage', '']);
    }

    private function storeMessage(string $messageId, string $age = '-1 day'): int
    {
        static $uid = 100;

        return $this->messages->create(
            mailboxId: 1,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: null,
            subject: 'Sujet',
            fromEmail: 'jeanne@example.be',
            fromName: null,
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: $this->now->modify($age)
        );
    }

    private function storeStoredBytes(int $bytes): void
    {
        $id = $this->storeMessage('bytes-' . $bytes . '@example.be');
        $this->messages->addAttachment($id, $bytes, 'a.pdf', 'application/pdf', $bytes, 'h' . $bytes);
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $rows module, key, value
     */
    private function settingsWith(array $rows): SettingService
    {
        foreach ($rows as [$module, $key, $value]) {
            $this->pdo->prepare('DELETE FROM settings WHERE module_id = ? AND setting_key = ?')
                ->execute([$module, $key]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value, module_id, setting_type, label, description)
                 VALUES (?, ?, ?, \'text\', \'x\', \'x\')'
            );
            $stmt->execute([$key, $value, $module]);
        }

        return new SettingService(new SettingRepository($this->pdo));
    }

    private function quotaWith(string $megabytes, ?\Closure $alert = null): StorageQuotaService
    {
        $settings = $this->settingsWith([
            ['inbound_mail', StorageQuotaService::SETTING_QUOTA_MB, $megabytes],
            ['inbound_mail', StorageQuotaService::SETTING_LAST_ALERT, ''],
        ]);

        return new StorageQuotaService(
            $this->messages,
            $settings,
            new SettingRepository($this->pdo),
            null,
            $alert
        );
    }
}
