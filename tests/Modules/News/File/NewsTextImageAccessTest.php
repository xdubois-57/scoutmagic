<?php

declare(strict_types=1);

namespace Tests\Modules\News\File;

use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\File\FileAccessGuard;
use Core\File\FilePublicIds;
use Core\File\FileRepository;
use Core\Security\Role;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Modules\News\File\NewsCoverOwnershipChecker;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Service\ArticleService;
use Modules\News\Service\BodyImageAccess;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * Issue #1110, asked of the file guard itself: who may read an image
 * inserted in an article's text, once that text is saved.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class NewsTextImageAccessTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $files;
    private ArticleRepository $articles;
    private NewsCoverOwnershipChecker $checker;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->files = new FileRepository($this->pdo);
        $this->articles = new ArticleRepository($this->pdo);
        FilePublicIds::useRepository($this->files);
        $service = new ArticleService(
            $this->articles,
            new FormRepository($this->pdo),
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new ShortUrlService(new ShortUrlRepository(
                $this->pdo,
                new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
            )),
            $this->files
        );
        $this->checker = new NewsCoverOwnershipChecker($this->articles, $service);
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        FilePublicIds::useRepository(null);
    }

    public function testTheCheckerAnswersForTextImagesToo(): void
    {
        $this->assertTrue($this->checker->supports(NewsCoverOwnershipChecker::BODY_IMAGE_OWNER_TYPE));
    }

    /**
     * The leak as reported: a « Chef d'Unité » or « Animateurs » article's
     * photographs, readable by anybody holding their address.
     */
    public function testAStaffArticlesTextImageIsRefusedBelowItsVisibility(): void
    {
        $image = $this->savedImageOf(Article::VISIBILITY_CHIEF, true);

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNull($this->guard(Role::IDENTIFIED)->check($image));
        $this->assertNotNull($this->guard(Role::CHIEF)->check($image));
    }

    public function testAMembersArticlesTextImageIsForMembers(): void
    {
        $image = $this->savedImageOf(Article::VISIBILITY_IDENTIFIED, true);

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNotNull($this->guard(Role::IDENTIFIED)->check($image));
    }

    /** A public article keeps public images. */
    public function testAPublicArticlesTextImageIsPublic(): void
    {
        $this->assertNotNull($this->guard(Role::PUBLIC)->check($this->savedImageOf(Article::VISIBILITY_PUBLIC, true)));
    }

    /** A draft shows nothing of itself before it goes online — the cover's rule. */
    public function testADraftsTextImageIsForTheNewsManagersOnly(): void
    {
        $image = $this->savedImageOf(Article::VISIBILITY_PUBLIC, false);

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNull($this->guard(Role::IDENTIFIED)->check($image));
        $this->assertNotNull($this->guard(Role::CHIEF)->check($image));
    }

    /** With the module off, nothing answers for `news_article`: fail-closed. */
    public function testWithTheModuleOffTheImageIsRefused(): void
    {
        $image = $this->savedImageOf(Article::VISIBILITY_PUBLIC, true);

        $this->assertNull((new FileAccessGuard($this->files, Role::ADMIN, [], []))->check($image));
    }

    /** An image uploaded but never saved into an article is no visitor's. */
    public function testAnUnsavedUploadIsNotPublic(): void
    {
        $image = $this->image();

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNull($this->guard(Role::IDENTIFIED)->check($image));
        $this->assertNotNull($this->guard(Role::CHIEF)->check($image));
    }

    private function savedImageOf(string $visibility, bool $online): int
    {
        $image = $this->image();
        $id = $this->articles->create('Camp', $visibility, false, null, null, $this->authorId);
        if ($online) {
            $this->articles->setPublication($id, Article::STATUS_PUBLISHED, '2026-01-01 00:00:00');
        }
        $this->pdo->prepare('INSERT INTO news_forms (news_article_id) VALUES (?)')->execute([$id]);
        $this->pdo->prepare(
            "INSERT INTO news_form_fields (form_id, sort_order, field_type, confirmation_text) VALUES (?, 0, 'text', ?)"
        )->execute([(int) $this->pdo->lastInsertId(), '<p><img src="' . FilePublicIds::urlFor($image) . '"></p>']);

        BodyImageAccess::forPdo($this->pdo)->syncArticle($id);

        return $image;
    }

    private function image(): int
    {
        return $this->files->create(
            'news/images/' . bin2hex(random_bytes(6)) . '.jpg',
            'photo.jpg',
            'image/jpeg',
            1024,
            BodyImageAccess::UNSAVED_ROLE_MIN,
            'news',
            $this->authorId
        );
    }

    private function guard(Role $role): FileAccessGuard
    {
        return new FileAccessGuard($this->files, $role, [], [$this->checker]);
    }
}
