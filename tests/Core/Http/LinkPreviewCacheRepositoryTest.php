<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\LinkPreviewCacheRepository;
use Core\Http\UrlMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[Group('database')]
class LinkPreviewCacheRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private LinkPreviewCacheRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new LinkPreviewCacheRepository($this->pdo);
    }

    public function testFindFreshReturnsNullWhenNeverStored(): void
    {
        $this->assertNull($this->repository->findFresh(LinkPreviewCacheRepository::hashUrl('https://example.com/a')));
    }

    public function testStoreThenFindFreshRoundTrips(): void
    {
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/a');

        $this->repository->store($hash, new UrlMetadata('Title', 'Page', 'Description', 'https://example.com/img.jpg'));

        $this->assertEquals(
            new UrlMetadata('Title', 'Page', 'Description', 'https://example.com/img.jpg'),
            $this->repository->findFresh($hash)
        );
    }

    public function testStoreWithAllNullFieldsIsStillARealCachedResult(): void
    {
        // A page that scraped successfully but carried no og:* tags and no
        // title at all is a legitimate "nothing to show" result, distinct
        // from "never fetched" — findFresh() must return the (all-null)
        // row, not null itself.
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/no-tags');

        $this->repository->store($hash, new UrlMetadata(null, null, null, null));

        $this->assertEquals(new UrlMetadata(null, null, null, null), $this->repository->findFresh($hash));
    }

    /**
     * A third-party page can put anything in its tags; the columns are
     * VARCHAR(255)/(500), and a clipped title beats a failed insert.
     */
    public function testAnOverlongValueIsClippedToItsColumn(): void
    {
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/long');

        $this->repository->store($hash, new UrlMetadata(str_repeat('é', 400), str_repeat('t', 300), null, null));

        $cached = $this->repository->findFresh($hash);
        $this->assertSame(255, mb_strlen((string) $cached?->ogTitle));
        $this->assertSame(255, mb_strlen((string) $cached?->htmlTitle));
    }

    public function testStoringTheSameUrlHashTwiceOverwritesRatherThanDuplicating(): void
    {
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/a');

        $this->repository->store($hash, new UrlMetadata('First', null, 'First description', null));
        $this->repository->store($hash, new UrlMetadata('Second', null, 'Second description', null));

        $this->assertSame('Second', $this->repository->findFresh($hash)?->ogTitle);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_link_preview_cache')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testFindFreshIgnoresARowOlderThanTheTtl(): void
    {
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/stale');
        $this->repository->store($hash, new UrlMetadata('Stale', null, null, null));

        $stmt = $this->pdo->prepare('UPDATE gallery_link_preview_cache SET fetched_at = ? WHERE url_hash = ?');
        $stmt->execute([(new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s'), $hash]);

        $this->assertNull($this->repository->findFresh($hash));
    }

    public function testStorePurgesRowsOlderThanTheTtlAsASideEffect(): void
    {
        $staleHash = LinkPreviewCacheRepository::hashUrl('https://example.com/old');
        $this->repository->store($staleHash, new UrlMetadata('Old', null, null, null));
        $stmt = $this->pdo->prepare('UPDATE gallery_link_preview_cache SET fetched_at = ? WHERE url_hash = ?');
        $stmt->execute([(new \DateTimeImmutable('-48 hours'))->format('Y-m-d H:i:s'), $staleHash]);

        $this->repository->store(
            LinkPreviewCacheRepository::hashUrl('https://example.com/new'),
            new UrlMetadata('New', null, null, null)
        );

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_link_preview_cache')->fetchColumn();
        $this->assertSame(1, $count, 'the stale row should have been purged, leaving only the fresh one');
    }
}
