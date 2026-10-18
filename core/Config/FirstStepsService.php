<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Config;

use Core\Security\Role;

/**
 * Owns the `first_steps_pending` setting: whether the « Premiers pas » band
 * — the three pages to visit after a new installation (issue #718 § A) —
 * is still shown to superadmins.
 *
 * The setup wizard ends on a redirect to the home page, logged out, so a
 * page shown "right after the installation" would be seen by nobody. The
 * band waits instead for the first superadmin to sign in, and stays on
 * every page until one of them hides it: the three steps take more than
 * one sitting, and a band that disappeared at the first page change would
 * be read once and lost.
 *
 * The declared default is off, so a site that existed before this setting
 * never sees the band; only SetupController switches it on, for the site
 * it has just created.
 */
class FirstStepsService
{
    public const SETTING_KEY = 'first_steps_pending';

    public function __construct(private SettingService $settingService)
    {
    }

    /**
     * Declare the setting row. Here rather than in public/index.php for the
     * reason InstallationDateService::register() gives: SetupController
     * writes this value before the composition root has ever run.
     */
    public static function register(SettingService $settingService): void
    {
        $settingService->register(
            self::SETTING_KEY,
            '0',
            'boolean',
            'Bandeau « Premiers pas » affiché',
            'Affiche aux superadmins les trois étapes à suivre après une nouvelle installation, jusqu\'à ce '
                . 'que l\'un d\'eux masque le bandeau. Géré automatiquement.',
            null,
            null,
            null,
            false,
            285
        );
    }

    /**
     * Called once, by the setup wizard, for the site it has just created.
     */
    public function markPending(): void
    {
        $this->settingService->setInternal(self::SETTING_KEY, '1');
    }

    public function shouldShowTo(string $role): bool
    {
        return $role === Role::SUPERADMIN->value
            && (string) $this->settingService->get(self::SETTING_KEY) === '1';
    }

    /**
     * For every superadmin at once: the steps belong to the site, not to
     * an account, and the next superadmin to sign in has nothing to redo.
     */
    public function dismiss(): void
    {
        $this->settingService->setInternal(self::SETTING_KEY, '0');
    }
}
