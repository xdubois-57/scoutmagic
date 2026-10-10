<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisJournal;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * « Détacher » for good (#720): a message taken off an object is never
 * filed under that object again by an automatic path, and stays open to
 * every other one.
 *
 * Before this, a detached message was an unlinked message, and an unlinked
 * message is precisely what « Relancer l'analyse » and the deferred pass
 * look at: the same rules that filed it under the wrong booking filed it
 * there again, and the manager's correction lasted until the next pass.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DetachForGoodTest extends TestCase
{
    private InboundMessageRepository $messages;
    private MessageConsumerRegistry $consumers;
    private InboundMailService $service;
    private int $mailboxId;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($pdo, $encryption);
        $mailboxes = new InboundMailboxRepository($pdo, $encryption);
        $this->consumers = new MessageConsumerRegistry();
        $this->service = new InboundMailService(
            $this->messages,
            $mailboxes,
            new FileRepository($pdo),
            $this->consumers,
            null,
            new AnalysisJournal(new JournalService(new JournalRepository($pdo)))
        );

        $this->mailboxId = $mailboxes->create(
            'Locations',
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'locations@unite.be',
            'secret',
            ['INBOX'],
            true
        );
    }

    public function testADetachedForGoodMessageIsNotFiledBackUnderTheSameObject(): void
    {
        $messageId = $this->linkedMessage('LOC-K7Q2MX');
        $this->consumerFiling('LOC-K7Q2MX');

        $this->assertTrue($this->service->detach('rental', 'LOC-K7Q2MX', $messageId, [], true, 7));
        $report = $this->service->reanalyzeUnlinked('rental');

        $this->assertSame(0, $report['linked']);
        $this->assertSame([], $this->messages->findLinksForMessage($messageId));
    }

    public function testItStaysOpenToAnotherObjectOfTheSameModule(): void
    {
        // « Ce message ne concerne pas CETTE réservation » — it may well
        // concern the renter's other one.
        $messageId = $this->linkedMessage('LOC-K7Q2MX');
        $this->consumerFiling('LOC-K7Q2MX', 'LOC-P4W8ZA');

        $this->service->detach('rental', 'LOC-K7Q2MX', $messageId, [], true);
        $this->service->reanalyzeUnlinked('rental');

        $links = $this->messages->findLinksForMessage($messageId);
        $this->assertCount(1, $links);
        $this->assertSame('LOC-P4W8ZA', $links[0]->businessReference);
    }

    public function testAnOrdinaryDetachLeavesTheMessageOpenToTheSameObject(): void
    {
        // Camps detach without the flag, and nothing changes for them.
        $messageId = $this->linkedMessage('LOC-K7Q2MX');
        $this->consumerFiling('LOC-K7Q2MX');

        $this->service->detach('rental', 'LOC-K7Q2MX', $messageId);
        $this->service->reanalyzeUnlinked('rental');

        $this->assertCount(1, $this->messages->findLinksForMessage($messageId));
    }

    public function testTheExclusionIsTheConsumersOwn(): void
    {
        // Another module filing the same message under a reference that
        // happens to read the same is not what rentals ruled out.
        $messageId = $this->linkedMessage('LOC-K7Q2MX');
        $this->service->detach('rental', 'LOC-K7Q2MX', $messageId, [], true);

        $applied = (new AnalysisResultApplier($this->messages))->applyAndReport($messageId, [
            'camps' => new AnalysisResult([new MessageLink('camps', 'LOC-K7Q2MX', LinkOrigin::REFERENCE)]),
        ]);

        $this->assertCount(1, $applied->links);
    }

    public function testNoPropositionIsMadeTowardsTheExcludedObjectEither(): void
    {
        $messageId = $this->linkedMessage('LOC-K7Q2MX');
        $this->service->detach('rental', 'LOC-K7Q2MX', $messageId, [], true);

        $applied = (new AnalysisResultApplier($this->messages))->applyAndReport($messageId, [
            'rental' => AnalysisResult::proposing(
                new MessageCandidate('LOC-K7Q2MX', 'Réservation', 'sender_window', 'parce que')
            ),
        ]);

        $this->assertSame([], $applied->candidates);
    }

    public function testAConsumerCanAskWhetherItWasRuledOut(): void
    {
        // What the rentals' model asks before weighing two bookings: the one
        // a person took the message off is not on the list (#720, step 6).
        $messageId = $this->linkedMessage('LOC-K7Q2MX');
        $this->assertFalse($this->service->isExcluded('rental', $messageId, 'LOC-K7Q2MX'));

        $this->service->detach('rental', 'LOC-K7Q2MX', $messageId, [], true);

        $this->assertTrue($this->service->isExcluded('rental', $messageId, 'LOC-K7Q2MX'));
        $this->assertFalse($this->service->isExcluded('rental', $messageId, 'LOC-2027-AAAAAA'));
        $this->assertFalse($this->service->isExcluded('camps', $messageId, 'LOC-K7Q2MX'));
    }

    public function testDetachingTwiceForGoodIsOneDecisionAndNoError(): void
    {
        $messageId = $this->linkedMessage('LOC-K7Q2MX');
        $this->service->detach('rental', 'LOC-K7Q2MX', $messageId, [], true);
        $this->messages->addLink($messageId, 'rental', 'LOC-K7Q2MX', LinkOrigin::MANUAL);

        $this->assertTrue($this->service->detach('rental', 'LOC-K7Q2MX', $messageId, [], true));
        $this->assertTrue($this->messages->isExcluded($messageId, 'rental', 'LOC-K7Q2MX'));
    }

    public function testAPersonMayStillAttachItByHand(): void
    {
        // Only automatic paths read the exclusion: a person on the unit's
        // general mail screen decides past it, as a person may.
        $messageId = $this->linkedMessage('LOC-K7Q2MX');
        $this->service->detach('rental', 'LOC-K7Q2MX', $messageId, [], true);

        $this->assertTrue($this->service->attach('rental', 'LOC-K7Q2MX', $messageId, 7));
        $this->assertCount(1, $this->messages->findLinksForMessage($messageId));
    }

    private function linkedMessage(string $reference): int
    {
        $messageId = $this->messages->create(
            mailboxId: $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: 501,
            messageId: 'detach@mail',
            inReplyTo: null,
            subject: 'Réservation',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
        $this->messages->addLink($messageId, 'rental', $reference, LinkOrigin::SENDER);

        return $messageId;
    }

    /** A consumer whose rules file every message under each of $references. */
    private function consumerFiling(string ...$references): void
    {
        $this->consumers->register(new FakeMessageConsumer(
            'rental',
            static fn(): AnalysisResult => new AnalysisResult(array_map(
                static fn(string $reference): MessageLink => new MessageLink('rental', $reference, LinkOrigin::SENDER),
                $references
            ))
        ));
    }
}
