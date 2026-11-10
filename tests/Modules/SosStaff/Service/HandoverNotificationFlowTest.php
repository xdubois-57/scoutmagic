<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Module\ModuleManager;
use Core\Module\ModuleManifest;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Notification\Task\SendNotificationEmailsHandler;
use Core\Notification\Task\SendNotificationsHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskCapabilities;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Api\ForwardingState;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Repository\ExcludedSectionRepository;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosSettingsService;
use Modules\SosStaff\Task\ApplyRedirectHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;
use Tests\TestTwig;

/** Real member resolution, notification queue and rendering; only telephony and SMTP are replaced. */
#[Group('database')]
final class HandoverNotificationFlowTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settings;
    private MemberEmailRepository $memberEmails;
    private UserAccountRepository $accounts;
    private NotificationPreferenceRepository $preferences;
    private SchedulerRepository $tasks;
    private SchedulerRunner $runner;
    private TaskContext $context;
    private RedirectService $redirect;
    private int $year;
    private ForwardingState $forwarding;
    private array $changes = [];
    private array $attempts = [];
    private ?string $refusedAddress = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register('site_name', 'Unité de test', 'text', 'Nom', 'Nom du site.');
        $this->settings->register('base_url', 'https://unit.example.test', 'text', 'URL', 'URL du site.');
        $this->settings->register('email_notifications_enabled', '1', 'boolean', 'Notifications de garde', 'Activation.', 'sos_staff');
        $years = new ScoutYearService($this->pdo);
        $this->year = $years->ensureYear(DatabaseTestHelper::scoutYear()[0]);
        $memberYears = new MemberYearRepository($this->pdo);
        $this->memberEmails = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);
        $this->preferences = new NotificationPreferenceRepository($this->pdo);
        $this->tasks = new SchedulerRepository($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $notifications = new NotificationService(
            new NotificationRepository($this->pdo, $this->encryption),
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            $this->preferences, null, $this->settings, $journal,
            new SchedulerService($this->tasks), $this->accounts,
            new RoleResolver($memberYears, $this->encryption, $this->pdo, $this->memberEmails), $years,
        );
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/sos_staff/module.json');
        $notifications->registerModuleTypes($manifest->id, $manifest->notifications);
        $mail = $this->createStub(MailService::class);
        $mail->method('send')->willReturnCallback(function (string $to, string $subject, string $bodyHtml, string $bodyText): void {
            $this->attempts[] = [$to, $subject,
                str_contains($bodyHtml, 'https://unit.example.test/admin/sos'),
                str_contains($bodyText, 'https://unit.example.test/admin/sos')];
            if ($to === $this->refusedAddress) {
                throw new MailException('Transport de test indisponible.');
            }
        });
        $this->forwarding = new ForwardingState(true, '+32470000001');
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnCallback(fn() => $this->forwarding);
        $provider->method('setForwarding')->willReturnCallback(function (string $number): void {
            $this->changes[] = $number;
            $this->forwarding = new ForwardingState(true, $number);
        });
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('getEnabledModuleIds')->willReturn(['test_tools']);
        $capabilities = new TaskCapabilities($modules);
        $capabilities->register(PhoneProviderInterface::class, 'test_tools', fn() => $provider);
        $this->context = new TaskContext(
            $connection, $this->encryption, $mail, $journal, $this->settings, $this->accounts,
            sys_get_temp_dir(), $notifications, $capabilities,
        );
        $this->runner = new SchedulerRunner($this->tasks, $journal);
        $this->runner->setTaskContext($this->context);
        $this->runner->registerHandler('sos_staff', 'apply_redirect', new ApplyRedirectHandler());
        $this->runner->registerHandler('core', 'send_notification_emails', new SendNotificationEmailsHandler());
        $this->runner->registerHandler('core', 'send_notifications', new SendNotificationsHandler());
        $sections = new SectionService(new SectionRepository($connection), new MemberProfileRepository($connection, $this->encryption));
        $sos = new SosSettingsService(
            new ExcludedSectionRepository($this->pdo), new SosSettingsRepository($this->pdo), $sections,
            $memberYears, new UnitStaffSectionService(new \Core\Member\Repository\UnitStaffSectionRepository($this->pdo)),
            $this->settings,
        );
        $this->redirect = new RedirectService(
            new ProviderConfigService(new ProviderCredentialRepository($this->pdo, $this->encryption), null, $provider),
            $sos, new MemberService($memberYears, new MemberProfileRepository($connection, $this->encryption), null, $this->memberEmails),
            $this->accounts, $mail, $journal,
            EmailTemplateRendererFactory::shippedOnlyForModule(TestTwig::create(['sos_staff']), 'sos_staff'),
            $notifications, new MemberAccountResolver($memberYears, $this->memberEmails, $this->accounts),
        );
    }

    public static function paths(): iterable
    {
        yield 'scheduled' => [true];
        yield 'immediate' => [false];
    }

    #[DataProvider('paths')]
    public function testNormalHandoverQueuesThenSendsBothEmailsAndRetriesDoNotDuplicate(bool $scheduled): void
    {
        [$incoming, $outgoing] = $this->pair('incoming@example.test');
        $this->runHandover($scheduled, $incoming, $outgoing);
        $queued = [$this->noticeCount(), count($this->emailTasks()), $this->attempts];
        $this->runner->processOverdue();
        $this->repeatEmailTasks();
        $this->runHandover($scheduled, $incoming, $outgoing);
        $this->runner->processOverdue();

        self::assertSame([[2, 2, []], 2, [
            ['incoming@example.test', 'Vous êtes de garde SOS', true, true],
            ['outgoing@example.test', 'Fin de votre garde SOS', true, true],
        ], ['+32470000002']], [$queued, $this->noticeCount(), $this->emailAttempts(), $this->changes]);
    }

    #[DataProvider('paths')]
    public function testAValidatedSecondaryAccountReceivesTheHandover(bool $scheduled): void
    {
        [$incoming, $outgoing] = $this->pair('desk-without-account@example.test', false);
        $this->memberEmails->create($incoming, 'secondary@example.test', 'manual', 'valid', null, null);
        $this->accounts->create('secondary@example.test');
        $this->runHandover($scheduled, $incoming, $outgoing);
        $queued = [$this->noticeCount(), count($this->emailTasks()), $this->attempts];
        $this->runner->processOverdue();

        self::assertSame([[2, 2, []], [
            ['outgoing@example.test', 'Fin de votre garde SOS', true, true],
            ['secondary@example.test', 'Vous êtes de garde SOS', true, true],
        ]], [$queued, $this->emailAttempts()]);
    }

    #[DataProvider('paths')]
    public function testAValidatedSecondaryWorksWithoutAnyDeskEmail(bool $scheduled): void
    {
        [$incoming, $outgoing] = $this->pair(null, false);
        $this->memberEmails->create($incoming, 'secondary@example.test', 'manual', 'valid', null, null);
        $this->accounts->create('secondary@example.test');
        $this->runHandover($scheduled, $incoming, $outgoing);
        $this->runner->processOverdue();

        self::assertSame([2, [
            ['outgoing@example.test', 'Fin de votre garde SOS', true, true],
            ['secondary@example.test', 'Vous êtes de garde SOS', true, true],
        ]], [$this->noticeCount(), $this->emailAttempts()]);
    }

    public function testLinkedAccountsReceiveOneCopyEachEvenWhenTheDeskAddressIsAlsoSecondary(): void
    {
        [$incoming, $outgoing] = $this->pair('incoming@example.test');
        $this->memberEmails->create($incoming, 'incoming@example.test', 'manual', 'valid', null, null);
        $this->memberEmails->create($incoming, 'secondary@example.test', 'manual', 'valid', null, null);
        $this->accounts->create('secondary@example.test');
        $this->runHandover(true, $incoming, $outgoing);
        $this->runner->processOverdue();
        $this->repeatEmailTasks();

        self::assertSame([3, [
            ['incoming@example.test', 'Vous êtes de garde SOS', true, true],
            ['outgoing@example.test', 'Fin de votre garde SOS', true, true],
            ['secondary@example.test', 'Vous êtes de garde SOS', true, true],
        ]], [$this->noticeCount(), $this->emailAttempts()]);
    }

    public function testNoLinkedAccountIsDiagnosedWithoutLoggingTheAddressOrNumber(): void
    {
        [$incoming, $outgoing] = $this->pair('no-account@example.test', false);
        $this->runHandover(true, $incoming, $outgoing);
        $this->runner->processOverdue();
        $this->runHandover(true, $incoming, $outgoing);
        $skipped = $this->pdo->query("SELECT context FROM event_log WHERE category = 'sos_staff' AND event_type = 'notification_skipped'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $contexts = array_map(static fn(string $json): array => json_decode($json, true), $skipped);
        $journal = implode(' ', $this->pdo->query("SELECT description || context FROM event_log WHERE category = 'sos_staff'")
            ->fetchAll(\PDO::FETCH_COLUMN));

        self::assertSame([[
            ['member_id' => $incoming, 'type_id' => 'sos_staff.oncall_started', 'reason' => 'no_account'],
        ], 1, [['outgoing@example.test', 'Fin de votre garde SOS', true, true]], false], [
            $contexts, $this->noticeCount(), $this->emailAttempts(),
            preg_match('/example\.test|\+3247000000[12]/', $journal) === 1,
        ]);
    }

    public function testAResolvedAccountBelowTheDeclaredRoleDoesNotReceiveTheHandover(): void
    {
        [$incoming, $outgoing] = $this->pair('incoming@example.test');
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('LOW', 'Fonction de test', 'identified', 1)");
        $function = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('UPDATE member_functions SET function_id = ? WHERE member_year_id IN (SELECT id FROM member_years WHERE member_id = ?)');
        $stmt->execute([$function, $incoming]);
        $this->runHandover(true, $incoming, $outgoing);
        $this->runner->processOverdue();

        self::assertSame([1, [['outgoing@example.test', 'Fin de votre garde SOS', true, true]]], [
            $this->noticeCount(), $this->emailAttempts(),
        ]);
    }

    #[DataProvider('paths')]
    public function testAnEmailPreferenceIsHonoredWithoutSilencingTheOtherMember(bool $scheduled): void
    {
        [$incoming, $outgoing] = $this->pair('incoming@example.test');
        $account = $this->accounts->findByEmail('incoming@example.test');
        $this->preferences->setChannel($account->id, 'sos_staff.oncall_started', 'email', false);
        $this->runHandover($scheduled, $incoming, $outgoing);
        $this->runner->processOverdue();

        self::assertSame([2, 1, [['outgoing@example.test', 'Fin de votre garde SOS', true, true]]], [
            $this->noticeCount(), count($this->emailTasks()), $this->emailAttempts(),
        ]);
    }

    #[DataProvider('paths')]
    public function testTheGlobalSwitchSilencesTheWholeHandover(bool $scheduled): void
    {
        [$incoming, $outgoing] = $this->pair('incoming@example.test');
        $this->settings->set('email_notifications_enabled', '0', 'sos_staff');
        $this->runHandover($scheduled, $incoming, $outgoing);
        $this->runner->processOverdue();

        self::assertSame([0, [], [], ['+32470000002']], [
            $this->noticeCount(), $this->emailTasks(), $this->emailAttempts(), $this->changes,
        ]);
    }

    #[DataProvider('paths')]
    public function testAnAlreadyCorrectNumberDoesNotTriggerANewHandover(bool $scheduled): void
    {
        [$incoming, $outgoing] = $this->pair('incoming@example.test');
        $this->forwarding = new ForwardingState(true, '+32470000002');
        $this->runHandover($scheduled, $incoming, $outgoing);
        $this->runner->processOverdue();

        self::assertSame([0, [], [], []], [
            $this->noticeCount(), $this->emailTasks(), $this->emailAttempts(), $this->changes,
        ]);
    }

    public static function invalidAddresses(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'inactive' => ['inactive'];
    }

    #[DataProvider('invalidAddresses')]
    public function testAnUnvalidatedSecondaryCannotReceiveTheHandover(string $status): void
    {
        [$incoming, $outgoing] = $this->pair(null, false);
        $this->memberEmails->create($incoming, 'secondary@example.test', 'manual', $status, null, null);
        $this->accounts->create('secondary@example.test');
        $this->runHandover(true, $incoming, $outgoing);
        $this->runner->processOverdue();

        self::assertSame([1, [['outgoing@example.test', 'Fin de votre garde SOS', true, true]]], [
            $this->noticeCount(), $this->emailAttempts(),
        ]);
    }

    public function testATransportRefusalIsJournaledAndTheEmailClaimPreventsARetry(): void
    {
        [$incoming, $outgoing] = $this->pair('incoming@example.test');
        $this->refusedAddress = 'incoming@example.test';
        $this->runHandover(true, $incoming, $outgoing);
        $this->runner->processOverdue();
        $this->repeatEmailTasks();
        $failures = $this->pdo->query("SELECT event_type FROM event_log WHERE event_type = 'notification_email_failed'")->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame([[
            ['incoming@example.test', 'Vous êtes de garde SOS', true, true],
            ['outgoing@example.test', 'Fin de votre garde SOS', true, true],
        ], ['notification_email_failed'], 2], [
            $this->emailAttempts(), $failures,
            (int) $this->pdo->query('SELECT COUNT(*) FROM notifications WHERE email_sent_at IS NOT NULL')->fetchColumn(),
        ]);
    }

    /** @return array{int, int} */
    private function pair(?string $incomingEmail, bool $createIncomingAccount = true): array
    {
        $outgoing = $this->member('outgoing', '+32470000001', 'outgoing@example.test');
        $incoming = $this->member('incoming', '+32470000002', $incomingEmail);
        $this->accounts->create('outgoing@example.test');
        if ($createIncomingAccount && $incomingEmail !== null) {
            $this->accounts->create($incomingEmail);
        }

        return [$incoming, $outgoing];
    }

    private function member(string $code, string $mobile, ?string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$code]);
        $id = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
             mobile_encrypted, email_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $this->year,
            $this->encryption->encrypt('Membre', 'member_years.first_name'),
            $this->encryption->encrypt('Test', 'member_years.last_name'),
            $this->encryption->encrypt($mobile, 'member_years.mobile'),
            $email === null ? null : $this->encryption->encrypt($email, 'member_years.email'),
            $email === null ? null : $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYear = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES ('CU', 'Fonction de test', 'admin', 1)");
        $function = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'CU'")->fetchColumn();
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)');
        $stmt->execute([$memberYear, $function]);

        return $id;
    }

    private function runHandover(bool $scheduled, int $incoming, int $outgoing): void
    {
        if (!$scheduled) {
            $this->redirect->apply($incoming, $outgoing, $this->year);
            return;
        }
        $id = $this->tasks->create('sos_staff', 'apply_redirect', (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            json_encode(['date' => date('Y-m-d'), 'member_id' => $incoming, 'previous_member_id' => $outgoing, 'scout_year_id' => $this->year]),
            date('Y-m-d'));
        $this->runner->processOverdue();
        self::assertSame('done', $this->tasks->findById($id)['status']);
    }

    private function noticeCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    }

    private function emailTasks(): array
    {
        return (new SchedulerService($this->tasks))->findAllForTask('core', 'send_notification_emails', 100);
    }

    private function repeatEmailTasks(): void
    {
        foreach ($this->emailTasks() as $task) {
            (new SendNotificationEmailsHandler())->handle(json_decode($task['payload'], true), $this->context);
        }
    }

    private function emailAttempts(): array
    {
        $attempts = $this->attempts;
        sort($attempts);

        return $attempts;
    }
}
