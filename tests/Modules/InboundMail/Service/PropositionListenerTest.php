<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Security\EncryptionService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\LinkedMessageNotifier;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeListeningConsumer;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * A consumer is told about its propositions once each, and only when
 * they were actually written (`Api\PropositionListener`).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PropositionListenerTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private MessageConsumerRegistry $registry;
    private FakeListeningConsumer $rental;
    private FakeMessageConsumer $camps;
    private int $messageId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $mailboxId = $mailboxes->create('Unité', ProviderType::FAKE, 'imap.test', 993, 'ssl', 'unite@unite.be', 'mdp', ['INBOX'], true);

        $this->rental = new FakeListeningConsumer('rental');
        $this->camps = new FakeMessageConsumer('camps');
        $this->registry = new MessageConsumerRegistry();
        $this->registry->register($this->rental);
        $this->registry->register($this->camps);

        $this->messageId = $this->messages->create(
            $mailboxId, 'INBOX', 1, 10, 'a@x', null, 'Bonjour', 'jeanne@example.be', null, 'Corps', '',
            new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }

    private function candidate(string $reference): MessageCandidate
    {
        return new MessageCandidate($reference, 'Réservation ' . $reference, 'sender_window', 'parce que');
    }

    private function applyAndNotify(array $results): void
    {
        $applied = (new AnalysisResultApplier($this->messages))->applyAndReport($this->messageId, $results);
        (new LinkedMessageNotifier($this->messages, $this->registry))->notify($this->messageId, $applied->links, $applied->candidates);
    }

    public function testTheListeningConsumerHearsOfItsPropositionsOnce(): void
    {
        $results = ['rental' => new AnalysisResult([], [$this->candidate('LOC-1'), $this->candidate('LOC-2')])];

        $this->applyAndNotify($results);
        $this->applyAndNotify($results);

        $this->assertCount(1, $this->rental->proposed, 'a re-analysis that proposes the same thing again says nothing');
        [$message, $candidates] = $this->rental->proposed[0];
        $this->assertSame($this->messageId, $message->id);
        $this->assertSame(['LOC-1', 'LOC-2'], array_map(static fn($c) => $c->businessReference, $candidates));
    }

    public function testOnlyTheNewPropositionIsAnnouncedTheSecondTime(): void
    {
        $this->applyAndNotify(['rental' => AnalysisResult::proposing($this->candidate('LOC-1'))]);
        $this->applyAndNotify(['rental' => new AnalysisResult([], [$this->candidate('LOC-1'), $this->candidate('LOC-2')])]);

        $this->assertCount(2, $this->rental->proposed);
        $this->assertSame(['LOC-2'], array_map(static fn($c) => $c->businessReference, $this->rental->proposed[1][1]));
    }

    public function testAConsumerThatDoesNotListenIsSimplyNotTold(): void
    {
        $this->applyAndNotify(['camps' => AnalysisResult::proposing($this->candidate('camp-1'))]);

        $this->assertSame([], $this->rental->proposed);
        $this->assertSame(1, $this->messages->countMessagesWithActiveCandidatesFor('camps'));
    }

    public function testEachConsumerHearsOnlyItsOwn(): void
    {
        $this->applyAndNotify([
            'rental' => AnalysisResult::proposing($this->candidate('LOC-1')),
            'camps' => AnalysisResult::proposing($this->candidate('camp-1')),
        ]);

        $this->assertCount(1, $this->rental->proposed);
        $this->assertSame(['LOC-1'], array_map(static fn($c) => $c->businessReference, $this->rental->proposed[0][1]));
    }

    public function testAListenerThatThrowsDoesNotUndoThePropositions(): void
    {
        $this->rental->throwsOnProposed = true;

        $this->applyAndNotify(['rental' => AnalysisResult::proposing($this->candidate('LOC-1'))]);

        $this->assertCount(1, $this->messages->findActiveCandidates($this->messageId));
    }

    public function testADismissedPropositionIsNeitherRewrittenNorAnnounced(): void
    {
        $this->applyAndNotify(['rental' => AnalysisResult::proposing($this->candidate('LOC-1'))]);
        $this->messages->dismissCandidate($this->messageId, 'rental', 'LOC-1', 0, new \DateTimeImmutable());

        $this->applyAndNotify(['rental' => AnalysisResult::proposing($this->candidate('LOC-1'))]);

        $this->assertCount(1, $this->rental->proposed);
        $this->assertSame(0, $this->messages->countMessagesWithActiveCandidatesFor('rental'));
    }

    public function testAnAssociationOnTheSameObjectSettlesTheCount(): void
    {
        $this->applyAndNotify(['rental' => AnalysisResult::proposing($this->candidate('LOC-1'))]);
        $this->assertSame(1, $this->messages->countMessagesWithActiveCandidatesFor('rental'));

        $this->messages->addLink($this->messageId, 'rental', 'LOC-1', LinkOrigin::MANUAL);

        $this->assertSame(0, $this->messages->countMessagesWithActiveCandidatesFor('rental'));
    }
}
