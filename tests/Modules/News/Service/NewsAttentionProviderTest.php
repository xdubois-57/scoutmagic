<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Attention\AttentionPoint;
use Core\Security\EncryptionService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\NewsAttentionProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * Issue #1525: the responses stored with a negative or fractional
 * quantity, told to the Staff d'U — one point per article, gone once they
 * are corrected.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class NewsAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private FormResponseRepository $responses;
    private int $articleId;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->responses = new FormResponseRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES ('enc', 'idx')");
        $authorId = (int) $this->pdo->lastInsertId();
        $this->articleId = (new ArticleRepository($this->pdo))->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $authorId);
        $this->formId = (new FormRepository($this->pdo))->create(
            $this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null
        );
    }

    public function testNothingIsReportedWhileNoResponseIsMarked(): void
    {
        $this->responses->create($this->formId, null, null, 'a@test.com', [], null, null);

        $this->assertSame([], (new NewsAttentionProvider($this->responses))->collect(1));
    }

    public function testMarkedResponsesMakeOnePointLeadingToTheResponses(): void
    {
        $first = $this->responses->create($this->formId, null, null, 'a@test.com', [], null, null);
        $second = $this->responses->create($this->formId, null, null, 'b@test.com', [], null, null);
        $this->responses->flagInvalidQuantity([$first, $second], '2026-11-27 10:00:00');

        $points = (new NewsAttentionProvider($this->responses))->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('2 réponses au formulaire de « Souper » contiennent une quantité invalide', $points[0]->title);
        $this->assertSame('/news/' . $this->articleId . '/form/responses', $points[0]->actionUrl);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
    }

    public function testThePointGoesOnceTheResponsesAreCorrected(): void
    {
        $id = $this->responses->create($this->formId, null, null, 'a@test.com', [], null, null);
        $this->responses->flagInvalidQuantity([$id], '2026-11-27 10:00:00');
        $this->assertSame(
            '1 réponse au formulaire de « Souper » contient une quantité invalide',
            (new NewsAttentionProvider($this->responses))->collect(1)[0]->title
        );

        $this->responses->update($id, 'a@test.com', []);

        $this->assertSame([], (new NewsAttentionProvider($this->responses))->collect(1));
    }
}
