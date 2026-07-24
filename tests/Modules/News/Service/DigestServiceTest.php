<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\DigestService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @group database
 */
class DigestServiceTest extends TestCase
{
    private \PDO $pdo;
    private FormRepository $formRepository;
    private FormResponseRepository $responseRepository;
    private ArticleRepository $articleRepository;
    private MailService $mailService;
    private int $articleId;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->formRepository = new FormRepository($this->pdo);
        $this->responseRepository = new FormResponseRepository($this->pdo, $encryption);
        $this->articleRepository = new ArticleRepository($this->pdo);
        $this->mailService = $this->createMock(MailService::class);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('author@test.com'), $encryption->blindIndex('author@test.com')]);
        $this->authorId = (int) $this->pdo->lastInsertId();
        $this->articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
    }

    private function service(?MailService $mailService = null): DigestService
    {
        $twig = new Environment(new ArrayLoader([
            '@news/email/digest.html.twig' => 'html',
            '@news/email/digest.text.twig' => 'text',
        ]));

        return new DigestService(
            $this->formRepository, $this->responseRepository, $this->articleRepository,
            new UserAccountRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            $mailService ?? $this->mailService, $twig, 'Test Unit', 'https://example.com'
        );
    }

    public function testSendsNoEmailWhenNoNewResponses(): void
    {
        $this->formRepository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', true, null);

        $this->mailService->expects($this->never())->method('send');

        $this->service()->sendPendingDigests();
    }

    public function testSendsEmailWhenThereAreNewResponses(): void
    {
        $formId = $this->formRepository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', true, null);
        $this->responseRepository->create($formId, null, null, 'parent@test.com', [], null, null);

        $this->mailService->expects($this->once())->method('send')->with('author@test.com');

        $this->service()->sendPendingDigests();
    }

    public function testMarksDigestSentEvenWhenNoResponses(): void
    {
        $formId = $this->formRepository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', true, null);

        $this->service()->sendPendingDigests();

        $this->assertNotNull($this->formRepository->findById($formId)->lastDigestSentAt);
    }

    public function testIgnoresFormsWithDigestDisabled(): void
    {
        $this->formRepository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);

        $this->mailService->expects($this->never())->method('send');

        $this->service()->sendPendingDigests();
    }

    public function testSecondRunOnlyCountsResponsesSinceLastDigest(): void
    {
        $formId = $this->formRepository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', true, null);
        $this->responseRepository->create($formId, null, null, 'first@test.com', [], null, null);

        $this->mailService->expects($this->once())->method('send');
        $this->service()->sendPendingDigests();

        // Second run, no new responses since — no further email.
        $mailService2 = $this->createMock(MailService::class);
        $mailService2->expects($this->never())->method('send');

        $this->service($mailService2)->sendPendingDigests();
    }
}
