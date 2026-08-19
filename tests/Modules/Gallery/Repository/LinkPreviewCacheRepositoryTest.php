<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Repository;

use Modules\Gallery\Repository\LinkPreviewCacheRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

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
        GalleryTestHelper::createTables($this->pdo);
        $this->repository = new LinkPreviewCacheRepository($this->pdo);
    }

    public function testFindFreshReturnsNullWhenNeverStored(): void
    {
        $this->assertNull($this->repository->findFresh(LinkPreviewCacheRepository::hashUrl('https://example.com/a')));
    }

    public function testStoreThenFindFreshRoundTrips(): void
    {
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/a');

        $this->repository->store($hash, 'Title', 'Description', 'https://example.com/img.jpg');

        $this->assertSame(
            ['title' => 'Title', 'description' => 'Description', 'imageUrl' => 'https://example.com/img.jpg'],
            $this->repository->findFresh($hash)
        );
    }

    public function testStoreWithAllNullFieldsIsStillARealCachedResult(): void
    {
        // A page that scraped successfully but carried no og:* tags at
        // all is a legitimate "nothing to show" result, distinct from
        // "never fetched" — findFresh() must return the (all-null) row,
        // not null itself.
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/no-tags');

        $this->repository->store($hash, null, null, null);

        $this->assertSame(
            ['title' => null, 'description' => null, 'imageUrl' => null],
            $this->repository->findFresh($hash)
        );
    }

    public function testStoringTheSameUrlHashTwiceOverwritesRatherThanDuplicating(): void
    {
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/a');

        $this->repository->store($hash, 'First', 'First description', null);
        $this->repository->store($hash, 'Second', 'Second description', null);

        $this->assertSame('Second', $this->repository->findFresh($hash)['title']);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_link_preview_cache')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testFindFreshIgnoresARowOlderThanTheTtl(): void
    {
        $hash = LinkPreviewCacheRepository::hashUrl('https://example.com/stale');
        $this->repository->store($hash, 'Stale', null, null);

        $stmt = $this->pdo->prepare('UPDATE gallery_link_preview_cache SET fetched_at = ? WHERE url_hash = ?');
        $stmt->execute([(new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s'), $hash]);

        $this->assertNull($this->repository->findFresh($hash));
    }

    public function testStorePurgesRowsOlderThanTheTtlAsASideEffect(): void
    {
        $staleHash = LinkPreviewCacheRepository::hashUrl('https://example.com/old');
        $this->repository->store($staleHash, 'Old', null, null);
        $stmt = $this->pdo->prepare('UPDATE gallery_link_preview_cache SET fetched_at = ? WHERE url_hash = ?');
        $stmt->execute([(new \DateTimeImmutable('-48 hours'))->format('Y-m-d H:i:s'), $staleHash]);

        $this->repository->store(LinkPreviewCacheRepository::hashUrl('https://example.com/new'), 'New', null, null);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_link_preview_cache')->fetchColumn();
        $this->assertSame(1, $count, 'the stale row should have been purged, leaving only the fresh one');
    }
}
