<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Telephony;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Module\InstallationProfile;
use Core\Security\SandboxLossState;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\TestTools\Service\MailSandboxService;

/**
 * Simulated telephony (ARCHITECTURE.md §8.63): a stand-in line that the
 * sos_staff module talks to instead of a real provider, so an end-to-end
 * run can drive « Gérer le téléphone d'urgence » without an OVH account.
 *
 * Same three-way condition as the mail sandbox, and for the same reason —
 * a switch that makes the emergency number's redirection a pretence must
 * never be reachable on a deploying unit's installation:
 *
 *  1. the installation profile carries `reference_installation` or
 *     `local_installation`;
 *  2. the `test_tools` module is enabled — checked by the caller, which
 *     already knows (`$isEnabled()` in the composition root,
 *     TaskCapabilities on the scheduled path);
 *  3. the arm switch is on.
 */
final class SimulatedTelephony
{
    /** The arm switch. Registered non-editable, toggled only from /test-tools. */
    public const SETTING_ARMED = 'simulated_telephony_armed';

    /** Where the simulated line forwards to — written by setForwarding() only. */
    public const SETTING_FORWARDING = 'simulated_telephony_forwarding';

    /** @var array<int, string> */
    private const ALLOWED_FLAGS = [
        InstallationProfile::FLAG_REFERENCE_INSTALLATION,
        InstallationProfile::FLAG_LOCAL_INSTALLATION,
    ];

    public function __construct(
        private SettingService $settingService,
        private JournalService $journalService
    ) {
    }

    /**
     * Conditions 1 and 3. The module's own enablement is the caller's to
     * check: this is only ever asked from a place that has already done so.
     */
    public static function isAllowed(InstallationProfile $profile, SettingService $settingService): bool
    {
        return $profile->hasAny(self::ALLOWED_FLAGS) && self::isArmed($settingService);
    }

    /**
     * The stand-in line public/index.php hands `sos_staff`, or null for the
     * configured operator: the refusing line while simulated telephony is
     * armed and no longer acting (issue #1469), the simulated one where
     * all three conditions hold, nothing otherwise.
     *
     * The loss reason has already been recorded on $lossState by the
     * composition root (Modules\TestTools\Sandbox\SandboxLoss), from the
     * same conditions ModuleManager loads the module on — so with the
     * switch armed and no loss recorded, $moduleEnabled is true. Should
     * the two ever disagree, the line is refused rather than handed back
     * to the real operator: null is reached only unarmed or released.
     */
    public static function providerFor(
        bool $moduleEnabled,
        InstallationProfile $profile,
        SettingService $settingService,
        SandboxLossState $lossState
    ): ?PhoneProviderInterface {
        if ($lossState->isBlocked(SandboxLossState::CHANNEL_TELEPHONY)) {
            return new SuspendedPhoneProvider();
        }

        if ($lossState->reason(SandboxLossState::CHANNEL_TELEPHONY) !== null || !self::isArmed($settingService)) {
            // Released by a superadmin, or simply not armed: the configured operator.
            return null;
        }

        return $moduleEnabled && self::isAllowed($profile, $settingService)
            ? new SimulatedPhoneProvider($settingService)
            : new SuspendedPhoneProvider();
    }

    public static function isArmed(SettingService $settingService): bool
    {
        return (string) ($settingService->get(self::SETTING_ARMED, MailSandboxService::MODULE_ID) ?? '0') === '1';
    }

    public function armed(): bool
    {
        return self::isArmed($this->settingService);
    }

    /**
     * Journaled at level `security` both ways, like the mail sandbox's
     * switch: whoever wonders why the emergency number was never really
     * redirected needs to read when this was turned on, and by whom.
     */
    public function setArmed(bool $armed, ?int $userId = null): void
    {
        $this->settingService->setInternal(self::SETTING_ARMED, $armed ? '1' : '0', MailSandboxService::MODULE_ID);

        $this->journalService->log(
            MailSandboxService::MODULE_ID,
            $armed ? 'simulated_telephony_armed' : 'simulated_telephony_disarmed',
            'security',
            $armed
                ? 'Téléphonie simulée activée : le téléphone d\'urgence ne parle plus au vrai fournisseur.'
                : 'Téléphonie simulée désactivée : le téléphone d\'urgence reparle au fournisseur configuré.',
            [],
            $userId
        );
    }
}
