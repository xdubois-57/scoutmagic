<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The consumer contract's v2 half: propositions, and the two ends of an
 * association's life.
 *
 * `onUnlinked()` is the one that fixed a real bug — reassigning a message
 * in `rental` left the documents it had created hanging off the old
 * booking, invisible to whoever manages the new one and unexplainable to
 * whoever manages the old one.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ConsumerLifecycleTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private MessageConsumerRegistry $consumers;
    private InboundMailService $service;
    private AnalysisResultApplier $applier;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $this->consumers = new MessageConsumerRegistry();
        $this->applier = new AnalysisResultApplier($this->messages);
        $this->service = new InboundMailService(
            $this->messages,
            $mailboxes,
            new FileRepository($this->pdo),
            $this->consumers
        );

        $this->mailboxId = $mailboxes->create(
            'Unité',
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            ['INBOX'],
            true
        );
    }

    // ── The two ends of an association's life ───────────────────────────

    public function testRemovingAnAssociationTellsTheConsumerSoItCanTakeBackWhatItFiled(): void
    {
        $consumer = new FakeMessageConsumer(id: 'rental');
        $this->consumers->register($consumer);

        $id = $this->storeMessage('a@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $this->assertTrue($this->service->detach('rental', 'LOC-1', $id));

        $this->assertCount(1, $consumer->unlinked);
        $this->assertSame('LOC-1', $consumer->unlinked[0][1]->businessReference);
        $this->assertSame($id, $consumer->unlinked[0][0]->id);
    }

    public function testMovingAMessageUnlinksTheOldObjectBeforeLinkingTheNew(): void
    {
        // The order matters: a consumer has to take back what it filed on
        // the old object before it files anything on the new one.
        $consumer = new FakeMessageConsumer(id: 'rental');
        $this->consumers->register($consumer);

        $id = $this->storeMessage('a@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $this->assertTrue($this->service->move('rental', 'LOC-1', 'LOC-2', $id));

        $this->assertCount(1, $consumer->unlinked);
        $this->assertSame('LOC-1', $consumer->unlinked[0][1]->businessReference);
        $this->assertCount(1, $consumer->linked);
        $this->assertSame('LOC-2', $consumer->linked[0][1]->businessReference);
    }

    public function testAConsumerThatThrowsOnLinkedDoesNotFailTheAssociation(): void
    {
        $this->consumers->register(new FakeMessageConsumer(id: 'rental', throwsOnLinked: true));

        $id = $this->storeMessage('a@example.be');

        $this->assertTrue($this->service->link('rental', 'LOC-1', $id, 42));
        $this->assertTrue($this->messages->hasLink($id, 'rental', 'LOC-1'));
    }

    // ── Manual association (D20) ────────────────────────────────────────

    public function testAManualAssociationNamesItsAuthorAndSaysItWasManual(): void
    {
        $id = $this->storeMessage('a@example.be');

        $this->assertTrue($this->service->link('rental', 'LOC-1', $id, 42));

        $links = $this->messages->findLinksForMessage($id);
        $this->assertCount(1, $links);
        $this->assertSame(LinkOrigin::MANUAL, $links[0]->origin);
        $this->assertSame(42, $links[0]->createdByUserAccountId);
    }

    public function testAssociatingByHandTwiceTowardsOneTargetIsIdempotent(): void
    {
        $id = $this->storeMessage('a@example.be');

        $this->assertTrue($this->service->link('rental', 'LOC-1', $id, 42));
        // The second person clicks. One association, and no error.
        $this->assertFalse($this->service->link('rental', 'LOC-1', $id, 99));

        $this->assertSame(1, $this->messages->countLinks($id));
        $this->assertSame(42, $this->messages->findLinksForMessage($id)[0]->createdByUserAccountId);
    }

    // ── Propositions ────────────────────────────────────────────────────

    public function testAPropositionIsStoredWithItsFrenchLabelAndExplanation(): void
    {
        $id = $this->storeMessage('a@example.be');

        $this->assertTrue($this->messages->addCandidate($id, 'camps', new MessageCandidate(
            'camp-3',
            'Camp de la Meute, juillet 2027',
            'sender_window',
            'L\'expéditeur est un contact du séjour, et le message est arrivé pendant la fenêtre.'
        )));

        $candidates = $this->messages->findActiveCandidates($id);
        $this->assertCount(1, $candidates);
        $this->assertSame('Camp de la Meute, juillet 2027', $candidates[0]->label);
        $this->assertSame('sender_window', $candidates[0]->evidenceType);
        $this->assertStringContainsString('fenêtre', $candidates[0]->explanation);
    }

    public function testAPropositionsLabelIsNotReadableInTheDatabase(): void
    {
        $id = $this->storeMessage('a@example.be');
        $this->messages->addCandidate($id, 'camps', new MessageCandidate(
            'camp-3',
            'Camp chez Jeanne Martin',
            'sender_window',
            'Message de jeanne@example.be'
        ));

        $row = $this->pdo->query('SELECT * FROM inbound_message_candidates')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $blob = (string) $row['evidence_label_encrypted'] . (string) $row['evidence_explanation_encrypted'];

        $this->assertStringNotContainsString('Jeanne Martin', $blob);
        $this->assertStringNotContainsString('jeanne@example.be', $blob);
    }

    public function testAPropositionSetAsideNeverComesBack(): void
    {
        // `dismissed_at` is final (A3/D10). Setting one aside is a human
        // decision; a technical job must not undo it — not on the next
        // synchronisation, and not on a manual re-analysis.
        $id = $this->storeMessage('a@example.be');
        $candidate = new MessageCandidate('camp-3', 'Un séjour', 'sender_window', 'Parce que.');

        $this->messages->addCandidate($id, 'camps', $candidate);
        $this->assertTrue(
            $this->messages->dismissCandidate($id, 'camps', 'camp-3', 0, new \DateTimeImmutable())
        );

        $this->assertFalse($this->messages->addCandidate($id, 'camps', $candidate));
        $this->assertSame([], $this->messages->findActiveCandidates($id));
        $this->assertFalse($this->messages->hasActiveCandidates($id));
    }

    public function testAPropositionOnAnAttachmentDoesNotProposeTheWholeMessage(): void
    {
        $id = $this->storeMessage('a@example.be');

        $this->messages->addCandidate($id, 'finance', new MessageCandidate(
            'ACC-7',
            'Compte Intendance',
            'amount_and_date',
            'Le texte extrait porte un montant et une date.',
            77
        ));

        $candidates = $this->messages->findActiveCandidates($id);
        $this->assertCount(1, $candidates);
        $this->assertSame(77, $candidates[0]->attachmentId);
        $this->assertFalse($candidates[0]->isWholeMessage());
    }

    // ── Applying an analysis ────────────────────────────────────────────

    public function testAConsumerCannotAssociateOnAnotherConsumersBehalf(): void
    {
        // Whatever consumer id a returned link carries is ignored in favour
        // of the id of the consumer that returned it. Otherwise a module
        // could file a message under another module's reference, and §8.58's
        // access rules would be answering about an association its own
        // module never made.
        $id = $this->storeMessage('a@example.be');

        $this->applier->apply($id, [
            'camps' => AnalysisResult::linkedTo('rental', 'LOC-1', LinkOrigin::REFERENCE),
        ]);

        $this->assertTrue($this->messages->hasLink($id, 'camps', 'LOC-1'));
        $this->assertFalse($this->messages->hasLink($id, 'rental', 'LOC-1'));
    }

    public function testOnlyGenuinelyNewAssociationsAreReportedBack(): void
    {
        // `onLinked()` fires once per association, ever. A re-read after a
        // UIDVALIDITY reset must not make a consumer file the same
        // attachment a second time.
        $id = $this->storeMessage('a@example.be');
        $result = ['rental' => AnalysisResult::linkedTo('rental', 'LOC-1', LinkOrigin::REFERENCE)];

        $this->assertCount(1, $this->applier->apply($id, $result));
        $this->assertSame([], $this->applier->apply($id, $result));
    }

    public function testAnAttachmentLevelLinkDoesNotAlsoLinkTheWholeMessage(): void
    {
        $id = $this->storeMessage('a@example.be');

        $this->applier->apply($id, [
            'finance' => AnalysisResult::linkedTo('finance', 'ACC-7', LinkOrigin::SENDER, 77),
        ]);

        $this->assertTrue($this->messages->hasLink($id, 'finance', 'ACC-7', 77));
        $this->assertFalse($this->messages->hasLink($id, 'finance', 'ACC-7', 0));
    }

    // ── The deferred pass's marker ──────────────────────────────────────

    public function testAMessageIsOfferedToTheDeferredPassOnceAndOnlyOnce(): void
    {
        $id = $this->storeMessage('a@example.be');

        $this->assertSame([$id], $this->messages->findMessagesAwaitingStoredAnalysis(10));

        $this->messages->markStoredAnalysisDone($id, new \DateTimeImmutable());
        $this->assertSame([], $this->messages->findMessagesAwaitingStoredAnalysis(10));
    }

    public function testTheManualReanalysisOffersEveryStoredMessageAgain(): void
    {
        // The indispensable corollary of analysing only once: without it,
        // enabling Finances on tresorerie@ after three months of collecting
        // would produce exactly nothing.
        $first = $this->storeMessage('a@example.be');
        $second = $this->storeMessage('b@example.be');
        $this->messages->markStoredAnalysisDone($first, new \DateTimeImmutable());
        $this->messages->markStoredAnalysisDone($second, new \DateTimeImmutable());

        $this->assertSame(2, $this->messages->queueAllForStoredAnalysis());
        $this->assertSame([$first, $second], $this->messages->findMessagesAwaitingStoredAnalysis(10));
    }

    public function testTheDeferredPassIsBounded(): void
    {
        // poor_mans_cron runs inside a page view: a pass that tried to read
        // every PDF of a five-year-old mailbox would be killed by
        // max_execution_time and come back to the same doomed batch.
        for ($i = 0; $i < 5; $i++) {
            $this->storeMessage('msg-' . $i . '@example.be');
        }

        $this->assertCount(2, $this->messages->findMessagesAwaitingStoredAnalysis(2));
    }

    public function testTheDeferredPassSeesTheMessageWithItsAttachmentsAndItsAssociations(): void
    {
        $id = $this->storeMessage('a@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE);
        $this->messages->addAttachment($id, 1, 'facture.pdf', 'application/pdf', 2048, 'hash');

        $stored = $this->messages->findAnyForAnalysis($id);

        $this->assertNotNull($stored);
        $this->assertCount(1, $stored->attachments);
        $this->assertSame('facture.pdf', $stored->attachments[0]->filename);
        $this->assertCount(1, $stored->links);
    }

    // ── The declarations the configuration screen reads (D4) ────────────

    public function testEveryConsumerDeclaresWhatItProposesOnAndWhoWillSeeIt(): void
    {
        $consumer = new FakeMessageConsumer();

        $this->assertNotSame([], $consumer->describeEvidence());
        $this->assertNotSame('', $consumer->triageAudienceLabel());
        $this->assertGreaterThanOrEqual(0, $consumer->triageAudienceCount());
    }

    private function storeMessage(string $messageId): int
    {
        static $uid = 100;

        return $this->messages->create(
            mailboxId: $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: null,
            subject: 'Facture',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }
}
