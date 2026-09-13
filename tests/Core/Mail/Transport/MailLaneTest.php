<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Mail\MailPurpose;
use Core\Mail\Transport\MailLane;
use PHPUnit\Framework\TestCase;

/**
 * A lane comes from `MailPurpose` and from nothing else
 * (ARCHITECTURE.md §8.106).
 *
 * The mapping is four lines of code and it is the whole routing
 * decision, which is why it is pinned here rather than left to the
 * chain's own tests: a second way of deciding a lane — from the
 * recipient, from the calling class, from a per-feature flag — would be
 * a second answer to the one question this mechanism asks.
 */
class MailLaneTest extends TestCase
{
    public function testEveryPurposeMapsToOneLane(): void
    {
        $this->assertSame(MailLane::Authentication, MailLane::fromPurpose(MailPurpose::MagicLink));
        $this->assertSame(MailLane::Transactional, MailLane::fromPurpose(MailPurpose::Ordinary));
        $this->assertSame(MailLane::Bulk, MailLane::fromPurpose(MailPurpose::Bulk));
    }

    /**
     * The `match` above is exhaustive, so a fourth purpose is a fatal
     * error rather than a silent fallback — which is the right shape: a
     * new category means a new lane and a new chain to configure, not an
     * addition somebody can make without noticing.
     */
    public function testEveryPurposeIsCovered(): void
    {
        foreach (MailPurpose::cases() as $purpose) {
            $this->assertInstanceOf(MailLane::class, MailLane::fromPurpose($purpose));
        }
    }

    /**
     * D6. A cadence paces a mailing; an authentication or transactional
     * message leaves immediately. A sign-in link waiting for the next
     * slot of a lot is not a sign-in link — the token lives fifteen
     * minutes.
     */
    public function testOnlyTheMailingLaneHonoursACadence(): void
    {
        $this->assertTrue(MailLane::Bulk->honoursCadence());
        $this->assertFalse(MailLane::Authentication->honoursCadence());
        $this->assertFalse(MailLane::Transactional->honoursCadence());
    }

    public function testEveryLaneIsNamedAndDescribedInFrench(): void
    {
        foreach (MailLane::ordered() as $lane) {
            $this->assertNotSame('', $lane->label());
            $this->assertNotSame('', $lane->detail());
            $this->assertSame($lane->label(), ucfirst($lane->label()));
        }

        $this->assertCount(count(MailLane::cases()), MailLane::ordered());
    }
}
