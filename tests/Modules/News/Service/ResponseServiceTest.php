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
            ->with('news', 'confirmation_email_failed', 'info', $this->anything(), $this->anything(), null);

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
            public function sumFieldValues(int $fieldId, ?int $excludeResponseId = null, bool $lockForUpdate = false): float
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
