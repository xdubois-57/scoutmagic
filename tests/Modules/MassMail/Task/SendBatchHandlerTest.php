<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Task\SendBatchHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SendBatchHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RecipientRepository $recipientRepository;
    private UserAccountRepository $userAccountRepository;
    private int $emailId;
    private int $memberId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->recipientRepository = new RecipientRepository($this->pdo, $this->encryption);
        $this->userAccountRepository = new UserAccountRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Sujet', '<p>Corps</p>', {$sectionId}, 'default_active_members', '" . Email::STATUS_SENDING . "')"
        );
        $this->emailId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        for ($i = 0; $i < 3; $i++) {
            $this->recipientRepository->create($this->emailId, $this->memberId, $this->scoutYearId, "member{$i}@test.be", Recipient::STATUS_PENDING, null);
        }

        $settingRepository = new SettingRepository($this->pdo);
        $settingService = new SettingService($settingRepository);
        $settingService->register('batch_size', '2', 'number', 'label', 'desc', 'mass_mail');
        $settingService->register('batch_interval_minutes', '5', 'number', 'label', 'desc', 'mass_mail');
    }

    private function buildContext(MailService $mailService): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir()
        );
    }

    private function buildContextWithNotifications(MailService $mailService, NotificationService $notificationService): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir(),
            $notificationService
        );
    }

    public function testProcessesOnlyBatchSizeRecipientsAndReschedulesWhenPendingRemain(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->exactly(2))->method('send');

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $counts = $this->recipientRepository->countGroupedByStatus($this->emailId);
        $this->assertSame(2, $counts['sent']);
        $this->assertSame(1, $counts['pending']);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'send_batch' AND status = 'pending'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDoesNotRescheduleWhenNoPendingRemain(): void
    {
        // Shrink to 1 recipient so a single batch (size 2) drains everything.
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");

        $mailService = $this->createMock(MailService::class);
        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'send_batch' AND status = 'pending'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testMailExceptionMarksRecipientAsErrorWithoutLeakingAddress(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willThrowException(new MailException('550 relay denied'));

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $recipients = $this->recipientRepository->findByEmailId($this->emailId);
        $errored = array_values(array_filter($recipients, fn(Recipient $r) => $r->status === Recipient::STATUS_ERROR));
        $this->assertNotEmpty($errored);
        foreach ($errored as $recipient) {
            $this->assertStringNotContainsString('@test.be', (string) $recipient->errorMessage);
        }
    }

    /**
     * The stored value is rendered, much later and with no catch block in
     * between, by views/tracking.html.twig — so the sanitising has to happen
     * at the WRITE. It used to store Core\Mail\MailException's message
     * verbatim, i.e. PHPMailer's ErrorInfo: raw SMTP English, on a page a
     * chief reads.
     */
    public function testASendFailureStoresAFrenchSentenceRatherThanThePhpMailerText(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willThrowException(new MailException(
            'SMTP Error: data not accepted. SMTP server error: 554 5.7.1 <x@test.be>: Relay access denied'
        ));

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $errored = array_values(array_filter(
            $this->recipientRepository->findByEmailId($this->emailId),
            fn(Recipient $r) => $r->status === Recipient::STATUS_ERROR
        ));
        $this->assertNotEmpty($errored);
        foreach ($errored as $recipient) {
            $stored = (string) $recipient->errorMessage;
            $this->assertStringNotContainsString('SMTP', $stored);
            $this->assertStringNotContainsString('Relay access denied', $stored);
            $this->assertStringNotContainsString('5.7.1', $stored);
            $this->assertSame("Échec de l'envoi — voir le journal pour le détail technique.", $stored);
        }

    }

    /**
     * The other half of the same rule: sanitising the stored value must not
     * lose the transport error, which is the only thing that tells an admin
     * why nothing went out.
     */
    public function testASendFailureStillJournalsTheRealTransportError(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willThrowException(new MailException('554 5.7.1 Relay access denied'));

        $journal = $this->createMock(JournalService::class);
        $logged = [];
        $journal->method('log')->willReturnCallback(
            function (string $category, string $type, string $level, string $description, array $context = [], ?int $userId = null) use (&$logged): void {
                $logged[] = ['type' => $type, 'context' => $context];
            }
        );

        $handler = new SendBatchHandler();
        $handler->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir()
        ));

        $failures = array_values(array_filter($logged, fn(array $e) => $e['type'] === 'recipient_send_failed'));
        $this->assertNotEmpty($failures);
        $this->assertSame('554 5.7.1 Relay access denied', $failures[0]['context']['mail_error']);
    }

    /**
     * « Lot d'emails de masse envoyé — 6 envoyés, 1 erreur » is not a
     * trace of anything: a batch spans several emails at once, so it
     * names no mailing, no recipient and no reason. Every copy that
     * leaves now writes its own line, keyed on the same ids the tracking
     * page uses — and the email id sits in the DESCRIPTION, because that
     * is the only column /admin/journal's search box looks at.
     */
    public function testEveryCopyThatLeavesWritesItsOwnJournalLine(): void
    {
        $logged = [];
        $journal = $this->createMock(JournalService::class);
        $journal->method('log')->willReturnCallback(
            function (string $category, string $type, string $level, string $description, array $context = [], ?int $userId = null) use (&$logged): void {
                $logged[] = ['type' => $type, 'level' => $level, 'description' => $description, 'context' => $context];
            }
        );

        $handler = new SendBatchHandler();
        $handler->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir()
        ));

        $sent = array_values(array_filter($logged, fn(array $e) => $e['type'] === 'recipient_sent'));
        // batch_size is 2 in setUp(), and this run sends that lot.
        $this->assertCount(2, $sent);
        foreach ($sent as $entry) {
            $this->assertSame('info', $entry['level']);
            $this->assertStringContainsString('#' . $this->emailId, $entry['description']);
            $this->assertSame($this->emailId, $entry['context']['email_id']);
            $this->assertSame($this->memberId, $entry['context']['member_id']);
            $this->assertNotNull($entry['context']['recipient_id']);
            // SECURITY.md §11 — never the address, on the success path
            // any more than on the failure one.
            $this->assertStringNotContainsString('@', $entry['description']);
            $this->assertStringNotContainsString('@', (string) json_encode($entry['context']));
        }
    }

    /**
     * A transport failure is filed as `error`, the same word the
     * recipient row and the tracking page already use for it. It used to
     * be `info`, which is invisible to the one reader who arrives at
     * /admin/journal having filtered on « Erreur » to find exactly this.
     */
    public function testASendFailureIsFiledAsAnErrorAndNamesItsMailing(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willThrowException(new MailException('554 5.7.1 Relay access denied'));

        $logged = [];
        $journal = $this->createMock(JournalService::class);
        $journal->method('log')->willReturnCallback(
            function (string $category, string $type, string $level, string $description, array $context = [], ?int $userId = null) use (&$logged): void {
                $logged[] = ['type' => $type, 'level' => $level, 'description' => $description];
            }
        );

        $handler = new SendBatchHandler();
        $handler->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->userAccountRepository,
            sys_get_temp_dir()
        ));

        $failures = array_values(array_filter($logged, fn(array $e) => $e['type'] === 'recipient_send_failed'));
        $this->assertNotEmpty($failures);
        $this->assertSame('error', $failures[0]['level']);
        $this->assertStringContainsString('#' . $this->emailId, $failures[0]['description']);
    }

    /**
     * Module addendum (RFC 8058 one-click unsubscribe): every send must
     * carry both headers plus a human-facing footer link, and the
     * recipient row must end up with a verifiable token — never a bare
     * member/email id trusted from the URL alone.
     */
    public function testEverySendIncludesListUnsubscribeHeadersFooterAndAToken(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");
        $recipientBefore = $this->recipientRepository->findByEmailId($this->emailId)[0];

        $capturedExtraHeaders = null;
        $capturedBodyHtml = null;
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (...$args) use (&$capturedExtraHeaders, &$capturedBodyHtml): void {
                $capturedBodyHtml = $args[2];
                $capturedExtraHeaders = $args[8] ?? null;
            });

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $this->assertIsArray($capturedExtraHeaders);
        $this->assertArrayHasKey('List-Unsubscribe', $capturedExtraHeaders);
        $this->assertArrayHasKey('List-Unsubscribe-Post', $capturedExtraHeaders);
        $this->assertSame('List-Unsubscribe=One-Click', $capturedExtraHeaders['List-Unsubscribe-Post']);
        $this->assertMatchesRegularExpression('#^<.*/mass-mail/unsubscribe/\d+\?token=[a-f0-9]{64}>$#', $capturedExtraHeaders['List-Unsubscribe']);
        $this->assertStringContainsString('/mass-mail/unsubscribe/', $capturedBodyHtml);
        $this->assertStringContainsString('Se désinscrire des emails groupés', $capturedBodyHtml);

        $stmt = $this->pdo->prepare('SELECT unsubscribe_token_hash FROM mass_mail_recipients WHERE id = ?');
        $stmt->execute([$recipientBefore->id]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testMarksParentEmailSentOnceAllRecipientsProcessed(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");

        $mailService = $this->createMock(MailService::class);
        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $stmt = $this->pdo->prepare('SELECT status FROM mass_mail_emails WHERE id = ?');
        $stmt->execute([$this->emailId]);
        $this->assertSame(Email::STATUS_SENT, $stmt->fetchColumn());
    }

    private function buildNotificationService(): NotificationService
    {
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $notificationService = new NotificationService(
            new NotificationRepository($this->pdo, $this->encryption),
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createMock(WebPush::class),
            $settingService,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->userAccountRepository
        );
        $notificationService->registerModuleTypes('mass_mail', [[
            'id' => 'mass_mail.email_received', 'label' => 'Nouvel email reçu', 'description' => 'd',
            'group' => 'Emails groupés', 'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'off'],
        ]]);

        return $notificationService;
    }

    public function testDispatchesEmailReceivedNotificationWhenRecipientHasALoginAccount(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");
        $recipient = $this->recipientRepository->findByEmailId($this->emailId)[0];

        $account = $this->userAccountRepository->create($recipient->emailAddress);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $this->scoutYearId, $this->encryption->encrypt('Jean', 'member_years.first_name'), $this->encryption->encrypt('Dupont', 'member_years.last_name')]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $mailService = $this->createMock(MailService::class);
        $notificationService = $this->buildNotificationService();

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications($mailService, $notificationService));

        $notifications = (new NotificationRepository($this->pdo, $this->encryption))->findByUserAccountId($account->id);
        $this->assertCount(1, $notifications);
        $this->assertSame('mass_mail.email_received', $notifications[0]->typeId);
        $this->assertSame('Sujet', $notifications[0]->body);
        $this->assertSame('/members/' . $memberYearId . '/emails/' . $recipient->id, $notifications[0]->url);
    }

    /**
     * Issue #287 showed the notification reading the stored TEMPLATE —
     * « Camp de {{Prenom}} » — because it took `$email->subject` while
     * the substitution lived in a local variable one scope away. The
     * template must never reach it.
     *
     * Nor must the substituted subject, and that is the second half:
     * `notifications.body` is written once and only ever purged once
     * READ, so a personalised subject stored there outlives the 18-month
     * merge retention the rest of this change is built to respect.
     */
    public function testAPersonalisedSubjectIsNeverWrittenIntoTheNotificationStore(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");
        $recipient = $this->recipientRepository->findByEmailId($this->emailId)[0];
        $account = $this->userAccountRepository->create($recipient->emailAddress);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Jean', 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
        ]);

        // Turn the fixture's ordinary email into a publipostage whose
        // subject carries a variable, and give this recipient a row.
        $audienceRepository = new \Modules\MassMail\Repository\AudienceRepository($this->pdo, $this->encryption);
        $audienceId = $audienceRepository->createAudience('camp.xlsx', 'Camp', ['Prenom'], 1, null);
        $rowId = $audienceRepository->createRow($audienceId, 2, $this->memberId, null, ['Prenom' => 'Kaa']);
        $update = $this->pdo->prepare(
            "UPDATE mass_mail_emails SET subject = 'Camp de {{Prenom}}', list_type = 'mail_merge', audience_id = ?
             WHERE id = ?"
        );
        $update->execute([$audienceId, $this->emailId]);
        $this->pdo->prepare('UPDATE mass_mail_recipients SET audience_row_id = ? WHERE id = ?')
            ->execute([$rowId, $recipient->id]);

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications(
            $this->createMock(MailService::class),
            $this->buildNotificationService()
        ));

        $notifications = (new NotificationRepository($this->pdo, $this->encryption))->findByUserAccountId($account->id);
        $this->assertCount(1, $notifications);
        // A subject that personalises is NOT written into the
        // notification store — see notificationBody(). What must never
        // survive is the raw template.
        $this->assertStringNotContainsString('{{', $notifications[0]->body);
        $this->assertSame('Un email personnalisé vous a été envoyé.', $notifications[0]->body);
    }

    /**
     * The other half of that rule, and the common case: most
     * publipostages keep their variables in the BODY, so every recipient
     * shares one subject. Nothing per-recipient is written, so the
     * notification says what the mail is — losing that for every merge
     * would be paying a privacy price where there is nothing to protect.
     */
    public function testAMergeWhoseSubjectIsTheSameForEverybodyKeepsItsSubjectInTheNotification(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");
        $recipient = $this->recipientRepository->findByEmailId($this->emailId)[0];
        $account = $this->userAccountRepository->create($recipient->emailAddress);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Jean', 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
        ]);

        $audienceRepository = new \Modules\MassMail\Repository\AudienceRepository($this->pdo, $this->encryption);
        $audienceId = $audienceRepository->createAudience('camp.xlsx', 'Camp', ['Prenom'], 1, null);
        $rowId = $audienceRepository->createRow($audienceId, 2, $this->memberId, null, ['Prenom' => 'Kaa']);
        // Variables in the body only — the subject is shared.
        $update = $this->pdo->prepare(
            "UPDATE mass_mail_emails SET subject = 'Infos camp', body_html = '<p>Cher {{Prenom}}</p>',
                    list_type = 'mail_merge', audience_id = ? WHERE id = ?"
        );
        $update->execute([$audienceId, $this->emailId]);
        $this->pdo->prepare('UPDATE mass_mail_recipients SET audience_row_id = ? WHERE id = ?')
            ->execute([$rowId, $recipient->id]);

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications(
            $this->createMock(MailService::class),
            $this->buildNotificationService()
        ));

        $notifications = (new NotificationRepository($this->pdo, $this->encryption))->findByUserAccountId($account->id);
        $this->assertCount(1, $notifications);
        $this->assertSame('Infos camp', $notifications[0]->body);
    }

    public function testDoesNotDispatchWhenRecipientHasNoLoginAccount(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");

        $mailService = $this->createMock(MailService::class);
        $notificationService = $this->buildNotificationService();

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContextWithNotifications($mailService, $notificationService));

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    // --- Mail merge: per-recipient rendering at send time (ARCHITECTURE.md §8.61) ---

    /**
     * @param array<string, string> $data
     * @return array{0: int, 1: int} [merge email id, recipient id]
     */
    private function createMergeEmailWithRecipient(?array $data): array
    {
        $sectionId = (int) $this->pdo->query('SELECT id FROM sections LIMIT 1')->fetchColumn();
        $audienceRepository = new \Modules\MassMail\Repository\AudienceRepository($this->pdo, $this->encryption);
        $audienceId = $audienceRepository->createAudience('f.xlsx', 'Feuille1', ['Prenom', 'Montant'], 1, null);

        $stmt = $this->pdo->prepare(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, audience_id, status)
             VALUES ('Infos {{Prenom}}', '<p>Cher {{Prenom}}, montant : {{Montant}} €</p>', ?, 'mail_merge', ?, '" . Email::STATUS_SENDING . "')"
        );
        $stmt->execute([$sectionId, $audienceId]);
        $emailId = (int) $this->pdo->lastInsertId();

        $rowId = $data !== null ? $audienceRepository->createRow($audienceId, 2, null, 'ext@test.be', $data) : null;
        $recipientId = $this->recipientRepository->create(
            $emailId, null, null, 'ext@test.be', Recipient::STATUS_PENDING, null, null, $rowId
        );

        return [$emailId, $recipientId];
    }

    public function testMergeRecipientGetsTheirOwnRenderedSubjectAndBody(): void
    {
        $this->pdo->exec('DELETE FROM mass_mail_recipients');
        $this->pdo->exec('DELETE FROM mass_mail_emails');
        [$emailId] = $this->createMergeEmailWithRecipient(['Prenom' => 'Louis', 'Montant' => '145']);

        $capturedSubject = null;
        $capturedBody = null;
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send')
            ->willReturnCallback(function (...$args) use (&$capturedSubject, &$capturedBody): void {
                $capturedSubject = $args[1];
                $capturedBody = $args[2];
            });

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $this->assertSame('Infos Louis', $capturedSubject);
        $this->assertStringContainsString('Cher Louis, montant : 145 €', $capturedBody);
        // The unsubscribe footer still applies to an external recipient.
        $this->assertStringContainsString('/mass-mail/unsubscribe/', $capturedBody);

        $counts = $this->recipientRepository->countGroupedByStatus($emailId);
        $this->assertSame(1, $counts['sent']);
    }

    public function testMergeValuesAreHtmlEscapedInTheRenderedBody(): void
    {
        $this->pdo->exec('DELETE FROM mass_mail_recipients');
        $this->pdo->exec('DELETE FROM mass_mail_emails');
        $this->createMergeEmailWithRecipient(['Prenom' => '<script>alert(1)</script>', 'Montant' => '1']);

        $capturedBody = null;
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willReturnCallback(function (...$args) use (&$capturedBody): void {
            $capturedBody = $args[2];
        });

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $this->assertStringNotContainsString('<script>', (string) $capturedBody);
        $this->assertStringContainsString('&lt;script&gt;', (string) $capturedBody);
    }

    public function testMergeRecipientWhoseAudienceRowWasPurgedFailsExplicitly(): void
    {
        $this->pdo->exec('DELETE FROM mass_mail_recipients');
        $this->pdo->exec('DELETE FROM mass_mail_emails');
        [$emailId] = $this->createMergeEmailWithRecipient(null); // no audience row at all

        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');

        (new SendBatchHandler())->handle([], $this->buildContext($mailService));

        $recipients = $this->recipientRepository->findByEmailId($emailId);
        $this->assertSame(Recipient::STATUS_ERROR, $recipients[0]->status);
        $this->assertSame('Données de publipostage purgées', $recipients[0]->errorMessage);
    }
}
