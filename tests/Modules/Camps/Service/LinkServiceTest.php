<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Http\LinkPreview;
use Core\Http\LinkPreviewFetcher;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\LinkService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

#[\PHPUnit\Framework\Attributes\Group('database')]
class LinkServiceTest extends TestCase
{
    private \PDO $pdo;
    private LinkRepository $links;
    private AuditService $audit;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->links = new LinkRepository($this->pdo);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
        $this->pdo->exec("INSERT INTO camp_camps (place_id, year_only, status) VALUES (1, 2028, 'confirmed')");
    }

    /**
     * @dataProvider urls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('urls')]
    public function testOnlyHttpUrlsAreAccepted(string $input, ?string $expected): void
    {
        $service = $this->service();

        if ($expected === null) {
            $this->expectException(CampsException::class);
            $service->validateUrl($input);

            return;
        }

        $this->assertSame($expected, $service->validateUrl($input));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function urls(): array
    {
        return [
            'plain https' => ['https://domainedemozet.be', 'https://domainedemozet.be'],
            'http kept as-is' => ['http://example.org/x', 'http://example.org/x'],
            'a bare host gets https' => ['domainedemozet.be', 'https://domainedemozet.be'],
            // A URL box on a chief-only page is still an injection
            // vector: escaping at render time does not stop a stored
            // "javascript:" from being clickable.
            'javascript refused' => ['javascript:alert(1)', null],
            'data uri refused' => ['data:text/html,<script>', null],
            'file scheme refused' => ['file:///etc/passwd', null],
            'empty refused' => ['   ', null],
        ];
    }

    public function testAnOverlongUrlIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service()->validateUrl('https://example.org/' . str_repeat('a', 1200));
    }

    public function testWithoutTheGalleryTheLinkIsStoredAsABareUrl(): void
    {
        // The gallery module is optional. Its absence must cost the
        // preview, never the link.
        $id = $this->service()->attach(1, 'https://domainedemozet.be/terrains', 42);

        $link = $this->links->findById($id);
        $this->assertNotNull($link);
        $this->assertSame('https://domainedemozet.be/terrains', $link->url);
        $this->assertNull($link->title);
        $this->assertNull($link->fetchedAt);
        $this->assertFalse($link->hasPreview());
        $this->assertSame('domainedemozet.be', $link->siteName);
    }

    public function testAPreviewIsStoredWhenTheGalleryProvidesOne(): void
    {
        $fetcher = $this->createStub(LinkPreviewFetcher::class);
        $fetcher->method('fetch')->willReturn(
            new LinkPreview('Domaine de Mozet — Terrains', 'Terrains et hébergements', null)
        );

        $id = $this->service($fetcher)->attach(1, 'https://domainedemozet.be', 42);

        $link = $this->links->findById($id);
        $this->assertSame('Domaine de Mozet — Terrains', $link?->title);
        $this->assertNotNull($link?->fetchedAt);
        $this->assertTrue($link->hasPreview());
    }

    public function testASiteThatAnswersNothingStillGivesAUsableLink(): void
    {
        $fetcher = $this->createStub(LinkPreviewFetcher::class);
        $fetcher->method('fetch')->willReturn(null);

        $id = $this->service($fetcher)->attach(1, 'https://example.org/page', 42);

        $link = $this->links->findById($id);
        $this->assertNotNull($link);
        // A bare URL is still a usable link; an empty card is not.
        $this->assertSame('example.org', $link->heading());
    }

    /**
     * A page whose preview « image » is not an image at all — an HTML
     * error page served where the picture should be. The real upload
     * handler refuses it, and the link is kept with its title and no
     * picture.
     */
    public function testAPreviewImageThatIsNotAnImageCostsThePictureAndNotTheLink(): void
    {
        $storage = sys_get_temp_dir() . '/camps-link-previews-' . bin2hex(random_bytes(6));
        mkdir($storage, 0777, true);
        $fetcher = new \Tests\Modules\DataMigration\Support\FakeLinkPreviewFetcher([
            'https://domainedemozet.be' => new LinkPreview(
                'Domaine de Mozet',
                null,
                "<!DOCTYPE html><html><body>404 Not Found</body></html>\n"
            ),
        ]);
        $service = new LinkService(
            $this->links,
            $this->audit,
            $fetcher,
            new \Core\File\UploadHandler(new \Core\File\FileRepository($this->pdo), $storage)
        );

        try {
            $id = $service->attach(1, 'https://domainedemozet.be', 42);
            $leftOnDisk = iterator_to_array(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS)
            ));
        } finally {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
            }
            rmdir($storage);
        }

        $link = $this->links->findById($id);
        $this->assertSame('Domaine de Mozet', $link?->title);
        $this->assertNull($link->imageFileId);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        $this->assertSame([], $leftOnDisk);
    }

    public function testTheHeadingFallsBackFromTitleToSiteToUrl(): void
    {
        $withTitle = $this->links->findById($this->links->create(1, 'https://a.be', 'Titre', null, null, 'a.be', null));
        $withSite = $this->links->findById($this->links->create(1, 'https://b.be', null, null, null, 'b.be', null));
        $bare = $this->links->findById($this->links->create(1, 'https://c.be/x', null, null, null, null, null));

        $this->assertSame('Titre', $withTitle?->heading());
        $this->assertSame('b.be', $withSite?->heading());
        $this->assertSame('https://c.be/x', $bare?->heading());
    }

    public function testTheSiteNameDropsTheWwwPrefix(): void
    {
        $this->assertSame('domainedemozet.be', LinkService::siteNameFor('https://www.domainedemozet.be/x'));
        $this->assertSame('example.org', LinkService::siteNameFor('http://example.org'));
        $this->assertNull(LinkService::siteNameFor('pas-une-url'));
    }

    public function testAttachingAndDetachingAreBothRecorded(): void
    {
        $service = $this->service();
        $id = $service->attach(1, 'https://domainedemozet.be', 42);
        $link = $this->links->findById($id);
        $this->assertNotNull($link);

        $service->detach($link, 42);

        $summaries = array_map(
            static fn($e): ?string => $e->summary,
            $this->audit->page(CampService::ENTITY_TYPE, 1, 1, 10)->entries
        );
        $this->assertSame(['Lien retiré', 'Lien ajouté'], $summaries);
        $this->assertNull($this->links->findById($id));
    }

    private function service(?LinkPreviewFetcher $fetcher = null): LinkService
    {
        return new LinkService($this->links, $this->audit, $fetcher, null);
    }
}
