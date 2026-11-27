<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailServiceFactory;
use Core\Mail\SuspendedMailTransport;
use Core\Mail\Transport\MailTransportFactory;
use Core\Mail\Transport\MailTransportChain;
use Core\Module\InstallationProfile;
use Core\Module\ModuleRegistryRepository;
use Core\Security\SandboxLossAttentionProvider;
use Core\Security\SandboxLossState;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Api\ProviderException;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\TestTools\Mail\CaptureTransport;
use Modules\TestTools\Mail\CaptureTransportFactory;
use Modules\TestTools\Sandbox\SandboxLoss;
use Modules\TestTools\Service\MailSandboxService;
use Modules\TestTools\Telephony\SimulatedPhoneProvider;
use Modules\TestTools\Telephony\SimulatedTelephony;
use Modules\TestTools\Telephony\SuspendedPhoneProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * Issue #1469, end to end: a sandbox armed on a reference installation,
 * then the installation profile goes missing — an update whose
 * config/app.php no longer declares it. Before, the factories answered
 * null and every e-mail went back to the real addresses of members and
 * parents, the real SOS line was driven again. Now: nothing reaches the
 * real transport, one journal line says why, and an attention point asks a
 * superadmin to decide.
 *
 * Each `request()` is what one entry point does on one request — the same
 * calls public/index.php and public/cron.php make, in the same order, on a
 * fresh SettingService — so « journaled once, not on every request » is
 * measured over several of them.
 */
#[Group('database')]
final class LostSandboxTest extends TestCase
{
    private const REFERENCE_URL = 'https://scoutmagic.be';

    private \PDO $pdo;
    private JournalService $journal;
    private string $tempDir;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        TestToolsTestHelper::createTables($this->pdo);
        SosStaffTestHelper::createTables($this->pdo);
        $this->journal = new JournalService(new JournalRepository($this->pdo));
        $this->tempDir = sys_get_temp_dir() . '/test_tools_lost_' . bin2hex(random_bytes(4));

        $settings = $this->settings();
        SandboxLossState::register($settings);
        foreach (
            [
                MailSandboxService::SETTING_ARMED,
                MailSandboxService::SETTING_DELIVER_MAGIC_LINKS,
                SimulatedTelephony::SETTING_ARMED,
                SimulatedTelephony::SETTING_FORWARDING,
            ] as $key
        ) {
            $settings->register(
                $key,
                $key === SimulatedTelephony::SETTING_FORWARDING ? '' : '0',
                $key === SimulatedTelephony::SETTING_FORWARDING ? 'text' : 'boolean',
                'Réglage du module',
                'Réglage de test.',
                MailSandboxService::MODULE_ID,
                null,
                null,
                false
            );
        }
        (new ModuleRegistryRepository($this->pdo))->upsert(MailSandboxService::MODULE_ID, true, '1.4.0', null);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    private function settings(): SettingService
    {
        return new SettingService(new SettingRepository($this->pdo));
    }

    private function arm(string $key): void
    {
        $this->settings()->setInternal($key, '1', MailSandboxService::MODULE_ID);
    }

    /**
     * One request of an entry point: resolve the profile from what
     * config/app.php declares, record both losses, then ask the factories.
     *
     * @return array{state: SandboxLossState, settings: SettingService, profile: InstallationProfile}
     */
    private function request(mixed $declaredFlags, ?string $modulesDir = null): array
    {
        $modulesDir ??= dirname(__DIR__, 3) . '/modules';
        $settings = $this->settings();
        $profile = InstallationProfile::resolve(self::REFERENCE_URL, '', $declaredFlags);
        $registry = new ModuleRegistryRepository($this->pdo);
        $state = new SandboxLossState($settings);
        $state->record(
            SandboxLossState::CHANNEL_MAIL,
            SandboxLoss::mailLossReason($profile, $declaredFlags, $registry, $settings, $modulesDir),
            $this->journal
        );
        $state->record(
            SandboxLossState::CHANNEL_TELEPHONY,
            SandboxLoss::telephonyLossReason($profile, $declaredFlags, $registry, $settings, $modulesDir),
            $this->journal
        );

        return ['state' => $state, 'settings' => $settings, 'profile' => $profile];
    }

