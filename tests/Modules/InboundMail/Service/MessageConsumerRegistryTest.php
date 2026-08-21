<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageClaim;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Who gets asked whether a message is theirs (§7.6).
 *
 * The registry is the ARCHITECTURE.md §7.6 pattern applied a second time:
 * mutable, owned by the extended module, appended to from each consumer's
 * block in the composition root. So the tests here are about the same three
 * properties that pattern always needs — it can be filled after it was
 * handed out, one contributor's failure is not everybody's, and the module
 * itself knows nothing about who registered.
 */
class MessageConsumerRegistryTest extends TestCase
{
    private function candidate(string $subject = 'Demande'): CandidateMessage
    {
        return new CandidateMessage(
            subject: $subject,
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            messageId: 'a@b',
            inReplyTo: null,
            references: [],
            toEmails: ['locations@unite.be'],
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00'),
            bodyText: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>'
        );
    }

    private function consumer(?MessageClaim $claim, string $id = 'rental'): MessageConsumerInterface
    {
        return new class ($claim, $id) implements MessageConsumerInterface {
            public function __construct(private ?MessageClaim $claim, private string $id)
            {
            }

            public function consumerId(): string
            {
                return $this->id;
            }

            public function claim(CandidateMessage $message): ?MessageClaim
            {
                return $this->claim;
            }
        };
    }

    public function testAnEmptyRegistrySaysSoSoTheSyncCanSkipTheWorkEntirely(): void
    {
        $registry = new MessageConsumerRegistry();

        $this->assertFalse($registry->hasConsumers());
        $this->assertNull($registry->firstClaim($this->candidate()));
    }

    public function testAConsumerRegisteredAfterTheRegistryWasHandedOutStillReachesIt(): void
    {
        // The property that breaks the construction cycle: the sync service
        // holds the object, not a snapshot of its contents.
        $registry = new MessageConsumerRegistry();
        $heldBySyncService = $registry;

        $registry->register($this->consumer(new MessageClaim('LOC-2027-0042', LinkOrigin::REFERENCE)));

        $this->assertTrue($heldBySyncService->hasConsumers());
        $this->assertNotNull($heldBySyncService->firstClaim($this->candidate()));
    }

    public function testTheFirstConsumerThatRecognisesTheMessageWins(): void
    {
        // First, not best: two modules claiming one message would store it
        // twice under two references, and nothing says which is right.
        $registry = new MessageConsumerRegistry();
        $registry->register($this->consumer(null, 'finance'));
        $registry->register($this->consumer(new MessageClaim('LOC-2027-0042', LinkOrigin::REFERENCE), 'rental'));
        $registry->register($this->consumer(new MessageClaim('AUTRE', LinkOrigin::SENDER), 'registration'));

        $claimed = $registry->firstClaim($this->candidate());

        $this->assertNotNull($claimed);
        $this->assertSame('rental', $claimed['consumer']->consumerId());
        $this->assertSame('LOC-2027-0042', $claimed['claim']->businessReference);
    }

    public function testAConsumerThatThrowsIsSkippedRatherThanTakingTheRunDown(): void
    {
        $registry = new MessageConsumerRegistry();
        $registry->register(new class implements MessageConsumerInterface {
            public function consumerId(): string
            {
                return 'broken';
            }

            public function claim(CandidateMessage $message): ?MessageClaim
            {
                throw new \RuntimeException('bug');
            }
        });
        $registry->register($this->consumer(new MessageClaim('LOC-2027-0042', LinkOrigin::REFERENCE)));

        $claimed = $registry->firstClaim($this->candidate());

        $this->assertNotNull($claimed);
        $this->assertSame('rental', $claimed['consumer']->consumerId());
    }

    public function testNobodyClaimingIsACompleteAnswer(): void
    {
        $registry = new MessageConsumerRegistry();
        $registry->register($this->consumer(null, 'rental'));
        $registry->register($this->consumer(null, 'finance'));

        $this->assertTrue($registry->hasConsumers());
        $this->assertNull($registry->firstClaim($this->candidate()));
    }

    // ── The candidate a consumer is handed ──────────────────────────────

    public function testTheThreadIdsAreOfferedMostSpecificFirst(): void
    {
        // In-Reply-To names the direct parent; References is the whole
        // chain, oldest first. A consumer wanting the closest known message
        // should not have to work that ordering out itself.
        $candidate = new CandidateMessage(
            subject: 'Re: Demande',
            fromEmail: 'jeanne@example.be',
            fromName: null,
            messageId: 'reply@example.be',
            inReplyTo: 'second@unite.be',
            references: ['root@unite.be', 'second@unite.be'],
            toEmails: [],
            sentAt: new \DateTimeImmutable(),
            bodyText: '',
            bodyHtml: ''
        );

        $this->assertSame(['second@unite.be', 'root@unite.be'], $candidate->threadMessageIds());
    }

    public function testAMessageWithNoThreadHeadersOffersNoThreadIds(): void
    {
        $this->assertSame([], $this->candidate()->threadMessageIds());
    }

    // ── Link origins are honest about their own strength (§7.6) ──────────

    public function testAReferenceAndAThreadAreCertainWhileASenderMatchAndAiAreNot(): void
    {
        $this->assertTrue(LinkOrigin::REFERENCE->isCertain());
        $this->assertTrue(LinkOrigin::THREAD->isCertain());
        $this->assertFalse(LinkOrigin::SENDER->isCertain());
        $this->assertFalse(LinkOrigin::AI->isCertain());
    }

    public function testEveryOriginHasALabelAManagerCanRead(): void
    {
        foreach (LinkOrigin::cases() as $origin) {
            $this->assertNotSame('', $origin->label());
        }
    }
}
