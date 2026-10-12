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
     * @return array<int, DecisionEmail>
     */
    private function history(int $requestId): array
    {
        return $this->decisionEmails->findForRequest($requestId);
    }

    private function writeBodies(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue !</p>', 'rich_text', 1);
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
    }

    public function testBodiesAreNotReadyByDefault(): void
    {
        $service = $this->buildService($this->createStub(MailService::class));

        $this->assertFalse($service->isAcceptedBodyReady());
        $this->assertFalse($service->isRefusedBodyReady());
    }

    /**
     * The guard D10 keeps, on a request that does hold the decision: no
     * body, no e-mail — and the attempt is recorded as FAILED, never as a
     * success, with a sentence a chief may read. The transport is never
     * reached.
     */
    public function testAMissingBodyIsRecordedAsAFailedAttemptNeverASuccess(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');

        try {
            $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected a RegistrationException for an unwritten body.');
        } catch (RegistrationException $e) {
            $this->assertSame("Le corps de l'email d'acceptation n'a pas encore été rédigé.", $e->getMessage());
        }

        [$attempt] = $this->history($requestId);
        $this->assertSame(DecisionEmail::STATE_FAILED, $attempt->state);
        $this->assertSame(DecisionEmail::FAILURE_BODY_NOT_WRITTEN, $attempt->failureCode);
        $this->assertSame("Le corps de l'email d'acceptation n'a pas encore été rédigé.", $attempt->failureMessage);
        $this->assertNull($attempt->sentAt);
        $this->assertNull($this->requestRepository->findById($requestId)->decisionEmailSentAt);
    }

    /**
     * « À rédiger » has one definition (RequestEmailTemplateService::
     * isToWrite(), IT-07): a body the editor emptied to `<p>&nbsp;</p>`
     * is no body. The guard refuses it, records the failed attempt, and
     * the transport is never reached.
     */
    public function testABodyEmptiedByTheEditorCountsAsNotWritten(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>&nbsp;</p>', 'rich_text', 1);
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->buildService($mailService);
        $requestId = $this->createRequest('accepted');
        $this->assertFalse($service->isAcceptedBodyReady());

        try {
            $service->sendAccepted($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected a RegistrationException for a body that renders as nothing.');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertSame(
            [DecisionEmail::FAILURE_BODY_NOT_WRITTEN],
            array_map(static fn(DecisionEmail $email) => $email->failureCode, $this->history($requestId))
        );
    }

    public function testAMissingRefusalBodyIsRecordedAsAFailedAttemptToo(): void
    {
        $service = $this->buildService($this->createStub(MailService::class));
        $requestId = $this->createRequest('refused');

        try {
            $service->sendRefused($this->requestRepository->findById($requestId), '2026-2027');
            $this->fail('Expected a RegistrationException for an unwritten body.');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertSame(
            [DecisionEmail::FAILURE_BODY_NOT_WRITTEN],
            array_map(static fn(DecisionEmail $email) => $email->failureCode, $this->history($requestId))
        );
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
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue {{prenom_enfant}} !</p>', 'rich_text', 1);
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
        $this->assertStringContainsString('lien de suivi', (string) $failed->failureMessage);
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
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé.</p>', 'rich_text', 1);
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
            $this->assertStringContainsString('lien de suivi', $e->getMessage());
            // The detail is not lost — it is on the cause, and in the
            // journal entry written just above the throw.
            $this->assertInstanceOf(MailException::class, $e->getPrevious());
        }
        $this->assertStringNotContainsString('SMTP', (string) $this->history($requestId)[0]->failureMessage);
    }

    /**
     * A failed send must leave the family's EXISTING tracking link alive:
     * the token used to be rotated and persisted before the send, so an SMTP
     * outage killed the link the parent already had while the replacement
     * never reached them (R6).
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
        $this->requestRepository->updateStatus($created['id'], 'refused', new \DateTimeImmutable());

        try {
            $service->sendRefused($this->requestRepository->findById($created['id']), '2026-2027');
            $this->fail('Expected a RegistrationException.');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertTrue($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
    }

    /**
     * The same holds when the body guard refuses: no e-mail, no new link.
     */
    public function testAMissingBodyLeavesThePreviousTrackingLinkUsable(): void
    {
        $service = $this->buildService($this->createStub(MailService::class));
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'D', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'p@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        try {
            $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');
            $this->fail('Expected a RegistrationException.');
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
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');

        // This request carries a different address from the fixture's, so
        // the assertion tells the two apart rather than matching whatever
        // the service last sent to.
        $this->assertSame(['p@example.com'], $recipients);
        $this->assertFalse($this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']));
    }

    /**
     * The message left, then recording it failed (a database error). The
     * family must still get a working link — the one the e-mail carries —
     * and the lost record is journaled; the chief is not told the send
     * failed, because it did not.
     */
    public function testARecordingFailureAfterTheSendStillStoresTheNewLink(): void
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
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'P', 'child_last_name' => 'D', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'p@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        $service->sendAccepted($this->requestRepository->findById($created['id']), '2026-2027');

        $this->assertFalse(
            $this->requestRepository->verifyTrackingToken($created['id'], $created['tracking_token']),
            'the link the e-mail carries replaced the old one'
        );
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'registration_decision_email_outcome_lost'"
        )->fetchColumn());
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
                ['request_id' => $requestId, 'attempt' => 1, 'resend' => false],
                ['request_id' => $requestId, 'attempt' => 2, 'resend' => true],
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
}
