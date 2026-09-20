<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\MessagePayload;
use Modules\InboundMail\Api\PayloadConsumerInterface;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Modules\InboundMail\FakeMessageConsumer;

/**
 * The narrow door a machine feed reads its bytes through (roadmap IT-06).
 */
class PayloadConsumerTest extends TestCase
{
    private function candidate(): CandidateMessage
    {
        return new CandidateMessage(
            mailboxId: 7,
            subject: 'Report domain: unite.be',
            fromEmail: 'noreply-dmarc-support@google.com',
            fromName: null,
            messageId: 'r@google.com',
            inReplyTo: null,
            references: [],
            toEmails: ['dmarc@unite.be'],
            sentAt: new \DateTimeImmutable('-1 hour'),
            bodyText: '',
            bodyHtml: ''
        );
    }

    /** @param list<MessagePayload> $available */
    private function ask(array $consumers, array $available): array
    {
        $registry = new MessageConsumerRegistry();
        foreach ($consumers as $consumer) {
            $registry->register($consumer);
        }

        return $registry->analyzeAllPayloads($this->candidate(), $available);
    }

    private function payload(string $mime, int $bytes = 32, string $name = 'report.xml.gz'): MessagePayload
    {
        return new MessagePayload($name, $mime, str_repeat('x', $bytes));
    }

    /**
     * **Asked before a single payload is built**, which is the point of
     * having it at all: `MailboxSyncService` sniffs a mime type per
     * attachment to build the list, and the ordinary installation —
     * inbound mail on, nothing implementing this contract — would pay
     * that on every message it syncs for a list nobody takes.
     */
    public function testTheRegistrySaysWhetherAnybodyWantsPayloadsBeforeOneIsBuilt(): void
    {
        $registry = new MessageConsumerRegistry();
        $registry->register(new FakeMessageConsumer(
            id: 'rental',
            onAnalyze: static fn(CandidateMessage $m): AnalysisResult => AnalysisResult::nothing()
        ));

        $this->assertFalse($registry->wantsPayloads(), 'An ordinary consumer declares no payload appetite.');

        $registry->register(new RecordingPayloadConsumer(['application/gzip'], 1024));

        $this->assertTrue($registry->wantsPayloads());
    }

    /** The ordinary case: it asked for gzip, it gets the gzip. */
    public function testAConsumerIsHandedTheTypesItAskedFor(): void
    {
        $consumer = new RecordingPayloadConsumer(['application/gzip'], 1024);

        $this->ask([$consumer], [$this->payload('application/gzip', 64)]);

        $this->assertCount(1, $consumer->seen);
        $this->assertSame('application/gzip', $consumer->seen[0]->mimeType);
        $this->assertSame(64, $consumer->seen[0]->sizeBytes());
    }

    /**
     * **And nothing else.** A consumer reading a machine feed has no
     * business being handed somebody's medical certificate because it
     * happened to travel on the same message.
     */
    public function testATypeItDidNotAskForNeverReachesIt(): void
    {
        $consumer = new RecordingPayloadConsumer(['application/gzip'], 1024);

        $this->ask([$consumer], [$this->payload('application/pdf', 64, 'certificat.pdf')]);

        $this->assertSame([], $consumer->seen);
        $this->assertFalse($consumer->wasCalled, 'nothing matched, so it is not asked at all.');
    }

    /**
     * **The ceiling refuses rather than truncates.** Half a machine report
     * is not a smaller machine report — it is rubbish that would be parsed
     * and recorded as though it meant something.
     */
    public function testAPayloadOverItsOwnCeilingIsNotHandedOverAtAll(): void
    {
        $consumer = new RecordingPayloadConsumer(['application/gzip'], 100);

        $this->ask([$consumer], [$this->payload('application/gzip', 101)]);

        $this->assertSame([], $consumer->seen);
        $this->assertFalse($consumer->wasCalled);
    }

    public function testAPayloadExactlyAtTheCeilingIsHandedOver(): void
    {
        $consumer = new RecordingPayloadConsumer(['application/gzip'], 100);

        $this->ask([$consumer], [$this->payload('application/gzip', 100)]);

        $this->assertCount(1, $consumer->seen);
    }

    /**
     * An ordinary consumer is untouched by all of this — the whole reason
     * the contract is a separate, opt-in interface rather than a method
     * added to `MessageConsumerInterface`.
     */
    public function testAnOrdinaryConsumerIsNotAskedForPayloadsAtAll(): void
    {
        $ordinary = new FakeMessageConsumer(
            id: 'rental',
            onAnalyze: static fn(CandidateMessage $m): AnalysisResult => AnalysisResult::nothing()
        );

        $results = $this->ask([$ordinary], [$this->payload('application/gzip')]);

        $this->assertSame([], $results);
    }

    /**
     * Same isolation as the arrival pass: one feed's parser failing must
     * not cost everybody else their mail.
     */
    public function testAThrowingPayloadConsumerLosesOnlyItsOwnAnswer(): void
    {
        $angry = new RecordingPayloadConsumer(['application/gzip'], 1024, throw: true);
        $calm = new RecordingPayloadConsumer(['application/gzip'], 1024, id: 'calm', claims: true);

        $results = $this->ask([$angry, $calm], [$this->payload('application/gzip')]);

        $this->assertArrayNotHasKey('angry', $results);
        $this->assertArrayHasKey('calm', $results, 'the second feed still answered.');
    }

    /** A message with no attachments never reaches the pass at all. */
    public function testAMessageWithNoAttachmentsAsksNobody(): void
    {
        $consumer = new RecordingPayloadConsumer(['application/gzip'], 1024);

        $this->assertSame([], $this->ask([$consumer], []));
        $this->assertFalse($consumer->wasCalled);
    }
}

/**
 * A payload consumer that records what it was handed. It implements both
 * contracts, as a real one must: the payload pass is in addition to
 * `analyze()`, never instead of it.
 */
final class RecordingPayloadConsumer implements MessageConsumerInterface, PayloadConsumerInterface
{
    /** @var list<MessagePayload> */
    public array $seen = [];

    public bool $wasCalled = false;

    /** @param string[] $types */
    public function __construct(
        private array $types,
        private int $ceiling,
        private bool $throw = false,
        private string $id = 'angry',
        private bool $claims = false
    ) {
    }

    public function payloadMimeTypes(): array
    {
        return $this->types;
    }

    public function maxPayloadBytes(): int
    {
        return $this->ceiling;
    }

    public function analyzePayloads(CandidateMessage $message, array $payloads): AnalysisResult
    {
        $this->wasCalled = true;

        if ($this->throw) {
            throw new \RuntimeException('the parser gave up');
        }

        $this->seen = $payloads;

        return $this->claims
            ? AnalysisResult::linkedTo($this->id, 'ref-1', \Modules\InboundMail\Api\LinkOrigin::REFERENCE)
            : AnalysisResult::nothing();
    }

    public function consumerId(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return 'Flux machine';
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    public function analyzeStored(\Modules\InboundMail\Api\InboundMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    public function onLinked(\Modules\InboundMail\Api\InboundMessage $message, MessageLink $link): void
    {
    }

    public function onUnlinked(\Modules\InboundMail\Api\InboundMessage $message, MessageLink $link): void
    {
    }

    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
    {
        return false;
    }

    public function describeReference(string $businessReference): ?string
    {
        return null;
    }

    public function describeEvidence(): array
    {
        return ['pièce jointe lisible par machine'];
    }

    public function triageAudienceLabel(): string
    {
        return 'personne';
    }

    public function triageAudienceCount(): int
    {
        return 0;
    }
}
