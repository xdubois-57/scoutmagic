<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Core\Http\Request;
use Core\Security\EncryptionService;
use Modules\News\Controller\TicketQrController;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\TicketQrTokenService;
use Modules\News\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The PNG of one ticket's QR, served to a mail client — the ONLY public
 * route the whole ticketing feature has, and an image rather than a page.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TicketQrControllerTest extends TestCase
{
    private \PDO $pdo;
    private FormResponseRepository $responses;
    private TicketService $tickets;
    private TicketQrTokenService $tokens;
    private TicketQrController $controller;
    private string $reference;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->responses = new FormResponseRepository($this->pdo, $encryption);
        $this->tickets = new TicketService($this->responses);
        $this->tokens = new TicketQrTokenService($encryption);
        $this->controller = new TicketQrController(
            new Environment(new ArrayLoader([])),
            $this->tickets,
            $this->tokens
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com', 'user_accounts.email'), $encryption->blindIndex('chief@test.com', 'email')]);
        $accountId = (int) $this->pdo->lastInsertId();

        $articleId = (new ArticleRepository($this->pdo))->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $accountId);
        $formId = (new FormRepository($this->pdo))->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, true
        );
        $responseId = $this->responses->create($formId, null, null, 'a@test.com', [], null, null);
        $this->reference = $this->tickets->issueFor($this->responses->findById($responseId));
    }

    private function png(string $reference, string $token): \Core\Http\Response
    {
        return $this->controller->png(
            new Request('GET', '/news/qr/' . $reference . '/' . $token, [], [], [], []),
            ['reference' => $reference, 'token' => $token]
        );
    }

    public function testAValidLinkServesThePng(): void
    {
        $response = $this->png($this->reference, $this->tokens->tokenFor($this->reference));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaders()['Content-Type'] ?? null);
        $this->assertStringStartsWith("\x89PNG", $response->getBody());
        // One family's ticket: never a shared cache.
        $this->assertStringContainsString('private', (string) ($response->getHeaders()['Cache-Control'] ?? ''));
    }

    public function testTheGroupedFormOfTheReferenceWorksToo(): void
    {
        // The URL is built from the canonical form, but a link somebody
        // retyped from the mail's printed reference must not 404.
        $formatted = TicketService::format($this->reference);

        $this->assertSame(200, $this->png($formatted, $this->tokens->tokenFor($this->reference))->getStatusCode());
    }

    public function testAWrongTokenIsNotFound(): void
    {
        // The same answer as a reference that does not exist: a wrong
        // token must not tell a prober which references are real.
        $this->assertSame(404, $this->png($this->reference, str_repeat('0', 64))->getStatusCode());
    }

    public function testAnUnknownReferenceIsNotFoundEvenWithItsOwnToken(): void
    {
        $unknown = 'X7K29QMFA3';

        $this->assertSame(404, $this->png($unknown, $this->tokens->tokenFor($unknown))->getStatusCode());
    }

    public function testWhatIsNotAReferenceAtAllIsNotFound(): void
    {
        $this->assertSame(404, $this->png('../../etc/passwd', 'x')->getStatusCode());
        $this->assertSame(404, $this->png('', '')->getStatusCode());
    }

    public function testTheUrlIsAbsoluteOrNothing(): void
    {
        // A relative src in a mail is a broken image, and a broken image
        // is worse than none.
        $this->assertNull($this->tokens->urlFor($this->reference, ''));
        $this->assertSame(
            'https://example.be/news/qr/' . $this->reference . '/' . $this->tokens->tokenFor($this->reference),
            $this->tokens->urlFor($this->reference, 'https://example.be/')
        );
    }

    public function testTheSameTicketAlwaysYieldsTheSameUrl(): void
    {
        // Nothing is stored, and an archived copy of a sent mail must show
        // the image that went out.
        $this->assertSame(
            $this->tokens->urlFor($this->reference, 'https://example.be'),
            $this->tokens->urlFor($this->reference, 'https://example.be')
        );
    }
}
