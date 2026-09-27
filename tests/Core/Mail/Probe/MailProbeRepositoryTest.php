<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Probe;

use Core\Mail\Feedback\Bounce\BounceCategory;
use Core\Mail\Probe\MailProbeRepository;
use Core\Mail\Transport\MailLane;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two doors issue #419 opened on the probe history: finding a probe by
 * the code a bounce quoted, and attaching what the far end said.
 *
 * `BounceConsumerTest` exercises both through the consumer, which is where
 * they are used. What it cannot reach is the pair of decisions underneath
 * them, because the consumer never produces the situation: a code that has
 * come round twice, and a bounce arriving for a probe that already carries
 * one. Both are written down in the repository's docblocks, and prose is
 * not a guarantee.
 *
 * @group database
 */
#[Group('database')]
final class MailProbeRepositoryTest extends TestCase
{
    private MailProbeRepository $probes;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->probes = new MailProbeRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    private function sent(string $code, string $at): int
    {
        return $this->probes->record(
            $code,
            'parent@exemple.be',
            null,
            'Relais principal',
            MailLane::Transactional,
            new \DateTimeImmutable($at)
        );
    }

    /**
     * **A repeated code belongs to the run still in flight.**
     *
     * `MailProbeSender::generateCode()` draws six characters from an
     * alphabet of 32 — a billion codes, so a repeat is unlikely rather than
     * impossible, and this table keeps years of history on purpose. When one
     * does come round, the bounce is answering the probe that has just left,
     * not the one somebody answered two years ago.
     */
    public function testACodeThatCameRoundAgainResolvesToTheLatestProbe(): void
    {
        $old = $this->sent('SM-7K2XPQ', '2024-03-01 09:00:00');
        $recent = $this->sent('SM-7K2XPQ', '2026-09-15 07:00:00');

        $found = $this->probes->findByCode('SM-7K2XPQ');

        $this->assertNotNull($found);
        $this->assertSame($recent, $found->id);
        $this->assertNotSame($old, $found->id);
    }

    public function testAnUnknownCodeResolvesToNothing(): void
    {
        $this->sent('SM-7K2XPQ', '2026-09-15 07:00:00');

        $this->assertNull($this->probes->findByCode('SM-ZZZZZZ'));
    }

    /**
     * **The first rejection stays, and the return value says who wrote it.**
     *
     * Same guard as `recordVerdict()`'s `WHERE verdict IS NULL`, for the same
     * reason in a different key: a mailbox that bounces once bounces again,
     * and the later bounces are about other messages. Without this the row
     * would end up dated by the last unrelated failure.
     */
    public function testOnlyTheFirstRejectionIsRecorded(): void
    {
        $id = $this->sent('SM-7K2XPQ', '2026-09-15 07:00:00');

        $this->assertTrue($this->probes->recordBounce(
            $id,
            BounceCategory::NoSuchAddress,
            '5.1.1',
            new \DateTimeImmutable('2026-09-15 08:00:00')
        ));
        $this->assertFalse($this->probes->recordBounce(
            $id,
            BounceCategory::MailboxFull,
            '5.2.2',
            new \DateTimeImmutable('2026-09-20 08:00:00')
        ));

        $probe = $this->probes->find($id);
        $this->assertNotNull($probe?->bounce);
        $this->assertSame(BounceCategory::NoSuchAddress, $probe->bounce->category);
        $this->assertSame('5.1.1', $probe->bounce->statusCode);
        $this->assertSame('15/09/2026', $probe->bounce->at->format('d/m/Y'));
    }

    /**
     * A probe nobody traced anything to carries no attachment at all — and
     * `bounce` is keyed on the DATE, not on the category, so a category a
     * later version no longer knows cannot turn a recorded rejection back
     * into « rien n'est revenu ».
     */
    public function testAProbeWithNothingTracedToItHasNoBounce(): void
    {
        $id = $this->sent('SM-7K2XPQ', '2026-09-15 07:00:00');

        $this->assertNull($this->probes->find($id)?->bounce);
    }
}
