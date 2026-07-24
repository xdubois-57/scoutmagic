<?php

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * @group database
 */
class FormFieldRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private FormFieldRepository $repository;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->repository = new FormFieldRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $this->pdo->lastInsertId();
        $articleId = (new ArticleRepository($this->pdo))->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $authorId);
        $this->formId = (new FormRepository($this->pdo))->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
    }

    public function testCreateAndFindByFormIdOrderedBySortOrder(): void
    {
        $this->repository->create($this->formId, 1, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->repository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 50, 12.5, null);

        $fields = $this->repository->findByFormId($this->formId);

        $this->assertCount(2, $fields);
        $this->assertSame('Places', $fields[0]->label);
        $this->assertSame(50, $fields[0]->capacityMax);
        $this->assertSame(12.5, $fields[0]->pricePerUnit);
        $this->assertSame('Nom', $fields[1]->label);
    }

    public function testManualOptionsSplitsOnNewlines(): void
    {
        $id = $this->repository->create($this->formId, 0, FormField::TYPE_DROPDOWN, 'Jour', false, FormField::OPTIONS_SOURCE_MANUAL, "Lundi\nMardi\n\nMercredi", null, null, null);

        $field = $this->repository->findById($id);

        $this->assertSame(['Lundi', 'Mardi', 'Mercredi'], $field->manualOptions());
    }

    public function testIsPricedIsTrueOnlyForPricedNumberFields(): void
    {
        $priced = $this->repository->findById($this->repository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Repas', false, null, null, null, 5.0, null));
        $unpriced = $this->repository->findById($this->repository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Personnes', false, null, null, null, null, null));
        $textField = $this->repository->findById($this->repository->create($this->formId, 2, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null));

        $this->assertTrue($priced->isPriced());
        $this->assertFalse($unpriced->isPriced());
        $this->assertFalse($textField->isPriced());
    }

    public function testReorderPersistsNewSortOrder(): void
    {
        $id1 = $this->repository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Un', false, null, null, null, null, null);
        $id2 = $this->repository->create($this->formId, 1, FormField::TYPE_SHORT_TEXT, 'Deux', false, null, null, null, null, null);

        $this->repository->reorder([$id2, $id1]);

        $fields = $this->repository->findByFormId($this->formId);
        $this->assertSame('Deux', $fields[0]->label);
        $this->assertSame('Un', $fields[1]->label);
    }

    public function testDeleteByFormIdRemovesAllFields(): void
    {
        $this->repository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Un', false, null, null, null, null, null);
        $this->repository->create($this->formId, 1, FormField::TYPE_SHORT_TEXT, 'Deux', false, null, null, null, null, null);

        $this->repository->deleteByFormId($this->formId);

        $this->assertSame([], $this->repository->findByFormId($this->formId));
    }
}
