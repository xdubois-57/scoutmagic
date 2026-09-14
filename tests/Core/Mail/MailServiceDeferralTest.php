<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneExhaustedException;
use Core\Mail\Transport\MailLane;
use Core\Security\EncryptionService;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What `MailService` does when a whole lane has run out (D9, D18).
 *
 * This is the seam the deferral hangs on, and the one place where « le
 * message est perdu » and « le message attendra » are told apart. The
 * distinction is not a detail of the queue: it is the difference between
 * an expéditeur who is told the truth and one who is reassured about a
 * message that will never leave.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailServiceDeferralTest extends TestCase
{
    private \PDO $pdo;
    private string $tempDir;
    private DeferredMailRepository $repository;
    private DeferredMailQueue $queue;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->tempDir = sys_get_temp_dir() . '/mail_deferral_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);

        $this->repository = new DeferredMailRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register(
            DeferredMailQueue::SETTING_LIFETIME_HOURS,
            (string) DeferredMailQueue::DEFAULT_LIFETIME_HOURS,
            'integer',
            'Durée de vie',
            'Test'
        );
        $this->queue = new DeferredMailQueue($this->repository, $settings);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    /**
     * The lane ran out and the message waits. **`send()` returns rather
     * than throwing**, which is the whole bargain: the caller has been
     * told their message is on its way, and the queue now owes them that.
     */
    public function testALaneThatRanOutQueuesTheMessageInsteadOfFailing(): void
    {
        $service = $this->serviceDeferring(MailLane::Transactional);

        $service->send(
            to: 'parent@exemple.test',
            subject: 'Reçu de paiement',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertSame(['transactional' => 1], $this->repository->pendingCountByLane());
    }

    /**
     * **The authentication lane fails, loudly and now** (D9). A magic
     * link delivered tomorrow is not a magic link, and the person is at a
     * login form waiting to be told the truth.
     */
    public function testTheAuthenticationLaneFailsRatherThanWaiting(): void
    {
        $service = $this->serviceDeferring(MailLane::Authentication);

        $this->expectException(MailException::class);

        try {
            $service->send(
                to: 'chef@exemple.test',
                subject: 'Votre lien de connexion',
                bodyHtml: '<p>Lien</p>',
                bodyText: 'Lien',
                purpose: MailPurpose::MagicLink
            );
        } finally {
            $this->assertSame([], $this->repository->pendingCountByLane());
        }
    }

    /**
     * What is queued is the CALL, and the attachment travels as bytes:
     * the file behind that path is a temporary the request is about to
     * delete, and a retry tomorrow would find nothing there.
     */
    public function testTheAttachmentIsCarriedAsBytesRatherThanAsAPath(): void
    {
        $attachment = $this->tempDir . '/recu.pdf';
        file_put_contents($attachment, "%PDF-1.4\x00binaire");

        $this->serviceDeferring(MailLane::Bulk)->send(
            to: 'parent@exemple.test',
            subject: 'Reçu',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            attachments: [['path' => $attachment, 'name' => 'recu.pdf']],
            purpose: MailPurpose::Bulk
        );

        unlink($attachment);

        $queued = $this->repository->due(1, '2099-01-01 00:00:00')[0];
        $this->assertSame('recu.pdf', $queued->payload['attachments'][0]['name']);
        $this->assertSame("%PDF-1.4\x00binaire", $queued->payload['attachments'][0]['content']);
    }

    /**
     * A deferral is written down, and the note names no address
     * (SECURITY.md §11): the journal says which lane ran out, never who
     * was waiting on it.
     */
    public function testTheDeferralIsJournaledWithoutNamingAnybody(): void
    {
        $this->serviceDeferring(MailLane::Transactional, journal: true)->send(
            to: 'parent@exemple.test',
            subject: 'Reçu de paiement',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $statement = $this->pdo->prepare(
            'SELECT context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['mail_deferred']);
        $context = (string) $statement->fetchColumn();

        $this->assertStringContainsString('transactional', $context);
        $this->assertStringNotContainsString('parent@exemple.test', $context);
    }

    /**
     * A message the queue refuses — too heavy to carry — fails as it
     * would have before. Refusing loudly beats queueing something that
     * would drain tomorrow without its attachment.
     */
    public function testAMessageTheQueueRefusesStillFails(): void
    {
        $attachment = $this->tempDir . '/camp.zip';
        file_put_contents($attachment, str_repeat('x', DeferredMailQueue::MAX_ATTACHMENT_BYTES + 1));

        $this->expectException(MailException::class);

        $this->serviceDeferring(MailLane::Bulk)->send(
            to: 'parent@exemple.test',
            subject: 'Photos du camp',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            attachments: [['path' => $attachment, 'name' => 'camp.zip']],
            purpose: MailPurpose::Bulk
        );
    }

    /**
     * An installation with no queue at all — the setup wizard — fails
     * exactly as it did before the queue existed.
     */
    public function testWithNoQueueTheFailureIsUnchanged(): void
    {
        $this->expectException(MailException::class);

        $this->service($this->exhaustedTransport(MailLane::Transactional), null)->send(
            to: 'parent@exemple.test',
            subject: 'Reçu',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );
    }

    private function serviceDeferring(MailLane $lane, bool $journal = false): MailService
    {
        return $this->service($this->exhaustedTransport($lane), $this->queue, $journal);
    }

    private function service(
        MailTransportInterface $transport,
        ?DeferredMailQueue $queue,
        bool $journal = false
    ): MailService {
        return new MailService(
            mode: 'local',
            fromAddress: 'noreply@exemple.test',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            transport: $transport,
            journal: $journal
                ? new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
                : null,
            deferred: $queue
        );
    }

    /** A transport standing in for a chain whose lane has nothing left. */
    private function exhaustedTransport(MailLane $lane): MailTransportInterface
    {
        return new class ($lane) implements MailTransportInterface {
            public function __construct(private MailLane $lane)
            {
            }

            public function deliver(PHPMailer $mail, MailPurpose $purpose = MailPurpose::Ordinary): void
            {
                throw new LaneExhaustedException($this->lane, 'aucun fournisseur disponible');
            }
        };
    }
}
