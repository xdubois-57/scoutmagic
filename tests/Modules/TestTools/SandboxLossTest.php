<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Module\InstallationProfile;
use Core\Module\ModuleRegistryRepository;
use Core\Security\SandboxLossState;
use Modules\TestTools\Sandbox\SandboxLoss;
use Modules\TestTools\Service\MailSandboxService;
use Modules\TestTools\Telephony\SimulatedTelephony;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Why an armed sandbox no longer acts (issue #1469): each reason, and null
 * whenever the sandbox is not armed or still holds.
 */
#[Group('database')]
final class SandboxLossTest extends TestCase
{
    private SettingService $settings;
    private ModuleRegistryRepository $registry;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($pdo));
        $this->registry = new ModuleRegistryRepository($pdo);
        foreach ([MailSandboxService::SETTING_ARMED, SimulatedTelephony::SETTING_ARMED] as $key) {
            $this->settings->register(
                $key,
                '0',
                'boolean',
                'Interrupteur',
                'Réglage de test.',
                MailSandboxService::MODULE_ID,
                null,
                null,
                false
            );
        }
        $this->registry->upsert(MailSandboxService::MODULE_ID, true, '1.0.0', null);
    }

    private function armMail(): void
    {
        $this->settings->setInternal(MailSandboxService::SETTING_ARMED, '1', MailSandboxService::MODULE_ID);
    }

    private function armTelephony(): void
    {
        $this->settings->setInternal(SimulatedTelephony::SETTING_ARMED, '1', MailSandboxService::MODULE_ID);
    }

    private function local(): InstallationProfile
    {
        return new InstallationProfile([InstallationProfile::FLAG_LOCAL_INSTALLATION]);
    }

    private function mailReason(InstallationProfile $profile, mixed $declared): ?string
    {
        return SandboxLoss::mailLossReason($profile, $declared, $this->registry, $this->settings);
    }

    public function testNothingIsLostWhileTheSwitchIsNotArmed(): void
    {
        $this->assertNull($this->mailReason(new InstallationProfile(), []));
        $this->assertNull(
            SandboxLoss::telephonyLossReason(new InstallationProfile(), [], $this->registry, $this->settings)
        );
    }

    public function testNothingIsLostWhileTheCaptureStillHolds(): void
    {
        $this->armMail();

        $this->assertNull($this->mailReason($this->local(), ['local_installation']));
    }

    public function testNoDeclarationLeftIsAMissingProfile(): void
    {
        $this->armMail();

        $this->assertSame(SandboxLossState::REASON_PROFILE_MISSING, $this->mailReason(new InstallationProfile(), []));
        $this->assertSame(
            SandboxLossState::REASON_PROFILE_MISSING,
            $this->mailReason(new InstallationProfile(), 'not even a list')
        );
        $this->assertSame(
            SandboxLossState::REASON_PROFILE_MISSING,
            $this->mailReason(new InstallationProfile(), ['statistics_receiver'])
        );
    }

    public function testADeclaredFlagTheHostNoLongerSatisfiesIsAHostMismatch(): void
    {
        $this->armMail();

        $this->assertSame(
            SandboxLossState::REASON_HOST_MISMATCH,
            $this->mailReason(new InstallationProfile(), ['reference_installation'])
        );
        $this->assertSame(
            SandboxLossState::REASON_HOST_MISMATCH,
            $this->mailReason(new InstallationProfile(), ['local_installation'])
        );
    }

    public function testAHoldingProfileWithTheModuleOffIsAnUnloadedModule(): void
    {
        $this->armMail();
        $this->registry->setEnabled(MailSandboxService::MODULE_ID, false);

        $this->assertSame(
            SandboxLossState::REASON_MODULE_UNLOADED,
            $this->mailReason($this->local(), ['local_installation'])
        );
    }

    public function testAModuleNeverRegisteredIsUnloadedToo(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $settings = new SettingService(new SettingRepository($pdo));
        $settings->register(
            MailSandboxService::SETTING_ARMED,
            '1',
            'boolean',
            'Interrupteur',
            'Réglage de test.',
            MailSandboxService::MODULE_ID,
            null,
            null,
            false
        );

        $this->assertSame(
            SandboxLossState::REASON_MODULE_UNLOADED,
            SandboxLoss::mailLossReason($this->local(), ['local_installation'], new ModuleRegistryRepository($pdo), $settings)
        );
    }

    /** The profile first: a lost profile is also what unloads the module. */
    public function testTheProfileIsReportedBeforeTheModule(): void
    {
        $this->armMail();
        $this->registry->setEnabled(MailSandboxService::MODULE_ID, false);

        $this->assertSame(SandboxLossState::REASON_PROFILE_MISSING, $this->mailReason(new InstallationProfile(), []));
    }

    public function testTelephonyFollowsTheSameRuleOnItsOwnSwitch(): void
    {
        $this->armTelephony();

        $this->assertNull($this->mailReason(new InstallationProfile(), []), 'The mail switch is not armed.');
        $this->assertSame(
            SandboxLossState::REASON_HOST_MISMATCH,
            SandboxLoss::telephonyLossReason(
                new InstallationProfile(),
                ['reference_installation'],
                $this->registry,
                $this->settings
            )
        );
        $this->assertNull(
            SandboxLoss::telephonyLossReason($this->local(), ['local_installation'], $this->registry, $this->settings)
        );
    }
}
