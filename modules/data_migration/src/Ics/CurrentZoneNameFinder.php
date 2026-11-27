<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Ics;

use Sabre\VObject\TimezoneGuesser\FindFromTimezoneIdentifier;
use Sabre\VObject\TimezoneGuesser\TimezoneFinder;

/**
 * vobject's lookup of a zone by its name, with today's names for the zones
 * renamed since a calendar may have been written (#1238).
 *
 * Asia/Calcutta, America/Godthab and Asia/Rangoon survive only as links in
 * the time zone database, and a PHP that reads the system's data without
 * them (Ubuntu 24.04 without `tzdata-legacy`) does not know them: a TZID —
 * or an `X-LIC-LOCATION`, which vobject resolves through the same lookup —
 * carrying one of them was an unknown zone on one host and a known one on
 * the next. They are looked up under their current names instead.
 *
 * {@see ExchangeZoneGuesser} does the same for Exchange's numbered zones,
 * which vobject maps to these very names.
 */
final class CurrentZoneNameFinder implements TimezoneFinder
{
    /** vobject's key for its lookup by name: registering under it replaces that one, in place. */
    public const FINDER_KEY = 'tzid';

    /** @var array<string, string> a renamed zone => its current name */
    public const RENAMED = [
        'Asia/Calcutta' => 'Asia/Kolkata',
        'America/Godthab' => 'America/Nuuk',
        'Asia/Rangoon' => 'Asia/Yangon',
    ];

    public function __construct(private TimezoneFinder $vobject = new FindFromTimezoneIdentifier())
    {
    }

    /** The current name of a zone, or the name given when it was not renamed. */
    public static function current(string $name): string
    {
        return self::RENAMED[$name] ?? $name;
    }

    public function find(string $tzid, bool $failIfUncertain = false): ?\DateTimeZone
    {
        return $this->vobject->find(self::current($tzid), $failIfUncertain);
    }
}
