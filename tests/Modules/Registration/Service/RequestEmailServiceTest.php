<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Acceptance/refusal emails: no shipped default body (unlike itération 4's
 * submission emails), send button gated on a real body having been
 * written, resend always available, and — unlike the best-effort
 * submission emails — a mail delivery failure must surface to the caller
 * rather than being swallowed into the journal.
 *
 * @group database
 */
class RequestEmailServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $requestRepository;
    private EditableContentService $editableContentService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function buildService(MailService $mailService): RequestEmailService
    {
        return new RequestEmailService(
            $this->requestRepository, $mailService, $this->editableContentService,
            new JournalService(new JournalRepository($this->pdo)), 'https://example.com', 'Unité Test'
        );
    }

    private function createRequest(): int
    {
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        return $created['id'];
    }

    public function testBodiesAreNotReadyByDefault(): void
    {
        $service = $this->buildService($this->createMock(MailService::class));

        $this->assertFalse($service->isAcceptedBodyReady());
        $this->assertFalse($service->isRefusedBodyReady());
    }

    public function testSendAcceptedThrowsWhenBodyNotWritten(): void
    {
        $service = $this->buildService($this->createMock(MailService::class));
        $request = $this->requestRepository->findById($this->createRequest());

        $this->expectException(RegistrationException::class);
        $service->sendAccepted($request, '2026-2027');
    }

    public function testSendRefusedThrowsWhenBodyNotWritten(): void
    {
        $service = $this->buildService($this->createMock(MailService::class));
        $request = $this->requestRepository->findById($this->createRequest());

        $this->expectException(RegistrationException::class);
        $service->sendRefused($request, '2026-2027');
    }

    public function testSendAcceptedSucceedsAndMarksSentOnceBodyIsWritten(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue {{prenom_enfant}} !</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send');
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest();

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        $this->assertNotNull($this->requestRepository->findById($requestId)->acceptedEmailSentAt);
    }

    public function testResendIsAlwaysAvailableAfterFirstSend(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue !</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->exactly(2))->method('send');
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest();

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        $this->assertNotNull($this->requestRepository->findById($requestId)->acceptedEmailSentAt);
    }

    public function testMailDeliveryFailureIsSurfacedNotSwallowed(): void
    {
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willThrowException(new MailException('SMTP down'));
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest();

        try {
            $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected RegistrationException on mail delivery failure');
        } catch (RegistrationException) {
            // expected — never silently swallowed
        }

        // *_email_sent_at stays unset so a retry is always possible.
        $this->assertNull($this->requestRepository->findById($requestId)->refusedEmailSentAt);
    }

    /**
     * A failed send must leave the family's EXISTING tracking link alive:
     * the token used to be rotated and persisted before the send, so an SMTP
     * outage killed the link the parent already had while the replacement
     * never reached them.
     */
    public function testFailedSendLeavesThePreviousTrackingLinkUsable(): void
    {
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willThrowException(new MailException('SMTP down'));
        $service = $this->buildService($mailService);

        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'D', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'p@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        try {
            $service->sendRefused($this->requestRepository->findById($created['id']), '2026-2027');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertTrue($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
    }

    public function testSuccessfulSendRotatesTheTrackingToken(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue !</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send');
        $service = $this->buildService($mailService);

        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'D', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'p@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');

        $this->assertFalse($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
    }

    /**
     * substitute() html-escapes the values it injects; strip_tags() alone
     * left those entities in the plain-text alternative.
     */
    public function testPlainTextAlternativeDecodesEntities(): void
    {
        $this->assertSame(
            "Bonjour Ma'lo & bienvenue",
            RequestEmailService::toPlainText('<p>Bonjour Ma&#039;lo &amp; bienvenue</p>')
        );
    }
}
