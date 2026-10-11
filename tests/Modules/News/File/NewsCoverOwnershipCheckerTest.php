<?php

declare(strict_types=1);

namespace Tests\Modules\News\File;

use Core\File\FileRepository;
use Core\Security\Role;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\News\File\NewsCoverOwnershipChecker;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Service\ArticleService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * An article's cover is refused, before publication, to everybody but
 * those who manage the news — decided at each request, so it opens at the
 * instant the article goes online (issue #845).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class NewsCoverOwnershipCheckerTest extends TestCase
{
    private \PDO $pdo;
    private ArticleRepository $articles;
    private ArticleService $service;
    private NewsCoverOwnershipChecker $checker;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->articles = new ArticleRepository($this->pdo);
        $this->service = new ArticleService(
            $this->articles,
            new FormRepository($this->pdo),
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new ShortUrlService(new ShortUrlRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)))),
            new FileRepository($this->pdo)
        );
        $this->checker = new NewsCoverOwnershipChecker($this->articles, $this->service);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testItAnswersForNewsCoversOnly(): void
    {
        $this->assertTrue($this->checker->supports('news_cover'));
        $this->assertFalse($this->checker->supports('section_document'));
    }

    public function testADraftsCoverIsForTheNewsManagersOnly(): void
    {
        $id = $this->articles->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->assertFalse($this->checker->isAllowed($id, Role::PUBLIC, []));
        $this->assertFalse($this->checker->isAllowed($id, Role::IDENTIFIED, []));
        $this->assertTrue($this->checker->isAllowed($id, Role::CHIEF, []));
    }

    public function testAScheduledCoverOpensAtItsTimeWithNothingToFlip(): void
    {
        $id = $this->articles->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->articles->setPublication($id, Article::STATUS_PUBLISHED, '2999-01-01 08:00:00');
        $this->assertFalse($this->checker->isAllowed($id, Role::PUBLIC, []));

        $this->articles->setPublication($id, Article::STATUS_PUBLISHED, (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'));
        $this->assertTrue($this->checker->isAllowed($id, Role::PUBLIC, []));
    }

    public function testACoverWhoseArticleIsGoneIsRefused(): void
    {
        $this->assertFalse($this->checker->isAllowed(999, Role::ADMIN, []));
    }

    /**
     * The article's create() hands its cover to this checker: the file
     * row names the article as its owner.
     */
    public function testTheArticleOwnsItsCoverFromCreation(): void
    {
        $fileId = (new FileRepository($this->pdo))->create('news/images/c.jpg', 'c.jpg', 'image/jpeg', 10, 'public', 'news', $this->authorId);

        $article = $this->service->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', $fileId);

        $owner = $this->pdo->query("SELECT owner_type, owner_id FROM files WHERE id = {$fileId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['owner_type' => 'news_cover', 'owner_id' => $article->id], [
            'owner_type' => $owner['owner_type'],
            'owner_id' => (int) $owner['owner_id'],
        ]);
    }
}
