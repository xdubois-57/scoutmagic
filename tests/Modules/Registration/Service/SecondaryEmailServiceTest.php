<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\SecondaryEmailService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SecondaryEmailServiceTest extends TestCase
{
    private \PDO $pdo;
    private SecondaryEmailService $service;
    private RegistrationSecondaryEmailRepository $repository;
    private MailService&\PHPUnit\Framework\MockObject\MockObject $mailService;
    private int $requestId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->repository = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);
        $this->mailService = $this->createMock(MailService::class);
        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html></html>');
        $journalService = $this->createMock(JournalService::class);

        $this->service = new SecondaryEmailService(
            $this->repository, $this->mailService, $twig, $journalService, 'https://example.com', 'Unité Test'
        );

        $scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $created = $requestRepository->create($scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'L', 'child_first_name' => 'F',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'origin@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestId = $created['id'];
    }

    public function testAddEmailRejectsInvalidAddress(): void
    {
        $this->expectException(RegistrationException::class);
        $this->service->addEmail($this->requestId, 'not-an-email');
    }

    public function testAddEmailSendsConfirmationAndCreatesAPendingRow(): void
    {
        $this->mailService->expects($this->once())->method('send');

        $row = $this->service->addEmail($this->requestId, 'Secondary@Example.com');

        $this->assertTrue($row->isPending());
        $this->assertSame('secondary@example.com', $row->email);
    }

    public function testAddingTheSameEmailTwiceReturnsSameRowWithoutDuplicateSend(): void
    {
        $this->mailService->expects($this->once())->method('send');

        $first = $this->service->addEmail($this->requestId, 'secondary@example.com');
        // Second call within the resend cooldown must not re-send.
        $second = $this->service->addEmail($this->requestId, 'secondary@example.com');

        $this->assertSame($first->id, $second->id);
    }

    public function testConfirmEmailRequiresACorrectUnexpiredToken(): void
    {
        $this->mailService->method('send');
        // addEmail() generates its own random token internally — exercise
        // confirmEmail() directly against a row we control instead, mirroring
        // Core\Member\MemberEmailServiceTest's own confirm-flow tests.
        $rawToken = bin2hex(random_bytes(32));
        $id = $this->repository->create($this->requestId, 'secondary@example.com', password_hash($rawToken, PASSWORD_DEFAULT), new \DateTimeImmutable('+48 hours'));

        $this->assertFalse($this->service->confirmEmail($id, 'wrong-token'));
        $this->assertTrue($this->service->confirmEmail($id, $rawToken));

        $row = $this->repository->findById($id);
        $this->assertTrue($row->isValid());
    }

    public function testConfirmEmailRejectsExpiredToken(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $id = $this->repository->create($this->requestId, 'secondary@example.com', password_hash($rawToken, PASSWORD_DEFAULT), new \DateTimeImmutable('-1 hour'));

        $this->assertFalse($this->service->confirmEmail($id, $rawToken));
    }

    public function testRemoveEmailRejectsRowFromAnotherRequest(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $otherRequest = $requestRepository->create(
            RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31'),
            [
                'parent_name' => 'P2', 'child_last_name' => 'L2', 'child_first_name' => 'F2',
                'gender' => 'M', 'birth_date' => '2020-01-01', 'street' => 'S', 'number' => '1',
                'postal_code' => '1000', 'city' => 'V', 'email' => 'other@example.com',
                'phone1' => '000', 'phone2' => null, 'remarks' => null,
            ],
            null,
            []
        );

        $this->mailService->method('send');
        $row = $this->service->addEmail($this->requestId, 'secondary@example.com');

        $this->expectException(RegistrationException::class);
        $this->service->removeEmail($otherRequest['id'], $row->id);
    }

    public function testListForRequestReturnsOnlyRowsForThatRequest(): void
    {
        $this->mailService->method('send');
        $this->service->addEmail($this->requestId, 'a@example.com');
        $this->service->addEmail($this->requestId, 'b@example.com');

        $this->assertCount(2, $this->service->listForRequest($this->requestId));
    }
}
