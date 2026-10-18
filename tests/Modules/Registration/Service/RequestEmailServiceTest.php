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
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\DecisionEmailPanel;
use Modules\Registration\Service\DecisionEmailService;
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
    private DecisionEmailRepository $decisionEmails;
    private EditableContentService $editableContentService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->decisionEmails = new DecisionEmailRepository($this->pdo);
        $this->requestRepository = new RegistrationRequestRepository(
            $this->pdo,
            $encryption,
            null,
            $this->decisionEmails
        );
        $this->editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function buildService(
        MailService $mailService,
        ?FamilyEmailCopyService $familyEmailCopy = null
    ): RequestEmailService {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new RequestEmailService(
            $this->requestRepository, $mailService, $this->editableContentService,
            $journal, 'https://example.com', 'Unité Test',
            new DecisionEmailService($this->decisionEmails, $journal),
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
     * the signature cannot make this read another argument. The first
     * $failures sends throw, as a relay that refuses would.
     *
     * @param list<array{to: string, cc: mixed}> $copies written to by the double
     */
    private function recordCopies(
        MailService&\PHPUnit\Framework\MockObject\MockObject $mailService,
        InvocationOrder $times,
        array &$copies,
        int $failures = 0
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
            ->willReturnCallback(function (...$arguments) use (&$copies, $ccIndex, $failures): void {
                $copies[] = ['to' => (string) $arguments[0], 'cc' => $arguments[$ccIndex] ?? null];
                if (count($copies) <= $failures) {
                    throw new MailException('SMTP connect() failed.');
                }
            });
    }

    /**
     * A request, moved to $status the way a chief's decision moves it.
     */
    private function createRequest(string $status = 'pending', string $email = 'marie@example.com'): int
    {
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => $email,
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        if ($status !== 'pending') {
            $this->requestRepository->updateStatus(
                $created['id'],
                $status,
                $status === 'accepted' ? null : new \DateTimeImmutable()
            );
        }

        return $created['id'];
    }

    /**
     * Like createRequest(), keeping the raw token the repository handed
     * out — the one the family's receipt carried.
     *
     * @param \DateTimeImmutable|null $receivedAt when the request was filed (now when null)
     * @return array{id: int, tracking_token: string}
     */
    private function createRequestWithToken(
        string $status = 'pending',
        ?\DateTimeImmutable $receivedAt = null,
        string $email = 'p@example.com'
    ): array {
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'D', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => $email,
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [], 18, $receivedAt);
        if ($status !== 'pending') {
            $this->requestRepository->updateStatus(
                $created['id'],
                $status,
                $status === 'accepted' ? null : new \DateTimeImmutable()
            );
        }

        return $created;
    }

    private function trackingHash(int $requestId): string
    {
        return (string) $this->pdo->query('SELECT tracking_token_hash FROM registration_requests WHERE id = ' . $requestId)
            ->fetchColumn();
    }

    private function trackingEncrypted(int $requestId): string
    {
        return (string) $this->pdo
            ->query('SELECT tracking_token_encrypted FROM registration_requests WHERE id = ' . $requestId)
            ->fetchColumn();
    }

    /**
     * @return array<int, DecisionEmail>
     */
    private function history(int $requestId): array
    {
        return $this->decisionEmails->findForRequest($requestId);
    }

    private function writeBodies(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue ! {{ lien_suivi }}</p>', 'rich_text', 1);
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé. {{ lien_suivi }}</p>', 'rich_text', 1);
    }

    /**
     * Issue #932: the acceptance and the refusal ship with a text, so a
     * fresh install sends them — with the family's link, and nothing
     * written first.
     */
    public function testAFreshInstallSendsTheShippedTextWithTheFamilysLink(): void
    {
        $mailService = $this->createMock(MailService::class);
        $bodies = [];
        $this->recordBodies($mailService, $this->exactly(2), $bodies);
        $service = $this->buildService($mailService);
        $accepted = $this->createRequest('accepted');
        $refused = $this->createRequest('refused', 'autre@example.com');

        $service->sendAccepted($this->requestRepository->findById($accepted), '2026-2027');
        $service->sendRefused($this->requestRepository->findById($refused), '2026-2027');

        $this->assertSame(['marie@example.com', 'autre@example.com'], array_column($bodies, 'to'));
        $this->assertStringContainsString('a été acceptée', $bodies[0]['html']);
        $this->assertStringContainsString("n'a pas été retenue", $bodies[1]['html']);
        $this->assertStringContainsString('https://example.com/inscriptions/suivi/' . $accepted . '/', $bodies[0]['html']);
        $this->assertStringContainsString('https://example.com/inscriptions/suivi/' . $refused . '/', $bodies[1]['html']);
        $this->assertStringNotContainsString('{{', $bodies[0]['html'] . $bodies[1]['html']);
        $this->assertSame(
            [DecisionEmail::STATE_SENT],
            array_map(static fn(DecisionEmail $e) => $e->state, $this->history($accepted))
        );
    }

    /**
     * The send guard (issue #932): a stored body that lost its
     * `{{ lien_suivi }}` — written by another path, an old fixture — never
     * leaves silently without the one way back to the request. The attempt
     * is recorded as FAILED, never as a success, with a sentence a chief
     * may read, and the transport is never reached.
     */
    public function testABodyWithoutTheTrackingLinkIsRecordedAsAFailedAttemptNeverASuccess(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue !</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');

        try {
            $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected a RegistrationException for a body without the link.');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('ne contient pas le lien de suivi', $e->getMessage());
            $this->assertStringContainsString('{{ lien_suivi }}', $e->getMessage());
        }

        [$attempt] = $this->history($requestId);
        $this->assertSame(DecisionEmail::STATE_FAILED, $attempt->state);
        $this->assertSame(DecisionEmail::FAILURE_BODY_NOT_WRITTEN, $attempt->failureCode);
        $this->assertStringContainsString('ne contient pas le lien de suivi', (string) $attempt->failureMessage);
        $this->assertNull($attempt->sentAt);
        $this->assertNull($this->requestRepository->findById($requestId)->decisionEmailSentAt);
    }

    public function testARefusalBodyWithoutTheTrackingLinkIsRecordedAsAFailedAttemptToo(): void
    {
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('refused');

        try {
            $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected a RegistrationException.');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertSame(
            [DecisionEmail::FAILURE_BODY_NOT_WRITTEN],
            array_map(static fn(DecisionEmail $email) => $email->failureCode, $this->history($requestId))
        );
    }

    /**
     * A body the editor emptied to `<p>&nbsp;</p>` is no body: the shipped
     * text goes out in its place (issue #932), link included.
     */
    public function testABodyEmptiedByTheEditorFallsBackToTheShippedText(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>&nbsp;</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $bodies = [];
        $this->recordBodies($mailService, $this->once(), $bodies);
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        $this->assertSame(['marie@example.com'], array_column($bodies, 'to'));
        $this->assertStringContainsString('a été acceptée', $bodies[0]['html']);
        $this->assertStringContainsString('/inscriptions/suivi/' . $requestId . '/', $bodies[0]['html']);
    }

    /**
     * A decision e-mail goes only to a request that holds that decision:
     * nothing is sent and nothing is recorded for a pending request, nor
     * an acceptance for a refused one.
     */
    public function testAnEmailOfAnotherDecisionIsRefusedBeforeAnythingIsSentOrRecorded(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->buildService($mailService);
        $pending = $this->createRequest();
        $refused = $this->createRequest('refused');

        foreach ([
            [$pending, 'sendAccepted', "Cette demande n'est pas acceptée"],
            [$pending, 'sendRefused', "Cette demande n'est pas refusée"],
            [$refused, 'sendAccepted', "Cette demande n'est pas acceptée"],
        ] as [$requestId, $method, $message]) {
            try {
                $service->$method($this->requestRepository->findById($requestId), '2026-2027');
                $this->fail("{$method} should have been refused.");
            } catch (RegistrationException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
            $this->assertSame([], $this->history($requestId), $method);
        }
    }

    /**
     * Record the HTML and plain-text body of every doubled `MailService::
     * send()`, read by the parameter's NAME so a reordering of the signature
     * cannot make this read another argument.
     *
     * @param list<array{to: string, html: string, text: string}> $bodies written to by the double
     */
    private function recordBodies(
        MailService&\PHPUnit\Framework\MockObject\MockObject $mailService,
        InvocationOrder $times,
        array &$bodies
    ): void {
        $positions = [];
        foreach ((new \ReflectionMethod(MailService::class, 'send'))->getParameters() as $parameter) {
            $positions[$parameter->getName()] = $parameter->getPosition();
        }

        $mailService->expects($times)
            ->method('send')
            ->willReturnCallback(function (...$arguments) use (&$bodies, $positions): void {
                $bodies[] = [
                    'to' => (string) $arguments[0],
                    'html' => (string) $arguments[$positions['bodyHtml']],
                    'text' => (string) $arguments[$positions['bodyText']],
                ];
            });
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

    public function testSendAcceptedSucceedsAndRecordsTheSentAttemptOnceBodyIsWritten(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue {{prenom_enfant}} ! {{ lien_suivi }}</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $recipients = [];
        $this->recordRecipients($mailService, $this->once(), $recipients);
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        // The address the family wrote on its own request, and no other.
        $this->assertSame(['marie@example.com'], $recipients);
        [$attempt] = $this->history($requestId);
        $this->assertSame(DecisionEmail::STATE_SENT, $attempt->state);
        $this->assertSame(DecisionEmail::DECISION_ACCEPTED, $attempt->decision);
        $this->assertSame(1, $attempt->attempt);
        $this->assertFalse($attempt->isResend);
        $this->assertNull($attempt->dueAt, 'a send started by hand has no due date');
        $this->assertEquals($attempt->sentAt, $this->requestRepository->findById($requestId)->acceptedEmailSentAt());
    }

    /**
     * A resend is an e-mail of its own: the first one stays in the
     * history, sent, and the date the family's page reads is still the
     * first send's (R3).
     */
    public function testAResendIsARowOfItsOwnAndNeverMovesTheFirstSendDate(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $recipients = [];
        $this->recordRecipients($mailService, $this->exactly(2), $recipients);
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
        $this->pdo->exec("UPDATE registration_decision_emails SET sent_at = '2026-03-01 08:00:00'");
        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        // A resend goes back to the same family, twice.
        $this->assertSame(['marie@example.com', 'marie@example.com'], $recipients);
        $history = $this->history($requestId);
        $this->assertSame([DecisionEmail::STATE_SENT, DecisionEmail::STATE_SENT], array_map(
            static fn(DecisionEmail $email) => $email->state,
            $history
        ));
        $this->assertSame([false, true], array_map(static fn(DecisionEmail $email) => $email->isResend, $history));
        $this->assertSame([1, 2], array_map(static fn(DecisionEmail $email) => $email->attempt, $history));
        $this->assertSame(
            '2026-03-01 08:00:00',
            $this->requestRepository->findById($requestId)->decisionEmailSentAt?->format('Y-m-d H:i:s')
        );
    }

    /**
     * An SMTP failure: surfaced to the chief, recorded as a failed attempt
     * whose message is a French sentence and never the transport's text,
     * and the family still sees « en attente ». A retry is a second
     * attempt, which can then succeed — the failed one stays in the
     * history.
     */
    public function testMailDeliveryFailureIsSurfacedRecordedAndRetriable(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $sentTo = [];
        $mailService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (string $to) use (&$sentTo): void {
                $sentTo[] = $to;
                if (count($sentTo) === 1) {
                    throw new MailException('550 5.1.1 <marie@example.com>: Recipient address rejected');
                }
            });
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('refused');

        try {
            $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected RegistrationException on mail delivery failure');
        } catch (RegistrationException) {
            // expected — never silently swallowed
        }

        [$failed] = $this->history($requestId);
        $this->assertSame(DecisionEmail::STATE_FAILED, $failed->state);
        $this->assertSame(DecisionEmail::FAILURE_MAIL_TRANSPORT, $failed->failureCode);
        $this->assertNotNull($failed->failedAt);
        $this->assertStringContainsString('réessayez', (string) $failed->failureMessage);
        $this->assertStringNotContainsString('marie@example.com', (string) $failed->failureMessage);
        $this->assertStringNotContainsString('550', (string) $failed->failureMessage);
        $this->assertNull($this->requestRepository->findById($requestId)->refusedEmailSentAt());

        $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');

        $history = $this->history($requestId);
        $this->assertSame([DecisionEmail::STATE_FAILED, DecisionEmail::STATE_SENT], array_map(
            static fn(DecisionEmail $email) => $email->state,
            $history
        ));
        $this->assertSame([1, 2], array_map(static fn(DecisionEmail $email) => $email->attempt, $history));
        $this->assertFalse($history[1]->isResend, 'a retry after a failure is not a resend');
        $this->assertSame(['marie@example.com', 'marie@example.com'], $sentTo);
        $this->assertNotNull($this->requestRepository->findById($requestId)->refusedEmailSentAt());
    }

    /**
     * Acceptance and its resend carry the unit in copy (chantier #843,
     * D9) — by default the unit's own address — and so does a refusal.
     */
    public function testBothDecisionEmailsAndTheirResendsCarryTheUnitAddressInCopy(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $copies = [];
        $this->recordCopies($mailService, $this->exactly(3), $copies);
        $service = $this->buildService($mailService, $this->familyEmailCopy('1', '', 'info@unite.example.com'));
        $acceptedId = $this->createRequest('accepted');
        $refusedId = $this->createRequest('refused', 'paul@example.com');

        $service->sendAccepted($this->requestRepository->findById($acceptedId), '2026-2027');
        $service->sendAccepted($this->requestRepository->findById($acceptedId), '2026-2027');
        $service->sendRefused($this->requestRepository->findById($refusedId), '2026-2027');

        // The family stays the recipient; the unit is the copy.
        $this->assertSame([
            ['to' => 'marie@example.com', 'cc' => 'info@unite.example.com'],
            ['to' => 'marie@example.com', 'cc' => 'info@unite.example.com'],
            ['to' => 'paul@example.com', 'cc' => 'info@unite.example.com'],
        ], $copies);
        $this->assertTrue($this->history($acceptedId)[1]->isResend, 'the second acceptance is a resend.');
    }

    /** A retry after a failed attempt carries the copy too. */
    public function testARetryAfterAFailureCarriesTheCopyToo(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $copies = [];
        $this->recordCopies($mailService, $this->exactly(2), $copies, 1);
        $service = $this->buildService($mailService, $this->familyEmailCopy('1', '', 'info@unite.example.com'));
        $requestId = $this->createRequest('refused');

        try {
            $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('the first attempt fails.');
        } catch (RegistrationException) {
            // expected
        }
        $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');

        $sent = ['to' => 'marie@example.com', 'cc' => 'info@unite.example.com'];
        $this->assertSame([$sent, $sent], $copies);
    }

    /** An address typed in the box replaces the unit's own. */
    public function testATypedCopyAddressReplacesTheUnitAddress(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $copies = [];
        $this->recordCopies($mailService, $this->once(), $copies);
        $copy = $this->familyEmailCopy('1', 'secretariat@example.com', 'info@unite.example.com');
        $service = $this->buildService($mailService, $copy);

        $service->sendRefused($this->requestRepository->findById($this->createRequest('refused')), '2026-2027');

        $this->assertSame([['to' => 'marie@example.com', 'cc' => 'secretariat@example.com']], $copies);
    }

    /** Switched off, the decision goes to the family alone. */
    public function testNoCopyWhenTheOptionIsOff(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $copies = [];
        $this->recordCopies($mailService, $this->once(), $copies);
        $copy = $this->familyEmailCopy('0', 'secretariat@example.com', 'info@unite.example.com');
        $service = $this->buildService($mailService, $copy);

        $service->sendAccepted($this->requestRepository->findById($this->createRequest('accepted')), '2026-2027');

        $this->assertSame([['to' => 'marie@example.com', 'cc' => null]], $copies);
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
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé. {{ lien_suivi }}</p>', 'rich_text', 1);
        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willThrowException(
            new MailException('SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting')
        );
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('refused');

        try {
            $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected a RegistrationException.');
        } catch (RegistrationException $e) {
            $this->assertStringNotContainsString('SMTP', $e->getMessage());
            $this->assertStringNotContainsString('PHPMailer', $e->getMessage());
            $this->assertStringContainsString('réessayez', $e->getMessage());
            // The detail is not lost — it is on the cause, and in the
            // journal entry written just above the throw.
            $this->assertInstanceOf(MailException::class, $e->getPrevious());
        }
        $this->assertStringNotContainsString('SMTP', (string) $this->history($requestId)[0]->failureMessage);
    }

    /**
     * Issue #932: the link is the request's own, generated once at filing.
     * A failed send changes nothing about it — same token, same expiry.
     */
    public function testFailedSendLeavesTheTrackingLinkUntouched(): void
    {
        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willThrowException(new MailException('SMTP down'));
        $service = $this->buildService($mailService);
        $created = $this->createRequestWithToken('refused');
        $before = $this->requestRepository->trackingLinkOf($created['id']);

        try {
            $service->sendRefused($this->requestRepository->findById($created['id']), '2026-2027');
            $this->fail('Expected a RegistrationException.');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertTrue($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
        $this->assertEquals($before, $this->requestRepository->trackingLinkOf($created['id']));
    }

    /**
     * The same holds when the link guard refuses: no e-mail, no change.
     */
    public function testAGuardedSendLeavesTheTrackingLinkUntouched(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);
        $service = $this->buildService($this->createStub(MailService::class));
        $created = $this->createRequestWithToken('accepted');
        $before = $this->requestRepository->trackingLinkOf($created['id']);

        try {
            $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');
            $this->fail('Expected a RegistrationException.');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertTrue($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
        $this->assertEquals($before, $this->requestRepository->trackingLinkOf($created['id']));
    }

    /**
     * Replaces `testSuccessfulSendRotatesTheTrackingToken()`, which asserted
     * the behaviour issue #932 removes: a successful send used to replace
     * the family's token, killing the link its receipt carried. It now
     * writes nothing on the request — the link the receipt carried is the
     * link of the decision e-mail, and still works afterwards.
     */
    public function testSuccessfulSendKeepsTheTrackingTokenTheReceiptCarried(): void
    {
        $mailService = $this->createMock(MailService::class);
        $recipients = [];
        $this->recordRecipients($mailService, $this->once(), $recipients);
        $service = $this->buildService($mailService);
        $created = $this->createRequestWithToken('accepted');
        $hashBefore = $this->trackingHash($created['id']);
        $encryptedBefore = $this->trackingEncrypted($created['id']);

        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');

        // This request carries a different address from the fixture's, so
        // the assertion tells the two apart rather than matching whatever
        // the service last sent to.
        $this->assertSame(['p@example.com'], $recipients);
        $this->assertTrue($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
        $this->assertSame($hashBefore, $this->trackingHash($created['id']), 'no new hash was written');
        $this->assertSame($encryptedBefore, $this->trackingEncrypted($created['id']), 'no new ciphertext was written');
    }

    /**
     * The acceptance criterion that names the whole feature: the link of
     * the receipt, of the acceptance, of its manual resend, of a retry after
     * a failure and of the refusal of the same request are ONE url.
     */
    public function testEveryEmailOfARequestCarriesTheSameLink(): void
    {
        $mailService = $this->createStub(MailService::class);
        $bodies = [];
        $failuresLeft = 1;
        $positions = [];
        foreach ((new \ReflectionMethod(MailService::class, 'send'))->getParameters() as $parameter) {
            $positions[$parameter->getName()] = $parameter->getPosition();
        }
        $mailService->method('send')->willReturnCallback(
            function (...$arguments) use (&$bodies, &$failuresLeft, $positions): void {
                if ($failuresLeft > 0) {
                    $failuresLeft--;
                    throw new MailException('SMTP down');
                }
                $bodies[] = (string) $arguments[$positions['bodyHtml']];
            }
        );
        $service = $this->buildService($mailService);
        $created = $this->createRequestWithToken('accepted');
        $expected = 'https://example.com/inscriptions/suivi/' . $created['id'] . '/' . $created['tracking_token'];

        try {
            $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');
            $this->fail('The first attempt was meant to fail.');
        } catch (RegistrationException) {
            // expected: the transport refused
        }
        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027'); // the retry
        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027'); // a resend
        $this->requestRepository->updateStatus($created['id'], 'refused', new \DateTimeImmutable());
        $service->sendRefused($this->requestRepository->findById($created['id']), '2026-2027');

        $this->assertCount(3, $bodies);
        foreach ($bodies as $body) {
            $this->assertStringContainsString('href="' . $expected . '"', $body);
            // The href and the visible text: two spellings of the one url.
            $this->assertSame(2, preg_match_all('#https://[^"<\s]*/inscriptions/suivi/\d+/[^"<\s]+#', $body, $found));
            $this->assertSame([$expected], array_values(array_unique($found[0])));
        }
        $this->assertTrue($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
    }

    /**
     * The message is out, then recording it failed (a database error). The
     * chief is not told the send failed, because it did not; the lost
     * record is journaled. The family's link is, as ever, the one the
     * e-mail carries.
     */
    public function testARecordingFailureAfterTheSendStillLeavesTheLinkWorking(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send')->with($this->identicalTo('p@example.com'));
        $failingRecords = new class ($this->pdo) extends DecisionEmailRepository {
            public function markSent(int $id, \DateTimeImmutable $now): bool
            {
                throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
        };
        $journal = new JournalService(new JournalRepository($this->pdo));
        $service = new RequestEmailService(
            $this->requestRepository,
            $mailService,
            $this->editableContentService,
            $journal,
            'https://example.com',
            'Unité Test',
            new DecisionEmailService($failingRecords, $journal)
        );
        $created = $this->createRequestWithToken('accepted');

        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');

        $this->assertTrue($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'registration_decision_email_outcome_lost'"
        )->fetchColumn());
    }

    /**
     * Sent after the link expired (issue #932): the e-mail still leaves,
     * without a new token and without extending the validity, and it says
     * the public tracking is no longer accessible instead of letting the
     * family think the link works.
     */
    public function testAnEmailSentAfterTheLinkExpiredStillLeavesAndSaysSo(): void
    {
        $mailService = $this->createMock(MailService::class);
        $bodies = [];
        $this->recordBodies($mailService, $this->once(), $bodies);
        $service = $this->buildService($mailService);
        $created = $this->createRequestWithToken('accepted', new \DateTimeImmutable('-2 years'));
        $expiresBefore = $this->requestRepository->trackingLinkOf($created['id'])['expires_at'];
        $this->assertFalse($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));

        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');

        $this->assertSame(['p@example.com'], array_column($bodies, 'to'));
        $this->assertStringContainsString('le suivi public de cette demande n', $bodies[0]['html']);
        $this->assertStringContainsString('plus accessible', $bodies[0]['text']);
        $this->assertSame(
            [DecisionEmail::STATE_SENT],
            array_map(static fn(DecisionEmail $e) => $e->state, $this->history($created['id']))
        );
        $this->assertEquals($expiresBefore, $this->requestRepository->trackingLinkOf($created['id'])['expires_at']);
        $this->assertFalse($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
    }

    public function testAnEmailSentWhileTheLinkIsValidDoesNotMentionAnExpiry(): void
    {
        $mailService = $this->createMock(MailService::class);
        $bodies = [];
        $this->recordBodies($mailService, $this->once(), $bodies);
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        $this->assertSame(['marie@example.com'], array_column($bodies, 'to'));
        $this->assertStringNotContainsString('plus accessible', $bodies[0]['text']);
    }

    /**
     * A request with no readable link (a row from before the token was
     * stored) cannot be written to: nothing leaves, the attempt is failed
     * and says why — never a mail without its link.
     */
    public function testARequestWithoutAReadableLinkSendsNothingAndSaysWhy(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');
        $this->pdo->exec('UPDATE registration_requests SET tracking_token_encrypted = NULL WHERE id = ' . $requestId);

        try {
            $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected a RegistrationException.');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('lien de suivi', $e->getMessage());
        }

        [$attempt] = $this->history($requestId);
        $this->assertSame(DecisionEmail::STATE_FAILED, $attempt->state);
    }

    /**
     * Never the token or the url in the journal: the sent entry names the
     * attempt, and the failure entry names the request.
     */
    public function testNeitherTheTokenNorTheUrlReachesTheJournal(): void
    {
        $mailService = $this->createStub(MailService::class);
        $service = $this->buildService($mailService);
        $ok = $this->createRequestWithToken('accepted');
        $service->sendAccepted($this->requestRepository->findById($ok['id']), '2026-2027');

        $failing = $this->createStub(MailService::class);
        $failing->method('send')->willThrowException(new MailException('SMTP down'));
        $failService = $this->buildService($failing);
        $bad = $this->createRequestWithToken('refused', null, 'z@example.com');
        try {
            $failService->sendRefused($this->requestRepository->findById($bad['id']), '2026-2027');
        } catch (RegistrationException) {
            // expected
        }

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString($ok['tracking_token'], $journal);
        $this->assertStringNotContainsString($bad['tracking_token'], $journal);
        $this->assertStringNotContainsString('/inscriptions/suivi/', $journal);
    }

    /**
     * The claim: while another send of this request's e-mail is in flight
     * — another click, the automatic sending — a second one sends nothing
     * and says so.
     */
    public function testASendWhileAnotherIsInFlightSendsNothing(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');
        $this->assertNotNull($this->decisionEmails->insert(
            $requestId,
            DecisionEmail::DECISION_ACCEPTED,
            DecisionEmail::STATE_SENDING,
            null,
            1,
            false,
            new \DateTimeImmutable()
        ));

        try {
            $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('A second send must be refused while one is in flight.');
        } catch (RegistrationException $e) {
            $this->assertStringStartsWith('Un envoi de cet e-mail est déjà en cours', $e->getMessage());
        }

        $this->assertCount(1, $this->history($requestId));
    }

    /**
     * « Envoyer maintenant » on an e-mail already planned takes THAT
     * e-mail — one row, sent — rather than sending a second one beside it.
     */
    public function testAManualSendTakesTheScheduledEmail(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send')->with($this->identicalTo('marie@example.com'));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $requestId = $this->createRequest('accepted');
        $scheduled = (new DecisionEmailService($this->decisionEmails, $journal))->schedule(
            $this->requestRepository->findById($requestId),
            new \DateTimeImmutable('+1 day')
        );
        $this->assertNotNull($scheduled);

        $this->buildService($mailService)->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        $history = $this->history($requestId);
        $this->assertCount(1, $history);
        $this->assertSame($scheduled->id, $history[0]->id);
        $this->assertSame(DecisionEmail::STATE_SENT, $history[0]->state);
    }

    /**
     * A send claimed long ago and never reported back (the process died)
     * would block the request for ever. The next send records it as
     * interrupted — without resending it on its own — and goes ahead.
     */
    public function testAnInterruptedSendIsRecordedAndNoLongerBlocksTheNextOne(): void
    {
        $this->writeBodies();
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send')->with($this->identicalTo('marie@example.com'));
        $requestId = $this->createRequest('accepted');
        $stale = new \DateTimeImmutable('-' . (DecisionEmailService::STALE_SENDING_MINUTES + 1) . ' minutes');
        $this->decisionEmails->insert($requestId, 'accepted', DecisionEmail::STATE_SENDING, null, 1, false, $stale);

        $this->buildService($mailService)->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        $history = $this->history($requestId);
        $this->assertSame(
            [DecisionEmail::FAILURE_INTERRUPTED, null],
            array_map(static fn(DecisionEmail $email) => $email->failureCode, $history)
        );
        $this->assertSame(DecisionEmail::STATE_SENT, $history[1]->state);
        $this->assertSame(2, $history[1]->attempt);
    }

    /**
     * A send claimed a moment ago is still somebody's: it is not taken for
     * interrupted.
     */
    public function testARecentClaimIsNotTakenForAnInterruptedSend(): void
    {
        $this->writeBodies();
        $requestId = $this->createRequest('accepted');
        $recent = new \DateTimeImmutable('-' . (DecisionEmailService::STALE_SENDING_MINUTES - 1) . ' minutes');
        $this->decisionEmails->insert($requestId, 'accepted', DecisionEmail::STATE_SENDING, null, 1, false, $recent);

        try {
            $this->buildService($this->createStub(MailService::class))
                ->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('A recent claim must still block.');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertSame([DecisionEmail::STATE_SENDING], array_map(
            static fn(DecisionEmail $email) => $email->state,
            $this->history($requestId)
        ));
    }

    /**
     * The journal says which attempt it was and whether it was a resend —
     * never the family it went to.
     */
    public function testTheSentJournalEntryCarriesTheAttemptAndNothingAboutTheFamily(): void
    {
        $this->writeBodies();
        $service = $this->buildService($this->createStub(MailService::class));
        $requestId = $this->createRequest('accepted');

        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
        $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');

        $rows = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'registration_accepted_email_sent'
             ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(
            [
                ['request_id' => $requestId, 'attempt' => 1, 'resend' => false, 'automatic' => false],
                ['request_id' => $requestId, 'attempt' => 2, 'resend' => true, 'automatic' => false],
            ],
            array_map(static fn(array $row) => json_decode((string) $row['context'], true), $rows)
        );
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('marie@example.com', $row['description'] . $row['context']);
            $this->assertStringNotContainsString('Léa', $row['description'] . $row['context']);
        }
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

    /**
     * The fiche's block (IT-08): none for a request without a decision, and
     * one for each decision — no template is ever « à rédiger » (issue #932).
     */
    public function testThePanelFollowsTheDecision(): void
    {
        $service = $this->buildService($this->createStub(MailService::class));

        $this->assertNull($service->panel($this->requestRepository->findById($this->createRequest())));
        $this->assertSame(
            DecisionEmailPanel::STATE_NONE,
            $service->panel($this->requestRepository->findById($this->createRequest('accepted')))?->state
        );
        $this->assertSame(
            DecisionEmailPanel::STATE_NONE,
            $service->panel($this->requestRepository->findById($this->createRequest('refused')))?->state
        );
    }

    /**
     * A fiche button the block does not offer sends nothing and records
     * nothing: the button is not on the page, so nothing claims to have
     * tried.
     */
    public function testAFicheActionTheBlockDoesNotOfferSendsAndRecordsNothing(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->buildService($mailService);

        $pending = $this->createRequest();
        $accepted = $this->createRequest('accepted');
        $refusals = [
            [$pending, DecisionEmailPanel::ACTION_SEND_NOW, "Cette demande n'a pas de décision"],
            [$accepted, DecisionEmailPanel::ACTION_RETRY, "Il n'y a pas d'échec à réessayer"],
            [$accepted, DecisionEmailPanel::ACTION_RESEND, "Cet e-mail n'est pas encore parti"],
            [$accepted, 'delete', 'Action inconnue'],
        ];
        foreach ($refusals as [$id, $action, $message]) {
            try {
                $service->sendFromRequestPage($this->requestRepository->findById($id), $action, '');
                $this->fail("« {$action} » must be refused");
            } catch (RegistrationException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }

        foreach ([$pending, $accepted] as $id) {
            $this->assertSame([], $this->history($id));
        }
    }

    /**
     * « Renvoyer l'e-mail » on a body that lost its link since the e-mail
     * went out: the resend is a failed attempt like any other, the first
     * send stays in the history as sent.
     */
    public function testAResendOfABodyThatLostItsLinkIsAFailedAttempt(): void
    {
        $service = $this->buildService($this->createStub(MailService::class));
        $this->writeBodies();
        $id = $this->createRequest('accepted');
        $request = $this->requestRepository->findById($id);
        $service->sendFromRequestPage($request, DecisionEmailPanel::ACTION_SEND_NOW, '');
        $this->editableContentService->set('registration_email_accepted_body', '<p>Sans lien</p>', 'rich_text', 1);

        try {
            $service->sendFromRequestPage($request, DecisionEmailPanel::ACTION_RESEND, '');
            $this->fail('A resend without the link sends nothing.');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('ne contient pas le lien de suivi', $e->getMessage());
        }
        $states = array_map(static fn(DecisionEmail $e) => $e->state, $this->history($id));
        sort($states);
        $this->assertSame([DecisionEmail::STATE_FAILED, DecisionEmail::STATE_SENT], $states);
    }
}
