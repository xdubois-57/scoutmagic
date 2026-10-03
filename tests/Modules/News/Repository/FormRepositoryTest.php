<?php

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FormRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private FormRepository $repository;
    private int $articleId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->repository = new FormRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $this->pdo->lastInsertId();

        $articleRepo = new ArticleRepository($this->pdo);
        $this->articleId = $articleRepo->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $authorId);
    }

    public function testCreateAndFindByArticleId(): void
    {
        $id = $this->repository->create(
            $this->articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT,
            null, null, false, 'chief', null, null
        );

        $form = $this->repository->findByArticleId($this->articleId);

        $this->assertNotNull($form);
        $this->assertSame($id, $form->id);
        $this->assertSame(NewsForm::ACCESS_IDENTIFIED, $form->access);
        $this->assertSame(NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT, $form->responseLimit);
    }

    public function testUpdateChangesFields(): void
    {
        $id = $this->repository->create(
            $this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', null, null
        );

        $this->repository->update($id, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER, '2026-01-01', '2026-02-01', true, 'admin', 'resume@unite.test', 5);

        $form = $this->repository->findById($id);
        $this->assertSame(NewsForm::ACCESS_IDENTIFIED, $form->access);
        $this->assertSame(NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER, $form->responseLimit);
        $this->assertSame('2026-01-01', $form->opensAt);
        $this->assertSame('2026-02-01', $form->closesAt);
        $this->assertTrue($form->isForceClosed);
        $this->assertSame('admin', $form->responseRoleMin);
        $this->assertSame('resume@unite.test', $form->digestEmail);
        $this->assertSame(5, $form->financeAccountId);
    }

    public function testIsOpenWhenNoDatesAndNotForceClosed(): void
    {
        $id = $this->repository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $this->assertTrue($this->repository->findById($id)->isOpen());
    }

    public function testIsOpenIsFalseWhenForceClosed(): void
    {
        $id = $this->repository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', null, null);
        $this->assertFalse($this->repository->findById($id)->isOpen());
    }

    public function testIsOpenIsFalseBeforeOpensAt(): void
    {
        $id = $this->repository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, '2099-01-01', null, false, 'chief', null, null);
        $this->assertFalse($this->repository->findById($id)->isOpen());
    }

    public function testIsOpenIsFalseAfterClosesAt(): void
    {
        $id = $this->repository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, '2000-01-01', false, 'chief', null, null);
        $this->assertFalse($this->repository->findById($id)->isOpen());
    }

    public function testMarkDigestSentUpdatesTimestamp(): void
    {
        $id = $this->repository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', 'resume@unite.test', null);

        $this->repository->markDigestSent($id, '2026-05-01 08:00:00');

        $this->assertSame('2026-05-01 08:00:00', $this->repository->findById($id)->lastDigestSentAt);
    }

    /**
     * The iteration set of the digest task: a form with somewhere to send
     * it, and not the one with nothing (issue #738).
     */
    public function testFindAllWithDigestEmailFiltersCorrectly(): void
    {
        $this->repository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', 'resume@unite.test', null);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc2', 'idx2']);
        $author2 = (int) $this->pdo->lastInsertId();
        $articleRepo = new ArticleRepository($this->pdo);
        $article2 = $articleRepo->create('Autre', Article::VISIBILITY_PUBLIC, false, null, null, $author2);
        $this->repository->create($article2, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

        $forms = $this->repository->findAllWithDigestEmail();

        $this->assertCount(1, $forms);
        $this->assertSame('resume@unite.test', $forms[0]->digestEmail);
    }

    /**
     * An empty string is « nowhere », not an address. The settings form
     * posts one whenever the field is cleared, and a NULL check alone
     * would hand MailService an empty recipient — so the query excludes
     * both, and this is the half a NULL check would miss.
     */
    public function testAFormWhoseAddressWasClearedToAnEmptyStringIsNotInTheSet(): void
    {
        $id = $this->repository->create(
            $this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', '', null
        );

        $this->assertSame('', $this->repository->findById($id)->digestEmail);
        $this->assertSame([], $this->repository->findAllWithDigestEmail());
    }

    public function testDeleteRemovesTheForm(): void
    {
        $id = $this->repository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }
}
