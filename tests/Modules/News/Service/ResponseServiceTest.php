<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
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
        ?FinanceAccountInterface $financeAccount = null
    ): ResponseService {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $roleResolver = new RoleResolver(new MemberYearRepository($this->pdo), $encryption, $this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $twig = new Environment(new ArrayLoader([
            '@news/email/confirmation.html.twig' => 'html',
            '@news/email/confirmation.text.twig' => 'text',
        ]));
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo));

        return new ResponseService(
            $this->responseRepository, $roleResolver, $sectionService,
            $this->mailService, $twig, $shortUrlService, 'https://example.com', 'Test Unit',
            $structuredCommunication, $expectedReceivable, $sepaQrCode, $financeAccount
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
