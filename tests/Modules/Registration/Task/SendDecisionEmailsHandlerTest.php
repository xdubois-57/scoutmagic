<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Notification\NotificationService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\AutomaticDecisionEmailService;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\FamilyEmailCopyService;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Task\SendDecisionEmailsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The automatic sending of the decision e-mails (issue #843, IT-09): the
 * task that sends each planned e-mail once its due date has come.
 *
 * Real collaborators throughout — the planning service, the decision
 * e-mail rows, the template guard, the copy to the unit, the scheduler —
 * and only the transport doubled, since what was sent is what is asserted.
 * The clock the task reads is handed to it, so « 24 hours later » is a
 * date and not a wait. The races across two sessions are on MariaDB, in
 * SendDecisionEmailsHandlerOnMysqlTest.
 */
#[Group('database')]
class SendDecisionEmailsHandlerTest extends TestCase
{
    private const DECIDED_AT = '2027-01-10 08:00:00';

    private \PDO $pdo;
    private SettingService $settings;
    private EditableContentService $content;
    private RegistrationRequestRepository $requests;
    private RequestStatusService $statusService;
    private DecisionEmailRepository $emails;
    private AutomaticDecisionEmailService $automation;
    private TaskContext $context;
    private int $yearId;

    /** @var list<array{to: string, cc: mixed, subject: string, html: string}> */
    private array $sent = [];

    /** How many of the next sends the transport refuses. */
    private int $refusals = 0;
    private int $temporaryRefusals = 0;
    private int $notificationFailures = 0;
    private int $deferralBypasses = 0;
    /** @var list<array{years: array, roles: array}> */
    private array $recipientQueries = [];
    /** @var list<array{type: string, recipients: array, payload: array}> */
    private array $alerts = [];

    /** How many of the next sends break with something that is not a refusal. */
    private int $crashes = 0;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settings);
        $this->settings->register('base_url', 'https://exemple.be', 'text', 'URL', 'Test.');
        $this->settings->register('site_name', 'Unité test', 'text', 'Nom du site', 'Test.');
        $this->settings->register(
            FamilyEmailCopyService::SETTING_UNIT_ADDRESS,
            'unite@example.com',
            'email',
            'Contact',
            'Test.'
        );

        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->emails = new DecisionEmailRepository($this->pdo);
        $this->requests = new RegistrationRequestRepository($this->pdo, $encryption, null, $this->emails);
        $this->statusService = new RequestStatusService($this->requests, $journal);
        $this->content = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->content->set('registration_email_accepted_body', '<p>Bienvenue {{ prenom_enfant }} {{ lien_suivi }}</p>', 'rich_text', 1);
        $this->content->set('registration_email_refused_body', '<p>Désolés. {{ lien_suivi }}</p>', 'rich_text', 1);

        $mailService = $this->recordingMailService();
        $this->automation = new AutomaticDecisionEmailService(
            $this->settings,
            new DecisionEmailService($this->emails, $journal)
        );
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24);

        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(function ($type, $recipients, $payload): void {
            if ($this->notificationFailures > 0) {
                $this->notificationFailures--;
                throw new \RuntimeException('Notification unavailable');
            }
            $this->alerts[] = ['type' => $type, 'recipients' => $recipients, 'payload' => $payload];
        });
        $accounts = $this->createStub(UserAccountRepository::class);
        $accounts->method('findStaffAndSuperAdminIds')->willReturnCallback(
            function (array $years, array $roles): array {
                $this->recipientQueries[] = ['years' => $years, 'roles' => $roles];
                return [9];
            }
        );
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $mailService,
            $journal,
            $this->settings,
            $accounts,
            sys_get_temp_dir(),
            $notifications
        );
        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
    }

    // ── The due date ────────────────────────────────────────────────────

    /**
     * Planned 24 hours after the decision: nothing a second before, the
     * e-mail at the due date — to the family, the unit in copy — and the
     * family sees the decision from then on.
     */
    public function testAnEmailLeavesAtItsDueDateAndNotASecondBefore(): void
    {
        $request = $this->decide('accepted');

        $this->runAt('2027-01-11 07:59:59');
        $this->assertSame([], $this->sent);
        $this->assertSame(['scheduled'], $this->states($request->id));

        $this->runAt('2027-01-11 08:00:00');
        $this->assertCount(1, $this->sent);
        $this->assertSame('marie@example.com', $this->sent[0]['to']);
        $this->assertSame('unite@example.com', $this->sent[0]['cc']);
        $this->assertSame("Votre demande d'inscription a été acceptée", $this->sent[0]['subject']);
        $this->assertSame(['sent'], $this->states($request->id));
        $this->assertSame(
            'accepted',
            $this->statusService->visibleStatus($this->reload($request->id))
        );

        // And once only: a later pass finds nothing due.
        $this->runAt('2027-01-12 08:00:00');
        $this->assertCount(1, $this->sent);
    }

    /**
     * The link the e-mail carries is the one the request was filed with
     * (issue #932): nothing is renewed by the send. The journal says the
     * send was automatic, and counts without naming anybody.
     */
    public function testASentEmailKeepsTheFamilysLinkAndIsJournaledAsAutomatic(): void
    {
        $request = $this->decide('accepted');
        $tokenBefore = $this->tokenHash($request->id);
        $token = $this->requests->trackingLinkOf($request->id)['token'] ?? '';
        $this->assertNotSame('', $token);

        $this->runAt('2027-01-11 09:00:00');

        $this->assertSame($tokenBefore, $this->tokenHash($request->id));
        $this->assertStringContainsString("/inscriptions/suivi/{$request->id}/{$token}", $this->sent[0]['html']);
        $this->assertSame(
            ['request_id' => $request->id, 'attempt' => 1, 'resend' => false, 'automatic' => true],
            $this->journalContext('registration_accepted_email_sent')
        );
        $this->assertSame(
            ['sent' => 1, 'failed' => 0, 'skipped' => 0],
            $this->journalContext('registration_decision_emails_auto_sent')
        );
    }

    /**
     * A pass with nothing due sends nothing and writes no line of its own
     * (the scheduler already journals that it ran).
     */
    public function testAPassWithNothingDueWritesNothing(): void
    {
        $this->decide('accepted');

        $this->runAt('2027-01-10 12:00:00');

        $this->assertSame([], $this->sent);
        $this->assertNull($this->journalContext('registration_decision_emails_auto_sent'));
    }

    // ── Cancellation and a new planning ─────────────────────────────────

    /**
     * Changed before its e-mail left, a decision cancels it — the old
     * e-mail never leaves — and the new decision waits a full delay from
     * the change, not from the first decision.
     */
    public function testAChangeOfDecisionCancelsThePlannedEmailAndTheNewOneWaitsAFullDelay(): void
    {
        $request = $this->decide('accepted');

        // Twenty hours later, the chief refuses instead.
        $this->statusService->refuse($request, 'other');
        $this->automation->plan(
            $this->reload($request->id),
            DecisionEmail::DECISION_REFUSED,
            new \DateTimeImmutable('2027-01-11 04:00:00')
        );

        $this->runAt('2027-01-11 08:00:00');
        $this->assertSame([], $this->sent);
        $this->assertSame(['cancelled', 'scheduled'], $this->states($request->id));

        $this->runAt('2027-01-12 03:59:59');
        $this->assertSame([], $this->sent);

        $this->runAt('2027-01-12 04:00:00');
        $this->assertCount(1, $this->sent);
        $this->assertSame("Votre demande d'inscription a été refusée", $this->sent[0]['subject']);
        $this->assertSame(['cancelled', 'sent'], $this->states($request->id));
    }

    // ── What never passes for a success ────────────────────────────────

    /**
     * A template that lost its `{{ lien_suivi }}` after the e-mail was
     * planned: nothing leaves, the attempt is recorded failed with its
     * reason, the family's link is untouched and it still sees « en attente ».
     */
    public function testABodyWithoutTheLinkIsRecordedAsAFailureAndNeverPassesForASuccess(): void
    {
        $request = $this->decide('accepted');
        $tokenBefore = $this->tokenHash($request->id);
        $this->content->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);

        $this->runAt('2027-01-11 08:00:00');

        $this->assertSame([], $this->sent);
        $rows = $this->emails->findForRequest($request->id);
        $this->assertSame(DecisionEmail::STATE_FAILED, $rows[0]->state);
        $this->assertSame(DecisionEmail::FAILURE_BODY_NOT_WRITTEN, $rows[0]->failureCode);
        $this->assertSame($tokenBefore, $this->tokenHash($request->id));
        $this->assertSame('pending', $this->statusService->visibleStatus($this->reload($request->id)));
        $this->assertSame(
            ['sent' => 0, 'failed' => 1, 'skipped' => 0],
            $this->journalContext('registration_decision_emails_auto_sent')
        );
        // A failure is not retried on its own: « Réessayer » is the chief's.
        $this->runAt('2027-01-12 08:00:00');
        $this->assertCount(1, $this->emails->findForRequest($request->id));
    }

    /**
     * The relay refuses: the attempt is recorded failed with a sentence a
     * chief may read — never the relay's own text — and the link stays.
     */
    public function testATransportFailureIsRecordedAndTheLinkIsKept(): void
    {
        $request = $this->decide('refused');
        $tokenBefore = $this->tokenHash($request->id);
        $this->refusals = 1;

        $this->runAt('2027-01-11 08:00:00');

        $rows = $this->emails->findForRequest($request->id);
        $this->assertSame(DecisionEmail::STATE_FAILED, $rows[0]->state);
        $this->assertSame(DecisionEmail::FAILURE_MAIL_TRANSPORT, $rows[0]->failureCode);
        $this->assertStringNotContainsString('SMTP', (string) $rows[0]->failureMessage);
        $this->assertSame($tokenBefore, $this->tokenHash($request->id));
    }

    // ── Never twice ─────────────────────────────────────────────────────

    /**
     * A pass killed after it claimed an e-mail — whether that one left is
     * unknown — resumes without sending it again: the replay sends the
     * others and leaves that one to the chief.
     */
    public function testAnEmailClaimedByAnInterruptedPassIsNeverSentAgain(): void
    {
        $first = $this->decide('accepted', 'Alix');
        $second = $this->decide('accepted', 'Basile');
        $claimed = $this->emails->findForRequest($first->id)[0];
        $this->assertTrue($this->emails->claim($claimed->id, new \DateTimeImmutable()));

        $this->runAt('2027-01-11 08:00:00');

        $this->assertSame(['alix@example.com' => false, 'basile@example.com' => true], [
            'alix@example.com' => in_array('alix@example.com', array_column($this->sent, 'to'), true),
            'basile@example.com' => in_array('basile@example.com', array_column($this->sent, 'to'), true),
        ]);
        $this->assertSame(['sending'], $this->states($first->id));
        $this->assertSame(['sent'], $this->states($second->id));
    }

    /**
     * A chief's « Envoyer maintenant » took the planned e-mail first: the
     * task, holding the row it read before, sends nothing — never a second
     * e-mail in its place.
     */
    public function testAnEmailSentByHandMeanwhileIsSkippedNotSentAgain(): void
    {
        $request = $this->decide('accepted');
        $due = $this->emails->findForRequest($request->id)[0];
        $emailService = $this->emailService();

        $emailService->sendAccepted($this->reload($request->id), '2027-2028');
        $this->assertCount(1, $this->sent);

        $outcome = $emailService->sendScheduled($due, static fn(int $scoutYearId): string => '2027-2028');

        $this->assertSame(RequestEmailService::SCHEDULED_SKIPPED, $outcome);
        $this->assertCount(1, $this->sent);
        $this->assertSame(['sent'], $this->states($request->id));
    }

    /**
     * A request whose status moved without its e-mail being superseded —
     * nothing in the code does that, a hand-edited database might — never
     * gets the e-mail of a decision it no longer holds; the row is settled
     * rather than left claimed.
     */
    public function testTheEmailOfADecisionTheRequestNoLongerHoldsIsNeverSent(): void
    {
        $request = $this->decide('accepted');
        $this->pdo->exec("UPDATE registration_requests SET status = 'refused' WHERE id = " . $request->id);

        $this->runAt('2027-01-11 08:00:00');

        $this->assertSame([], $this->sent);
        $rows = $this->emails->findForRequest($request->id);
        $this->assertSame(DecisionEmail::STATE_FAILED, $rows[0]->state);
        $this->assertSame(DecisionEmail::FAILURE_INTERRUPTED, $rows[0]->failureCode);
        $this->assertStringContainsString("rien n'est parti", (string) $rows[0]->failureMessage);
        // The run counts it as what its row now says: failed.
        $this->assertSame(
            ['sent' => 0, 'failed' => 1, 'skipped' => 0],
            $this->journalContext('registration_decision_emails_auto_sent')
        );
    }

    // ── One e-mail never stops the others ───────────────────────────────

    /**
     * A request that cannot be read — its stored address no longer
     * decrypts — fails ITS e-mail, recorded with a sentence saying nothing
     * left, and the others of the batch leave. The row is settled, so the
     * next pass is not re-armed at once for it, over and over.
     */
    public function testAnUnreadableRequestFailsItsOwnEmailAndTheOthersLeave(): void
    {
        $broken = $this->decide('accepted', 'Alix');
        $this->decide('accepted', 'Basile');
        $this->pdo->prepare("UPDATE registration_requests SET email_encrypted = 'not-a-ciphertext' WHERE id = ?")
            ->execute([$broken->id]);

        $this->runAt('2027-01-11 08:00:00');

        $this->assertSame(['basile@example.com'], array_column($this->sent, 'to'));
        $row = $this->emails->findForRequest($broken->id)[0];
        $this->assertSame(DecisionEmail::STATE_FAILED, $row->state);
        $this->assertStringContainsString("n'a pas pu être lancé : rien n'est parti", (string) $row->failureMessage);
        $this->assertSame(
            ['sent' => 1, 'failed' => 1, 'skipped' => 0],
            $this->journalContext('registration_decision_emails_auto_sent')
        );
        $error = $this->journalContext('registration_decision_email_auto_send_error');
        $this->assertSame($broken->id, $error['request_id'] ?? null);
        $this->assertStringNotContainsString('alix', strtolower((string) json_encode($error)));
        $this->assertSame([], $this->emails->findDue(new \DateTimeImmutable('2027-01-11 08:00:00'), 10));
    }

    /**
     * Something other than a refusal breaks the transport itself: whether
     * that e-mail left is unknown, which its row says — never a success,
     * never resent — and the rest of the batch still leaves.
     */
    public function testAnUnexpectedErrorDuringASendIsRecordedAsUncertainAndTheBatchGoesOn(): void
    {
        $first = $this->decide('accepted', 'Alix');
        $this->decide('accepted', 'Basile');
        $this->crashes = 1;

        $this->runAt('2027-01-11 08:00:00');

        $this->assertSame(['basile@example.com'], array_column($this->sent, 'to'));
        $row = $this->emails->findForRequest($first->id)[0];
        $this->assertSame(DecisionEmail::STATE_FAILED, $row->state);
        $this->assertStringContainsString(
            "il n'est pas certain que la famille ait reçu",
            (string) $row->failureMessage
        );

        $this->runAt('2027-01-12 08:00:00');
        $this->assertCount(1, $this->sent);
    }

    // ── The switch ──────────────────────────────────────────────────────

    /**
     * Switching the automatic sending off cancels nothing: an e-mail
     * planned before still leaves at its due date.
     */
    public function testAnEmailPlannedBeforeTheSwitchWasTurnedOffStillLeaves(): void
    {
        $request = $this->decide('accepted');
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24, false);

        $this->runAt('2027-01-11 08:00:00');

        $this->assertCount(1, $this->sent);
        $this->assertSame(['sent'], $this->states($request->id));
    }

    // ── The chain ───────────────────────────────────────────────────────

    /**
     * Each pass re-arms the poller once — never later than an hour, the
     * shortest delay a unit can set — and a pass that throws still does.
     */
    public function testEachPassReArmsTheChainOnceWithinTheHour(): void
    {
        $this->runAt('2027-01-10 08:00:00');
        $this->runAt('2027-01-10 08:00:00');

        $rows = $this->pendingChainRows();
        $this->assertCount(1, $rows);
        $this->assertLessThanOrEqual(
            (new \DateTimeImmutable('+3600 seconds'))->getTimestamp(),
            (new \DateTimeImmutable($rows[0]))->getTimestamp()
        );
    }

    public function testAFullBatchReArmsAtOnceAndTheRestLeavesOnTheNextPass(): void
    {
        for ($i = 0; $i <= SendDecisionEmailsHandler::BATCH_SIZE; $i++) {
            $this->decide('accepted', 'Enfant' . $i);
        }

        $this->runAt('2027-01-11 08:00:00');
        $this->assertCount(SendDecisionEmailsHandler::BATCH_SIZE, $this->sent);
        $rows = $this->pendingChainRows();
        $this->assertCount(1, $rows);
        $this->assertLessThanOrEqual(time() + 1, (new \DateTimeImmutable($rows[0]))->getTimestamp());

        $this->pdo->exec("DELETE FROM scheduled_actions");
        $this->runAt('2027-01-11 08:00:00');
        $this->assertCount(SendDecisionEmailsHandler::BATCH_SIZE + 1, $this->sent);
    }

    public function testTheNextRunIsTheEarliestDueDateWithinTheHour(): void
    {
        $now = new \DateTimeImmutable('2027-01-10 08:00:00');

        $this->assertSame('2027-01-10 09:00:00', SendDecisionEmailsHandler::nextRun(null, $now)->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2027-01-10 08:20:00',
            SendDecisionEmailsHandler::nextRun(new \DateTimeImmutable('2027-01-10 08:20:00'), $now)
                ->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2027-01-10 09:00:00',
            SendDecisionEmailsHandler::nextRun(new \DateTimeImmutable('2027-01-11 08:00:00'), $now)
                ->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2027-01-10 08:00:00',
            SendDecisionEmailsHandler::nextRun(new \DateTimeImmutable('2027-01-09 08:00:00'), $now)
                ->format('Y-m-d H:i:s')
        );
    }

    public function testTheTaskIsDeclaredInTheManifest(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'),
            true
        );

        $this->assertContains(
            ['key' => SendDecisionEmailsHandler::TASK_KEY, 'handler' => SendDecisionEmailsHandler::class],
            $manifest['scheduled_tasks']
        );
    }

    public function testATemporaryFailureRetriesAfterOneHourAndKeepsTheOriginalAttempt(): void
    {
        $request = $this->decide('accepted');
        $this->temporaryRefusals = 1;
        $this->runAt('2027-01-11 08:00:00');
        $this->assertSame(['failed', 'scheduled'], $this->states($request->id));
        $this->assertSame(1, $this->deferralBypasses);
        $this->assertSame('pending', $this->statusService->visibleStatus($this->reload($request->id)));
        $this->assertSame(
            '2027-01-11 09:00:00',
            $this->emails->findForRequest($request->id)[1]->dueAt?->format('Y-m-d H:i:s')
        );
        $this->assertSame([], $this->alerts);
        $this->runAt('2027-01-11 08:59:59');
        $this->assertSame([], $this->sent);
        $this->runAt('2027-01-11 09:00:00');
        $this->assertSame(['failed', 'sent'], $this->states($request->id));
        $this->assertSame('accepted', $this->statusService->visibleStatus($this->reload($request->id)));
        $this->assertCount(1, $this->sent);
        $this->assertSame([], $this->alerts);
    }

    public function testThreeFailedAutomaticAttemptsRaiseOneGenericNotification(): void
    {
        $request = $this->decide('accepted');
        $this->temporaryRefusals = 10;
        $this->runAt('2027-01-11 08:00:00');
        $this->runAt('2027-01-11 09:00:00');
        $this->assertSame(['failed', 'failed', 'scheduled'], $this->states($request->id));
        $this->assertSame(
            '2027-01-11 15:00:00',
            $this->emails->findForRequest($request->id)[2]->dueAt?->format('Y-m-d H:i:s')
        );
        $this->runAt('2027-01-11 15:00:00');
        $this->assertSame(['failed', 'failed', 'failed'], $this->states($request->id));
        $this->assertSame([[
            'type' => 'registration.decision_email_failed',
            'recipients' => [['userAccountId' => 9, 'memberId' => null]],
            'payload' => [
                'title' => 'E-mail de décision à vérifier',
                'body' => 'Un e-mail de décision nécessite une intervention. '
                    . 'Vérifiez sa réception et la fiche avant de réessayer.',
                'url' => '/config/inscriptions/demandes/' . $request->id,
            ],
        ]], $this->alerts);
        $this->runAt('2027-01-12 08:00:00');
        $this->assertCount(1, $this->alerts);
        $this->assertCount(3, $this->emails->findForRequest($request->id));
        $this->assertSame([['years' => [$this->yearId], 'roles' => ['admin']]], $this->recipientQueries);
    }

    public function testAPermanentFailureNotifiesWithoutAutomaticRetry(): void
    {
        $request = $this->decide('refused');
        $this->refusals = 1;
        $this->runAt('2027-01-11 08:00:00');
        $this->assertSame(['failed'], $this->states($request->id));
        $this->assertCount(1, $this->alerts);
    }

    public function testAChangedDecisionCancelsItsAutomaticRetry(): void
    {
        $request = $this->decide('accepted');
        $this->temporaryRefusals = 1;
        $this->runAt('2027-01-11 08:00:00');
        $this->statusService->refuse($this->reload($request->id), 'other');
        $this->runAt('2027-01-11 09:00:00');
        $this->assertSame(['failed', 'cancelled'], $this->states($request->id));
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->alerts);
    }

    public function testAManualSendTakesThePendingRetryAndDoesNotSendTwice(): void
    {
        $request = $this->decide('accepted');
        $this->temporaryRefusals = 1;
        $this->runAt('2027-01-11 08:00:00');
        $this->emailService()->sendAccepted($this->reload($request->id), '2027-2028');
        $this->runAt('2027-01-11 09:00:00');
        $this->assertSame(['failed', 'sent'], $this->states($request->id));
        $this->assertCount(1, $this->sent);
        $this->assertSame([], $this->alerts);
    }

    public function testAFailedNotificationIsRetriedWithoutSendingTheEmailAgain(): void
    {
        $request = $this->decide('accepted');
        $this->refusals = 1;
        $this->notificationFailures = 1;
        $this->runAt('2027-01-11 08:00:00');
        $this->assertSame([], $this->alerts);
        $marker = $this->pdo->query('SELECT recovery_processed_at FROM registration_decision_emails')->fetchColumn();
        $this->assertNull($marker);
        $this->runAt('2027-01-11 09:00:00');
        $this->runAt('2027-01-11 10:00:00');
        $this->assertCount(1, $this->alerts);
        $this->assertSame(['failed'], $this->states($request->id));
        $this->assertSame([], $this->sent);
    }

    public function testRecoveryResumesAFailedAttemptAfterAnInterruptedWorker(): void
    {
        $request = $this->decide('accepted');
        $email = $this->emails->findForRequest($request->id)[0];
        $now = new \DateTimeImmutable('2027-01-11 08:00:00');
        $this->emails->claim($email->id, $now, true);
        $this->emails->markFailed($email->id, DecisionEmail::FAILURE_MAIL_TEMPORARY, 'Envoi impossible.', $now);
        $this->runAt('2027-01-11 08:00:00');
        $this->assertSame(['failed', 'scheduled'], $this->states($request->id));
        $this->runAt('2027-01-11 09:00:00');
        $this->assertSame(['failed', 'sent'], $this->states($request->id));
        $this->assertCount(1, $this->sent);
    }

    public function testMissingNotificationCapabilityLeavesTheFailurePending(): void
    {
        $request = $this->decide('accepted');
        $original = $this->context;
        $this->context = new TaskContext(
            $original->connection, $original->encryption, $original->mailService,
            $original->journal, $original->settings, $original->userAccounts, $original->storagePath
        );
        $this->refusals = 1;
        $this->runAt('2027-01-11 08:00:00');
        $this->assertNull(
            $this->pdo->query('SELECT recovery_processed_at FROM registration_decision_emails')->fetchColumn()
        );
        $this->context = $original;
        $this->runAt('2027-01-11 09:00:00');
        $this->assertCount(1, $this->alerts);
        $this->assertSame(['failed'], $this->states($request->id));
    }

    public function testABodyWithoutTrackingLinkIsNotRetriedAndNotifiesStaff(): void
    {
        $request = $this->decide('accepted');
        $this->content->set('registration_email_accepted_body', '<p>Sans lien.</p>', 'rich_text', 1);
        $this->runAt('2027-01-11 08:00:00');
        $this->assertSame(['failed'], $this->states($request->id));
        $this->assertSame([], $this->sent);
        $this->assertCount(1, $this->alerts);
    }

    public function testPendingAlertsDoNotBlockRetriesWhenNotificationsAreUnavailable(): void
    {
        for ($i = 0; $i < SendDecisionEmailsHandler::BATCH_SIZE; $i++) {
            $this->decide('accepted', 'Parent' . $i);
        }
        $last = $this->decide('accepted', 'Dernier');
        $original = $this->context;
        $this->context = new TaskContext(
            $original->connection, $original->encryption, $original->mailService,
            $original->journal, $original->settings, $original->userAccounts, $original->storagePath
        );
        $this->refusals = SendDecisionEmailsHandler::BATCH_SIZE;
        $this->runAt('2027-01-11 08:00:00');
        $this->temporaryRefusals = 1;
        $this->runAt('2027-01-11 08:00:01');
        $this->assertSame(['failed', 'scheduled'], $this->states($last->id));
        $this->context = $original;
        $this->runAt('2027-01-11 09:00:01');
        $this->assertCount(1, $this->sent);
        $this->assertCount(SendDecisionEmailsHandler::BATCH_SIZE, $this->alerts);
    }

    public function testTheGenericNotificationIsStoredOnceAndCannotUseEmailEvenWithAnOverride(): void
    {
        $request = $this->decide('accepted');
        $original = $this->context;
        $encryption = $original->encryption;
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (id, email_encrypted, email_blind_index, is_super_admin) VALUES (9, ?, ?, 1)'
        );
        $stmt->execute([
            $encryption->encrypt('admin@example.com', 'user_accounts.email'),
            $encryption->blindIndex('admin@example.com', 'email'),
        ]);
        $repository = new \Core\Notification\NotificationRepository($this->pdo, $encryption);
        $preferences = new \Core\Notification\NotificationPreferenceRepository($this->pdo);
        $type = 'registration.decision_email_failed';
        $preferences->setChannel(9, $type, 'email', true);
        $notifications = new NotificationService(
            $repository,
            new \Core\Notification\PushSubscriptionRepository($this->pdo, $encryption),
            $preferences,
            null,
            $this->settings,
            $original->journal,
            \Core\Scheduler\SchedulerService::forPdo($this->pdo),
            new UserAccountRepository($this->pdo, $encryption)
        );
        $manifest = json_decode((string) file_get_contents(
            dirname(__DIR__, 4) . '/modules/registration/module.json'
        ), true);
        $notifications->registerModuleTypes('registration', $manifest['notifications']);
        $this->context = new TaskContext(
            $original->connection, $encryption, $original->mailService,
            $original->journal, $original->settings, $original->userAccounts, $original->storagePath, $notifications
        );
        $this->refusals = 1;
        $this->runAt('2027-01-11 08:00:00');
        $this->runAt('2027-01-11 09:00:00');
        $this->assertSame(1, $repository->countByUserAccountId(9));
        $record = $repository->findByUserAccountId(9)[0];
        $this->assertSame([
            $type, 'E-mail de décision à vérifier', '/config/inscriptions/demandes/' . $request->id,
        ], [$record->typeId, $record->title, $record->url]);
        $declared = $notifications->findType($type);
        $this->assertNotNull($declared);
        $this->assertFalse($notifications->channelEnabled(9, $declared, 'email'));
        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'send_notification_emails'"
        )->fetchColumn());
    }

    public function testADeadAutomaticSenderRaisesAnAlertWithoutRepeatingUncertainDelivery(): void
    {
        $request = $this->decide('accepted');
        $email = $this->emails->findForRequest($request->id)[0];
        $this->emails->claim($email->id, new \DateTimeImmutable('-11 minutes'), true);
        $this->runAt('2027-01-11 08:00:00');
        $this->runAt('2027-01-12 08:00:00');
        $record = $this->emails->findForRequest($request->id)[0];
        $this->assertSame(['failed'], $this->states($request->id));
        $this->assertSame(DecisionEmail::FAILURE_INTERRUPTED, $record->failureCode);
        $this->assertSame([], $this->sent);
        $this->assertCount(1, $this->alerts);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * A request the chief decided at DECIDED_AT, its e-mail planned by the
     * automatic sending — what RequestDecisionService::apply() does, on a
     * clock of the test's own.
     */
    private function decide(string $status, string $firstName = 'Marie'): RegistrationRequest
    {
        $id = $this->requests->create($this->yearId, [
            'parent_name' => 'Parent ' . $firstName, 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        $request = $this->reload($id);
        if ($status === 'accepted') {
            $this->statusService->accept($request);
        } else {
            $this->statusService->refuse($request, 'other');
        }

        $planned = $this->automation->plan(
            $this->reload($id),
            $status === 'accepted' ? DecisionEmail::DECISION_ACCEPTED : DecisionEmail::DECISION_REFUSED,
            new \DateTimeImmutable(self::DECIDED_AT)
        );
        $this->assertNotNull($planned, 'the decision e-mail is planned');

        return $this->reload($id);
    }

    private function runAt(string $moment): void
    {
        (new SendDecisionEmailsHandler(new \DateTimeImmutable($moment)))->handle([], $this->context);
    }

    private function reload(int $id): RegistrationRequest
    {
        $request = $this->requests->findById($id);
        $this->assertNotNull($request);

        return $request;
    }

    private function emailService(): RequestEmailService
    {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new RequestEmailService(
            $this->requests,
            $this->context->mailService,
            $this->content,
            $journal,
            'https://exemple.be',
            'Unité test',
            new DecisionEmailService($this->emails, $journal)
        );
    }

    /**
     * @return list<string>
     */
    private function states(int $requestId): array
    {
        return array_map(
            static fn(DecisionEmail $email): string => $email->state,
            $this->emails->findForRequest($requestId)
        );
    }

    private function tokenHash(int $requestId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT tracking_token_hash FROM registration_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * The context of the last journal line of $eventType, or null.
     *
     * @return array<string, mixed>|null
     */
    private function journalContext(string $eventType): ?array
    {
        $stmt = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$eventType]);
        $context = $stmt->fetchColumn();

        return $context === false ? null : json_decode((string) $context, true);
    }

    /**
     * @return list<string> the run_at of each pending row of the chain
     */
    private function pendingChainRows(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT run_at FROM scheduled_actions
             WHERE module_id = 'registration' AND task_key = ? AND reference = ? AND status = 'pending'"
        );
        $stmt->execute([SendDecisionEmailsHandler::TASK_KEY, SendDecisionEmailsHandler::REFERENCE]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function recordingMailService(): MailService
    {
        $ccIndex = null;
        foreach ((new \ReflectionMethod(MailService::class, 'send'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'cc') {
                $ccIndex = $parameter->getPosition();
            }
        }
        $this->assertNotNull($ccIndex, 'MailService::send() takes a cc.');

        $mail = $this->createStub(MailService::class);
        $mail->method('withoutDeferral')->willReturnCallback(function () use ($mail): MailService {
            $this->deferralBypasses++;
            return $mail;
        });
        $mail->method('send')->willReturnCallback(function (...$arguments) use ($ccIndex): bool {
            if ($this->crashes > 0) {
                $this->crashes--;
                throw new \RuntimeException('Connection reset while writing DATA');
            }
            if ($this->temporaryRefusals > 0) {
                $this->temporaryRefusals--;
                throw new MailException('SMTP Error: 421 4.3.0 service temporarily unavailable for marie@example.com');
            }
            if ($this->refusals > 0) {
                $this->refusals--;
                throw new MailException('SMTP Error: 550 mailbox unavailable for marie@example.com');
            }
            $this->sent[] = [
                'to' => (string) $arguments[0],
                'cc' => $arguments[$ccIndex] ?? null,
                'subject' => (string) $arguments[1],
                'html' => (string) $arguments[2],
            ];

            return true;
        });

        return $mail;
    }
}
