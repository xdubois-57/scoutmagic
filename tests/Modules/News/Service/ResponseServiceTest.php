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

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
        $this->formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
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
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
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
            new \Modules\News\Service\TicketMailService($this->mailService, $renderer, 'Test Unit', $icsBuilder)
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
        $this->responseRepository->create($this->formId, null, null, 'a@test.com', [$fieldId => '3'], null, null);

        $field = $this->fieldRepository->findById($fieldId);
        $this->assertSame(7, $this->service()->remainingCapacity($field));
    }

    public function testSubmitCreatesResponseAndSendsEmail(): void
    {
        $fieldId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $field = $this->fieldRepository->findById($fieldId);

        $this->mailService->expects($this->once())->method('send');

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
        $this->formRepository->update($this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', false, null);

        $this->expectException(NewsException::class);
        $this->service()->submit($this->article, $this->form(), [], null, null, 1, 'a@test.com', [], null);
    }

    public function testSubmitRejectsAnonymousWhenAccessIsIdentified(): void
    {
        $this->formRepository->update($this->formId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);

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
        $fields = [$this->fieldRepository->findById($placesId), $this->fieldRepository->findById($nameId)];

        $response = $this->service()->submit($this->article, $this->form(), $fields, null, null, 1, 'a@test.com', [$placesId => '', $nameId => 'Alice'], null);

        $this->assertSame('Alice', $this->service()->getAnswers($response->id)[$nameId]);
    }

    public function testSubmitStillRequiresACappedFieldWithRemainingCapacity(): void
    {
        $placesId = $this->fieldRepository->create($this->formId, 0, FormField::TYPE_NUMBER, 'Places bus', true, null, null, 5, null, null);
        $this->responseRepository->create($this->formId, null, null, 'x@test.com', [$placesId => '3'], null, null);
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
        $this->formRepository->update($this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, 7);

        $structuredCommunication = $this->createMock(StructuredCommunicationInterface::class);
        $structuredCommunication->method('generate')->willReturn('+++100/0000/00034+++');

        $expectedReceivable = $this->createMock(ExpectedReceivableInterface::class);
        $expectedReceivable->expects($this->once())->method('createReceivable')
            ->with('news', $this->formId, 7, 1000, '+++100/0000/00034+++', 'a@test.com')
            ->willReturn(55);

        $sepaQrCode = $this->createMock(SepaQrCodeInterface::class);
        $sepaQrCode->method('generatePng')->willReturn('png-bytes');

        $financeAccount = $this->createMock(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => 7, 'name' => 'Compte', 'iban' => 'BE68539007547034', 'holder_name' => 'Unité', 'section_id' => null],
        ]);

        $response = $this->service($structuredCommunication, $expectedReceivable, $sepaQrCode, $financeAccount)
            ->submit($this->article, $this->form(), [$field], null, null, 1, 'a@test.com', [$fieldId => '2'], null);

        $this->assertSame('+++100/0000/00034+++', $response->structuredCommunication);
        $this->assertSame(55, $response->receivableId);
    }

    // --- IT-02: the ticket ---

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeFormTicketed(bool $issuesTicket, ?string $eventDate = null, ?string $eventLocation = null): void
    {
        $this->formRepository->update(
            $this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, $issuesTicket, $eventDate, $eventLocation
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
        $icsBuilder = $this->createMock(\Modules\Calendar\Api\IcsFeedBuilderInterface::class);
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
        $this->formRepository->update($this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', false, null);

        $this->assertTrue($this->service()->canEditResponse($response, $this->form(), Role::ADMIN, 999));
    }

    public function testCanEditResponseAllowsOwnerOnlyWhileFormIsOpen(): void
    {
        $id = $this->responseRepository->create($this->formId, 42, null, 'a@test.com', [], null, null);
        $response = $this->responseRepository->findById($id);

        $this->assertTrue($this->service()->canEditResponse($response, $this->form(), Role::IDENTIFIED, 42));

        $this->formRepository->update($this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', false, null);
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
}
