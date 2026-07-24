<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;
use Modules\News\Service\NewsException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * @group database
 */
class FormServiceTest extends TestCase
{
    private \PDO $pdo;
    private FormService $service;
    private FormFieldRepository $fieldRepository;
    private ArticleRepository $articleRepository;
    private int $articleId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $this->articleRepository = new ArticleRepository($this->pdo);
        $formRepository = new FormRepository($this->pdo);
        $this->fieldRepository = new FormFieldRepository($this->pdo);
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo));
        $articleService = new ArticleService($this->articleRepository, $formRepository, $editableContentService, $shortUrlService);

        $this->service = new FormService($formRepository, $this->fieldRepository, $articleService);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $this->pdo->lastInsertId();
        $this->articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $authorId);
    }

    private function baseSettings(array $overrides = []): array
    {
        return array_merge([
            'access' => NewsForm::ACCESS_IDENTIFIED,
            'response_limit' => NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT,
            'opens_at' => null,
            'closes_at' => null,
            'is_force_closed' => false,
            'response_role_min' => 'chief',
            'daily_digest_enabled' => false,
            'finance_account_id' => null,
        ], $overrides);
    }

    public function testSaveCreatesFormAndFieldsAndMarksArticleHasForm(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => null, 'field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Nom', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);

        $this->assertCount(1, $this->service->getFields($form->id));
        $this->assertTrue($this->articleRepository->findById($this->articleId)->hasForm);
    }

    public function testSaveForcesUnlimitedResponseLimitWhenAccessIsPublic(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(['access' => NewsForm::ACCESS_PUBLIC, 'response_limit' => NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER]), []);

        $this->assertSame(NewsForm::RESPONSE_LIMIT_UNLIMITED, $form->responseLimit);
    }

    public function testSaveRejectsAnUnknownFieldType(): void
    {
        $this->expectException(NewsException::class);
        $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => null, 'field_type' => 'bogus', 'label' => 'x', 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);
    }

    public function testSecondSavePreservesExistingFieldIdWhenIdIsPassed(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => null, 'field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Nom', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);
        $existingFieldId = $this->service->getFields($form->id)[0]->id;

        $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => $existingFieldId, 'field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Nom complet', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);

        $fields = $this->service->getFields($form->id);
        $this->assertCount(1, $fields);
        $this->assertSame($existingFieldId, $fields[0]->id);
        $this->assertSame('Nom complet', $fields[0]->label);
    }

    public function testSecondSaveRemovesFieldsNoLongerPresent(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => null, 'field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Un', 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
            ['id' => null, 'field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Deux', 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);

        $this->service->save($this->articleId, $this->baseSettings(), []);

        $this->assertSame([], $this->service->getFields($form->id));
    }

    public function testReorderFieldsPersistsNewOrder(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => null, 'field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Un', 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
            ['id' => null, 'field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Deux', 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);
        $fields = $this->service->getFields($form->id);

        $this->service->reorderFields($form->id, [$fields[1]->id, $fields[0]->id]);

        $reordered = $this->service->getFields($form->id);
        $this->assertSame('Deux', $reordered[0]->label);
    }

    public function testReorderFieldsRejectsAMismatchedIdSet(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => null, 'field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Un', 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);

        $this->expectException(NewsException::class);
        $this->service->reorderFields($form->id, [999]);
    }

    public function testRemoveFormDeletesFormAndUnmarksHasForm(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), []);

        $this->service->removeForm($form, $this->articleId, null);

        $this->assertNull($this->service->findByArticleId($this->articleId));
        $this->assertFalse($this->articleRepository->findById($this->articleId)->hasForm);
    }
}
