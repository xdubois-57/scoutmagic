<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\FamilyEmailCopyService;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailService;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

    private function buildService(
        MailService $mailService,
        ?FamilyEmailCopyService $familyEmailCopy = null
    ): RequestEmailService {
        return new RequestEmailService(
            $this->requestRepository, $mailService, $this->editableContentService,
            new JournalService(new JournalRepository($this->pdo)), 'https://example.com', 'Unité Test',
            $familyEmailCopy
        );
    }

    /**
     * The copy settings of chantier #843 (D9) over a real setting service:
     * the switch, the typed address, and the unit's own « Email de
     * contact », the default.
     */
    private function familyEmailCopy(string $enabled, string $address, string $unitAddress): FamilyEmailCopyService
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $module = 'registration';
        $settings->register(FamilyEmailCopyService::SETTING_ENABLED, $enabled, 'boolean', 'Copie', 'desc', $module);
        $settings->register(FamilyEmailCopyService::SETTING_ADDRESS, $address, 'email', 'Adresse', 'desc', $module);
        $settings->register(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, $unitAddress, 'email', 'Contact', 'desc');

        return new FamilyEmailCopyService($settings);
    }

    /**
     * Record, for every doubled `send()`, the recipient and the `cc` it is
     * handed — the copy read by the parameter's NAME, so a reordering of
     * the signature cannot make this read another argument.
     *
     * @param list<array{to: string, cc: mixed}> $copies written to by the double
     */
    private function recordCopies(
        MailService&\PHPUnit\Framework\MockObject\MockObject $mailService,
        InvocationOrder $times,
        array &$copies
    ): void {
        $ccIndex = null;
        foreach ((new \ReflectionMethod(MailService::class, 'send'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'cc') {
                $ccIndex = $parameter->getPosition();
            }
        }
        $this->assertNotNull($ccIndex, 'MailService::send() takes a cc.');

        $mailService->expects($times)
            ->method('send')
            ->willReturnCallback(function (...$arguments) use (&$copies, $ccIndex): void {
                $copies[] = ['to' => (string) $arguments[0], 'cc' => $arguments[$ccIndex] ?? null];
            });
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
        $service = $this->buildService($this->createStub(MailService::class));

        $this->assertFalse($service->isAcceptedBodyReady());
        $this->assertFalse($service->isRefusedBodyReady());
    }

    public function testSendAcceptedThrowsWhenBodyNotWritten(): void
    {
        $service = $this->buildService($this->createStub(MailService::class));
        $request = $this->requestRepository->findById($this->createRequest());

        $this->expectException(RegistrationException::class);
        $service->sendAccepted($request, '2026-2027');
    }

    public function testSendRefusedThrowsWhenBodyNotWritten(): void
    {
        $service = $this->buildService($this->createStub(MailService::class));
        $request = $this->requestRepository->findById($this->createRequest());

        $this->expectException(RegistrationException::class);
        $service->sendRefused($request, '2026-2027');
    }

    /**
     * Record every address the doubled `MailService::send()` is handed.
     *
     * Counting sends says a message left and says nothing about where it
     * went: a double with no argument constraint accepts any argument.
     * Measured on this file — replacing `to: $request->email` with a fixed
     * foreign address left all ten tests green (issue #439). What is
     * carried here is a decision on a child's enrolment, addressed to the
     * family who asked; delivered elsewhere it is a personal matter handed
     * to a stranger.
     *
     * @param list<string> $recipients written to by the double
     */
    private function recordRecipients(
        MailService&\PHPUnit\Framework\MockObject\MockObject $mailService,
        InvocationOrder $times,
        array &$recipients
    ): void {
        $mailService->expects($times)
            ->method('send')
            ->willReturnCallback(function (...$arguments) use (&$recipients): void {
                $recipients[] = (string) $arguments[0];
            });
    }

    public function testSendAcceptedSucceedsAndMarksSentOnceBodyIsWritten(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue {{prenom_enfant}} !</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $recipients = [];
        $this->recordRecipients($mailService, $this->once(), $recipients);
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest();

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        // The address the family wrote on its own request, and no other.
        $this->assertSame(['marie@example.com'], $recipients);
        $this->assertNotNull($this->requestRepository->findById($requestId)->acceptedEmailSentAt);
    }

    public function testResendIsAlwaysAvailableAfterFirstSend(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue !</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $recipients = [];
        $this->recordRecipients($mailService, $this->exactly(2), $recipients);
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest();

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        // A resend goes back to the same family, twice.
        $this->assertSame(['marie@example.com', 'marie@example.com'], $recipients);
        $this->assertNotNull($this->requestRepository->findById($requestId)->acceptedEmailSentAt);
    }

    /**
     * Acceptance, its resend and refusal all carry the unit in copy
     * (chantier #843, D9) — by default the unit's own address.
     */
    public function testBothDecisionEmailsAndTheirResendsCarryTheUnitAddressInCopy(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue !</p>', 'rich_text', 1);
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $copies = [];
        $this->recordCopies($mailService, $this->exactly(3), $copies);
        $service = $this->buildService($mailService, $this->familyEmailCopy('1', '', 'info@unite.example.com'));
        $requestId = $this->createRequest();

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
        $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');

        // The family stays the recipient; the unit is the copy.
        $sent = ['to' => 'marie@example.com', 'cc' => 'info@unite.example.com'];
        $this->assertSame([$sent, $sent, $sent], $copies);
    }

    /** An address typed in the box replaces the unit's own. */
    public function testATypedCopyAddressReplacesTheUnitAddress(): void
    {
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $copies = [];
        $this->recordCopies($mailService, $this->once(), $copies);
        $copy = $this->familyEmailCopy('1', 'secretariat@example.com', 'info@unite.example.com');
        $service = $this->buildService($mailService, $copy);

        $service->sendRefused($this->requestRepository->findById($this->createRequest()), '2026-2027');

        $this->assertSame([['to' => 'marie@example.com', 'cc' => 'secretariat@example.com']], $copies);
    }

    /** Switched off, the decision goes to the family alone. */
    public function testNoCopyWhenTheOptionIsOff(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue !</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $copies = [];
        $this->recordCopies($mailService, $this->once(), $copies);
        $copy = $this->familyEmailCopy('0', 'secretariat@example.com', 'info@unite.example.com');
        $service = $this->buildService($mailService, $copy);

        $service->sendAccepted($this->requestRepository->findById($this->createRequest()), '2026-2027');

        $this->assertSame([['to' => 'marie@example.com', 'cc' => null]], $copies);
    }

    public function testMailDeliveryFailureIsSurfacedNotSwallowed(): void
    {
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
        $mailService = $this->createStub(MailService::class);
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
     * Core\Mail\MailException is built from PHPMailer's ErrorInfo — raw
     * SMTP English, always — and this message used to be interpolated into
     * RegistrationException's own, which Controller\
     * RegistrationRequestController shows to the chief. RegistrationException
     * is now a Core\Exception\UserFacingException, so what it says IS what
     * the page says.
     */
    public function testTheSmtpErrorNeverReachesTheRegistrationExceptionsOwnMessage(): void
    {
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willThrowException(
            new MailException('SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting')
        );
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest();

        try {
            $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected a RegistrationException.');
        } catch (RegistrationException $e) {
            $this->assertStringNotContainsString('SMTP', $e->getMessage());
            $this->assertStringNotContainsString('PHPMailer', $e->getMessage());
            $this->assertStringContainsString('lien de suivi', $e->getMessage());
            // The detail is not lost — it is on the cause, and in the
            // journal entry written just above the throw.
            $this->assertInstanceOf(MailException::class, $e->getPrevious());
        }
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
        $mailService = $this->createStub(MailService::class);
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
        $recipients = [];
        $this->recordRecipients($mailService, $this->once(), $recipients);
        $service = $this->buildService($mailService);

        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'D', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'p@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');

        // This request carries a different address from the fixture's, so
        // the assertion tells the two apart rather than matching whatever
        // the service last sent to.
        $this->assertSame(['p@example.com'], $recipients);
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
