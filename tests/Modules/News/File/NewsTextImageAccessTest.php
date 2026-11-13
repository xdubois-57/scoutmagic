<?php

declare(strict_types=1);

namespace Tests\Modules\News\File;

use Core\File\FileAccessGuard;
use Core\File\FilePublicIds;
use Core\File\FileRepository;
use Core\Security\Role;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\News\File\NewsTextImageOwnershipChecker;
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
 * inserted in an article's text — the floor written at save, then the
 * request-time check of which article naming it is online and visible.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class NewsTextImageAccessTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $files;
    private ArticleRepository $articles;
    private NewsTextImageOwnershipChecker $checker;
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
        $this->checker = new NewsTextImageOwnershipChecker($this->files, BodyImageAccess::forPdo($this->pdo), $service);
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        FilePublicIds::useRepository(null);
    }

    public function testTheCheckerAnswersForTextImagesOnly(): void
    {
        $this->assertTrue($this->checker->supports(NewsTextImageOwnershipChecker::OWNER_TYPE));
        $this->assertFalse($this->checker->supports('news_cover'));
    }

    /**
     * The leak as reported: a « Chef d'Unité » or « Animateurs » article's
     * photographs, readable by anybody holding their address.
     */
    public function testAStaffArticlesTextImageIsRefusedBelowItsVisibility(): void
    {
        $image = $this->image();
        $this->saveArticle(Article::VISIBILITY_CHIEF, true, [$image]);

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNull($this->guard(Role::IDENTIFIED)->check($image));
        $this->assertNotNull($this->guard(Role::CHIEF)->check($image));
    }

    public function testAMembersArticlesTextImageIsForMembers(): void
    {
        $image = $this->image();
        $this->saveArticle(Article::VISIBILITY_IDENTIFIED, true, [$image]);

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNotNull($this->guard(Role::IDENTIFIED)->check($image));
    }

    /** A public article keeps public images. */
    public function testAPublicArticlesTextImageIsPublic(): void
    {
        $image = $this->image();
        $this->saveArticle(Article::VISIBILITY_PUBLIC, true, [$image]);

        $this->assertNotNull($this->guard(Role::PUBLIC)->check($image));
    }

    /** A draft shows nothing of itself before it goes online — the cover's rule. */
    public function testADraftsTextImageIsForTheNewsManagersOnly(): void
    {
        $image = $this->image();
        $this->saveArticle(Article::VISIBILITY_PUBLIC, false, [$image]);

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNull($this->guard(Role::IDENTIFIED)->check($image));
        $this->assertNotNull($this->guard(Role::CHIEF)->check($image));
    }

    /**
     * Going online needs no re-sync (a reviewer's scenario): a public
     * draft and an online members article share an image; once the draft
     * is published — by the « Publier » intent applied after the save, or
     * by its schedule reaching its time — anonymous readers get it, with
     * nothing saved in between.
     */
    public function testPublishingOpensTheImageWithNothingToResync(): void
    {
        $image = $this->image();
        $draft = $this->saveArticle(Article::VISIBILITY_PUBLIC, false, [$image]);
        $this->saveArticle(Article::VISIBILITY_IDENTIFIED, true, [$image]);
        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNotNull($this->guard(Role::IDENTIFIED)->check($image));

        $this->articles->setPublication($draft, Article::STATUS_PUBLISHED, '2026-01-01 00:00:00');

        $this->assertNotNull($this->guard(Role::PUBLIC)->check($image));
    }

    /**
     * Taken out of the public text, the image follows the staff article
     * still showing it (a reviewer's scenario) — even though its floor was
     * written `public` while both texts named it.
     */
    public function testAnImageRemovedFromThePublicTextFollowsTheArticleStillShowingIt(): void
    {
        $image = $this->image();
        $public = $this->saveArticle(Article::VISIBILITY_PUBLIC, true, [$image]);
        $this->saveArticle(Article::VISIBILITY_CHIEF, true, [$image]);
        $this->assertNotNull($this->guard(Role::PUBLIC)->check($image));

        $this->setText($public, '<p>Plus de photo.</p>');
        BodyImageAccess::forPdo($this->pdo)->syncArticle($public);

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNull($this->guard(Role::IDENTIFIED)->check($image));
        $this->assertNotNull($this->guard(Role::CHIEF)->check($image));
    }

    /**
     * Narrowed with nothing re-synced (a reviewer's scenario): the article
     * moves from public to `chief` and the form save that would re-floor
     * its images fails. The floor still reads `public`, but the request-time
     * check asks the article's own visibility, so an anonymous visitor is
     * refused at once.
     */
    public function testANarrowedArticleClosesItsImagesEvenBeforeAResync(): void
    {
        $image = $this->image();
        $article = $this->saveArticle(Article::VISIBILITY_PUBLIC, true, [$image]);
        $this->assertSame('public', $this->files->findById($image)?->roleMin);

        $this->pdo->prepare('UPDATE news_articles SET visibility = ? WHERE id = ?')
            ->execute([Article::VISIBILITY_CHIEF, $article]);

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNull($this->guard(Role::IDENTIFIED)->check($image));
        $this->assertNotNull($this->guard(Role::CHIEF)->check($image));
    }

    /** An image no text names any more is the news managers' alone. */
    public function testAnImageNoTextNamesIsForTheNewsManagersOnly(): void
    {
        $image = $this->image();
        $public = $this->saveArticle(Article::VISIBILITY_PUBLIC, true, [$image]);
        $this->setText($public, '<p>Plus de photo.</p>');

        $this->assertNull($this->guard(Role::PUBLIC)->check($image));
        $this->assertNotNull($this->guard(Role::CHIEF)->check($image));
    }

    /** With the module off, nothing answers for `news_text_image`: fail-closed. */
    public function testWithTheModuleOffTheImageIsRefused(): void
    {
        $image = $this->image();
        $this->saveArticle(Article::VISIBILITY_PUBLIC, true, [$image]);

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

    /**
     * An article whose text shows $imageIds, saved — its images synced.
     *
     * @param list<int> $imageIds
     */
    private function saveArticle(string $visibility, bool $online, array $imageIds): int
    {
        $id = $this->articles->create('Camp', $visibility, false, null, null, $this->authorId);
        if ($online) {
            $this->articles->setPublication($id, Article::STATUS_PUBLISHED, '2026-01-01 00:00:00');
        }
        $html = '';
        foreach ($imageIds as $imageId) {
            $html .= '<p><img src="' . FilePublicIds::urlFor($imageId) . '"></p>';
        }
        $this->pdo->prepare('INSERT INTO news_forms (news_article_id) VALUES (?)')->execute([$id]);
        $this->pdo->prepare(
            "INSERT INTO news_form_fields (form_id, sort_order, field_type, confirmation_text) VALUES (?, 0, 'text', ?)"
        )->execute([(int) $this->pdo->lastInsertId(), $html]);

        BodyImageAccess::forPdo($this->pdo)->syncArticle($id);

        return $id;
    }

    private function setText(int $articleId, string $html): void
    {
        $this->pdo->prepare(
            'UPDATE news_form_fields SET confirmation_text = ?
             WHERE form_id = (SELECT id FROM news_forms WHERE news_article_id = ?)'
        )->execute([$html, $articleId]);
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
