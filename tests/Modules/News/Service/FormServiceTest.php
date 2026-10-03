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
use Core\Security\Role;
use Modules\News\Service\NewsException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))));
        $articleService = new ArticleService($this->articleRepository, $formRepository, $editableContentService, $shortUrlService, new \Core\File\FileRepository($this->pdo));

        $this->service = new FormService($formRepository, $this->fieldRepository, $articleService, new \Modules\News\Repository\FormResponseRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat("a", 32), str_repeat("b", 32))));

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
            'digest_email' => null,
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

    public function testSaveSanitizesTextFieldContentAndForcesNoLabelOrRequired(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => null, 'field_type' => FormField::TYPE_TEXT, 'label' => 'Ignoré', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => '<p>Bonjour</p><script>alert(1)</script>'],
        ]);

        $field = $this->service->getFields($form->id)[0];
        $this->assertNull($field->label);
        $this->assertFalse($field->isRequired);
        $this->assertStringContainsString('Bonjour', $field->confirmationText);
        $this->assertStringNotContainsString('<script>', $field->confirmationText);
    }

    public function testTextFieldIsNonInputLikeConfirmation(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), [
            ['id' => null, 'field_type' => FormField::TYPE_TEXT, 'label' => null, 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => '<p>Info</p>'],
        ]);

        $this->assertTrue($this->service->getFields($form->id)[0]->isNonInput());
    }

    // --- IT-01: the « Finance » tab's link ---

    public function testTheReceivablesLinkCarriesTheFormId(): void
    {
        $settings = $this->baseSettings();
        $settings['finance_account_id'] = 3;
        $form = $this->service->save($this->articleId, $settings, []);

        // ResponseService registers its receivables as ('news', $form->id),
        // so `source_reference_id` — what receivables.html.twig compares
        // its focus_id against — is a form id.
        $this->assertSame(
            '/finance/receivables?source=news&id=' . $form->id,
            $this->service->receivablesLinkFor($form, true, Role::CHIEF)
        );
    }

    // ── The digest's address (issue #738) ─────────────────────────────

    /**
     * @return list<array{string, ?string}> the typed value, and what is stored
     */
    public static function acceptedDigestAddresses(): array
    {
        return [
            'an address is kept as typed' => ['intendance@unite.test', 'intendance@unite.test'],
            // The off switch, and the only one there is now: an empty
            // field has to be accepted and stored as nothing, never
            // refused as « missing ».
            'an empty field means nowhere' => ['', null],
            // Trimmed, because a copied address brings its own spaces and
            // « intendance@unite.test » with a trailing one is the same
            // address, not an invalid one.
            'a padded address is trimmed' => ['  intendance@unite.test  ', 'intendance@unite.test'],
            'whitespace alone means nowhere too' => ['   ', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedDigestAddresses')]
    public function testTheDigestAddressIsStoredAsTyped(string $typed, ?string $stored): void
    {
        $form = $this->service->save(
            $this->articleId,
            $this->baseSettings(['digest_email' => $typed]),
            []
        );

        $this->assertSame($stored, $form->digestEmail);
    }

    /**
     * A typo saved in silence is a digest that never arrives and says
     * nothing about why — worse than the refusal, which is why this is
     * refused rather than dropped to null.
     */
    public function testAnInvalidDigestAddressIsRefused(): void
    {
        $this->expectException(NewsException::class);
        $this->expectExceptionMessage('adresse e-mail du résumé quotidien');

        $this->service->save($this->articleId, $this->baseSettings(['digest_email' => 'pas-une-adresse']), []);
    }

    /**
     * An address too long for the column, refused rather than truncated
     * into something that would silently never deliver.
     *
     * The refusal comes from `FILTER_VALIDATE_EMAIL` alone, and that is
     * worth stating because the first version of this code had an
     * explicit `mb_strlen > 255` beside it: the filter enforces RFC
     * 5321's own ceiling, measured at 254 characters here, so the extra
     * guard could never fire and was removed. What this pins is the
     * property — nothing that gets stored can overflow `VARCHAR(255)` —
     * rather than which line enforces it.
     */
    public function testAnAddressTooLongForTheColumnIsRefused(): void
    {
        $this->expectException(NewsException::class);

        $this->service->save(
            $this->articleId,
            // 258 characters, every label short enough to be valid on its
            // own: this is rejected for its LENGTH, not its shape.
            $this->baseSettings(['digest_email' => str_repeat('a', 60) . '@' . str_repeat('cc.', 65) . 'be']),
            []
        );
    }

    /**
     * The other side of that boundary, so the test above is about length
     * and not about « long addresses are suspicious »: 254 characters is
     * the longest the filter accepts, and it has to go through.
     */
    public function testAnAddressAtTheRfcCeilingIsAccepted(): void
    {
        $address = str_repeat('a', 60) . '@' . substr(str_repeat('cc.', 200), 0, 191) . 'be';
        $this->assertSame(254, mb_strlen($address), 'the fixture itself drifted off the boundary');

        $form = $this->service->save($this->articleId, $this->baseSettings(['digest_email' => $address]), []);

        $this->assertSame($address, $form->digestEmail);
    }

    /**
     * The address is the form's own from the moment it is saved: a later
     * save that does not mention it must not resurrect a default, and
     * nothing about the article's author reaches it.
     */
    public function testASecondSaveChangesTheAddressWithoutTouchingAnythingElse(): void
    {
        $this->service->save(
            $this->articleId,
            $this->baseSettings(['digest_email' => 'premiere@unite.test']),
            []
        );

        $form = $this->service->save(
            $this->articleId,
            $this->baseSettings(['digest_email' => 'seconde@unite.test']),
            []
        );

        $this->assertSame('seconde@unite.test', $form->digestEmail);
    }

    /**
     * And clearing it is a real change rather than « no value supplied »:
     * this is how the digest is turned off.
     */
    public function testClearingTheAddressTurnsTheDigestOff(): void
    {
        $this->service->save(
            $this->articleId,
            $this->baseSettings(['digest_email' => 'intendance@unite.test']),
            []
        );

        $form = $this->service->save($this->articleId, $this->baseSettings(['digest_email' => '']), []);

        $this->assertNull($form->digestEmail);
    }

    public function testThereIsNoReceivablesLinkWithoutTheFinanceModule(): void
    {
        $settings = $this->baseSettings();
        $settings['finance_account_id'] = 3;
        $form = $this->service->save($this->articleId, $settings, []);

        $this->assertNull($this->service->receivablesLinkFor($form, false, Role::CHIEF));
    }

    public function testThereIsNoReceivablesLinkWithoutAFinanceAccount(): void
    {
        $form = $this->service->save($this->articleId, $this->baseSettings(), []);

        $this->assertNull($this->service->receivablesLinkFor($form, true, Role::CHIEF));
    }

    public function testThereIsNoReceivablesLinkBelowIntendant(): void
    {
        $settings = $this->baseSettings();
        $settings['finance_account_id'] = 3;
        $form = $this->service->save($this->articleId, $settings, []);

        // The page it opens is role_min: intendant. A tab pointing at a 403
        // is worse than no tab — the floor itself is the boundary.
        $this->assertNull($this->service->receivablesLinkFor($form, true, Role::IDENTIFIED));
        $this->assertNotNull($this->service->receivablesLinkFor($form, true, Role::INTENDANT));
    }

    public function testThereIsNoReceivablesLinkWithoutAForm(): void
    {
        $this->assertNull($this->service->receivablesLinkFor(null, true, Role::ADMIN));
    }
}
