<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Api\ReceivableStanding;
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Api\StructuredCommunicationInterface;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\NewsException;
use Modules\News\Service\ResponseService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class ResponseServiceTest extends TestCase
{
    private \PDO $pdo;
    private FormResponseRepository $responseRepository;
    private FormFieldRepository $fieldRepository;
    private FormRepository $formRepository;
    private MailService $mailService;
    private Article $article;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->responseRepository = new FormResponseRepository($this->pdo, $encryption);
        $this->fieldRepository = new FormFieldRepository($this->pdo);
        $this->formRepository = new FormRepository($this->pdo);
        $this->mailService = $this->createMock(MailService::class);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $this->pdo->lastInsertId();
        $articleId = (new ArticleRepository($this->pdo))->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $authorId);
        $this->article = (new ArticleRepository($this->pdo))->findById($articleId);
        $this->formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
    }

    private function service(
        ?StructuredCommunicationInterface $structuredCommunication = null,
        ?ExpectedReceivableInterface $expectedReceivable = null,
        ?SepaQrCodeInterface $sepaQrCode = null,
        ?FinanceAccountInterface $financeAccount = null,
        ?JournalService $journalService = null,
        ?\Modules\Calendar\Api\IcsFeedBuilderInterface $icsBuilder = null
    ): ResponseService {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $roleResolver = new RoleResolver(new MemberYearRepository($this->pdo), $encryption, $this->pdo);
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
);
        // The real bodies, not stubs: whether the ticket block renders at
        // all is half of what these tests are about.
        $twig = \Core\View\TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['news' => dirname(__DIR__, 4) . '/modules/news/views']
        );
        $twig->addGlobal('site_name', 'Test Unit');
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))));

        $renderer = EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'news');

        return new ResponseService(
            $this->responseRepository, $roleResolver, $sectionService,
            $this->mailService, $renderer, $shortUrlService, 'https://example.com', 'Test Unit',
            $structuredCommunication, $expectedReceivable, $sepaQrCode, $financeAccount, $journalService,
            new \Modules\News\Service\TicketService($this->responseRepository),
            new \Modules\News\Service\TicketMailService($this->mailService, $renderer, 'Test Unit', $icsBuilder),
            // Where the running capacity total lives since #241 — without
            // it the service falls back on summing the whole column, which
            // is exactly what this dependency exists to stop doing.
            $this->fieldRepository
        );
    }

    private function form(): NewsForm
    {
        return $this->formRepository->findById($this->formId);
    }

    public function testComputeTotalSumsOnlyPricedFields(): void
    {
        $priced = $this->fieldRepository->findById($this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Repas', false, null, null, null, 5.0, null));
        $unpriced = $this->fieldRepository->findById($this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Personnes', false, null, null, null, null, null));

        $total = $this->service()->computeTotal([$priced, $unpriced], [$priced->id => '3', $unpriced->id => '10']);

        $this->assertSame(15.0, $total);
    }

    public function testRemainingCapacityReturnsNullWithNoCap(): void
    {
        $field = $this->fieldRepository->findById($this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, null, null, null));
        $this->assertNull($this->service()->remainingCapacity($field));
    }

    public function testRemainingCapacitySubtractsExistingSum(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 10, null, null);
        // Through the service, because since #241 the running total is
        // kept by the SUBMISSION: a row written straight into the
        // repository is a row the application itself cannot produce, and
        // the reprise (recomputeUsedCapacities()) is what exists for the
        // rows that predate the column.
        $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => '3'], null);

        $field = $this->fieldRepository->findById($fieldId);
        $this->assertSame(7, $this->service()->remainingCapacity($field));
    }

    /**
     * #241. The remaining capacity used to be recomputed on every read —
     * one query over the whole column of answers for that field, each of
     * them decrypted — on the ordinary path of DISPLAYING the form. The
     * submissions already computed that sum under a row lock; they keep
     * it now, and the display reads it off the field.
     */
    public function testTheRunningTotalIsKeptByTheWritesAndReadWithoutDecrypting(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Couchages', true, null, null, 20, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com',
            [$fieldId => '5'], null);
        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'b@test.com',
            [$fieldId => '3'], null);

        $this->assertSame(8.0, $this->fieldRepository->usedCapacity($fieldId));
        $this->assertSame(12, $this->service()->remainingCapacity($this->fieldRepository->findById($fieldId)));

        // An edit gives its own share back before taking the new one.
        $response = $this->responseRepository->findByFormId($this->formId)[0];
        $this->service()->update($response, $this->form(), [$field], 'a@test.com', [$fieldId => '2'], null, 1);

        $this->assertSame(5.0, $this->fieldRepository->usedCapacity($fieldId));
        $this->assertSame(15, $this->service()->remainingCapacity($this->fieldRepository->findById($fieldId)));
    }

    /**
     * And the reprise for the rows written before the column existed: the
     * answers themselves are the source of truth it is rebuilt from.
     */
    public function testTheRunningTotalCanBeRecomputedFromTheAnswers(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Couchages', true, null, null, 20, null, null);
        $this->responseRepository->create($this->formId, null, null, 'a@test.com', [$fieldId => '4'], null, null);
        $this->responseRepository->create($this->formId, null, null, 'b@test.com', [$fieldId => '6'], null, null);

        $this->assertSame(0.0, $this->fieldRepository->usedCapacity($fieldId), 'the responses were written under the service');
        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);

        $this->assertSame(10.0, $this->fieldRepository->usedCapacity($fieldId));
    }

    public function testSubmitCreatesResponseAndSendsEmail(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        // The acknowledgement goes to the address the form carried, and
        // that is the assertion: a count of one is equally happy with a
        // copy sent to the article's author, or to the previous responder
        // (issue #439).
        $this->mailService->expects($this->once())->method('send')
            ->with($this->identicalTo('parent@test.com'));

        $response = $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'parent@test.com', [$fieldId => 'Alice'], null);

        $this->assertSame('parent@test.com', $response->contactEmail);
    }

    public function testSubmitIgnoresTextFieldsEntirely(): void
    {
        $shortTextId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $textId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_TEXT, null, false, null, null, null, null, '<p>Instructions</p>');
        $fields = [$this->fieldRepository->findById($shortTextId), $this->fieldRepository->findById($textId)];

        $response = $this->service()->submit($this->article, $this->form(), $fields, null, null, 1, 'a@test.com', [$shortTextId => 'Alice', $textId => 'should be ignored'], null);

        $answers = $this->service()->getAnswers($response->id);
        $this->assertArrayNotHasKey($textId, $answers);
        $this->assertSame('Alice', $answers[$shortTextId]);
    }

    public function testSubmitStillReturnsTheResponseWhenTheConfirmationEmailFailsToSend(): void
    {
        // Reproduces the real-world failure: MailService::send() throws
        // MailException (e.g. the site's SMTP "from" address isn't
        // configured yet). The response was already committed and must
        // not be lost behind an uncaught fatal error.
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $this->mailService->method('send')->willThrowException(new MailException('Invalid address: (From)'));

        $response = $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'parent@test.com', [$fieldId => 'Alice'], null);

        $this->assertSame('parent@test.com', $response->contactEmail);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
    }

    public function testSubmitJournalsTheFailureWhenTheConfirmationEmailFailsToSend(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);
        $this->mailService->method('send')->willThrowException(new MailException('Invalid address: (From)'));

        $journalService = $this->createMock(JournalService::class);
        $journalService->expects($this->once())->method('log')
            ->with('news', 'confirmation_email_failed', 'error', $this->anything(), $this->anything(), null);

        $this->service(journalService: $journalService)
            ->submit($this->article, $this->form(), [$field], null, null, 1, 'parent@test.com', [$fieldId => 'Alice'], null);
    }

    public function testSubmitRejectsWhenFormIsClosed(): void
    {
        $this->formRepository->update($this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', null, null);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [], null, null, 1, 'a@test.com', [], null);
    }

    public function testSubmitRejectsAnonymousWhenAccessIsIdentified(): void
    {
        $this->formRepository->update($this->formId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [], null, null, 1, 'a@test.com', [], null);
    }

    public function testSubmitRejectsMissingRequiredField(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => ''], null);
    }

    public function testSubmitRejectsMalformedEmailAnswer(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_EMAIL, 'Email des parents', true, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => 'pas-un-email'], null);
    }

    public function testSubmitRejectsMalformedPhoneAnswer(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_PHONE, 'Téléphone', true, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => 'abc'], null);
    }

    public function testSubmitAcceptsAValidPhoneAnswer(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_PHONE, 'Téléphone', true, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $response = $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => '+32 470 12 34 56'], null);

        $this->assertNotNull($response->id);
    }

    public function testSubmitRejectsInvalidDropdownOption(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_DROPDOWN, 'Jour', true, FormField::OPTIONS_SOURCE_MANUAL, "Lundi\nMardi", null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => 'Jeudi'], null);
    }

    public function testSubmitRejectsWhenCapacityWouldBeExceeded(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 5, null, null);
        $field = $this->fieldRepository->findById($fieldId);
        $this->responseRepository->create($this->formId, null, null, 'x@test.com', [$fieldId => '4'], null, null);
        // Written straight into the repository, so the running total
        // (#241) is rebuilt from the answers — the same reprise an
        // installation runs for the rows that predate the column.
        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => '2'], null);
    }

    public function testSubmitAllowsExactlyReachingCapacity(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 5, null, null);
        $field = $this->fieldRepository->findById($fieldId);
        $this->responseRepository->create($this->formId, null, null, 'x@test.com', [$fieldId => '3'], null, null);

        $response = $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => '2'], null);

        $this->assertNotNull($response);
    }

    public function testSubmitAcceptsAnEmptyRequiredFieldWhoseCapacityIsExhausted(): void
    {
        // Once a required capped field is full, the template renders it
        // without an input at all ("Complet") — the browser submits
        // nothing for it. The server must not reject the whole
        // submission as « obligatoire » in that case, otherwise nobody
        // can submit anymore once the first quota is gone.
        $placesId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places bus', true, null, null, 5, null, null);
        $nameId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($this->formId, null, null, 'x@test.com', [$placesId => '5'], null, null);
        // Written straight into the repository, so the running total
        // (#241) is rebuilt from the answers — the same reprise an
        // installation runs for the rows that predate the column.
        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);
        $fields = [$this->fieldRepository->findById($placesId), $this->fieldRepository->findById($nameId)];

        $response = $this->service()->submit($this->article, $this->form(), $fields, null, null, 1, 'a@test.com', [$placesId => '', $nameId => 'Alice'], null);

        $this->assertSame('Alice', $this->service()->getAnswers($response->id)[$nameId]);
    }

    public function testSubmitStillRequiresACappedFieldWithRemainingCapacity(): void
    {
        $placesId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places bus', true, null, null, 5, null, null);
        $this->responseRepository->create($this->formId, null, null, 'x@test.com', [$placesId => '3'], null, null);
        // Written straight into the repository, so the running total
        // (#241) is rebuilt from the answers — the same reprise an
        // installation runs for the rows that predate the column.
        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);
        $field = $this->fieldRepository->findById($placesId);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$placesId => ''], null);
    }

    public function testUpdateAcceptsAnEmptyRequiredFieldWhoseCapacityIsExhaustedByOthers(): void
    {
        // Editing counts against the same cap it already consumed (the
        // response's own value is "returned to the pool"), so exhaustion
        // is only exhaustion when OTHER responses ate the whole cap.
        $placesId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places bus', true, null, null, 5, null, null);
        $nameId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($this->formId, null, null, 'x@test.com', [$placesId => '5'], null, null);
        // Written straight into the repository, so the running total
        // (#241) is rebuilt from the answers — the same reprise an
        // installation runs for the rows that predate the column.
        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);
        $ownId = $this->responseRepository->create($this->formId, 42, null, 'me@test.com', [$nameId => 'Old'], null, null);
        $own = $this->responseRepository->findById($ownId);
        $fields = [$this->fieldRepository->findById($placesId), $this->fieldRepository->findById($nameId)];

        $updated = $this->service()->update($own, $this->form(), $fields, 'me@test.com', [$placesId => '', $nameId => 'New'], null, 1);

        $this->assertSame('New', $this->service()->getAnswers($updated->id)[$nameId]);
    }

    public function testSubmitWithPaymentCreatesReceivableAndStoresCommunication(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Repas', false, null, null, null, 5.0, null);
        $field = $this->fieldRepository->findById($fieldId);
        $this->formRepository->update(
            $this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, 7,
            financeCategoryId: 9
        );

        $structuredCommunication = $this->createStub(StructuredCommunicationInterface::class);
        $structuredCommunication->method('generate')->willReturn('+++100/0000/00034+++');

        $expectedReceivable = $this->createMock(ExpectedReceivableInterface::class);
        $expectedReceivable->expects($this->once())->method('createReceivable')
            ->with('news', $this->formId, 7, 1000, '+++100/0000/00034+++', 'a@test.com', 9)
            ->willReturn(55);

        $sepaQrCode = $this->createStub(SepaQrCodeInterface::class);
        $sepaQrCode->method('generatePng')->willReturn('png-bytes');

        $financeAccount = $this->createStub(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => 7, 'name' => 'Compte', 'iban' => 'BE68539007547034', 'holder_name' => 'Unité', 'section_id' => null],
        ]);

        $response = $this->service($structuredCommunication, $expectedReceivable, $sepaQrCode, $financeAccount)
            ->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => '2'], null);

        $this->assertSame('+++100/0000/00034+++', $response->structuredCommunication);
        $this->assertSame(55, $response->receivableId);
    }

    /**
     * A form whose Finances category was archived since it was chosen:
     * the respondent gets a sentence meant for them, not a 500, and no
     * half-saved answer (issue #838).
     */
    public function testSubmitTellsTheRespondentWhenTheFormCanNoLongerTakePayment(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Repas', false, null, null, null, 5.0, null);
        $field = $this->fieldRepository->findById($fieldId);
        $this->formRepository->update(
            $this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, 7,
            financeCategoryId: 9
        );

        $structuredCommunication = $this->createStub(StructuredCommunicationInterface::class);
        $structuredCommunication->method('generate')->willReturn('+++100/0000/00034+++');
        $expectedReceivable = $this->createStub(ExpectedReceivableInterface::class);
        $expectedReceivable->method('createReceivable')
            ->willThrowException(new FinanceException('Choisissez une catégorie Finances active pour cette créance.'));
        $financeAccount = $this->createStub(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => 7, 'name' => 'Compte', 'iban' => 'BE68539007547034', 'holder_name' => 'Unité', 'section_id' => null],
        ]);

        try {
            $this->service($structuredCommunication, $expectedReceivable, $this->createStub(SepaQrCodeInterface::class), $financeAccount)
                ->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => '2'], null);
            $this->fail('A form that can no longer take payment must refuse the answer.');
        } catch (NewsException $e) {
            $this->assertStringContainsString('prévenez son responsable', $e->getMessage());
            $this->assertInstanceOf(FinanceException::class, $e->getPrevious());
        }

        $this->assertSame([], $this->responseRepository->findByFormId($this->formId));
    }

    // --- IT-02: the ticket ---

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeFormTicketed(bool $issuesTicket, ?string $eventDate = null, ?string $eventLocation = null): void
    {
        $this->formRepository->update(
            $this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', null, null, $issuesTicket, $eventDate, $eventLocation
        );
    }

    public function testSubmittingToATicketedFormIssuesAReference(): void
    {
        $this->makeFormTicketed(true);
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);

        $response = $this->service()->submit(
            $this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => 'Roskam'], null
        );

        $this->assertTrue($this->responseRepository->findById($response->id)?->hasTicket());
    }

    public function testSubmittingToAFormThatIssuesNoTicketLeavesTheReferenceEmpty(): void
    {
        // The flag is the whole switch: with it down, nothing changes.
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);

        $response = $this->service()->submit(
            $this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => 'Roskam'], null
        );

        $this->assertFalse($this->responseRepository->findById($response->id)?->hasTicket());
    }

    /**
     * A ticket reference the database will not store, however many are
     * drawn, costs the sign-up nothing: the response stays committed, the
     * confirmation still goes to the address given, and the journal says
     * the ticket was not issued — naming the response, never the person.
     */
    public function testAReferenceThatCannotBeStoredLeavesTheResponseAndJournalsIt(): void
    {
        $this->makeFormTicketed(true);
        $fieldId = $this->fieldRepository->create(
            $this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null
        );
        $this->pdo->exec(
            "CREATE TRIGGER refuse_ticket_reference BEFORE UPDATE OF ticket_reference ON news_form_responses
             BEGIN SELECT RAISE(ABORT, 'ticket references are read-only'); END"
        );
        $sentTo = [];
        $this->mailService->method('send')->willReturnCallback(
            static function (string $to) use (&$sentTo): void {
                $sentTo[] = $to;
            }
        );
        $journal = new JournalService(new \Core\Journal\JournalRepository($this->pdo));

        $response = $this->service(journalService: $journal)->submit(
            $this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => 'Roskam'], null
        );

        $this->assertFalse($this->responseRepository->findById($response->id)?->hasTicket());
        $this->assertSame(['Roskam'], array_values($this->responseRepository->getValues($response->id)));
        $this->assertSame(['a@test.com'], $sentTo);
        $stmt = $this->pdo->prepare('SELECT description, context FROM event_log WHERE event_type = ?');
        $stmt->execute(['ticket_reference_failed']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame(
            'Échec de la génération de la référence de billet pour une réponse de formulaire',
            $rows[0]['description']
        );
        $this->assertSame(
            ['article_id' => $this->article->id, 'form_id' => $this->formId, 'response_id' => $response->id],
            json_decode((string) $rows[0]['context'], true)
        );
    }

    public function testTheConfirmationCarriesTheReferenceInPlainTextAndItsQr(): void
    {
        $this->makeFormTicketed(true);
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);

        $sentHtml = null;
        $sentText = null;
        $this->mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $html, string $text) use (&$sentHtml, &$sentText): void {
                $sentHtml = $html;
                $sentText = $text;
            }
        );

        $response = $this->service()->submit(
            $this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => 'Roskam'], null
        );

        $reference = \Modules\News\Service\TicketService::format(
            (string) $this->responseRepository->findById($response->id)?->ticketReference
        );

        $this->assertStringContainsString($reference, (string) $sentHtml);
        $this->assertStringContainsString('data:image/png;base64,', (string) $sentHtml);
        // Most mail clients block images by default, so the plain-text
        // half must be able to get somebody through the door on its own.
        $this->assertStringContainsString($reference, (string) $sentText);
    }

    public function testTheConfirmationOfANonTicketedFormCarriesNoTicketBlock(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);

        $sentHtml = null;
        $this->mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $html) use (&$sentHtml): void {
                $sentHtml = $html;
            }
        );

        $this->service()->submit(
            $this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => 'Roskam'], null
        );

        $this->assertStringNotContainsString('le code à présenter à l\'entrée', (string) $sentHtml);
    }

    public function testAnEventWithADateSendsAnIcsAndOneWithoutDoesNot(): void
    {
        $icsBuilder = $this->createStub(\Modules\Calendar\Api\IcsFeedBuilderInterface::class);
        $icsBuilder->method('buildVirtualCalendar')->willReturn("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

        $attachments = [];
        $this->mailService->method('send')->willReturnCallback(
            function (string $to, string $s, string $h, string $t, ?string $r = null, array $a = []) use (&$attachments): void {
                $attachments[] = $a;
            }
        );

        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $this->makeFormTicketed(true, '2026-03-14', 'Salle paroissiale');
        $this->service(null, null, null, null, null, $icsBuilder)
            ->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => 'Roskam'], null);

        $this->assertCount(1, $attachments[0], 'a dated event carries its calendar file');
        $this->assertSame('evenement.ics', $attachments[0][0]['name']);
        $this->assertFileDoesNotExist($attachments[0][0]['path'], 'the temp file is deleted once the mail is out');

        // No date, no calendar entry to make — and the message is
        // otherwise unchanged. A perfectly usable degraded mode.
        $this->makeFormTicketed(true);
        $this->service(null, null, null, null, null, $icsBuilder)
            ->submit($this->article, $this->form(), [$field], null, null, 1, 'b@test.com', [$fieldId => 'Delvaux'], null);

        $this->assertSame([], $attachments[1]);
    }

    public function testTheEventDateAndPlaceAreWrittenIntoTheConfirmation(): void
    {
        $this->makeFormTicketed(true, '2026-03-14', 'Salle paroissiale');
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);

        $sentText = null;
        $this->mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $html, string $text) use (&$sentText): void {
                $sentText = $text;
            }
        );

        $this->service()->submit(
            $this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => 'Roskam'], null
        );

        // A ticket forwarded to a friend who never read the article has to
        // be self-contained.
        $this->assertStringContainsString('14/03/2026', (string) $sentText);
        $this->assertStringContainsString('Salle paroissiale', (string) $sentText);
    }

    public function testCanEditResponseAllowsAdminAlways(): void
    {
        $id = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [], null, null);
        $response = $this->responseRepository->findById($id);
        $this->formRepository->update($this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', null, null);

        $this->assertTrue($this->service()->canEditResponse($response, $this->form(), Role::ADMIN, 999));
    }

    public function testCanEditResponseAllowsOwnerOnlyWhileFormIsOpen(): void
    {
        $id = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [], null, null);
        $response = $this->responseRepository->findById($id);

        $this->assertTrue($this->service()->canEditResponse($response, $this->form(), Role::IDENTIFIED, 42));

        $this->formRepository->update($this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', null, null);
        $this->assertFalse($this->service()->canEditResponse($response, $this->form(), Role::IDENTIFIED, 42));
    }

    public function testCanEditResponseRejectsAnonymousResponse(): void
    {
        $id = $this->responseRepository->create($this->formId, null, null, 'a@test.com', [], null, null);
        $response = $this->responseRepository->findById($id);

        $this->assertFalse($this->service()->canEditResponse($response, $this->form(), Role::IDENTIFIED, 42));
    }

    public function testCanEditResponseRejectsADifferentAccount(): void
    {
        $id = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [], null, null);
        $response = $this->responseRepository->findById($id);

        $this->assertFalse($this->service()->canEditResponse($response, $this->form(), Role::IDENTIFIED, 43));
    }

    public function testUpdateReturnsResponseWithNewValues(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);
        $id = $this->responseRepository->create($this->formId, 42, null, 'old@test.com', [$fieldId => 'Old'], null, null);
        $response = $this->responseRepository->findById($id);

        $updated = $this->service()->update($response, $this->form(), [$field], 'new@test.com', [$fieldId => 'New'], null, 1);

        $this->assertSame('new@test.com', $updated->contactEmail);
        $this->assertSame('New', $this->service()->getAnswers($updated->id)[$fieldId]);
    }

    // --- Issue #1525: quantities, and what an edit owes ---

    /** @return array<string, array{0: string}> */
    public static function refusedQuantities(): array
    {
        return [
            'a negative' => ['-1'],
            'a fraction' => ['0.5'],
            'an exponent' => ['1e2'],
            'a leading space' => [' 3'],
            'a sign' => ['+3'],
            'a hexadecimal' => ['0x1A'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedQuantities')]
    public function testAPricedQuantityRefusesAnythingButAWholeNumberOnSubmission(string $value): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);

        try {
            $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
                null, null, 1, 'a@test.com', [$fieldId => $value], null);
            $this->fail('« ' . $value . ' » must be refused on a priced field.');
        } catch (NewsException $e) {
            $this->assertStringContainsString('"Adultes"', $e->getMessage());
        }

        $this->assertSame([], $this->responseRepository->findByFormId($this->formId));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedQuantities')]
    public function testACappedQuantityRefusesAnythingButAWholeNumberOnSubmission(string $value): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 100, null, null);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => $value], null);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedQuantities')]
    public function testAQuantityRefusesAnythingButAWholeNumberOnAnEdit(string $value): void
    {
        $pricedId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);
        $cappedId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 100, null, null);
        $fields = [$this->fieldRepository->findById($pricedId), $this->fieldRepository->findById($cappedId)];
        $response = $this->service()->submit($this->article, $this->form(), $fields, null, null, 1, 'a@test.com',
            [$pricedId => '1', $cappedId => '1'], null);

        foreach ([[$pricedId => $value, $cappedId => '1'], [$pricedId => '1', $cappedId => $value]] as $answers) {
            try {
                $this->service()->update($response, $this->form(), $fields, 'a@test.com', $answers, null, 1);
                $this->fail('« ' . $value . ' » must be refused on an edit too.');
            } catch (NewsException) {
            }
        }

        $this->assertSame([$pricedId => '1', $cappedId => '1'], $this->service()->getAnswers($response->id));
        $this->assertSame(1.0, $this->fieldRepository->usedCapacity($cappedId));
    }

    public function testAPlainNumberFieldStillTakesADecimalButNeverANegative(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Pointure', false, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $response = $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => '37.5'], null);
        $this->assertSame('37.5', $this->service()->getAnswers($response->id)[$fieldId]);

        $this->expectException(NewsException::class);
        $this->expectExceptionMessage('Le champ "Pointure" ne peut pas être négatif.');
        $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'b@test.com', [$fieldId => '-2'], null);
    }

    public function testAQuantityIsStoredInItsCanonicalForm(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);

        $response = $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => '007'], null);

        $this->assertSame('7', $this->service()->getAnswers($response->id)[$fieldId]);
    }

    /**
     * An uncapped priced field is bounded per response: `1e5` once put
     * 1 800 000 € « à recevoir » for an 18 € meal.
     */
    public function testAnUncappedQuantityIsBoundedPerResponse(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);
        $field = $this->fieldRepository->findById($fieldId);

        $response = $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com',
            [$fieldId => (string) ResponseService::MAX_QUANTITY_PER_RESPONSE], null);
        $this->assertSame('999', $this->service()->getAnswers($response->id)[$fieldId]);

        foreach (['1000', '100000', '99999999999999999999999'] as $value) {
            try {
                $this->service()->submit($this->article, $this->form(), [$field], null, null, 1, 'b@test.com', [$fieldId => $value], null);
                $this->fail($value . ' must be refused.');
            } catch (NewsException $e) {
                $this->assertSame('Le champ "Adultes" ne peut pas dépasser 999 par réponse.', $e->getMessage());
            }
        }
    }

    /** A capped field is bounded by its capacity, with the capacity's own sentence. */
    public function testACappedQuantityAboveItsCapacityIsRefusedAsFull(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 100, null, null);

        $this->expectException(NewsException::class);
        $this->expectExceptionMessage('Il n\'y a plus assez de places disponibles pour "Places".');
        $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => '100000'], null);
    }

    /**
     * « Adultes = −60 » on a field capped at 100 once took the running
     * total down by 60, and sixty places were sold twice.
     */
    public function testCapacityUsedNeverDecreasesAsTheResultOfASubmission(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, 100, 18.0, null);
        $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'a@test.com', [$fieldId => '4'], null);

        try {
            $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
                null, null, 1, 'b@test.com', [$fieldId => '-60'], null);
            $this->fail('A negative must be refused.');
        } catch (NewsException) {
        }
        $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, 'c@test.com', [$fieldId => '0'], null);

        $this->assertSame(4.0, $this->fieldRepository->usedCapacity($fieldId));
    }

    /**
     * And a negative already stored, from before: the reprise gives no
     * place back for it.
     */
    public function testTheRecomputedTotalCountsAStoredNegativeAsNothing(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, 100, 18.0, null);
        $this->responseRepository->create($this->formId, null, null, 'a@test.com', [$fieldId => '4'], null, null);
        $this->responseRepository->create($this->formId, null, null, 'b@test.com', [$fieldId => '-60'], null, null);

        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);

        $this->assertSame(4.0, $this->fieldRepository->usedCapacity($fieldId));
        $this->assertSame(96, $this->service()->remainingCapacity($this->fieldRepository->findById($fieldId)));
    }

    /**
     * Whole quantities can still add up below zero through a negative unit
     * price (a « réduction » field): a total owed TO the respondent is not
     * something a form creates.
     */
    public function testANegativeTotalIsRefusedOnSubmissionAndOnAnEdit(): void
    {
        $mealId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Repas', false, null, null, null, 9.0, null);
        $discountId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Réduction', false, null, null, null, -5.0, null);
        $fields = [$this->fieldRepository->findById($mealId), $this->fieldRepository->findById($discountId)];

        try {
            $this->service()->submit($this->article, $this->form(), $fields, null, null, 1, 'a@test.com',
                [$mealId => '1', $discountId => '2'], null);
            $this->fail('A negative total must be refused.');
        } catch (NewsException $e) {
            $this->assertStringContainsString('ne peut pas être négatif', $e->getMessage());
        }
        $this->assertSame([], $this->responseRepository->findByFormId($this->formId));

        $response = $this->service()->submit($this->article, $this->form(), $fields, null, null, 1, 'a@test.com',
            [$mealId => '1', $discountId => '1'], null);
        $this->expectException(NewsException::class);
        $this->service()->update($response, $this->form(), $fields, 'a@test.com', [$mealId => '0', $discountId => '1'], null, 1);
    }

    private function makeFormPaying(): void
    {
        $this->formRepository->update(
            $this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, 7,
            financeCategoryId: 9
        );
    }

    private function payingService(ExpectedReceivableInterface $expectedReceivable): ResponseService
    {
        $structuredCommunication = $this->createStub(StructuredCommunicationInterface::class);
        $structuredCommunication->method('generate')->willReturn('+++100/0000/00034+++');
        $sepaQrCode = $this->createStub(SepaQrCodeInterface::class);
        $sepaQrCode->method('generatePng')->willReturn('png-bytes');
        $financeAccount = $this->createStub(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => 7, 'name' => 'Compte', 'iban' => 'BE68539007547034', 'holder_name' => 'Unité', 'section_id' => null],
        ]);

        return $this->service($structuredCommunication, $expectedReceivable, $sepaQrCode, $financeAccount);
    }

    private static function standing(int $dueCents, int $receivedCents): ReceivableStanding
    {
        return new ReceivableStanding(
            amountDueCents: $dueCents,
            amountReceivedCents: $receivedCents,
            amountRefundedCents: 0,
            amountOverpaidCents: max(0, $receivedCents - $dueCents),
            amountRemainingCents: max(0, $dueCents - $receivedCents),
            status: $receivedCents === 0 ? 'unpaid' : ($receivedCents >= $dueCents ? 'paid' : 'partial')
        );
    }

    /**
     * previous quantity, receivable due and received (null: no receivable),
     * new quantity, then what finance must be asked: the method, the
     * amount and whether it is told it goes below what came in.
     *
     * @return array<string, array{0: string, 1: ?array{0: int, 1: int}, 2: string, 3: string, 4: ?int, 5: ?bool}>
     */
    public static function editsOfAPricedAnswer(): array
    {
        return [
            'up, on a paid response' => ['1', [1800, 1800], '10', 'update', 18000, false],
            'down, still above what came in' => ['10', [18000, 1800], '5', 'update', 9000, false],
            'down, below what came in' => ['10', [18000, 18000], '2', 'update', 3600, true],
            'from zero to a price' => ['0', null, '2', 'create', 3600, null],
            // Kept at zero, never deleted: a transfer still on its way
            // must find it (an import lags by days).
            'to zero, nothing paid yet' => ['2', [3600, 0], '0', 'update', 0, false],
            'to zero, already paid' => ['2', [3600, 3600], '0', 'update', 0, true],
            'unchanged' => ['2', [3600, 0], '2', 'none', null, null],
        ];
    }

    /**
     * The maintainer's decision on issue #1525: the amount due follows the
     * edit, through finance's Api\ only, inside the edit's transaction.
     *
     * @param ?array{0: int, 1: int} $receivable
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('editsOfAPricedAnswer')]
    public function testEditingAPricedAnswerMovesTheReceivable(
        string $previous,
        ?array $receivable,
        string $new,
        string $expectedCall,
        ?int $expectedCents,
        ?bool $expectedBelowReceived
    ): void {
        $this->makeFormPaying();
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);
        $field = $this->fieldRepository->findById($fieldId);
        $responseId = $this->responseRepository->create(
            $this->formId, 42, null, 'a@test.com', [$fieldId => $previous],
            $receivable !== null ? '+++090/9337/55493+++' : null,
            $receivable !== null ? 55 : null
        );

        $finance = $this->createMock(ExpectedReceivableInterface::class);
        $finance->method('getReceivableStanding')->willReturnCallback(
            static fn (string $module, int $id): ?ReceivableStanding => $module === 'news' && $id === 55 && $receivable !== null
                ? self::standing($receivable[0], $receivable[1])
                : null
        );
        $finance->expects($expectedCall === 'update' ? $this->once() : $this->never())
            ->method('updateReceivableAmount')
            ->with(55, $expectedCents, $expectedBelowReceived);
        $finance->expects($expectedCall === 'create' ? $this->once() : $this->never())
            ->method('createReceivable')
            ->with('news', $this->formId, 7, $expectedCents, '+++100/0000/00034+++', 'a@test.com', 9)
            ->willReturn(77);

        $updated = $this->payingService($finance)->update(
            $this->responseRepository->findById($responseId), $this->form(), [$field], 'a@test.com', [$fieldId => $new], null, 1
        );

        $this->assertSame($new, $this->service()->getAnswers($responseId)[$fieldId]);
        match ($expectedCall) {
            'create' => $this->assertSame([77, '+++100/0000/00034+++'], [$updated->receivableId, $updated->structuredCommunication]),
            default => $this->assertSame(55, $updated->receivableId, 'the receivable moves in place, communication kept'),
        };
    }

    /** Finance refusing the move leaves the answers as they were. */
    public function testAnEditFinanceRefusesToPriceIsNotSaved(): void
    {
        $this->makeFormPaying();
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, 100, 18.0, null);
        $field = $this->fieldRepository->findById($fieldId);
        $responseId = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [$fieldId => '1'], '+++090/9337/55493+++', 55);
        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);

        $finance = $this->createStub(ExpectedReceivableInterface::class);
        $finance->method('getReceivableStanding')->willReturn(self::standing(1800, 0));
        $finance->method('updateReceivableAmount')->willThrowException(new FinanceException("Cette créance n'existe pas."));

        try {
            $this->payingService($finance)->update(
                $this->responseRepository->findById($responseId), $this->form(), [$field], 'a@test.com', [$fieldId => '3'], null, 1
            );
            $this->fail('The edit must be refused.');
        } catch (NewsException $e) {
            $this->assertStringContainsString("Votre modification n'a pas été enregistrée.", $e->getMessage());
            $this->assertInstanceOf(FinanceException::class, $e->getPrevious());
        }

        $this->assertSame([$fieldId => '1'], $this->service()->getAnswers($responseId));
        $this->assertSame(1.0, $this->fieldRepository->usedCapacity($fieldId));
        $this->assertFalse($this->pdo->inTransaction());
    }

    /**
     * An edit that moves the amount tells the respondent what is LEFT to
     * pay, and how — not the whole new price, which they would pay again.
     */
    public function testAnEditThatRaisesTheAmountMailsWhatIsLeftToPay(): void
    {
        $this->makeFormPaying();
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);
        $field = $this->fieldRepository->findById($fieldId);
        $responseId = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [$fieldId => '1'], '+++090/9337/55493+++', 55);

        $moved = false;
        $finance = $this->createStub(ExpectedReceivableInterface::class);
        $finance->method('getReceivableStanding')->willReturnCallback(
            static function () use (&$moved): ReceivableStanding {
                return $moved ? self::standing(18000, 1800) : self::standing(1800, 1800);
            }
        );
        $finance->method('updateReceivableAmount')->willReturnCallback(static function () use (&$moved): void {
            $moved = true;
        });

        $this->mailService->expects($this->once())->method('send')->with(
            $this->identicalTo('a@test.com'),
            $this->anything(),
            $this->callback(static fn (string $html): bool => str_contains($html, 'Montant à payer : 162,00 €')
                && str_contains($html, 'Déjà reçu : 18,00 €'))
        );

        $this->payingService($finance)->update(
            $this->responseRepository->findById($responseId), $this->form(), [$field], 'a@test.com', [$fieldId => '10'], null, 1, $this->article
        );
    }

    /** An edit that changes nothing owed sends nothing. */
    public function testAnEditThatLeavesTheAmountAloneSendsNoMail(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', false, null, null, null, null, null);
        $responseId = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [$fieldId => 'Old'], null, null);
        $this->mailService->expects($this->never())->method('send');

        $this->service()->update($this->responseRepository->findById($responseId), $this->form(),
            [$this->fieldRepository->findById($fieldId)], 'a@test.com', [$fieldId => 'New'], null, 1, $this->article);
    }

    public function testThePaymentSummaryAsksOnlyForWhatIsLeftAndNothingOnceSettled(): void
    {
        $this->makeFormPaying();
        $responseId = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [], '+++090/9337/55493+++', 55);
        $response = $this->responseRepository->findById($responseId);

        $partial = $this->createStub(ExpectedReceivableInterface::class);
        $partial->method('getReceivableStanding')->willReturn(self::standing(18000, 1800));
        $summary = $this->payingService($partial)->buildPaymentSummary($this->form(), $response, 180.0);
        $this->assertSame(162.0, $summary['total'] ?? null);
        $this->assertSame(18.0, $summary['received'] ?? null);

        $overpaid = $this->createStub(ExpectedReceivableInterface::class);
        $overpaid->method('getReceivableStanding')->willReturn(self::standing(3600, 18000));
        $this->assertNull($this->payingService($overpaid)->buildPaymentSummary($this->form(), $response, 36.0));
        $this->assertSame(14400, $this->payingService($overpaid)->amountOwedBackCents($response));
    }

    /**
     * The reprise: every response holding a quantity the server now
     * refuses is marked, and nothing else.
     */
    public function testTheRepriseMarksTheResponsesHoldingAnInvalidQuantity(): void
    {
        $adultsId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);
        $ageId = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Âge', false, null, null, null, null, null);
        $clean = $this->responseRepository->create($this->formId, null, null, 'a@test.com', [$adultsId => '4', $ageId => '7.5'], null, null);
        $negative = $this->responseRepository->create($this->formId, null, null, 'b@test.com', [$adultsId => '-8'], null, null);
        $fraction = $this->responseRepository->create($this->formId, null, null, 'c@test.com', [$adultsId => '0.5'], null, null);
        $negativeAge = $this->responseRepository->create($this->formId, null, null, 'd@test.com', [$adultsId => '1', $ageId => '-3'], null, null);

        $this->assertSame(3, $this->service()->flagResponsesWithInvalidQuantities());

        $flagged = array_map(
            fn (int $id): bool => $this->responseRepository->findById($id)?->hasInvalidQuantity() ?? false,
            [$clean, $negative, $fraction, $negativeAge]
        );
        $this->assertSame([false, true, true, true], $flagged);

        // Correcting the response clears the mark.
        $this->service()->update($this->responseRepository->findById($negative), $this->form(),
            [$this->fieldRepository->findById($adultsId)], 'b@test.com', [$adultsId => '2'], null, 1);
        $this->assertFalse($this->responseRepository->findById($negative)?->hasInvalidQuantity());
    }

    /**
     * Two edits of one response, both loaded while it had no receivable:
     * the second decides on the link as stored, never on its own stale
     * copy, so it moves the receivable the first raised instead of raising
     * another.
     */
    public function testAnEditDecidesOnTheStoredReceivableLinkNotOnAStaleCopy(): void
    {
        $this->makeFormPaying();
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);
        $field = $this->fieldRepository->findById($fieldId);
        $responseId = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [$fieldId => '0'], null, null);
        $stale = $this->responseRepository->findById($responseId);
        // The competing edit committed its receivable meanwhile.
        $this->responseRepository->setReceivable($responseId, '+++090/9337/55493+++', 55);

        $finance = $this->createMock(ExpectedReceivableInterface::class);
        $finance->method('getReceivableStanding')->willReturn(self::standing(1800, 0));
        $finance->expects($this->never())->method('createReceivable');
        $finance->expects($this->once())->method('updateReceivableAmount')->with(55, 3600, false);

        $updated = $this->payingService($finance)->update($stale, $this->form(), [$field], 'a@test.com', [$fieldId => '2'], null, 1);

        $this->assertSame(55, $updated->receivableId);
    }

    /**
     * The reprise flags what the door would call invalid, the same rule
     * throughout: a fraction, a figure above the per-response ceiling, and
     * an answer that can no longer be read.
     */
    public function testTheRepriseFlagsAboveTheCeilingAndUnreadableAnswersToo(): void
    {
        $boxesId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Boîtes', false, null, null, null, 6.0, null);
        $tooMany = $this->responseRepository->create($this->formId, null, null, 'a@test.com', [$boxesId => '1000'], null, null);
        $unreadable = $this->responseRepository->create($this->formId, null, null, 'b@test.com', [$boxesId => '2'], null, null);
        $clean = $this->responseRepository->create($this->formId, null, null, 'c@test.com', [$boxesId => '999'], null, null);
        // Accepted by is_numeric() before #1525, refused now, and invalid
        // at the door too (ScanServiceTest).
        $spaced = $this->responseRepository->create($this->formId, null, null, 'd@test.com', [$boxesId => ' 3'], null, null);
        $this->pdo->prepare('UPDATE news_form_response_values SET value = ? WHERE response_id = ?')->execute(['corrompu', $unreadable]);

        $this->assertSame(3, $this->service()->flagResponsesWithInvalidQuantities());
        $this->assertTrue($this->responseRepository->findById($spaced)?->hasInvalidQuantity());
        $this->assertTrue($this->responseRepository->findById($tooMany)?->hasInvalidQuantity());
        $this->assertTrue($this->responseRepository->findById($unreadable)?->hasInvalidQuantity());
        $this->assertFalse($this->responseRepository->findById($clean)?->hasInvalidQuantity());
    }

    /** The reprise's recompute gives no place to a fraction stored before #1525. */
    public function testTheRecomputedTotalCountsAStoredFractionAsNothing(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 100, null, null);
        $this->responseRepository->create($this->formId, null, null, 'a@test.com', [$fieldId => '2'], null, null);
        $this->responseRepository->create($this->formId, null, null, 'b@test.com', [$fieldId => '1.5'], null, null);

        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);

        $this->assertSame(2.0, $this->fieldRepository->usedCapacity($fieldId));
    }

    /**
     * A capacity lowered after people booked does not void their bookings:
     * 8 written when the field was capped at 10 still holds its places in
     * the recomputed total, and the reprise does not flag it. Only the
     * per-answer ceiling (999) voids a stored answer.
     */
    public function testABookingAboveALoweredCapacityStillCountsAndIsNotFlagged(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 10, null, null);
        $booked = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [$fieldId => '8'], null, null);
        $this->responseRepository->create($this->formId, null, null, 'b@test.com', [$fieldId => '1'], null, null);
        $this->pdo->prepare('UPDATE news_form_fields SET capacity_max = 5 WHERE id = ?')->execute([$fieldId]);

        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);
        $this->assertSame(9.0, $this->fieldRepository->usedCapacity($fieldId));

        $this->assertSame(0, $this->service()->flagResponsesWithInvalidQuantities());
        $this->assertFalse($this->responseRepository->findById($booked)?->hasInvalidQuantity());
        $this->assertSame(0, $this->service()->remainingCapacity($this->fieldRepository->findById($fieldId)));
    }

    // --------------------------------------------- a cap set afterwards (#1465)

    /**
     * Thirty places taken on a field with no cap, then a cap of 50: twenty
     * remain, not fifty — the counter used to be kept only while a cap
     * existed, and started from zero when one was added.
     */
    public function testACapAddedAfterThirtyAnswersLeavesTwentyPlaces(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, null, null, null);
        foreach (['a', 'b', 'c'] as $who) {
            $this->submitPlaces($fieldId, '10', $who);
        }

        $formService = $this->formService();
        $this->saveCap($formService, $fieldId, 50);

        $this->assertSame(20, $this->service()->remainingCapacity($this->fieldRepository->findById($fieldId)));
        $this->assertSame(
            [['form_id' => $this->formId, 'field_id' => $fieldId, 'label' => 'Places', 'capacity_max' => 50, 'capacity_used' => 30.0]],
            $formService->recomputedCapacities()
        );
    }

    /**
     * The total is kept for a number field with no cap too, by the
     * submissions themselves (#1546 review): an answer in flight while the
     * editor sets a cap is then never left out of it — the recompute and
     * the increment take the same row lock, and no submission takes a
     * "no cap, nothing to count" path any more.
     */
    public function testANumberFieldWithoutACapStillKeepsItsTotalAndAnEditMovesIt(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, null, null, null);
        $this->submitPlaces($fieldId, '4', 'a');
        $this->submitPlaces($fieldId, '6', 'b');
        $this->assertSame(10.0, $this->fieldRepository->usedCapacity($fieldId));

        $response = $this->responseRepository->findByFormId($this->formId)[0];
        $this->service()->update($response, $this->form(), [$this->fieldRepository->findById($fieldId)],
            'a@test.com', [$fieldId => '1'], null, 1);

        $this->assertSame(7.0, $this->fieldRepository->usedCapacity($fieldId));
    }

    /** Cap removed, answers given meanwhile, cap restored: the total counts them all. */
    public function testACapRemovedThenRestoredCountsTheAnswersGivenInBetween(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 50, null, null);
        $this->submitPlaces($fieldId, '10', 'a');
        $formService = $this->formService();

        $this->saveCap($formService, $fieldId, null);
        $this->submitPlaces($fieldId, '15', 'b');
        $this->saveCap($formService, $fieldId, 50);

        $this->assertSame(25.0, $this->fieldRepository->usedCapacity($fieldId));
        $this->assertSame(25, $this->service()->remainingCapacity($this->fieldRepository->findById($fieldId)));
    }

    /**
     * A cap already exceeded by the answers is stored as asked — the
     * answers stay — and the save says by how much, for the editor to warn
     * about; an unchanged cap recomputes nothing.
     */
    public function testACapBelowTheAnswersIsReportedAndAnUnchangedCapIsLeftAlone(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, null, null, null);
        $this->submitPlaces($fieldId, '30', 'a');
        $formService = $this->formService();

        $this->saveCap($formService, $fieldId, 20);
        $this->assertSame(30.0, $formService->recomputedCapacities()[0]['capacity_used']);
        $this->assertSame(0, $this->service()->remainingCapacity($this->fieldRepository->findById($fieldId)));

        $this->saveCap($formService, $fieldId, 20);
        $this->assertSame([], $formService->recomputedCapacities());
    }

    /**
     * The cap and its recomputed total are one change (#1546 review): a
     * recompute that fails leaves the former cap in place, so no
     * submission ever reads a new cap against a stale total.
     */
    public function testACapIsNotKeptWhenItsRecomputeFails(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, null, null, null);
        $this->submitPlaces($fieldId, '30', 'a');
        $failing = new class ($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))) extends FormResponseRepository {
            public function sumFieldValues(FormField $field, ?int $excludeResponseId = null, bool $lockForUpdate = false): float
            {
                throw new \PDOException('deliberate failure while counting');
            }
        };

        try {
            $this->saveCap($this->formService($failing), $fieldId, 50);
            $this->fail('The failed recompute should reach the caller.');
        } catch (\PDOException) {
        }

        $this->assertNull($this->fieldRepository->findById($fieldId)?->capacityMax);
        $this->assertFalse($this->pdo->inTransaction());
    }

    /**
     * An answer taken under a higher cap keeps counting once the cap is
     * lowered below it (#1546 review): above MAX_QUANTITY_PER_ANSWER, the
     * per-answer ceiling follows the cap, and the recount would otherwise
     * read 1500 as nothing and offer a thousand places again.
     */
    public function testLoweringACapBelowABookingAboveTheCeilingKeepsItCounted(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 2000, null, null);
        $this->submitPlaces($fieldId, '1500', 'a');
        $formService = $this->formService();

        $this->saveCap($formService, $fieldId, 1000);

        $this->assertSame(1500.0, $this->fieldRepository->usedCapacity($fieldId));
        // What NewsController warns the editor from.
        $this->assertSame(1500.0, $formService->recomputedCapacities()[0]['capacity_used']);
        $this->assertSame(0, $this->service()->remainingCapacity($this->fieldRepository->findById($fieldId)));
    }

    /**
     * The bound is the highest cap the field ever had, kept on the field
     * (#1546 review): a booking taken under it survives every recount —
     * the editor's, the strict reprises' — once the cap is lowered.
     */
    public function testABookingUnderTheFieldsPeakCapSurvivesEveryRecount(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 2000, null, null);
        $this->submitPlaces($fieldId, '1500', 'a');
        $formService = $this->formService();
        $this->saveCap($formService, $fieldId, 1000);
        $this->saveCap($formService, $fieldId, 900);

        $this->assertSame(2000, $this->fieldRepository->findById($fieldId)?->capacityPeak);
        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);
        $this->assertSame(1500.0, $this->fieldRepository->usedCapacity($fieldId));
        $this->assertSame(1500, $this->fieldRepository->findById($fieldId)?->storedQuantity('1500'));
    }

    /**
     * An answer above every cap the field ever had was never taken by the
     * server, and the rule says so whatever the running total holds
     * (#1546 review): it counts nothing on a recount, and editing it away
     * gives nothing back.
     */
    public function testAnAnswerAboveThePeakCountsNothingWhateverTheTotal(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 1000, null, null);
        foreach (['600', '300', '1100'] as $i => $places) {
            $this->responseRepository->create($this->formId, null, null, "r{$i}@test.com", [$fieldId => $places], null, null);
        }
        $this->fieldRepository->recomputeUsedCapacities($this->responseRepository);
        $this->assertSame(900.0, $this->fieldRepository->usedCapacity($fieldId));

        $invalid = $this->responseRepository->findByFormId($this->formId)[2];
        $this->service()->update($invalid, $this->form(), [$this->fieldRepository->findById($fieldId)],
            'r2@test.com', [$fieldId => '0'], null, 1);

        $this->assertSame(900.0, $this->fieldRepository->usedCapacity($fieldId));
    }

    /** The peak only ever rises, and a field never capped has none. */
    public function testTheCapacityPeakOnlyRises(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, null, null, null);
        $formService = $this->formService();
        $this->assertNull($this->fieldRepository->findById($fieldId)?->capacityPeak);

        $this->saveCap($formService, $fieldId, 1200);
        $this->saveCap($formService, $fieldId, null);
        $this->saveCap($formService, $fieldId, 1100);
        $this->assertSame(1200, $this->fieldRepository->findById($fieldId)?->capacityPeak);

        $this->saveCap($formService, $fieldId, 3000);
        $this->assertSame(3000, $this->fieldRepository->findById($fieldId)?->capacityPeak);
    }

    /** A row from before the column starts from the cap it has. */
    public function testALegacyRowStartsItsPeakFromItsCurrentCap(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 1500, null, null);
        $this->pdo->exec('UPDATE news_form_fields SET capacity_peak = NULL WHERE id = ' . $fieldId);

        $this->saveCap($this->formService(), $fieldId, 1000);

        $this->assertSame(1500, $this->fieldRepository->findById($fieldId)?->capacityPeak);
    }

    /** The share an edit gives back is the one the total counted, whatever the cap became since. */
    public function testEditingABookingAboveALoweredCapGivesBackItsWholeShare(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, 2000, null, null);
        $this->submitPlaces($fieldId, '1500', 'a');
        // The cap lowered with no recount — the total still holds 1500 —
        // so only the edit's own give-back is measured.
        $this->pdo->exec('UPDATE news_form_fields SET capacity_max = 1000 WHERE id = ' . $fieldId);
        $response = $this->responseRepository->findByFormId($this->formId)[0];

        $this->service()->update($response, $this->form(), [$this->fieldRepository->findById($fieldId)],
            'a@test.com', [$fieldId => '500'], null, 1);

        $this->assertSame(500.0, $this->fieldRepository->usedCapacity($fieldId));
    }

    /**
     * A plain number's answer adds what its recount would count, so a cap
     * set later finds the total it would have rebuilt (#1546 review): a
     * fraction counts nothing on submission, on edit and on recount alike.
     */
    public function testAPlainFractionAddsWhatItsRecountCounts(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Taille', false, null, null, null, null, null);
        $this->submitPlaces($fieldId, '37.5', 'a');
        $this->assertSame(0.0, $this->fieldRepository->usedCapacity($fieldId));

        $response = $this->responseRepository->findByFormId($this->formId)[0];
        $this->service()->update($response, $this->form(), [$this->fieldRepository->findById($fieldId)],
            'a@test.com', [$fieldId => '38.5'], null, 1);
        $this->assertSame(0.0, $this->fieldRepository->usedCapacity($fieldId));

        $this->service()->update($response, $this->form(), [$this->fieldRepository->findById($fieldId)],
            'a@test.com', [$fieldId => '12'], null, 1);
        $this->assertSame(12.0, $this->fieldRepository->usedCapacity($fieldId));
        $this->assertSame(12.0, $this->fieldRepository->recomputeUsedCapacity($fieldId, $this->responseRepository));
    }

    /**
     * Every number field's row is locked before the answers are written,
     * capped or not, in ascending id order (#1546 review): the insert's
     * foreign key takes a shared lock on the row first otherwise, and two
     * submissions then deadlock on the increment.
     */
    public function testASubmissionLocksEveryNumberFieldBeforeWritingItsAnswers(): void
    {
        $plain = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Taille', false, null, null, null, null, null);
        $capped = $this->fieldRepository->create($this->formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 50, null, null);
        $text = $this->fieldRepository->create($this->formId, 2, FormField::TYPE_TEXT, 'Nom', false, null, null, null, null, null);
        $responses = $this->responseRepository;
        $spy = new class ($this->pdo, $responses) extends FormFieldRepository {
            /** @var list<list<int>> */
            public array $locked = [];
            public bool $answersWrittenWhenLocked = true;

            public function __construct(\PDO $pdo, private readonly FormResponseRepository $responses)
            {
                parent::__construct($pdo);
            }

            public function lockUsedCapacities(array $fieldIds): array
            {
                $this->locked[] = $fieldIds;
                $this->answersWrittenWhenLocked = $this->responses->findByFormId($this->formIdOf($fieldIds)) !== [];

                return parent::lockUsedCapacities($fieldIds);
            }

            /** @param list<int> $fieldIds */
            private function formIdOf(array $fieldIds): int
            {
                return (int) $this->findById($fieldIds[0])?->formId;
            }
        };
        $this->fieldRepository = $spy;

        $this->service()->submit($this->article, $this->form(), [
            $this->fieldRepository->findById($capped),
            $this->fieldRepository->findById($plain),
            $this->fieldRepository->findById($text),
        ], null, null, 1, 'a@test.com', [$plain => '3', $capped => '2', $text => 'Lea'], null);

        $this->assertCount(1, $spy->locked);
        $locked = $spy->locked[0];
        sort($locked);
        $this->assertSame([$plain, $capped], $locked);
        $this->assertFalse($spy->answersWrittenWhenLocked);
        $this->assertSame(3.0, $this->fieldRepository->usedCapacity($plain));
        $this->assertSame(2.0, $this->fieldRepository->usedCapacity($capped));
    }

    private function submitPlaces(int $fieldId, string $places, string $who): void
    {
        $this->service()->submit($this->article, $this->form(), [$this->fieldRepository->findById($fieldId)],
            null, null, 1, $who . '@test.com', [$fieldId => $places], null);
    }

    private function formService(?FormResponseRepository $responses = null): \Modules\News\Service\FormService
    {
        $formRepository = $this->formRepository;
        $articleService = new \Modules\News\Service\ArticleService(
            new ArticleRepository($this->pdo),
            $formRepository,
            new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)),
            new ShortUrlService(new ShortUrlRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)))),
            new \Core\File\FileRepository($this->pdo)
        );

        return new \Modules\News\Service\FormService($formRepository, $this->fieldRepository, $articleService, $responses ?? $this->responseRepository);
    }

    /** What the editor posts for this one field, with $cap as its capacity. */
    private function saveCap(\Modules\News\Service\FormService $formService, int $fieldId, ?int $cap): void
    {
        $formService->save($this->article->id, [
            'access' => NewsForm::ACCESS_PUBLIC,
            'response_limit' => NewsForm::RESPONSE_LIMIT_UNLIMITED,
            'opens_at' => null,
            'closes_at' => null,
            'is_force_closed' => false,
            'response_role_min' => 'chief',
            'digest_email' => null,
            'finance_account_id' => null,
        ], [[
            'id' => $fieldId, 'field_type' => FormField::TYPE_NUMBER, 'label' => 'Places', 'is_required' => false,
            'options_source' => null, 'options_manual' => null, 'capacity_max' => $cap, 'price_per_unit' => null,
            'confirmation_text' => null,
        ]]);
    }
}
