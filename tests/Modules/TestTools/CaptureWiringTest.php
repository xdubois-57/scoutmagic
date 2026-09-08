<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Module\InstallationProfile;
use Core\Module\ModuleRegistryRepository;
use Modules\TestTools\Mail\CaptureTransport;
use Modules\TestTools\Mail\CaptureTransportFactory;
use Modules\TestTools\Service\MailSandboxService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The three-way condition the composition root delegates
 * (ARCHITECTURE.md §8.63). All three must hold; each one is enough on its
 * own to keep mail sending exactly as before.
 */
#[Group('database')]
class CaptureWiringTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private ModuleRegistryRepository $registryRepo;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        TestToolsTestHelper::createTables($this->pdo);

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->registryRepo = new ModuleRegistryRepository($this->pdo);

        $this->settingService->register(
            MailSandboxService::SETTING_ARMED,
            '0',
            'boolean',
            'Capture des e-mails sortants',
            'Réglage de test.',
            MailSandboxService::MODULE_ID,
            null,
            null,
            false
        );

        $this->settingService->register(
            MailSandboxService::SETTING_DELIVER_MAGIC_LINKS,
            '0',
            'boolean',
            'Laisser partir les liens de connexion',
            'Réglage de test.',
            MailSandboxService::MODULE_ID,
            null,
            null,
            false
        );
    }

    private function enableModule(): void
    {
        $this->registryRepo->upsert(MailSandboxService::MODULE_ID, true, '1.0.0', null);
    }

    private function arm(): void
    {
        $this->settingService->setInternal(MailSandboxService::SETTING_ARMED, '1', MailSandboxService::MODULE_ID);
    }

    private function localProfile(): InstallationProfile
    {
        return new InstallationProfile([InstallationProfile::FLAG_LOCAL_INSTALLATION]);
    }

    private function exemptMagicLinks(): void
    {
        $this->settingService->setInternal(
            MailSandboxService::SETTING_DELIVER_MAGIC_LINKS,
            '1',
            MailSandboxService::MODULE_ID
        );
    }

    private function shouldCapture(InstallationProfile $profile): bool
    {
        return CaptureTransportFactory::shouldCapture($profile, $this->registryRepo, $this->settingService);
    }

    private function buildTransport(): ?CaptureTransport
    {
        return CaptureTransportFactory::forInstallation(
            $this->localProfile(),
            $this->registryRepo,
            $this->settingService,
            $this->pdo,
            TestToolsTestHelper::encryption(),
            sys_get_temp_dir()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manifestSetting(string $key): ?array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/modules/test_tools/module.json'),
            true
        );
        $this->assertIsArray($manifest);

        foreach ($manifest['settings'] as $setting) {
            if ($setting['key'] === $key) {
                return $setting;
            }
        }

        return null;
    }

    public function testEverythingLinedUpMeansCapture(): void
    {
        $this->enableModule();
        $this->arm();

        $this->assertTrue($this->shouldCapture($this->localProfile()));
        $this->assertInstanceOf(
            CaptureTransport::class,
            CaptureTransportFactory::forInstallation(
                $this->localProfile(),
                $this->registryRepo,
                $this->settingService,
                $this->pdo,
                TestToolsTestHelper::encryption(),
                sys_get_temp_dir()
            )
        );
    }

    public function testTheReferenceInstallationAlsoCaptures(): void
    {
        $this->enableModule();
        $this->arm();

        $this->assertTrue($this->shouldCapture(
            new InstallationProfile([InstallationProfile::FLAG_REFERENCE_INSTALLATION])
        ));
    }

    /**
     * A deploying unit's installation can never satisfy this, whatever its
     * database says — which is what makes the whole module admissible.
     */
    public function testNoCaptureWhenTheProfileLacksBothFlags(): void
    {
        $this->enableModule();
        $this->arm();

        $ordinary = new InstallationProfile([InstallationProfile::FLAG_STATISTICS_RECEIVER]);

        $this->assertFalse($this->shouldCapture($ordinary));
        $this->assertNull(CaptureTransportFactory::forInstallation(
            $ordinary,
            $this->registryRepo,
            $this->settingService,
            $this->pdo,
            TestToolsTestHelper::encryption(),
            sys_get_temp_dir()
        ));
    }

    public function testNoCaptureWhenTheModuleIsDisabled(): void
    {
        $this->arm();

        // No registry row at all…
        $this->assertFalse($this->shouldCapture($this->localProfile()));

        // …and an explicitly disabled one is no better.
        $this->registryRepo->upsert(MailSandboxService::MODULE_ID, false, '1.0.0', null);
        $this->assertFalse($this->shouldCapture($this->localProfile()));
    }

    public function testNoCaptureWhenTheSwitchIsOff(): void
    {
        $this->enableModule();

        $this->assertFalse($this->shouldCapture($this->localProfile()));
        $this->assertNull(CaptureTransportFactory::forInstallation(
            $this->localProfile(),
            $this->registryRepo,
            $this->settingService,
            $this->pdo,
            TestToolsTestHelper::encryption(),
            sys_get_temp_dir()
        ));
    }

    /**
     * A forged module_registry row is not a capture switch.
     */
    public function testARegistryRowAloneNeverCaptures(): void
    {
        $this->enableModule();

        $this->assertFalse($this->shouldCapture(new InstallationProfile([])));
    }

    /**
     * The exemption is NOT a fourth condition: it never decides whether
     * capture happens, only what one category of message does once it
     * has. Turning it on while the module is disabled, the profile
     * ordinary or the switch off must change nothing at all.
     */
    public function testTheMagicLinkExemptionIsNotAFourthCaptureCondition(): void
    {
        $this->exemptMagicLinks();

        $this->assertFalse($this->shouldCapture($this->localProfile()));
        $this->assertNull(CaptureTransportFactory::forInstallation(
            $this->localProfile(),
            $this->registryRepo,
            $this->settingService,
            $this->pdo,
            TestToolsTestHelper::encryption(),
            sys_get_temp_dir()
        ));

        // …and it does not stop capture happening either.
        $this->enableModule();
        $this->arm();
        $this->assertTrue($this->shouldCapture($this->localProfile()));
    }

    /**
     * The setting reaches the transport that reads it. Nothing else can
     * observe the wiring without actually delivering a message, which is
     * the one thing a test must not do.
     */
    public function testTheExemptionReachesTheTransportItConfigures(): void
    {
        $this->enableModule();
        $this->arm();

        $capturingEverything = $this->buildTransport();
        $this->assertInstanceOf(CaptureTransport::class, $capturingEverything);
        $this->assertFalse($capturingEverything->deliversMagicLinks());

        $this->exemptMagicLinks();

        $exempting = $this->buildTransport();
        $this->assertInstanceOf(CaptureTransport::class, $exempting);
        $this->assertTrue($exempting->deliversMagicLinks());
    }

    public function testTheMagicLinkExemptionIsRegisteredNonEditableAndOffByDefault(): void
    {
        $setting = $this->manifestSetting(MailSandboxService::SETTING_DELIVER_MAGIC_LINKS);

        $this->assertIsArray($setting, 'The exemption must be declared in module.json');
        $this->assertFalse(
            $setting['editable'],
            'The exemption must never render as an editable row on Configuration > Paramètres'
        );
        $this->assertSame(
            '0',
            $setting['default_value'],
            'Capturing everything is the default; letting sign-in links out is a decision an operator takes'
        );
    }

    public function testTheArmSwitchIsRegisteredNonEditable(): void
    {
        $armSetting = $this->manifestSetting(MailSandboxService::SETTING_ARMED);

        $this->assertIsArray($armSetting, 'The arm switch must be declared in module.json');
        $this->assertFalse(
            $armSetting['editable'],
            'The arm switch must never render as an editable row on Configuration > Paramètres'
        );
    }

    // ── Les deux points d'entrée, et pas seulement le web ───────────────

    /**
     * @return array<string, array{string}>
     */
    public static function entryPoints(): array
    {
        return [
            'the web request' => ['index.php'],
            'the real crontab' => ['cron.php'],
        ];
    }

    /**
     * Every entry point that builds a MailService asks the same question.
     *
     * The defect this pins: `public/cron.php` passed `null` where
     * index.php passed the factory's answer. The sandbox therefore
     * existed on the web path only, and everything a SCHEDULED task sends
     * went out for real — a retrospective's automatic closure, rental
     * reminders, invoices, notification digests. Half the site's mail,
     * and precisely the half nobody triggers by hand, so the half nobody
     * thinks to check. It was found by receiving one of those e-mails
     * with the capture armed.
     *
     * Asserted on the source rather than by running it: a composition
     * root is the one place no unit test reaches, which is exactly why
     * the two copies drifted.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testEveryEntryPointRoutesItsMailThroughTheSandboxDecision(string $file): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/public/' . $file);

        $this->assertStringContainsString(
            'CaptureTransportFactory::forInstallation(',
            $source,
            $file . ' builds a MailService without asking whether this installation captures mail: '
            . 'everything it sends would go out even with the sandbox armed'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/MailServiceFactory::create\([^;]*,\s*null\s*,/s',
            $source,
            $file . ' hands MailServiceFactory a null transport — that is the shape the bug had'
        );
    }

    /**
     * The one place a `null` transport is right, and why it is not an
     * oversight.
     *
     * The setup page's « envoyer un e-mail de test » exists to answer
     * « does my SMTP configuration work ». Capturing that would answer a
     * different question and answer it wrongly: the operator would see
     * « envoyé avec succès » having proven nothing at all. It is the only
     * send on the site whose entire purpose is to leave the machine.
     */
    public function testTheSetupSmtpTestDeliberatelySendsForReal(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/core/Http/Controller/SetupController.php'
        );

        $this->assertMatchesRegularExpression(
            '/MailServiceFactory::create\([^;]*,\s*null\s*,/s',
            $source,
            'the setup SMTP test must keep sending for real: capturing it would report success '
            . 'without proving anything'
        );
        // And it says so, so the next reader does not "fix" it.
        $this->assertStringContainsString(
            'bac à sable',
            $source,
            'the deliberate exception must explain itself where it lives'
        );
    }
}
