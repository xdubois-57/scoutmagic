<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\OperationalCheck;
use Core\Config\SettingService;

/**
 * Has the development update channel been left switched on?
 *
 * On that channel every push to the watched branch installs itself
 * immediately, with no version gate and no weekly slot (ARCHITECTURE.md
 * §8.17). That is right for a machine somebody is testing on and wrong for
 * a unit's live site, where it means the site reinstalls itself at
 * whatever hour a commit happens to land.
 *
 * **A binary check, so its two thresholds are the same fact read both
 * ways**: on trips it, off re-arms it. The gap the other checks need
 * exists to stop a wobbling number notifying in a loop; a switch does not
 * wobble.
 *
 * The setting the chantier document names — `dev_update_enabled` — does
 * not exist. Development mode is `auto_update_enabled` ON *and*
 * `auto_update_level` at `'dev'`: a fourth value of the level radio
 * group, gated by the same top-level switch, rather than a danger toggle
 * of its own. Both have to be true, which is why this reads both.
 */
final class DevelopmentModeCheck implements OperationalCheck
{
    public const KEY = 'development_mode';

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Mises à jour automatiques';
    }

    public function read(): AlertReading
    {
        $enabled = (bool) ((int) ($this->settings->get('auto_update_enabled') ?: '0'));
        $level = (string) ($this->settings->get('auto_update_level') ?: 'minor');
        $inDevelopmentMode = $enabled && $level === 'dev';

        return new AlertReading(
            overTrigger: $inDevelopmentMode,
            underRearm: !$inDevelopmentMode,
            value: $inDevelopmentMode ? 'activé' : 'désactivé',
            title: 'Le site est en mode développement.',
            why: 'Chaque nouveau commit s\'installe immédiatement, sans attendre une version publiée ni le '
                . 'créneau hebdomadaire. C\'est fait pour une installation de test, pas pour le site d\'une '
                . 'unité. Repassez sur « Corrections » ou « Nouveautés » dans les mises à jour automatiques.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir les mises à jour'
        );
    }
}
