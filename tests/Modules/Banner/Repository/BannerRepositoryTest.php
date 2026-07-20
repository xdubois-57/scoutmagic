<?php

declare(strict_types=1);

namespace Tests\Modules\Banner\Repository;

use Modules\Banner\Repository\BannerRepository;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Banner\BannerTestHelper;

class BannerRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private BannerRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        BannerTestHelper::createTables($this->pdo);
        $this->repository = new BannerRepository($this->pdo);
    }

    public function testCreateAppendsAtTheEndOfTheOrder(): void
    {
        $first = $this->repository->create();
        $second = $this->repository->create();

        $banners = $this->repository->findAllOrdered();

        $this->assertSame($first, $banners[0]->id);
        $this->assertSame($second, $banners[1]->id);
        $this->assertSame(0, $banners[0]->sortOrder);
        $this->assertSame(1, $banners[1]->sortOrder);
    }

    public function testCreateDefaultsToActive(): void
    {
        $id = $this->repository->create();

        $banner = $this->repository->findById($id);

        $this->assertTrue($banner->isActive);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repository->findById(999));
    }

    public function testFindAllOrderedIncludesInactiveBanners(): void
    {
        $id = $this->repository->create();
        $this->repository->setActive($id, false);

        $banners = $this->repository->findAllOrdered();

        $this->assertCount(1, $banners);
        $this->assertFalse($banners[0]->isActive);
    }

    public function testFindActiveOrderedExcludesInactiveBanners(): void
    {
        $activeId = $this->repository->create();
        $inactiveId = $this->repository->create();
        $this->repository->setActive($inactiveId, false);

        $active = $this->repository->findActiveOrdered();

        $this->assertCount(1, $active);
        $this->assertSame($activeId, $active[0]->id);
    }

    public function testSetActiveTogglesFlag(): void
    {
        $id = $this->repository->create();

        $this->repository->setActive($id, false);
        $this->assertFalse($this->repository->findById($id)->isActive);

        $this->repository->setActive($id, true);
        $this->assertTrue($this->repository->findById($id)->isActive);
    }

    public function testDeleteRemovesTheBanner(): void
    {
        $id = $this->repository->create();

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testReorderUpdatesSortOrderToMatchArrayPosition(): void
    {
        $first = $this->repository->create();
        $second = $this->repository->create();
        $third = $this->repository->create();

        $this->repository->reorder([$third, $first, $second]);

        $banners = $this->repository->findAllOrdered();
        $this->assertSame([$third, $first, $second], array_map(fn($b) => $b->id, $banners));
    }
}
