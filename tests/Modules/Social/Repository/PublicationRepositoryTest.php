<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Repository;

use Modules\Social\Repository\PublicationRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * « Once per destination » as the database holds it.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PublicationRepositoryTest extends TestCase
{
    private PublicationRepository $repository;
    private \DateTimeImmutable $now;
    private \DateTimeImmutable $stale;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($pdo);
        $this->repository = new PublicationRepository($pdo);
        $this->now = new \DateTimeImmutable('2026-09-26 10:00:00');
        $this->stale = $this->now->modify('-15 minutes');
    }

    public function testADestinationIsTakenOnceAndOnlyOnce(): void
    {
        $this->assertTrue($this->claim(false));
        $this->assertFalse($this->claim(false), 'A second claim, even concurrent, is refused.');
        $this->assertFalse($this->claim(true), 'Pending right now: not retryable.');

        $this->repository->markPublished('album', 3, 'instagram', '42_99', $this->now);

        $this->assertFalse($this->claim(true), 'Published: never again, retry or not.');
        $this->assertTrue($this->repository->forSource('album', 3)['instagram']->isPublished());
    }

    public function testAFailedDestinationIsTakenAgainOnlyWhenTheRetryIsConfirmed(): void
    {
        $this->claim(false);
        $this->repository->markFailed('album', 3, 'instagram', 'Meta a refusé la demande.');

        $this->assertFalse($this->claim(false));
        $this->assertTrue($this->claim(true));
        $this->assertFalse($this->claim(true), 'Two retries racing: one wins.');
    }

    public function testAnInterruptedPublicationBecomesRetryableAfterAQuarterOfAnHour(): void
    {
        $this->repository->claim('album', 3, 'instagram', false, 7, $this->now->modify('-20 minutes'), $this->stale);

        $publication = $this->repository->forSource('album', 3)['instagram'];
        $this->assertTrue($publication->isRetryable($this->stale));
        $this->assertTrue($this->claim(true));
    }

    public function testDestinationsAndContentsAreIndependent(): void
    {
        $this->assertTrue($this->repository->claim('album', 3, 'facebook', false, 7, $this->now, $this->stale));
        $this->assertTrue($this->repository->claim('album', 4, 'facebook', false, 7, $this->now, $this->stale));
        $this->assertTrue($this->repository->claim('article', 3, 'facebook', false, 7, $this->now, $this->stale));
    }

    private function claim(bool $retry): bool
    {
        return $this->repository->claim('album', 3, 'instagram', $retry, 7, $this->now, $this->stale);
    }
}
