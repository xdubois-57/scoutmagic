<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Pdf\DocumentPdfService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\News\Controller\ScanController;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;
use Modules\News\Service\ScanService;
use Modules\News\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;
use Twig\Environment;

/**
 * The door screen, through the REAL templates — so a Twig runtime error
 * fails here rather than in a parish hall at seven in the evening.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScanIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private ArticleRepository $articles;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormResponseRepository $responses;
    private TicketService $tickets;
    private ArticleService $articleService;
    private FormService $formService;
    private JournalService $journalService;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->articles = new ArticleRepository($this->pdo);
        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->responses = new FormResponseRepository($this->pdo, $encryption);
        $this->tickets = new TicketService($this->responses);
        $this->journalService = new JournalService(new JournalRepository($this->pdo));

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo, $encryption));
        $this->articleService = new ArticleService($this->articles, $this->forms, $editableContentService, $shortUrlService);
        $this->formService = new FormService($this->forms, $this->fields, $this->articleService, $this->responses);

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['news' => dirname(__DIR__, 4) . '/modules/news/views']
        );
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'chief');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com', 'user_accounts.email'), $encryption->blindIndex('chief@test.com', 'email')]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->accountId, 'chief@test.com', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function controller(?ExpectedReceivableInterface $receivables = null): ScanController
    {
        return new ScanController(
            $this->twig,
            $this->articleService,
            $this->formService,
            new ScanService(
                $this->forms, $this->fields, $this->responses, $this->articles, $this->tickets,
                $receivables,
                // The same concrete service that writes the transfer
                // payload for the e-mail reads one back for the door.
                new \Modules\Finance\Service\SepaQrCodeService()
            ),
            $this->tickets,
            new DocumentPdfService(),
            $this->journalService,
            'Test Unit'
        );
    }

    /** @return array{0: int, 1: int} article id, form id */
    private function event(string $title, bool $issuesTicket = true, ?string $eventDate = null): array
    {
        $articleId = $this->articles->create($title, Article::VISIBILITY_PUBLIC, true, null, null, $this->accountId);
        $formId = $this->forms->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, $issuesTicket, $eventDate, null
        );

        return [$articleId, $formId];
    }

    private function booking(int $formId, string $name, string $email, int $adults = 1): int
    {
        $nameField = $this->fields->findByFormId($formId)[0] ?? null;
        if ($nameField === null) {
            $this->fields->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
            $this->fields->create($formId, 1, FormField::TYPE_NUMBER, 'Repas adulte', false, null, null, 100, 15.0, null);
            $nameField = $this->fields->findByFormId($formId)[0];
        }
        $quantityField = $this->fields->findByFormId($formId)[1];

        $id = $this->responses->create($formId, null, null, $email, [
            $nameField->id => $name,
            $quantityField->id => (string) $adults,
        ], null, null);
        $this->tickets->issueFor($this->responses->findById($id));

        return $id;
    }

    // --- The generic page ---

    public function testTheGenericPageRedirectsStraightThroughWithASingleEvent(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, 'Roskam', 'a@test.com');

        $response = $this->controller()->index(new Request('GET', '/news/scan', [], [], [], []), []);

        // A unit running one dinner a year should not have to pick it out
        // of a list of one.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/news/scan/' . $formId, $response->getHeaders()['Location'] ?? null);
    }

    public function testTheGenericPageListsTheEventsWhenThereAreSeveral(): void
    {
        [, $first] = $this->event('Souper spaghetti');
        [, $second] = $this->event('Marché de Noël');
        $this->booking($first, 'Roskam', 'a@test.com');
        $this->booking($second, 'Delvaux', 'b@test.com');

        $body = $this->controller()->index(new Request('GET', '/news/scan', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('/news/scan/' . $first, $body);
        $this->assertStringContainsString('/news/scan/' . $second, $body);
    }

    public function testTheGenericPageSaysSoWhenThereIsNothingToControl(): void
    {
        $this->event('Réunion de section', false);

        $body = $this->controller()->index(new Request('GET', '/news/scan', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Aucun évènement à contrôler', $body);
    }

    public function testTheEventSearchAnswersJson(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, 'Roskam', 'a@test.com');

        $response = $this->controller()->searchEvents(new Request('GET', '/news/scan/events', ['q' => 'spag'], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertSame($formId, $data['events'][0]['form_id']);
    }

    // --- The door itself ---

    public function testTheDoorScreenRendersWithItsCountersAndItsOfflineWarning(): void
    {
        [, $formId] = $this->event('Souper spaghetti', true, '2026-03-14');
        $this->booking($formId, 'Roskam', 'a@test.com', 4);
        $this->booking($formId, 'Delvaux', 'b@test.com', 2);

        $body = $this->controller()->event(
            new Request('GET', '/news/scan/' . $formId, [], [], [], []),
            ['form_id' => (string) $formId]
        )->getBody();

        $this->assertStringContainsString('data-sold="6"', $body);
        $this->assertStringContainsString('data-entered="0"', $body);
        // Said on screen rather than discovered in front of a queue.
        $this->assertStringContainsString('Une connexion est nécessaire', $body);
        // The manual entry is never folded behind an « autres options ».
        $this->assertStringContainsString('Référence, nom ou adresse e-mail', $body);
    }

    public function testAFormThatIssuesNoTicketHasNoDoorToHold(): void
    {
        [, $formId] = $this->event('Réunion de section', false);

        $response = $this->controller()->event(
            new Request('GET', '/news/scan/' . $formId, [], [], [], []),
            ['form_id' => (string) $formId]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- The lookup ---

    private function lookup(int $formId, array $query, ?ExpectedReceivableInterface $receivables = null): array
    {
        $response = $this->controller($receivables)->lookup(
            new Request('GET', '/news/scan/' . $formId . '/lookup', $query, [], [], []),
            ['form_id' => (string) $formId]
        );

        return json_decode($response->getBody(), true);
    }

    public function testAReferenceResolvesToItsVerdict(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $responseId = $this->booking($formId, 'Roskam', 'a@test.com', 2);
        $reference = TicketService::format((string) $this->responses->findById($responseId)?->ticketReference);

        $data = $this->lookup($formId, ['q' => $reference]);

        $this->assertSame('valid', $data['verdict']['status']);
        $this->assertSame('Roskam', $data['verdict']['holder']);
        $this->assertSame($reference, $data['verdict']['reference']);
        $this->assertSame(2, $data['verdict']['seat_total']);
    }

    public function testATicketOfAnotherEventIsNamedRatherThanCalledUnknown(): void
    {
        [, $tonight] = $this->event('Souper spaghetti');
        [, $december] = $this->event('Marché de Noël', true, '2026-12-08');
        $this->booking($tonight, 'Roskam', 'a@test.com');
        $responseId = $this->booking($december, 'Delvaux', 'b@test.com');
        $reference = (string) $this->responses->findById($responseId)?->ticketReference;

        $data = $this->lookup($tonight, ['q' => $reference]);

        $this->assertSame('other_event', $data['verdict']['status']);
        $this->assertSame('Marché de Noël', $data['verdict']['article_title']);
    }

    public function testANameMatchingSeveralPeopleAnswersAListToChooseFrom(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, 'Famille Roskam', 'a@test.com');
        $this->booking($formId, 'Famille Delvaux', 'b@test.com');

        $data = $this->lookup($formId, ['q' => 'famille']);

        $this->assertNull($data['verdict']);
        $this->assertCount(2, $data['matches']);
    }

    public function testASingleNameMatchGoesStraightToItsVerdict(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, 'Roskam', 'a@test.com');
        $this->booking($formId, 'Delvaux', 'b@test.com');

        $data = $this->lookup($formId, ['q' => 'roskam']);

        $this->assertSame('valid', $data['verdict']['status']);
        $this->assertSame([], $data['matches']);
    }

    public function testPickingAMatchOfAnotherEventIsRefused(): void
    {
        // A write must never be steerable onto another evening's booking by
        // editing an id in a query string.
        [, $tonight] = $this->event('Souper spaghetti');
        [, $december] = $this->event('Marché de Noël');
        $this->booking($tonight, 'Roskam', 'a@test.com');
        $foreign = $this->booking($december, 'Delvaux', 'b@test.com');

        $data = $this->lookup($tonight, ['response_id' => (string) $foreign]);

        $this->assertSame('not_found', $data['verdict']['status']);
    }

    public function testAnEmptyQueryAnswersNothingAtAll(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, 'Roskam', 'a@test.com');

        $data = $this->lookup($formId, ['q' => '  ']);

        $this->assertNull($data['verdict']);
        $this->assertSame([], $data['matches']);
    }

    // --- The one write ---

    /**
     * `Request::getRawBody()` reads `php://input`, which a test cannot
     * set, so the body is stubbed — the same shape every other JSON
     * endpoint's tests use in this codebase.
     */
    private function jsonRequest(string $path, array $payload): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], $payload, [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn((string) json_encode($payload));

        return $request;
    }

    private function validate(int $formId, int $responseId, bool $used, ?ExpectedReceivableInterface $receivables = null): array
    {
        $payload = [
            'response_id' => $responseId,
            'used' => $used ? '1' : '0',
            '_csrf_token' => CsrfGuard::generateToken(),
        ];
        $request = $this->jsonRequest('/news/scan/' . $formId . '/validate', $payload);

        return json_decode($this->controller($receivables)->validate($request, ['form_id' => (string) $formId])->getBody(), true);
    }

    public function testValidatingMarksTheEntryAndRefreshesTheCounters(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $responseId = $this->booking($formId, 'Roskam', 'a@test.com', 4);
        $this->booking($formId, 'Delvaux', 'b@test.com', 2);

        $data = $this->validate($formId, $responseId, true);

        $this->assertTrue($data['success']);
        $this->assertSame('used', $data['verdict']['status']);
        $this->assertSame(4, $data['counters']['entered']);
        $this->assertSame(2, $data['counters']['expected']);
        $this->assertNotNull($this->responses->findById($responseId)?->ticketUsedAt);
    }

    public function testValidatingNeverTouchesTheReceivable(): void
    {
        // Paying and coming in are two distinct facts. No extra
        // confirmation is asked for on an unpaid ticket either: it would
        // slow the door at the exact moment it must not.
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, 'Roskam', 'a@test.com');
        $responseId = $this->responses->create($formId, null, null, 'c@test.com', [], '+++123/4567/89412+++', 55);
        $this->tickets->issueFor($this->responses->findById($responseId));

        $receivables = $this->createMock(ExpectedReceivableInterface::class);
        $receivables->method('getReceivableStatus')
            ->willReturn(['amount_due' => 3000, 'amount_received' => 0, 'status' => 'unpaid']);
        $receivables->expects($this->never())->method('updateReceivableAmount');
        $receivables->expects($this->never())->method('deleteReceivablesForSource');

        $data = $this->validate($formId, $responseId, true, $receivables);

        $this->assertSame('used', $data['verdict']['status']);
        $this->assertSame('unpaid', $data['verdict']['payment']['status']);
        $after = $this->responses->findById($responseId);
        $this->assertSame(55, $after?->receivableId);
        $this->assertSame('+++123/4567/89412+++', $after?->structuredCommunication);
    }

    public function testUnmarkingTakesTheEntryBack(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $responseId = $this->booking($formId, 'Roskam', 'a@test.com');
        $this->validate($formId, $responseId, true);

        $data = $this->validate($formId, $responseId, false);

        // A scan by mistake, a validation made too early.
        $this->assertSame('valid', $data['verdict']['status']);
        $this->assertNull($this->responses->findById($responseId)?->ticketUsedAt);
    }

    public function testValidatingAnotherEventsBookingIsRefused(): void
    {
        [, $tonight] = $this->event('Souper spaghetti');
        [, $december] = $this->event('Marché de Noël');
        $this->booking($tonight, 'Roskam', 'a@test.com');
        $foreign = $this->booking($december, 'Delvaux', 'b@test.com');

        $data = $this->validate($tonight, $foreign, true);

        $this->assertFalse($data['success']);
        $this->assertNull($this->responses->findById($foreign)?->ticketUsedAt);
    }

    public function testValidatingWithoutACsrfTokenIsRefused(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $responseId = $this->booking($formId, 'Roskam', 'a@test.com');

        $request = $this->jsonRequest('/news/scan/' . $formId . '/validate', ['response_id' => $responseId]);
        $response = $this->controller()->validate($request, ['form_id' => (string) $formId]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->responses->findById($responseId)?->ticketUsedAt);
    }

    public function testTheJournalNamesIdentifiersAndNoPersonalData(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $responseId = $this->booking($formId, 'Roskam', 'famille@example.be');

        $this->validate($formId, $responseId, true);

        $rows = $this->pdo->query('SELECT * FROM event_log ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC);
        $entry = $rows[0];

        $this->assertSame('ticket_validated', $entry['event_type']);
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Roskam', (string) $encoded);
        $this->assertStringNotContainsString('famille@example.be', (string) $encoded);
    }

    // --- The printable fallback ---

    public function testThePrintableListIsAPdf(): void
    {
        [, $formId] = $this->event('Souper spaghetti', true, '2026-03-14');
        $this->booking($formId, 'Roskam', 'a@test.com', 2);

        $response = $this->controller()->printableList(
            new Request('GET', '/news/scan/' . $formId . '/liste', [], [], [], []),
            ['form_id' => (string) $formId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF-', $response->getBody());
        $this->assertSame('application/pdf', $response->getHeaders()['Content-Type'] ?? null);
    }

    // --- IT-04: the scanner accepts two forms ---

    public function testScanningTheTransferCodeAnswersTheTicketAllTheSame(): void
    {
        // Two codes travel in one e-mail. Somebody holds out the wrong
        // one, and the door answers the right ticket rather than an
        // error — which is what allows the payment instructions to be in
        // that e-mail at all.
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, 'Roskam', 'a@test.com');
        $responseId = $this->responses->create($formId, null, null, 'c@test.com', [], '+++123/4567/89412+++', 55);
        $this->tickets->issueFor($this->responses->findById($responseId));

        $payload = \Modules\Finance\Service\EpcPayload::build(
            'Unité SV025', 'BE71096123456769', null, 4600, '+++123/4567/89412+++'
        );
        $data = $this->lookup($formId, ['q' => $payload]);

        $this->assertSame('valid', $data['verdict']['status']);
        $this->assertSame($responseId, $data['verdict']['response_id']);
    }

    public function testTheDoorScreenLoadsTheVendoredReaderAndNothingElseDoes(): void
    {
        [, $formId] = $this->event('Souper spaghetti');
        $this->booking($formId, 'Roskam', 'a@test.com');

        $body = $this->controller()->event(
            new Request('GET', '/news/scan/' . $formId, [], [], [], []),
            ['form_id' => (string) $formId]
        )->getBody();

        // 375 kB, on this page and nowhere else: every other page of the
        // site has no camera.
        $this->assertStringContainsString('/assets/vendor/html5-qrcode/html5-qrcode.min.js', $body);
        $this->assertStringContainsString('/assets/js/news-scan-reader.js', $body);
        // And the manual field stays visible, never folded behind the
        // camera: the scan is the comfort, the validation is the feature.
        $this->assertStringContainsString('id="news-scan-query"', $body);
    }
}
