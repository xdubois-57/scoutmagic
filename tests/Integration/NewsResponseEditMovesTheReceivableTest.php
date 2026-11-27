<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Integration;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Mail\MailService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ResponseService;
use Modules\News\Service\ScanService;
use Modules\News\Service\TicketMailService;
use Modules\News\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * Issue #1525, end to end through the REAL finance module: a family pays
 * for one meal, corrects their answer to ten, and at the door the ticket
 * no longer reads « Payé ». Then the other way: corrected down below what
 * they paid, the surplus is a trop-perçu finance shows the treasurer.
 *
 * The news module reaches finance through its Api\ only; finance's own
 * classes appear here because this test builds the composition root's
 * real wiring, as public/index.php does.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class NewsResponseEditMovesTheReceivableTest extends TestCase
{
    private \PDO $pdo;
    private FormResponseRepository $responses;
    private FormFieldRepository $fields;
    private FormRepository $forms;
    private ArticleRepository $articles;
    private TransactionRepository $transactions;
    private ExpectedReceivableService $receivables;
    private TicketService $tickets;
    private Article $article;
    private int $formId;
    private int $fieldId;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->responses = new FormResponseRepository($this->pdo, $encryption);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->forms = new FormRepository($this->pdo);
        $this->articles = new ArticleRepository($this->pdo);
        $this->transactions = new TransactionRepository($this->pdo, $encryption);
        $this->receivables = FinanceTestHelper::receivableService(
            $this->pdo,
            $encryption,
            new ExpectedReceivableRepository($this->pdo, $encryption)
        );
        $this->tickets = new TicketService($this->responses);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Unité', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $categoryId = FinanceTestHelper::createCategory($this->pdo, 'Soupers');
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES ('enc', 'idx')");
        $authorId = (int) $this->pdo->lastInsertId();
        $articleId = NewsTestHelper::publish(
            $this->pdo,
            $this->articles->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $authorId)
        );
        $this->article = $this->articles->findById($articleId);
        $this->formId = $this->forms->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', null, $this->accountId, true, null, null, $categoryId
        );
        $this->fieldId = $this->fields->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, 100, 18.0, null);
    }

    private function responseService(): ResponseService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $twig = \Core\View\TwigFactory::create(
            dirname(__DIR__, 2) . '/core/View/templates',
            false,
            ['news' => dirname(__DIR__, 2) . '/modules/news/views']
        );
        $renderer = EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'news');
        $mail = $this->createStub(MailService::class);

        $financeAccount = $this->createStub(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => $this->accountId, 'name' => 'Unité', 'iban' => 'BE68539007547034', 'holder_name' => 'Unité', 'section_id' => null],
        ]);

        return new ResponseService(
            $this->responses,
            new RoleResolver(new MemberYearRepository($this->pdo), $encryption, $this->pdo),
            new SectionService(
                new SectionRepository($connection),
                new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
            ),
            $mail,
            $renderer,
            new ShortUrlService(new ShortUrlRepository($this->pdo, $encryption)),
            'https://example.com',
            'Test Unit',
            new StructuredCommunicationService(new ExpectedReceivableRepository($this->pdo, $encryption)),
            $this->receivables,
            new SepaQrCodeService(),
            $financeAccount,
            null,
            $this->tickets,
            new TicketMailService($mail, $renderer, 'Test Unit', null),
            $this->fields
        );
    }

    private function scan(): ScanService
    {
        return new ScanService(
            $this->forms, $this->fields, $this->responses, $this->articles, $this->tickets, $this->receivables
        );
    }

    private function answer(string $quantity): FormResponse
    {
        return $this->responseService()->submit(
            $this->article, $this->forms->findById($this->formId), [$this->fields->findById($this->fieldId)],
            null, null, 1, 'famille@test.com', [$this->fieldId => $quantity], null
        );
    }

    private function edit(FormResponse $response, string $quantity): FormResponse
    {
        return $this->responseService()->update(
            $response, $this->forms->findById($this->formId), [$this->fields->findById($this->fieldId)],
            'famille@test.com', [$this->fieldId => $quantity], null, 1, $this->article
        );
    }

    private function pay(FormResponse $response, float $amount): void
    {
        $this->transactions->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01',
            'Virement ' . $response->structuredCommunication, $amount, null, null, 'import', null
        );
    }

    /** @return ?array{amount_due: int, amount_received: int, status: string, receivable_id: int} */
    private function paymentAtTheDoor(FormResponse $response): ?array
    {
        $fresh = $this->responses->findById($response->id);
        \assert($fresh !== null);

        return $this->scan()->verdictForResponse($this->forms->findById($this->formId), $fresh)['payment'];
    }

    public function testAPaidResponseEditedUpwardsIsNoLongerPaidAtTheDoor(): void
    {
        $response = $this->answer('1');
        $this->assertNotNull($response->receivableId);
        $this->pay($response, 18.00);
        $this->assertSame('paid', $this->paymentAtTheDoor($response)['status'] ?? null);

        $edited = $this->edit($response, '10');

        $this->assertSame($response->receivableId, $edited->receivableId, 'the same receivable, moved in place');
        $this->assertSame($response->structuredCommunication, $edited->structuredCommunication);
        $payment = $this->paymentAtTheDoor($edited);
        $this->assertSame(18000, $payment['amount_due'] ?? null);
        $this->assertSame(1800, $payment['amount_received'] ?? null);
        $this->assertNotSame('paid', $payment['status'] ?? null, 'the door must no longer say « Payé »');
        // The ticket follows the new quantities.
        $verdict = $this->scan()->verdictForResponse($this->forms->findById($this->formId), $edited);
        $this->assertSame(10, $verdict['seat_total']);
        // And the family is asked for the difference, not the whole price.
        $summary = $this->responseService()->buildPaymentSummary($this->forms->findById($this->formId), $edited, 180.0);
        $this->assertSame(162.0, $summary['total'] ?? null);
        $this->assertSame(10.0, $this->fields->usedCapacity($this->fieldId));
    }

    public function testAPaidResponseEditedBelowWhatCameInIsATropPercu(): void
    {
        $response = $this->answer('5');
        $this->pay($response, 90.00);

        $edited = $this->edit($response, '2');

        $standing = $this->receivables->getReceivableStanding('news', (int) $edited->receivableId);
        $this->assertNotNull($standing);
        $this->assertSame(3600, $standing->amountDueCents);
        // What finance's « Paiements à traiter › Trop-perçus » lists: the
        // surplus of a receivable, owed back.
        $this->assertSame(5400, $standing->amountOverpaidCents);
        $this->assertSame(5400, $this->responseService()->amountOwedBackCents($edited));

        // The family's confirmation: nothing left to pay, so no payment
        // card — but the new total, what came in and the refund are said.
        $form = $this->forms->findById($this->formId);
        $page = \Tests\TestTwig::create(['news'])->render('@news/confirmation.html.twig', [
            'article' => $this->article,
            'breadcrumb_current' => $this->article->title,
            'response' => $edited,
            'answers' => [],
            'payment' => $this->responseService()->buildPaymentSummary($form, $edited, 36.0),
            'amount_owed_back' => $this->responseService()->amountOwedBackCents($edited),
            'amount_total' => 36.0,
            'amount_received' => $this->responseService()->amountReceivedCents($edited),
            'edit_url' => null,
        ]);
        $text = html_entity_decode(strip_tags($page));
        $this->assertStringContainsString('Montant de votre réponse : 36,00', $text);
        $this->assertStringContainsString('Déjà reçu : 90,00', $text);
        $this->assertStringContainsString('54,00', $text);
        $this->assertStringNotContainsString('Montant à payer', $text);

        // Down to nothing: the receivable stays, at zero, as the
        // overpayment it now is.
        $zero = $this->edit($edited, '0');
        $this->assertSame($response->receivableId, $zero->receivableId);
        $this->assertSame(9000, $this->receivables->getReceivableStanding('news', (int) $zero->receivableId)?->amountOverpaidCents);
    }

    /**
     * Corrected to zero before the bank statement came in: the receivable
     * stays, at zero and with its communication, so the transfer the family
     * already made is matched and reads as a trop-perçu to refund — never
     * money nobody expected. It owes nothing meanwhile, and a later edit
     * back up moves the same receivable.
     */
    public function testAnUnpaidResponseEditedToZeroKeepsItsReceivableForALateTransfer(): void
    {
        $response = $this->answer('2');

        $free = $this->edit($response, '0');
        $this->assertSame($response->receivableId, $free->receivableId);
        $this->assertSame($response->structuredCommunication, $free->structuredCommunication);
        $standing = $this->receivables->getReceivableStanding('news', (int) $free->receivableId);
        $this->assertSame([0, 'paid'], [$standing?->amountDueCents, $standing?->status], 'owes nothing, on no unpaid list');

        $this->pay($response, 36.00);
        $this->assertSame(3600, $this->receivables->getReceivableStanding('news', (int) $free->receivableId)?->amountOverpaidCents);

        $paying = $this->edit($free, '3');
        $this->assertSame($response->receivableId, $paying->receivableId);
        $this->assertSame(5400, $this->receivables->getReceivableStatus((int) $paying->receivableId)['amount_due']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables')->fetchColumn());
    }

    public function testAFreeResponseEditedIntoAPaidOneGainsItsReceivable(): void
    {
        $free = $this->answer('0');
        $this->assertNull($free->receivableId);
        $this->assertNull($this->paymentAtTheDoor($free));

        $paying = $this->edit($free, '3');
        $this->assertNotNull($paying->receivableId);
        $this->assertTrue(StructuredCommunicationService::isValid((string) $paying->structuredCommunication));
        $this->assertSame(5400, $this->receivables->getReceivableStatus((int) $paying->receivableId)['amount_due']);
    }
}
