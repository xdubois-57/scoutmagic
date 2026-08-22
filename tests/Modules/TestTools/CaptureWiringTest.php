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

    private function shouldCapture(InstallationProfile $profile): bool
    {
        return CaptureTransportFactory::shouldCapture($profile, $this->registryRepo, $this->settingService);
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

    public function testTheArmSwitchIsRegisteredNonEditable(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/modules/test_tools/module.json'),
            true
        );
        $this->assertIsArray($manifest);

        $armSetting = null;
        foreach ($manifest['settings'] as $setting) {
            if ($setting['key'] === MailSandboxService::SETTING_ARMED) {
                $armSetting = $setting;
            }
        }

        $this->assertIsArray($armSetting, 'The arm switch must be declared in module.json');
        $this->assertFalse(
            $armSetting['editable'],
            'The arm switch must never render as an editable row on Configuration > Paramètres'
        );
    }
}
