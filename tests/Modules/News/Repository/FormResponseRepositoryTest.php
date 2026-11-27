<?php

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Core\Security\EncryptionService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FormResponseRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private FormResponseRepository $repository;
    private FormFieldRepository $fieldRepository;
    private int $formId;
    private int $fieldId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new FormResponseRepository($this->pdo, $encryption);
        $this->fieldRepository = new FormFieldRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $this->pdo->lastInsertId();
        $articleId = (new ArticleRepository($this->pdo))->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $authorId);
        $this->formId = (new FormRepository($this->pdo))->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $this->fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
    }

    public function testCreateAndFindByIdRoundTripsEncryptedContactEmail(): void
    {
        $id = $this->repository->create($this->formId, null, null, 'Parent@Example.com', [$this->fieldId => 'Alice'], null, null);

        $response = $this->repository->findById($id);

        $this->assertSame('parent@example.com', $response->contactEmail);
        $this->assertSame(['Alice'], array_values($this->repository->getValues($id)));
    }

    public function testContactEmailIsEncryptedAtRest(): void
    {
        $id = $this->repository->create($this->formId, null, null, 'secret@example.com', [], null, null);

        $raw = $this->pdo->query('SELECT contact_email FROM news_form_responses WHERE id = ' . $id)->fetchColumn();
        $this->assertStringNotContainsString('secret', (string) $raw);
    }

    public function testAnswerValuesAreEncryptedAtRest(): void
    {
        $id = $this->repository->create($this->formId, null, null, 'a@test.com', [$this->fieldId => 'SecretAnswer'], null, null);

        $raw = $this->pdo->query('SELECT value FROM news_form_response_values WHERE response_id = ' . $id)->fetchColumn();
        $this->assertStringNotContainsString('SecretAnswer', (string) $raw);
    }

    public function testUpdateReplacesContactEmailAndValues(): void
    {
        $id = $this->repository->create($this->formId, null, null, 'old@test.com', [$this->fieldId => 'Old'], null, null);

        $this->repository->update($id, 'new@test.com', [$this->fieldId => 'New']);

        $response = $this->repository->findById($id);
        $this->assertSame('new@test.com', $response->contactEmail);
        $this->assertNotNull($response->updatedAt);
        $this->assertSame(['New'], array_values($this->repository->getValues($id)));
    }

    public function testFindByAccountAndFormReturnsExistingResponse(): void
    {
        $this->repository->create($this->formId, 42, null, 'a@test.com', [], null, null);

        $response = $this->repository->findByAccountAndForm($this->formId, 42);

        $this->assertNotNull($response);
        $this->assertSame(42, $response->userAccountId);
    }

    public function testFindByAccountAndFormReturnsNullWhenNoResponse(): void
    {
        $this->assertNull($this->repository->findByAccountAndForm($this->formId, 999));
    }

    public function testFindByMemberYearAndForm(): void
    {
        $this->repository->create($this->formId, null, 7, 'a@test.com', [], null, null);

        $this->assertNotNull($this->repository->findByMemberYearAndForm($this->formId, 7));
        $this->assertNull($this->repository->findByMemberYearAndForm($this->formId, 8));
    }

    public function testFindAnsweredMemberYearIds(): void
    {
        $this->repository->create($this->formId, null, 7, 'a@test.com', [], null, null);
        $this->repository->create($this->formId, null, 9, 'b@test.com', [], null, null);

        $answered = $this->repository->findAnsweredMemberYearIds($this->formId, [7, 8, 9]);

        sort($answered);
        $this->assertSame([7, 9], $answered);
    }

    public function testSumFieldValuesSumsNumericAnswersAcrossResponses(): void
    {
        $numberFieldId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 50, null, null);

        $this->repository->create($this->formId, null, null, 'a@test.com', [$numberFieldId => '3'], null, null);
        $this->repository->create($this->formId, null, null, 'b@test.com', [$numberFieldId => '4'], null, null);

        $sum = $this->repository->sumFieldValues($numberFieldId);

        $this->assertSame(7.0, $sum);
    }

    public function testSumFieldValuesExcludesGivenResponseId(): void
    {
        $numberFieldId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 50, null, null);

        $id1 = $this->repository->create($this->formId, null, null, 'a@test.com', [$numberFieldId => '3'], null, null);
        $this->repository->create($this->formId, null, null, 'b@test.com', [$numberFieldId => '4'], null, null);

        $sum = $this->repository->sumFieldValues($numberFieldId, excludeResponseId: $id1);

        $this->assertSame(4.0, $sum);
    }

    public function testSetReceivableStoresCommunicationAndId(): void
    {
        $id = $this->repository->create($this->formId, null, null, 'a@test.com', [], null, null);

        $this->repository->setReceivable($id, '+++100/0000/00034+++', 42);

        $response = $this->repository->findById($id);
        $this->assertSame('+++100/0000/00034+++', $response->structuredCommunication);
        $this->assertSame(42, $response->receivableId);
    }

    public function testFindByFormIdSinceReturnsOnlyLaterResponses(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $stmt = $this->pdo->prepare('INSERT INTO news_form_responses (form_id, contact_email, contact_email_blind_index, submitted_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$this->formId, $encryption->encrypt('x@test.com', 'news_form_responses.contact_email'), $encryption->blindIndex('x@test.com', 'news_contact_email'), '2026-01-01 00:00:00']);
        $stmt->execute([$this->formId, $encryption->encrypt('x@test.com', 'news_form_responses.contact_email'), $encryption->blindIndex('x@test.com', 'news_contact_email'), '2026-06-01 00:00:00']);

        $recent = $this->repository->findByFormIdSince($this->formId, '2026-03-01 00:00:00');

        $this->assertCount(1, $recent);
    }

    /**
     * Two responses drawing the same ten characters: the unique index
     * refuses the second, which reads as « not claimed » so the caller
     * draws again — and the second response is left without a reference
     * rather than sharing the first one's.
     */
    public function testAReferenceAlreadyHeldByAnotherResponseIsNotClaimed(): void
    {
        $first = $this->repository->create($this->formId, null, null, 'a@example.com', [], null, null);
        $second = $this->repository->create($this->formId, null, null, 'b@example.com', [], null, null);
        $this->assertTrue($this->repository->claimTicketReference($first, 'K7QW3XPM2R'));

        $this->assertFalse($this->repository->claimTicketReference($second, 'K7QW3XPM2R'));

        $this->assertNull($this->repository->findById($second)?->ticketReference);
        $this->assertSame('K7QW3XPM2R', $this->repository->findById($first)?->ticketReference);
    }

    // --- Issue #1525 ---

    public function testClearReceivableUnlinksTheCommunicationAndTheReceivable(): void
    {
        $id = $this->repository->create($this->formId, null, null, 'a@test.com', [], '+++100/0000/00034+++', 42);

        $this->repository->clearReceivable($id);

        $response = $this->repository->findById($id);
        $this->assertNull($response?->structuredCommunication);
        $this->assertNull($response?->receivableId);
    }

    public function testASumOfAnswersCountsAStoredNegativeAsNothing(): void
    {
        $numberId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 100, null, null);
        $this->repository->create($this->formId, null, null, 'a@test.com', [$numberId => '4'], null, null);
        $this->repository->create($this->formId, null, null, 'b@test.com', [$numberId => '-60'], null, null);

        $this->assertSame(4.0, $this->repository->sumFieldValues($numberId));
    }

    public function testFindNumberAnswersReadsEveryNumberAnswerAndNothingElse(): void
    {
        $numberId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);
        $first = $this->repository->create($this->formId, null, null, 'a@test.com', [$this->fieldId => 'Alice', $numberId => '-8'], null, null);
        $this->repository->create($this->formId, null, null, 'b@test.com', [$this->fieldId => 'Bob', $numberId => ''], null, null);

        $this->assertSame(
            [['response_id' => $first, 'field_id' => $numberId, 'value' => '-8']],
            $this->repository->findNumberAnswers()
        );
    }

    public function testAFlaggedResponseIsCountedPerArticleUntilItIsSavedAgain(): void
    {
        $flagged = $this->repository->create($this->formId, null, null, 'a@test.com', [], null, null);
        $other = $this->repository->create($this->formId, null, null, 'b@test.com', [], null, null);
        $this->repository->create($this->formId, null, null, 'c@test.com', [], null, null);

        $this->repository->flagInvalidQuantity([$flagged, $other], '2026-11-27 10:00:00');
        // Flagging again keeps the first date.
        $this->repository->flagInvalidQuantity([$flagged], '2026-11-28 10:00:00');

        $this->assertSame('2026-11-27 10:00:00', $this->repository->findById($flagged)?->invalidQuantityFlaggedAt);
        $articleId = (int) $this->pdo->query('SELECT news_article_id FROM news_forms')->fetchColumn();
        $this->assertSame(
            [['article_id' => $articleId, 'title' => 'Camp', 'count' => 2]],
            $this->repository->countInvalidQuantitiesByArticle()
        );

        $this->repository->update($flagged, 'a@test.com', []);
        $this->repository->update($other, 'b@test.com', []);

        $this->assertFalse($this->repository->findById($flagged)?->hasInvalidQuantity());
        $this->assertSame([], $this->repository->countInvalidQuantitiesByArticle());
    }

    public function testASumOfAnswersCountsOnlyWholeNumbers(): void
    {
        $numberId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 100, null, null);
        foreach (['2', '1.5', '1e2', ' 3', 'abc'] as $index => $value) {
            $this->repository->create($this->formId, null, null, $index . '@test.com', [$numberId => $value], null, null);
        }

        $this->assertSame(2.0, $this->repository->sumFieldValues($numberId));
    }

    /**
     * One answer that no longer decrypts must not stop the one-off reprise:
     * it would never write its flag, and run again on every request.
     */
    public function testAnUnreadableAnswerIsSkippedBySumsAndReturnedAsNullToTheReprise(): void
    {
        $numberId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 100, null, null);
        $this->repository->create($this->formId, null, null, 'a@test.com', [$numberId => '2'], null, null);
        $broken = $this->repository->create($this->formId, null, null, 'b@test.com', [$numberId => '3'], null, null);
        $this->pdo->prepare('UPDATE news_form_response_values SET value = ? WHERE response_id = ?')->execute(['corrompu', $broken]);

        $this->assertSame(2.0, $this->repository->sumFieldValues($numberId));
        $unreadable = array_values(array_filter(
            $this->repository->findNumberAnswers(),
            static fn (array $answer): bool => $answer['response_id'] === $broken
        ));
        $this->assertSame([['response_id' => $broken, 'field_id' => $numberId, 'value' => null]], $unreadable);
    }

    public function testLockReceivableLinkReadsTheStoredLink(): void
    {
        $id = $this->repository->create($this->formId, null, null, 'a@test.com', [], null, null);
        $this->assertSame(['receivable_id' => null, 'structured_communication' => null], $this->repository->lockReceivableLink($id));

        $this->repository->setReceivable($id, '+++100/0000/00034+++', 42);

        $this->assertSame(
            ['receivable_id' => 42, 'structured_communication' => '+++100/0000/00034+++'],
            $this->repository->lockReceivableLink($id)
        );
    }
}
