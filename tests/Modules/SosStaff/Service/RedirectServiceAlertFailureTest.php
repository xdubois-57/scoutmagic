<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberService;
use Core\Module\ModuleManifest;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosException;
use Modules\SosStaff\Service\SosSettingsService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;
use Tests\TestTwig;

/**
 * The alert sent when the SOS line cannot be redirected, when one of its
 * own three channels fails — the recipients cannot be looked up, the
 * e-mail cannot be composed, the notification cannot be stored.
 *
 * Over a real database, a real journal and a real notification centre,
 * so each failure is a state of the database rather than a double told
 * to throw: a table that is not there. Whatever fails, the redirect
 * failure itself is still reported to the caller, and the failed channel
 * leaves a journal line naming the channel and the exception's class —
 * never its message, which can carry an address.
 *
 * No provider is configured in any of them: that is the redirect failure
 * the alert is about.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class RedirectServiceAlertFailureTest extends TestCase
{
    private const SCOUT_YEAR_ID = 100;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private MailService $mailService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->mailService = $this->createMock(MailService::class);

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 1)'
        );
        $stmt->execute([
            $this->encryption->encrypt('superadmin@test.be', 'user_accounts.email'),
            $this->encryption->blindIndex('superadmin@test.be', 'email'),
        ]);
    }

    public function testRecipientsThatCannotBeLookedUpAreJournaledAndNobodyIsWritten(): void
    {
        $this->pdo->exec('DROP TABLE member_functions');
        $this->mailService->expects($this->never())->method('send');

        $this->applyExpectingFailure($this->service());

        $this->assertSame(
            [['channel' => 'recipients', 'exception_class' => \PDOException::class]],
            $this->alertFailures()
        );
        $this->assertSame(0, $this->notificationCount());
    }

    public function testAnEmailThatCannotBeComposedIsJournaledAndTheNotificationStillGoes(): void
    {
        $this->pdo->exec('DROP TABLE email_template_overrides');
        $this->mailService->expects($this->never())->method('send');
        $registry = new EmailTemplateRegistry();
        $registry->registerModuleManifest(
            ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/sos_staff/module.json')
        );
        $renderer = new EmailTemplateRenderer(
            TestTwig::create(['sos_staff']),
            $registry,
            new EmailTemplateOverrideRepository($this->pdo)
        );

        $this->applyExpectingFailure($this->service($renderer));

        $this->assertSame(
            [['channel' => 'email', 'exception_class' => \PDOException::class]],
            $this->alertFailures()
        );
        $this->assertSame(1, $this->notificationCount(), 'the notification channel is independent of the e-mail');
    }

    public function testANotificationThatCannotBeStoredIsJournaledAfterTheEmailWent(): void
    {
        $this->pdo->exec('DROP TABLE notifications');
        $sentTo = [];
        $this->mailService->method('send')->willReturnCallback(
            static function (string $to) use (&$sentTo): void {
                $sentTo[] = $to;
            }
        );

        $this->applyExpectingFailure($this->service());

        $this->assertSame(['superadmin@test.be'], $sentTo);
        $this->assertSame(
            [['channel' => 'notification', 'exception_class' => \PDOException::class]],
            $this->alertFailures()
        );
    }

    private function applyExpectingFailure(RedirectService $service): void
    {
        try {
            $service->apply(1, null, self::SCOUT_YEAR_ID);
            $this->fail('With no provider, the redirect failure must reach the caller.');
        } catch (SosException $e) {
            $this->assertSame('Aucun fournisseur de téléphonie actif configuré.', $e->getMessage());
        }
    }

    private function service(?EmailTemplateRenderer $renderer = null): RedirectService
    {
        $journal = new JournalService(new JournalRepository($this->pdo));
        $notifications = new NotificationService(
            new NotificationRepository($this->pdo, $this->encryption),
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            null,
            new SettingService(new SettingRepository($this->pdo)),
            $journal,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption)
        );
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/sos_staff/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $notifications->registerModuleTypes('sos_staff', $manifest['notifications']);

        return new RedirectService(
            new ProviderConfigService(new ProviderCredentialRepository($this->pdo, $this->encryption)),
            $this->createStub(SosSettingsService::class),
            $this->createStub(MemberService::class),
            new UserAccountRepository($this->pdo, $this->encryption),
            $this->mailService,
            $journal,
            $renderer
                ?? EmailTemplateRendererFactory::shippedOnlyForModule(TestTwig::create(['sos_staff']), 'sos_staff'),
            $notifications,
            $this->createStub(MemberAccountResolver::class)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alertFailures(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT level, description, context FROM event_log WHERE event_type = ? ORDER BY id'
        );
        $stmt->execute(['admin_alert_failed']);

        return array_map(
            function (array $row): array {
                $this->assertSame('warning', $row['level']);
                $this->assertSame("Échec de l'envoi de l'alerte de redirection SOS.", $row['description']);

                return json_decode((string) $row['context'], true);
            },
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    private function notificationCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    }
}
