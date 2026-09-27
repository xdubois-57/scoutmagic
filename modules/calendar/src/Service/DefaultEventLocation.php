<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Config\SettingService;
use Core\Config\UnitAddresses;

/**
 * The place a new event starts with (issue #497): the calendar's own
 * setting when somebody wrote one, else the unit's premises, else the word
 * « Local » that used to be the only choice.
 *
 * The premises' coordinates are not passed on: an event carries a free text
 * location and nothing else, so the address is what it can hold.
 *
 * **« Local » counts as nobody having chosen.** It was the shipped default
 * until calendar 1.10.0, and every installation that ever enabled the
 * calendar still stores it: `SettingService::register()` moves a
 * never-customised value along with a new default only for `url` settings
 * (`SettingRepository::updateDefaultValue()`), so the empty default reaches
 * new sites only. Read here rather than migrated once, because a stored
 * « Local » and the old default are the same word — and a unit that typed it
 * by hand while filling in its premises is asking for the same thing anyway.
 *
 * Static, taking the settings as an argument, like `UnitAddresses`: no state,
 * and the one caller already holds the store.
 */
final class DefaultEventLocation
{
    /** The default location shipped until calendar 1.10.0. */
    public const LEGACY_DEFAULT = 'Local';

    public static function resolve(SettingService $settingService): string
    {
        $configured = trim((string) $settingService->get('event_default_location', 'calendar', ''));
        if ($configured !== '' && $configured !== self::LEGACY_DEFAULT) {
            return $configured;
        }

        $premises = UnitAddresses::premisesAddress($settingService);

        return $premises !== null ? UnitAddresses::oneLine($premises) : self::LEGACY_DEFAULT;
    }
}
