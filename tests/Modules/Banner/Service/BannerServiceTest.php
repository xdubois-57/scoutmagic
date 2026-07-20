<?php

declare(strict_types=1);

namespace Tests\Modules\Banner\Service;

use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Banner\Repository\BannerRepository;
use Modules\Banner\Service\BannerException;
use Modules\Banner\Service\BannerService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Banner\BannerTestHelper;

class BannerServiceTest extends TestCase
{
    private \PDO $pdo;
    private BannerService $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        BannerTestHelper::createTables($this->pdo);
        $this->pdo->exec("CREATE TABLE editable_contents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_key TEXT NOT NULL UNIQUE,
            content_type TEXT NOT NULL,
            content_value TEXT,
            module_id TEXT,
            modified_at TEXT,
            modified_by INTEGER
        )");

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->service = new BannerService(new BannerRepository($this->pdo), $editableContentService);
    }

    private function setContent(int $bannerId, string $html): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO editable_contents (content_key, content_type, content_value) VALUES (?, ?, ?)');
        $stmt->execute(['banner_content_' . $bannerId, 'rich_text', $html]);
    }

    public function testGetRandomBannerHtmlReturnsNullWhenNoBanners(): void
    {
        $this->assertNull($this->service->getRandomBannerHtml());
    }

    public function testGetRandomBannerHtmlReturnsNullWhenAllInactive(): void
    {
        $banner = $this->service->create();
        $this->setContent($banner->id, '<p>Hello</p>');
        $this->service->setActive($banner->id, false);

        $this->assertNull($this->service->getRandomBannerHtml());
    }

    public function testGetRandomBannerHtmlReturnsContentOfAnActiveBanner(): void
    {
        $banner = $this->service->create();
        $this->setContent($banner->id, '<p>Hello</p>');

        $this->assertSame('<p>Hello</p>', $this->service->getRandomBannerHtml());
    }

    public function testGetRandomBannerHtmlOnlyEverPicksActiveBanners(): void
    {
        $active = $this->service->create();
        $this->setContent($active->id, '<p>Active</p>');
        $inactive = $this->service->create();
        $this->setContent($inactive->id, '<p>Inactive</p>');
        $this->service->setActive($inactive->id, false);

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame('<p>Active</p>', $this->service->getRandomBannerHtml());
        }
    }

    public function testGetAllForConfigIncludesContentAndActiveState(): void
    {
        $banner = $this->service->create();
        $this->setContent($banner->id, '<p>Text</p>');

        $items = $this->service->getAllForConfig();

        $this->assertCount(1, $items);
        $this->assertSame($banner->id, $items[0]['id']);
        $this->assertTrue($items[0]['is_active']);
        $this->assertSame('<p>Text</p>', $items[0]['content']);
    }

    public function testGetAllForConfigDefaultsContentToEmptyStringForNewBanner(): void
    {
        $this->service->create();

        $items = $this->service->getAllForConfig();

        $this->assertSame('', $items[0]['content']);
    }

    public function testSetActiveRejectsUnknownBanner(): void
    {
        $this->expectException(BannerException::class);
        $this->service->setActive(999, true);
    }

    public function testDeleteRejectsUnknownBanner(): void
    {
        $this->expectException(BannerException::class);
        $this->service->delete(999);
    }

    public function testDeleteRemovesBothTheBannerAndItsContent(): void
    {
        $banner = $this->service->create();
        $this->setContent($banner->id, '<p>Bye</p>');

        $this->service->delete($banner->id);

        $this->assertSame([], $this->service->getAllForConfig());
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM editable_contents WHERE content_key = ?');
        $stmt->execute(['banner_content_' . $banner->id]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testReorderDelegatesToRepository(): void
    {
        $first = $this->service->create();
        $second = $this->service->create();

        $this->service->reorder([$second->id, $first->id]);

        $items = $this->service->getAllForConfig();
        $this->assertSame($second->id, $items[0]['id']);
        $this->assertSame($first->id, $items[1]['id']);
    }
}
