<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Service\ArticleService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Fixtures\ReferenceDataset\NewsBlueprint;
use Tests\Fixtures\ReferenceDataset\NewsSeeder;
use Tests\Modules\News\NewsTestHelper;

/**
 * What issue #211 actually reported: on the reference instance, the cover
 * of an article the site refuses read without a session was served to
 * anybody, because `NewsSeeder::upload()` passed `'public'` in hard for
 * every image and never looked at the article being built.
 *
 * The fixture no longer answers that question at all — the floor is
 * applied by `Service\ArticleService`, the same code the site runs — and
 * this is the assertion that would have caught the drift, and that fails
 * again if the fixture ever starts spelling a role_min out for itself.
 * A leak-tightness test written against this dataset has to be measuring
 * the site; before this, it measured the fixture.
 *
 * @see tests/fixtures/reference-dataset/README.md §8
 *
 * @group database
 */
#[Group('database')]
final class ReferenceDatasetNewsAccessTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_refdataset_news_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->storagePath);
    }

    public function testEveryCoverCarriesTheFloorItsArticlesVisibilityCallsFor(): void
    {
        $this->seed();

        $covers = 0;
        foreach ((new ArticleRepository($this->pdo))->findAll() as $article) {
            self::assertNotNull($article->imageFileId, "The article « {$article->title} » has no image.");
            $file = (new FileRepository($this->pdo))->findById($article->imageFileId);
            self::assertNotNull($file);

            self::assertSame(
                ArticleService::coverImageRoleMin($article->visibility),
                $file->roleMin,
                "The cover of « {$article->title} » ({$article->visibility}) does not carry the right access floor.",
            );
            $covers++;
        }

        self::assertSame(count(NewsBlueprint::ARTICLES), $covers, 'the reference dataset changed size');
    }

    /**
     * The two articles the issue named, spelled out rather than derived:
     * the staff one whose cover was public and is not any more, and the
     * members-only one whose cover is public on purpose because its
     * preview is (see SECURITY.md §3).
     */
    public function testTheStaffArticlesCoverIsShutAndTheMembersOnesIsOpen(): void
    {
        $this->seed();

        self::assertSame('chief', $this->coverRoleMinOf('Weekend de staff : inscrivez-vous'));
        self::assertSame('public', $this->coverRoleMinOf('Retour sur le camp de la Meute'));
    }

    /**
     * The body images are the half of the report that was NOT a fixture
     * defect: the product stores them `public` too
     * (`NewsController::uploadBodyImage()` — the article's visibility is
     * unknown mid-edit), so the fixture matching it is the fixture being
     * right. Pinned so the next reader does not "fix" it.
     */
    public function testABodyImageStaysPublicLikeTheProductStoresIt(): void
    {
        $this->seed();

        $bodyImages = 0;
        foreach ((new FileRepository($this->pdo))->findIdsByPathPrefix('news/images/') as $fileId) {
            if (in_array($fileId, $this->coverFileIds(), true)) {
                continue;
            }

            $file = (new FileRepository($this->pdo))->findById($fileId);
            self::assertNotNull($file);
            self::assertSame('public', $file->roleMin);
            $bodyImages++;
        }

        self::assertGreaterThan(0, $bodyImages, 'no body image: the test no longer proves anything');
    }

    /**
     * Issue #1109: a seeded article's body names its image by the opaque
     * address, and that address resolves to a file. The numeric one the
     * seeder used to write names nothing since the cutover.
     */
    public function testABodyImageIsAddressedByItsOpaqueId(): void
    {
        $this->seed();

        $bodies = $this->pdo->query(
            "SELECT content_value FROM editable_contents WHERE content_value LIKE '%<img%'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $files = new FileRepository($this->pdo);

        $images = 0;
        foreach ($bodies as $body) {
            preg_match_all('~<img[^>]*\ssrc="([^"]*)"~', (string) $body, $matches);
            foreach ($matches[1] as $src) {
                self::assertMatchesRegularExpression('~^/files/[A-Za-z0-9_-]{22}$~', $src);
                self::assertNotNull($files->findByPublicId(substr($src, strlen('/files/'))), $src);
                $images++;
            }
        }

        self::assertGreaterThan(0, $images, 'no body image: the test no longer proves anything');
    }

    private function seed(): void
    {
        (new NewsSeeder(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            $this->storagePath,
            self::datasetRoot(),
            $this->authorId,
        ))->seed();
    }

    private function coverRoleMinOf(string $title): string
    {
        foreach ((new ArticleRepository($this->pdo))->findAll() as $article) {
            if ($article->title !== $title || $article->imageFileId === null) {
                continue;
            }

            $file = (new FileRepository($this->pdo))->findById($article->imageFileId);
            self::assertNotNull($file);

            return $file->roleMin;
        }

        self::fail("The article « {$title} » is missing from the reference dataset.");
    }

    /** @return int[] */
    private function coverFileIds(): array
    {
        $ids = [];
        foreach ((new ArticleRepository($this->pdo))->findAll() as $article) {
            if ($article->imageFileId !== null) {
                $ids[] = $article->imageFileId;
            }
        }

        return $ids;
    }

    private static function datasetRoot(): string
    {
        return dirname(__DIR__) . '/fixtures/reference-dataset';
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
