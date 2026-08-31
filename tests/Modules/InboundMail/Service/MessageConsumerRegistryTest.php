<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Modules\InboundMail\FakeMessageConsumer;

/**
 * Who gets asked what a message means to them (§7.6).
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
            mailboxId: 1,
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

    private function consumer(?string $reference, string $id = 'rental'): FakeMessageConsumer
    {
        return new FakeMessageConsumer(
            id: $id,
            onAnalyze: static fn(CandidateMessage $message): AnalysisResult => $reference === null
                ? AnalysisResult::nothing()
                : AnalysisResult::linkedTo($id, $reference, LinkOrigin::REFERENCE)
        );
    }

    public function testAnEmptyRegistrySaysSoSoTheSyncCanSkipTheWorkEntirely(): void
    {
        $registry = new MessageConsumerRegistry();

        $this->assertFalse($registry->hasConsumers());
        $this->assertSame([], $registry->analyzeAll($this->candidate()));
    }

    public function testAConsumerRegisteredAfterTheRegistryWasHandedOutStillReachesIt(): void
    {
        // The property that breaks the construction cycle: the sync service
        // holds the object, not a snapshot of its contents.
        $registry = new MessageConsumerRegistry();
        $heldBySyncService = $registry;

        $registry->register($this->consumer('LOC-2027-0042'));

        $this->assertTrue($heldBySyncService->hasConsumers());
        $this->assertNotSame([], $heldBySyncService->analyzeAll($this->candidate()));
    }

    public function testEveryConsumerIsAskedAndEveryAnswerComesBack(): void
    {
        // This replaced first-claim-wins. Under that rule the second module
        // to recognise a message was never even asked, so an email that is
        // both a booking's correspondence and an invoice could only ever be
        // one of the two — and registration order silently decided which.
        $registry = new MessageConsumerRegistry();
        $registry->register($this->consumer(null, 'finance'));
        $registry->register($this->consumer('LOC-2027-0042', 'rental'));
        $registry->register($this->consumer('AUTRE', 'registration'));

        $results = $registry->analyzeAll($this->candidate());

        // 'finance' said nothing, so it is simply absent — an empty result
        // is not an answer worth carrying.
        $this->assertSame(['rental', 'registration'], array_keys($results));
        $this->assertSame('LOC-2027-0042', $results['rental']->links[0]->businessReference);
        $this->assertSame('AUTRE', $results['registration']->links[0]->businessReference);
    }

    public function testAConsumerThatThrowsIsSkippedRatherThanTakingTheRunDown(): void
    {
        $registry = new MessageConsumerRegistry();
        $registry->register(new FakeMessageConsumer(
            id: 'broken',
            onAnalyze: static function (CandidateMessage $message): AnalysisResult {
                throw new \RuntimeException('bug');
            }
        ));
        $registry->register($this->consumer('LOC-2027-0042'));

        $results = $registry->analyzeAll($this->candidate());

        $this->assertSame(['rental'], array_keys($results));
    }

    public function testNobodyRecognisingTheMessageIsACompleteAnswer(): void
    {
        $registry = new MessageConsumerRegistry();
        $registry->register($this->consumer(null, 'rental'));
        $registry->register($this->consumer(null, 'finance'));

        $this->assertTrue($registry->hasConsumers());
        $this->assertSame([], $registry->analyzeAll($this->candidate()));
    }

    // ── The candidate a consumer is handed ──────────────────────────────

    public function testTheThreadIdsAreOfferedMostSpecificFirst(): void
    {
        // In-Reply-To names the direct parent; References is the whole
        // chain, oldest first. A consumer wanting the closest known message
        // should not have to work that ordering out itself.
        $candidate = new CandidateMessage(
            mailboxId: 1,
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
        // A human decided: the strongest of the lot.
        $this->assertTrue(LinkOrigin::MANUAL->isCertain());
    }

    public function testEveryOriginHasALabelAManagerCanRead(): void
    {
        foreach (LinkOrigin::cases() as $origin) {
            $this->assertNotSame('', $origin->label());
        }
    }
}
