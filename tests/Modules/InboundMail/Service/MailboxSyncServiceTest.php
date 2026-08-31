<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\AttachmentOmission;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\MessageContentSanitizer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The whole synchronisation path, end to end, with no network (§7.5, §7.6).
 *
 * `FakeMailboxClient` is fed real raw RFC 5322 messages and runs them
 * through the same parser the IMAP path uses, so these tests exercise
 * production code rather than a second implementation written for tests.
 *
 * The rule this file exists to pin is the one that is easiest to break by
 * accident: **a message nobody claims is never written down**, and the
 * cursor moves past it anyway. Getting the first half wrong builds an
 * archive of somebody's mailbox; getting the second half wrong makes one
 * unrecognised newsletter block a mailbox forever.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailboxSyncServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InboundMailboxRepository $mailboxRepository;
    private InboundMessageRepository $messageRepository;
    private MessageConsumerRegistry $registry;
    private FakeMailboxClient $client;
    private MailboxSyncService $service;
    private int $mailboxId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->mailboxRepository = new InboundMailboxRepository($this->pdo, $this->encryption);
        $this->messageRepository = new InboundMessageRepository($this->pdo, $this->encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->client = new FakeMailboxClient();

        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::FAKE, $this->client);

        $this->storagePath = sys_get_temp_dir() . '/inbound-mail-test-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);

        $this->service = new MailboxSyncService(
            $this->mailboxRepository,
            $this->messageRepository,
            $this->registry,
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $factory,
            new AnalysisResultApplier($this->messageRepository),
            new UploadHandler(new FileRepository($this->pdo), $this->storagePath)
        );

        $this->mailboxId = $this->mailboxRepository->create(
            'Locations',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'locations@unite.be',
            'un-mot-de-passe',
            ['INBOX'],
            true
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->storagePath . '/*') ?: [] as $directory) {
                @rmdir($directory);
            }
            @rmdir($this->storagePath);
        }
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function addMessage(
        int $uid,
        string $subject = 'Demande de location',
        string $from = 'jeanne@example.be',
        string $messageId = 'msg-1@example.be',
        string $folder = 'INBOX',
        string $body = 'Bonjour, est-ce libre ?'
    ): void {
        $this->client->addRawMessage($folder, $uid, InboundMailTestHelper::rawMessage([
            'From' => 'Jeanne Martin <' . $from . '>',
            'To' => 'locations@unite.be',
            'Subject' => $subject,
            'Message-ID' => '<' . $messageId . '>',
            'Date' => 'Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], $body));
    }

    /**
     * A consumer that answers whatever the closure says, and records every
     * candidate it was offered.
     *
     * @param \Closure(CandidateMessage): AnalysisResult $decide
     */
    private function consumer(
        \Closure $decide,
        string $id = 'rental'
    ): FakeMessageConsumer {
        return new FakeMessageConsumer(id: $id, onAnalyze: $decide);
    }

    private function claimEverything(string $reference = 'LOC-2027-0042'): FakeMessageConsumer
    {
        return $this->consumer(
            static fn(CandidateMessage $m): AnalysisResult => AnalysisResult::linkedTo(
                'rental',
                $reference,
                LinkOrigin::REFERENCE
            )
        );
    }

    private function sync(): \Modules\InboundMail\Service\SyncOutcome
    {
        $mailbox = $this->mailboxRepository->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        return $this->service->syncMailbox($mailbox, new \DateTimeImmutable('2027-07-12 10:00:00'));
    }

    private function countMessages(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn();
    }

    // ── Nothing unclaimed is ever stored (§7.6) ──────────────────────────

    public function testAMessageNobodyRecognisesIsStoredAnyway(): void
    {
        // The reversal. This module used to discard what no consumer
        // claimed, reasoning that an archive nobody can consult is the
        // worst position under the RGPD. Right about the archive, wrong
        // about the conclusion: the answer is a screen, a retention and
        // somebody responsible — not throwing the unit's mail away.
        $this->registry->register($this->consumer(static fn(): AnalysisResult => AnalysisResult::nothing()));
        $this->addMessage(10);

        $outcome = $this->sync();

        $this->assertSame(1, $this->countMessages());
        $this->assertSame(1, $outcome->messagesSeen);
        $this->assertSame(1, $outcome->messagesStored);

        // Stored and belonging to nobody — which is exactly what the
        // retention purge is for, and what /courrier exists to show.
        $messageId = $this->messageRepository->findIdByMessageId($this->mailboxId, 'msg-1@example.be');
        $this->assertNotNull($messageId);
        $this->assertSame(0, $this->messageRepository->countLinks($messageId));
        $this->assertFalse($this->messageRepository->hasActiveCandidates($messageId));
    }

    public function testTheCursorStillMovesPastAMessageNobodyClaimed(): void
    {
        // Otherwise one unrecognised newsletter at the top of the box is
        // fetched, parsed and discarded on every run, forever, and nothing
        // behind it is ever read.
        $this->registry->register($this->consumer(static fn(): AnalysisResult => AnalysisResult::nothing()));
        $this->addMessage(10);

        $this->sync();

        $cursor = $this->mailboxRepository->findCursor($this->mailboxId, 'INBOX');
        $this->assertSame(10, $cursor->lastUid);
    }

    public function testNothingIsStoredWhenNoConsumerIsRegisteredAtAll(): void
    {
        $this->addMessage(10);

        $outcome = $this->sync();

        $this->assertSame(0, $this->countMessages());
        $this->assertFalse($outcome->connected, 'There is no point connecting when nothing could claim anything.');
    }

    public function testAClaimedMessageIsStoredWithItsOrigin(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessage(10);

        $this->sync();

        $messages = $this->messageRepository->findForReference('rental', 'LOC-2027-0042');
        $this->assertCount(1, $messages);
        $this->assertSame('Demande de location', $messages[0]->subject);
        $this->assertSame('jeanne@example.be', $messages[0]->fromEmail);
        $this->assertSame('Jeanne Martin', $messages[0]->fromName);
        $this->assertSame(LinkOrigin::REFERENCE, $messages[0]->linkOrigin);
    }

    public function testTheConsumerSeesASanitisedBodyBeforeItDecides(): void
    {
        // Looking for a reference in a body must not be where raw
        // attacker-supplied HTML is first handled (§7.9).
        $consumer = $this->consumer(static fn(): AnalysisResult => AnalysisResult::nothing());
        $this->registry->register($consumer);

        $this->client->addRawMessage('INBOX', 10, InboundMailTestHelper::rawMessage([
            'From' => 'jeanne@example.be',
            'Message-ID' => '<a@b>',
            'Content-Type' => 'text/html; charset=UTF-8',
        ], '<p>Bonjour</p><script>alert(1)</script>'));

        $this->sync();

        $this->assertCount(1, $consumer->offered);
        $this->assertStringNotContainsString('<script', $consumer->offered[0]->bodyHtml);
    }

    // ── Deduplication and UIDVALIDITY (§7.5) ─────────────────────────────

    public function testTheSameMessageIsNotStoredTwiceAcrossTwoRuns(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessage(10);

        $this->sync();
        // The cursor alone would already prevent this; the point is that
        // the message-level guard holds independently of it.
        $this->mailboxRepository->saveCursor(
            $this->mailboxRepository->findCursor($this->mailboxId, 'INBOX')->forUidValidity(999)
        );
        $this->sync();

        $this->assertSame(1, $this->countMessages());
    }

    public function testARenumberedFolderIsRereadWithoutDuplicating(): void
    {
        // A restore or a migration changes UIDVALIDITY. A cursor that
        // remembered only "last UID 10" would skip everything below the new
        // 10 and lose weeks of mail; re-reading is safe because
        // deduplication is on Message-ID.
        $this->registry->register($this->claimEverything());
        $this->addMessage(10, messageId: 'msg-1@example.be');
        $this->sync();

        $this->client->setUidValidity('INBOX', 77);
        $this->addMessage(3, messageId: 'msg-2@example.be');

        $outcome = $this->sync();

        $this->assertSame(2, $this->countMessages(), 'The message below the old cursor must be picked up.');
        $this->assertSame(2, $outcome->messagesSeen);
        $this->assertSame(1, $outcome->messagesStored, 'The one already held must not be stored again.');
    }

    public function testTheCursorRecordsTheNewUidValidityAfterAReset(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessage(10);
        $this->sync();

        $this->client->setUidValidity('INBOX', 77);
        $this->sync();

        $cursor = $this->mailboxRepository->findCursor($this->mailboxId, 'INBOX');
        $this->assertSame(77, $cursor->uidValidity);
    }

    public function testAMessageRecognisedTwiceGainsASecondAssociationRatherThanASecondCopy(): void
    {
        // Deduplication is per MAILBOX now: one Message-ID is one stored
        // message, however many objects end up associated with it. Both
        // bookings still find it from their own side — which is what the
        // second copy used to buy, at the price of storing the body twice.
        $this->registry->register($this->consumer(
            static fn(CandidateMessage $m): AnalysisResult => AnalysisResult::linkedTo(
                'rental',
                str_contains($m->subject, 'A') ? 'LOC-A' : 'LOC-B',
                LinkOrigin::REFERENCE
            )
        ));

        $this->addMessage(10, subject: 'Dossier A', messageId: 'shared@example.be');
        $this->sync();
        $this->addMessage(11, subject: 'Dossier B', messageId: 'shared@example.be');
        $this->sync();

        $fromA = $this->messageRepository->findForReference('rental', 'LOC-A');
        $fromB = $this->messageRepository->findForReference('rental', 'LOC-B');

        $this->assertCount(1, $fromA);
        $this->assertCount(1, $fromB);
        $this->assertSame($fromA[0]->id, $fromB[0]->id, 'One message, two associations.');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn());
    }

    public function testTwoConsumersRecognisingOneMessageBothGetIt(): void
    {
        // The whole point of dropping first-claim-wins: under the old rule
        // the second consumer was never even asked.
        $this->registry->register($this->consumer(
            static fn(CandidateMessage $m): AnalysisResult
                => AnalysisResult::linkedTo('rental', 'LOC-A', LinkOrigin::REFERENCE),
            'rental'
        ));
        $this->registry->register($this->consumer(
            static fn(CandidateMessage $m): AnalysisResult
                => AnalysisResult::linkedTo('finance', 'ACC-7', LinkOrigin::SENDER),
            'finance'
        ));

        $this->addMessage(10, messageId: 'shared@example.be');
        $this->sync();

        $this->assertCount(1, $this->messageRepository->findForReference('rental', 'LOC-A'));
        $this->assertCount(1, $this->messageRepository->findForReference('finance', 'ACC-7'));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn());
    }

    public function testAConsumerThatThrowsDoesNotCostTheOthersTheirMail(): void
    {
        $this->registry->register(new FakeMessageConsumer(
            id: 'broken',
            onAnalyze: static function (CandidateMessage $m): AnalysisResult {
                throw new \RuntimeException('bug');
            }
        ));
        $this->registry->register($this->claimEverything());

        $this->addMessage(10, messageId: 'msg@example.be');
        $this->sync();

        $this->assertCount(1, $this->messageRepository->findForReference('rental', 'LOC-2027-0042'));
    }

    public function testAPropositionIsRecordedWithoutCreatingAnAssociation(): void
    {
        $this->registry->register($this->consumer(
            static fn(CandidateMessage $m): AnalysisResult => AnalysisResult::proposing(
                new MessageCandidate(
                    'LOC-2027-0099',
                    'Location du 12 juillet',
                    'sender_window',
                    'L\'expéditeur est le locataire, et le message est arrivé pendant la fenêtre.'
                )
            )
        ));

        $this->addMessage(10, messageId: 'msg@example.be');
        $this->sync();

        // Stored, because somebody made something of it — but nobody
        // asserted it belongs anywhere.
        $messageId = $this->messageRepository->findIdByMessageId($this->mailboxId, 'msg@example.be');
        $this->assertNotNull($messageId);
        $this->assertSame(0, $this->messageRepository->countLinks($messageId));
        $this->assertTrue($this->messageRepository->hasActiveCandidates($messageId));
        $this->assertSame([], $this->messageRepository->findForReference('rental', 'LOC-2027-0099'));
    }

    public function testAPropositionSomebodySetAsideIsNotReemittedOnTheNextRun(): void
    {
        $this->registry->register($this->consumer(
            static fn(CandidateMessage $m): AnalysisResult => AnalysisResult::proposing(
                new MessageCandidate('LOC-1', 'Une location', 'sender_window', 'Parce que.')
            )
        ));

        $this->addMessage(10, messageId: 'msg@example.be');
        $this->sync();

        $messageId = $this->messageRepository->findIdByMessageId($this->mailboxId, 'msg@example.be');
        $this->assertNotNull($messageId);
        $this->messageRepository->dismissCandidate($messageId, 'rental', 'LOC-1', 0, new \DateTimeImmutable());

        // A re-read after a renumbering offers the same message again, and
        // the consumer proposes the same thing again. Setting it aside was
        // a human decision; a technical job must not undo it (A3/D10).
        $this->mailboxRepository->saveCursor(
            $this->mailboxRepository->findCursor($this->mailboxId, 'INBOX')->forUidValidity(99)
        );
        $this->sync();

        $this->assertFalse($this->messageRepository->hasActiveCandidates($messageId));
    }

    // ── Batching and folders ────────────────────────────────────────────

    public function testOneRunIsBoundedAndTheNextPicksUpWhereItStopped(): void
    {
        $this->registry->register($this->claimEverything());
        for ($uid = 1; $uid <= MailboxSyncService::BATCH_SIZE + 10; $uid++) {
            $this->addMessage($uid, messageId: 'msg-' . $uid . '@example.be');
        }

        $first = $this->sync();
        $this->assertSame(MailboxSyncService::BATCH_SIZE, $first->messagesSeen);

        $second = $this->sync();
        $this->assertSame(10, $second->messagesSeen);
        $this->assertSame(MailboxSyncService::BATCH_SIZE + 10, $this->countMessages());
    }

    public function testEveryWatchedFolderIsPolled(): void
    {
        $this->mailboxRepository->update(
            $this->mailboxId,
            'Locations',
            'imap.test',
            993,
            'ssl',
            'locations@unite.be',
            ['INBOX', 'INBOX/Locations'],
            true
        );

        $this->registry->register($this->claimEverything());
        $this->addMessage(1, messageId: 'a@example.be', folder: 'INBOX');
        $this->addMessage(1, messageId: 'b@example.be', folder: 'INBOX/Locations');

        $this->sync();

        $this->assertSame(2, $this->countMessages());
    }

    public function testAFolderIsExaminedBeforeItIsRead(): void
    {
        // EXAMINE is the read-only open. Reading a folder without first
        // asking for its UIDVALIDITY would make the cursor meaningless.
        $this->registry->register($this->claimEverything());
        $this->addMessage(10);

        $this->sync();

        $examineIndex = array_search('folderState:INBOX', $this->client->calls, true);
        $fetchIndex = array_search('fetchSince:INBOX:0', $this->client->calls, true);
        $this->assertIsInt($examineIndex);
        $this->assertIsInt($fetchIndex);
        $this->assertLessThan($fetchIndex, $examineIndex);
    }

    public function testTheConnectionIsAlwaysClosed(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessage(10);

        $this->sync();

        $this->assertFalse($this->client->isConnected());
        $this->assertContains('disconnect', $this->client->calls);
    }

    // ── Failures (§7.9) ─────────────────────────────────────────────────

    public function testATlsFailureIsRecordedInPlainLanguageAndNeverSwallowed(): void
    {
        $this->registry->register($this->claimEverything());
        $this->client->failNextConnect(new \RuntimeException('SSL certificate verify failed'));

        $outcome = $this->sync();

        $this->assertTrue($outcome->isFailure());
        $mailbox = $this->mailboxRepository->findById($this->mailboxId);
        $this->assertNotNull($mailbox);
        $this->assertSame(\Modules\InboundMail\Mailbox\SyncState::ERROR, $mailbox->syncState);
        $this->assertNotNull($mailbox->lastError);
        $this->assertStringContainsString('certificat', $mailbox->lastError);
    }

    public function testARecordedFailureNeverCarriesTheCredential(): void
    {
        $this->registry->register($this->claimEverything());
        $this->client->failNextConnect(
            new \RuntimeException('LOGIN failed for locations@unite.be with password un-mot-de-passe')
        );

        $this->sync();

        $mailbox = $this->mailboxRepository->findById($this->mailboxId);
        $this->assertNotNull($mailbox);
        $this->assertNotNull($mailbox->lastError);
        $this->assertStringNotContainsString('un-mot-de-passe', $mailbox->lastError);
    }

    public function testASuccessfulRunClearsAPreviousError(): void
    {
        $this->registry->register($this->claimEverything());
        $this->client->failNextConnect(new \RuntimeException('timeout'));
        $this->sync();

        $this->addMessage(10);
        $this->sync();

        $mailbox = $this->mailboxRepository->findById($this->mailboxId);
        $this->assertNotNull($mailbox);
        $this->assertSame(\Modules\InboundMail\Mailbox\SyncState::OK, $mailbox->syncState);
        $this->assertNull($mailbox->lastError);
        $this->assertNotNull($mailbox->lastSyncedAt);
    }

    public function testAConsumerThatThrowsDoesNotStopTheRun(): void
    {
        $this->registry->register($this->consumer(static function (): ?MessageClaim {
            throw new \RuntimeException('bug dans le module consommateur');
        }));
        $this->registry->register($this->claimEverything());
        $this->addMessage(10);

        $outcome = $this->sync();

        $this->assertFalse($outcome->isFailure());
        $this->assertSame(1, $this->countMessages());
    }

    public function testADisabledMailboxIsNeverPolled(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessage(10);
        $this->mailboxRepository->setEnabled($this->mailboxId, false);

        $report = $this->service->syncAll(new \DateTimeImmutable('2027-07-12 10:00:00'));

        $this->assertSame([], $report->all());
        $this->assertSame(0, $this->countMessages());
    }

    public function testOneFailingMailboxDoesNotCostTheOthersTheirMail(): void
    {
        $this->registry->register($this->claimEverything());

        $second = $this->mailboxRepository->create(
            'Secrétariat',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'secretariat@unite.be',
            'mdp',
            ['INBOX'],
            true
        );

        $this->addMessage(10);
        $this->client->failNextConnect(new \RuntimeException('timeout'));

        $report = $this->service->syncAll(new \DateTimeImmutable('2027-07-12 10:00:00'));

        $this->assertNotNull($report->forMailbox($this->mailboxId));
        $this->assertNotNull($report->forMailbox($second));
        $this->assertSame(1, $report->failureCount());
        $this->assertSame(1, $report->totalStored(), 'The healthy box must still have collected.');
    }

    // ── Attachments (§7.8) ──────────────────────────────────────────────

    private function addMessageWithAttachment(int $uid, string $messageId, string $bytes, string $filename): void
    {
        $this->client->addRawMessage('INBOX', $uid, implode("\r\n", [
            'From: Jeanne Martin <jeanne@example.be>',
            'Subject: Document',
            'Message-ID: <' . $messageId . '>',
            'Date: Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/plain',
            '',
            'Voici le document.',
            '--frontier',
            'Content-Type: application/octet-stream',
            'Content-Disposition: attachment; filename="' . $filename . '"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode($bytes),
            '--frontier--',
        ]));
    }

    private static function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    }

    public function testAnAcceptedAttachmentBecomesAStoredFile(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessageWithAttachment(10, 'a@b', self::pdfBytes(), 'contrat.pdf');

        $this->sync();

        $messages = $this->messageRepository->findForReference('rental', 'LOC-2027-0042');
        $this->assertTrue($messages[0]->hasAttachments());
        $this->assertSame('contrat.pdf', $messages[0]->attachments[0]->filename);
        $this->assertSame('application/pdf', $messages[0]->attachments[0]->mimeType);
    }

    public function testTheStoredFileIsNotNamedAfterWhatTheSenderCalledIt(): void
    {
        // The name is a label; the file on disk gets a generated one, which
        // is what stops a crafted filename from deciding anything.
        $this->registry->register($this->claimEverything());
        $this->addMessageWithAttachment(10, 'a@b', self::pdfBytes(), '../../evil.pdf');

        $this->sync();

        $path = (string) $this->pdo->query('SELECT relative_path FROM files LIMIT 1')->fetchColumn();
        $this->assertStringStartsWith('inbound_mail/attachments/', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('evil', $path);
    }

    public function testAStoredAttachmentIsNeverPublic(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessageWithAttachment(10, 'a@b', self::pdfBytes(), 'contrat.pdf');

        $this->sync();

        $roleMin = (string) $this->pdo->query('SELECT role_min FROM files LIMIT 1')->fetchColumn();
        $this->assertSame('intendant', $roleMin);
    }

    public function testARefusedAttachmentDoesNotCostTheMessageAroundIt(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessageWithAttachment(10, 'a@b', "PK\x03\x04" . str_repeat("\x00", 60), 'archive.zip');

        $this->sync();

        $messages = $this->messageRepository->findForReference('rental', 'LOC-2027-0042');
        $this->assertCount(1, $messages, 'The message itself must still be stored.');
        $this->assertFalse($messages[0]->hasAttachments(), 'Nothing openable was kept.');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());

        // But the reader is TOLD, rather than shown one attachment fewer
        // than the sender sent and left to wonder who dropped it.
        $this->assertTrue($messages[0]->hasOmittedAttachments());
        $omitted = $messages[0]->omittedAttachments[0];
        $this->assertSame('archive.zip', $omitted->filename);
        $this->assertSame(AttachmentOmission::MIME_REJECTED, $omitted->reason);
        $this->assertStringContainsString('boîte d\'origine', $omitted->explanation());
    }

    public function testTheSameFileArrivingTwiceIsStoredOnce(): void
    {
        $this->registry->register($this->claimEverything());
        $this->addMessageWithAttachment(10, 'a@b', self::pdfBytes(), 'contrat.pdf');
        $this->addMessageWithAttachment(11, 'c@d', self::pdfBytes(), 'contrat.pdf');

        $this->sync();

        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_message_attachments')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }
}
