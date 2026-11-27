<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Sandbox;

use Core\Config\SettingService;
use Core\Module\InstallationProfile;
use Core\Module\ModuleException;
use Core\Module\ModuleManifest;
use Core\Module\ModuleRegistryRepository;
use Core\Security\SandboxLossState;
use Modules\TestTools\Service\MailSandboxService;
use Modules\TestTools\Telephony\SimulatedTelephony;

/**
 * Why a sandbox whose switch is still armed no longer acts — or null when
 * it does, or is not armed (ARCHITECTURE.md §8.63, issue #1469).
 *
 * Static and free of module state on purpose: the composition roots call
 * it on every request, **including when this module is not loaded** —
 * that is one of the very situations it reports. It reads only what core
 * already holds: the resolved profile, the raw `installation_profile`
 * declaration, the registry row and two settings rows that outlive the
 * module's loading. What follows from its answer (journal, block,
 * attention point, release) is `Core\Security\SandboxLossState`'s.
 *
 * The profile is checked before the module, because a lost profile is
 * also what unloads the module (`visible_when`, §8.49): reporting
 * « module not loaded » there would send the superadmin to the Modules
 * page, where the module is not even listed.
 *
 * **« The module » is whether ModuleManager would load it, not its
 * registry row.** The row can stay enabled while `module.json` is gone or
 * invalid, and then ModuleManager skips the module: `$isEnabled('test_tools')`
 * reads false, the simulated line is not handed over and `sos_staff`
 * falls back to the real operator. So the same conditions are read here,
 * from disk, before the first decision of the request is taken — the
 * composition roots record this answer before building any transport.
 */
final class SandboxLoss
{
    /** @var array<int, string> */
    private const CAPTURE_FLAGS = [
        InstallationProfile::FLAG_REFERENCE_INSTALLATION,
        InstallationProfile::FLAG_LOCAL_INSTALLATION,
    ];

    /**
     * @param mixed $declaredFlags the raw `installation_profile` value of
     *        config/app.php, as InstallationProfile::resolve() received it
     */
    public static function mailLossReason(
        InstallationProfile $profile,
        mixed $declaredFlags,
        ModuleRegistryRepository $registry,
        SettingService $settings,
        string $modulesDir
    ): ?string {
        if (!MailSandboxService::isArmed($settings)) {
            return null;
        }

        return self::lossReason($profile, $declaredFlags, $registry, $modulesDir);
    }

    /** @param mixed $declaredFlags see {@see self::mailLossReason()} */
    public static function telephonyLossReason(
        InstallationProfile $profile,
        mixed $declaredFlags,
        ModuleRegistryRepository $registry,
        SettingService $settings,
        string $modulesDir
    ): ?string {
        if (!SimulatedTelephony::isArmed($settings)) {
            return null;
        }

        return self::lossReason($profile, $declaredFlags, $registry, $modulesDir);
    }

    private static function lossReason(
        InstallationProfile $profile,
        mixed $declaredFlags,
        ModuleRegistryRepository $registry,
        string $modulesDir
    ): ?string {
        if (!$profile->hasAny(self::CAPTURE_FLAGS)) {
            return self::declaresACaptureFlag($declaredFlags)
                ? SandboxLossState::REASON_HOST_MISMATCH
                : SandboxLossState::REASON_PROFILE_MISSING;
        }

        if (!self::moduleLoads($profile, $registry, $modulesDir)) {
            return SandboxLossState::REASON_MODULE_UNLOADED;
        }

        return null;
    }

    /**
     * Whether ModuleManager::loadEnabledModules() would load `test_tools`
     * on this request: its registry row enabled, its `module.json` present
     * and valid under its own directory name, its `visible_when` held by
     * the profile, and every module it requires enabled. Mirrors
     * ModuleManager::discoverModules() rather than calling it because the
     * answer is needed before ModuleManager exists — MailService is built
     * first. Read only while a switch is armed, so an ordinary
     * installation never pays for it.
     */
    private static function moduleLoads(
        InstallationProfile $profile,
        ModuleRegistryRepository $registry,
        string $modulesDir
    ): bool {
        $row = $registry->findByModuleId(MailSandboxService::MODULE_ID);
        if ($row === null || !$row['enabled']) {
            return false;
        }

        try {
            $manifest = ModuleManifest::fromFile(
                rtrim($modulesDir, '/') . '/' . MailSandboxService::MODULE_ID . '/module.json'
            );
        } catch (ModuleException) {
            return false;
        }

        if ($manifest->id !== MailSandboxService::MODULE_ID) {
            return false;
        }
        if ($manifest->visibleWhen !== [] && !$profile->hasAny($manifest->visibleWhen)) {
            return false;
        }

        foreach ($manifest->requires as $required) {
            $requiredRow = $registry->findByModuleId($required);
            if ($requiredRow === null || !$requiredRow['enabled']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether config/app.php still names one of the two flags. A
     * declaration InstallationProfile::resolve() refuses as a whole (not a
     * list, an unknown flag beside it) still counts when it names one: the
     * operator's intent is there, and « absent » would send them looking
     * for a line they can see.
     */
    private static function declaresACaptureFlag(mixed $declaredFlags): bool
    {
        if (!is_array($declaredFlags)) {
            return false;
        }

        foreach ($declaredFlags as $flag) {
            if (in_array($flag, self::CAPTURE_FLAGS, true)) {
                return true;
            }
        }

        return false;
    }
}