    /** @param array{state: SandboxLossState, settings: SettingService, profile: InstallationProfile} $request */
    private function mailTransport(array $request): ?\Core\Mail\MailTransportInterface
    {
        return CaptureTransportFactory::transportFor(
            $request['settings'],
            $request['state'],
            $this->pdo,
            TestToolsTestHelper::encryption(),
            $this->tempDir . '/storage'
        );
    }

    private function countJournal(string $type): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM event_log WHERE category = 'core' AND event_type = ?");
        $statement->execute([$type]);

        return (int) $statement->fetchColumn();
    }

    public function testALostMailCaptureBlocksInsteadOfSendingForReal(): void
    {
        $this->arm(MailSandboxService::SETTING_ARMED);

        // While the profile holds, the capture is the transport.
        $holding = $this->request(['reference_installation']);
        $this->assertInstanceOf(CaptureTransport::class, $this->mailTransport($holding));
        $this->assertSame(0, $this->countJournal('mail_capture_lost'));

        // The update lost `installation_profile`, the switch is still armed.
        for ($i = 0; $i < 3; $i++) {
            $lost = $this->request([]);
            $transport = $this->mailTransport($lost);
            $this->assertInstanceOf(
                SuspendedMailTransport::class,
                $transport,
                'A lost capture must never fall back to null — null is « send for real ».'
            );
        }

        // Through the very graph the entry points build — chain, then MailService.
        $built = MailTransportFactory::build(
            $this->pdo,
            [],
            $lost['settings'],
            TestToolsTestHelper::encryption(),
            $transport,
            $this->journal
        );
        $this->assertInstanceOf(MailTransportChain::class, $built['chain']);
        $this->assertSame($transport, $built['delivery'], 'The probe gets the blocking transport too.');
        $mailService = MailServiceFactory::create(
            ['mail_mode' => 'local', 'mail_from_address' => 'unite@example.be'],
            new DkimManager($this->tempDir . '/dkim'),
            $built['chain'],
            $this->journal
        );

        try {
            $mailService->send('parent@example.be', 'Convocation', '<p>Bonjour</p>', 'Bonjour');
            $this->fail('No message may leave while the lost capture is unreleased.');
        } catch (MailException $e) {
            $this->assertStringContainsString('suspendu', $e->getMessage());
        }
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM captured_emails')->fetchColumn(),
            'Nothing is captured either: the capture is not running.'
        );

        $this->assertSame(1, $this->countJournal('mail_capture_lost'), 'Written once, not on every request.');
        $context = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE event_type = 'mail_capture_lost'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($context);
        $this->assertSame('security', $context['level']);
        $this->assertSame(['reason' => 'profile_missing'], json_decode((string) $context['context'], true));

        $points = (new SandboxLossAttentionProvider($lost['state']))->collect(1);
        $this->assertCount(1, $points);
        $this->assertStringContainsString('capture des e-mails', $points[0]->title);
    }

    public function testAReleaseHandsBackTheOrdinaryPathUntilTheCaptureHoldsAgain(): void
    {
        $this->arm(MailSandboxService::SETTING_ARMED);
        $this->request([]);
        $this->assertTrue($this->request([])['state']->release(SandboxLossState::CHANNEL_MAIL, 7, $this->journal));

        $this->assertNull(
            $this->mailTransport($this->request([])),
            'Released: the superadmin chose the ordinary transport.'
        );

        // The profile is restored: capture again, and the release is gone.
        $this->assertInstanceOf(
            CaptureTransport::class,
            $this->mailTransport($this->request(['reference_installation']))
        );
        // Lost again later: blocked again, journaled again.
        $this->assertInstanceOf(SuspendedMailTransport::class, $this->mailTransport($this->request([])));
        $this->assertSame(2, $this->countJournal('mail_capture_lost'));
    }

    public function testALostTelephonySimulationNeverDrivesTheRealLine(): void
    {
        $this->arm(SimulatedTelephony::SETTING_ARMED);

        $operatorCalls = 0;
        $credentials = new ProviderCredentialRepository($this->pdo, TestToolsTestHelper::encryption());
        $credentials->save('ovh', [
            'application_key' => 'ak',
            'application_secret' => 'as',
            'consumer_key' => 'ck',
            'billing_account' => 'ba-1',
            'service_name' => '0032470000000',
            'sos_number' => '0032470000000',
        ]);
        $credentials->setActive('ovh');
        $operator = static function () use (&$operatorCalls): array {
            $operatorCalls++;

            return ['status' => 200, 'body' => '{}'];
        };

        $this->assertNotNull(
            (new ProviderConfigService($credentials, \Closure::fromCallable($operator)))->getActiveProvider(),
            'Without a stand-in, the configured operator is the line — the one that must not be reached.'
        );

        $holding = $this->request(['reference_installation']);
        $this->assertInstanceOf(
            SimulatedPhoneProvider::class,
            SimulatedTelephony::providerFor(true, $holding['profile'], $holding['settings'], $holding['state'])
        );

        for ($i = 0; $i < 3; $i++) {
            $lost = $this->request([]);
            // The module is filtered out by visible_when, so the composition
            // root's $isEnabled('test_tools') reads false here.
            $line = SimulatedTelephony::providerFor(false, $lost['profile'], $lost['settings'], $lost['state']);
            $this->assertInstanceOf(SuspendedPhoneProvider::class, $line);
        }

        $sos = new ProviderConfigService($credentials, \Closure::fromCallable($operator), $line);
        $active = $sos->getActiveProvider();
        $this->assertInstanceOf(PhoneProviderInterface::class, $active);
        try {
            $active->setForwarding('+32470111111');
            $this->fail('The suspended line must refuse.');
        } catch (ProviderException) {
            // expected
        }
        $this->assertSame(0, $operatorCalls, 'The configured operator is never called.');
        $this->assertNull($sos->getSosNumber(), 'No number is presented as the line\'s.');

        $this->assertSame(1, $this->countJournal('telephony_simulation_lost'), 'Written once, not on every request.');
        $this->assertSame(0, $this->countJournal('mail_capture_lost'), 'The mail switch was never armed.');
        $points = (new SandboxLossAttentionProvider($lost['state']))->collect(1);
        $this->assertCount(1, $points);
        $this->assertStringContainsString('téléphonie simulée', $points[0]->title);
    }

    /**
     * module.json gone while the registry row stays enabled (review of
     * #1549): ModuleManager skips test_tools, so the composition root hands
     * providerFor() `$isEnabled('test_tools') === false`. Before, nothing
     * was recorded and the configured operator was the line again.
     */
    public function testAModuleSkippedForItsManifestNeverDrivesTheRealLine(): void
    {
        $this->arm(SimulatedTelephony::SETTING_ARMED);
        $this->arm(MailSandboxService::SETTING_ARMED);
        $broken = $this->tempDir . '/modules';
        mkdir($broken . '/test_tools', 0700, true);

        $lost = $this->request(['reference_installation'], $broken);

        $this->assertSame(SandboxLossState::REASON_MODULE_UNLOADED, $lost['state']->reason(SandboxLossState::CHANNEL_TELEPHONY));
        $this->assertInstanceOf(
            SuspendedPhoneProvider::class,
            SimulatedTelephony::providerFor(false, $lost['profile'], $lost['settings'], $lost['state'])
        );
        $this->assertInstanceOf(SuspendedMailTransport::class, $this->mailTransport($lost));
        $this->assertSame(1, $this->countJournal('telephony_simulation_lost'));
    }

    /**
     * The capture's decision is the one recorded answer, never a second
     * read of the registry (review of #1549): a module switched off between
     * the record and the factory must not land on null, which is real
     * delivery. This request still captures; the next one records the loss.
     */
    public function testAModuleSwitchedOffAfterTheRecordStillCapturesThisRequest(): void
    {
        $this->arm(MailSandboxService::SETTING_ARMED);
        $holding = $this->request(['reference_installation']);

        (new ModuleRegistryRepository($this->pdo))->setEnabled(MailSandboxService::MODULE_ID, false);

        $this->assertInstanceOf(CaptureTransport::class, $this->mailTransport($holding));
        $this->assertInstanceOf(SuspendedMailTransport::class, $this->mailTransport($this->request(['reference_installation'])));
    }
}
