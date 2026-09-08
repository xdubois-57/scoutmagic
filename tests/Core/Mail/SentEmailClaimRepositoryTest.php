<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\SentEmailClaimRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The replay guard of the background e-mail handlers (issue #246): a
 * handler that is re-run after an abrupt stop must not write to anybody
 * a second time.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SentEmailClaimRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SentEmailClaimRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SentEmailClaimRepository($this->pdo);
    }

    public function testTheFirstClaimWins(): void
    {
        $this->assertTrue($this->repository->claim('campaign:2026-09-30', '42'));
    }

    public function testTheSecondClaimOnTheSameRecipientLoses(): void
    {
        $this->repository->claim('campaign:2026-09-30', '42');

        $this->assertFalse(
            $this->repository->claim('campaign:2026-09-30', '42'),
            'A replay of the same run must find the recipient already written to.'
        );
    }

    public function testAnotherRecipientInTheSameScopeIsUnaffected(): void
    {
        $this->repository->claim('campaign:2026-09-30', '42');

        $this->assertTrue($this->repository->claim('campaign:2026-09-30', '43'));
    }

    /**
     * The whole reason a scope names its own occurrence: next year's
     * campaign must start clean even though the recipient is the same
     * person.
     */
    public function testTheSameRecipientInAnotherScopeIsANewClaim(): void
    {
        $this->repository->claim('campaign:2026-09-30', '42');

        $this->assertTrue($this->repository->claim('campaign:2027-09-30', '42'));
    }

    public function testAReleasedClaimCanBeTakenAgain(): void
    {
        $this->repository->claim('campaign:2026-09-30', '42');
        $this->repository->release('campaign:2026-09-30', '42');

        $this->assertTrue($this->repository->claim('campaign:2026-09-30', '42'));
    }

    public function testReleasingAClaimNobodyHoldsIsHarmless(): void
    {
        $this->repository->release('campaign:2026-09-30', '42');

        $this->assertTrue($this->repository->claim('campaign:2026-09-30', '42'));
    }

    public function testThePurgeDropsOldClaimsAndKeepsRecentOnes(): void
    {
        $this->repository->claim('campaign:2026-09-30', '42');
        $this->pdo->exec("UPDATE sent_email_claims SET claimed_at = '2020-01-01 00:00:00' WHERE recipient_key = '42'");
        $this->repository->claim('campaign:2026-09-30', '43');

        $this->assertSame(1, $this->repository->deleteClaimedBefore('2021-01-01 00:00:00'));
        $this->assertTrue(
            $this->repository->claim('campaign:2026-09-30', '42'),
            'A purged claim is free again — which is safe only because its own occurrence is long past.'
        );
        $this->assertFalse($this->repository->claim('campaign:2026-09-30', '43'));
    }

    /**
     * A UNIQUE violation is the ONLY reason to answer "already claimed".
     * Anything else — a lost connection, a missing table — has to surface
     * as itself rather than as a recipient silently skipped.
     */
    public function testAFailureThatIsNotADuplicateIsRaised(): void
    {
        $this->pdo->exec('DROP TABLE sent_email_claims');

        $this->expectException(\PDOException::class);
        $this->repository->claim('campaign:2026-09-30', '42');
    }
}
