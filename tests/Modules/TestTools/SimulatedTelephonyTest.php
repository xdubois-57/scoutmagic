<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Config\SettingException;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\InstallationProfile;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Api\ProviderException;
use Modules\TestTools\Service\MailSandboxService;
use Modules\TestTools\Telephony\SimulatedPhoneProvider;
use Modules\TestTools\Telephony\SimulatedTelephony;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Simulated telephony (ARCHITECTURE.md §8.63): the gate that decides where
 * the SOS module may talk to a simulated line, and the line itself.
 */
#[Group('database')]
class SimulatedTelephonyTest extends TestCase
{
    private SettingService $settingService;
    private SimulatedTelephony $telephony;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $pdo = DatabaseTestHelper::createTestDatabase();
        TestToolsTestHelper::createTables($pdo);
        $this->settingService = new SettingService(new SettingRepository($pdo));
        foreach ([SimulatedTelephony::SETTING_ARMED => 'boolean', SimulatedTelephony::SETTING_FORWARDING => 'text'] as $key => $type) {
            $this->settingService->register(
                $key,
                $type === 'boolean' ? '0' : '',
                $type,
                'Téléphonie simulée',
                'Réglage de test.',
                MailSandboxService::MODULE_ID,
                null,
                null,
                false
            );
        }
        $this->telephony = new SimulatedTelephony($this->settingService, new JournalService(new JournalRepository($pdo)));
    }

    public function testNothingIsSimulatedUntilTheSwitchIsArmed(): void
    {
        $local = new InstallationProfile([InstallationProfile::FLAG_LOCAL_INSTALLATION]);

        $this->assertFalse(SimulatedTelephony::isAllowed($local, $this->settingService));
        $this->telephony->setArmed(true);
        $this->assertTrue(SimulatedTelephony::isAllowed($local, $this->settingService));
    }

    public function testAnArmedSwitchIsIgnoredOnADeployingUnitsInstallation(): void
    {
        $this->telephony->setArmed(true);

        // An armed row in a unit's own database never makes its emergency
        // number a pretence: the profile is decided from base_url.
        $this->assertFalse(SimulatedTelephony::isAllowed(new InstallationProfile([]), $this->settingService));
        $this->assertTrue(SimulatedTelephony::isAllowed(
            new InstallationProfile([InstallationProfile::FLAG_REFERENCE_INSTALLATION]),
            $this->settingService
        ));
    }

    public function testTheLineReadsBackTheForwardingItWasGiven(): void
    {
        $provider = new SimulatedPhoneProvider($this->settingService);
        $this->assertInstanceOf(PhoneProviderInterface::class, $provider);

        $before = $provider->readForwardingState();
        $this->assertFalse($before->active);
        $this->assertNull($before->number);

        $provider->setForwarding('+32470112233');
        // A second instance, as on the next request: the state is stored.
        $after = (new SimulatedPhoneProvider($this->settingService))->readForwardingState();
        $this->assertTrue($after->active);
        $this->assertSame('+32470112233', $after->number);
    }

    public function testAForwardingThatCannotBeStoredIsAProviderFailure(): void
    {
        $settings = $this->createStub(SettingService::class);
        $settings->method('setInternal')->willThrowException(new SettingException('introuvable'));

        $this->expectException(ProviderException::class);
        (new SimulatedPhoneProvider($settings))->setForwarding('+32470112233');
    }

    public function testTheLineAlwaysAnswersAndOffersOneNumber(): void
    {
        $provider = new SimulatedPhoneProvider($this->settingService);

        $this->assertTrue($provider->testConnection());
        $lines = $provider->listLines();
        $this->assertCount(1, $lines);
        $this->assertSame(SimulatedPhoneProvider::LINE_NUMBER, $lines[0]->number);
    }
}
