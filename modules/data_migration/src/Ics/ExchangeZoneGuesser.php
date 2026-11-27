<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Ics;

use Sabre\VObject\Component\VTimeZone;
use Sabre\VObject\TimeZoneUtil;
use Sabre\VObject\TimezoneGuesser\GuessFromMsTzId;
use Sabre\VObject\TimezoneGuesser\TimezoneGuesser;

/**
 * vobject's Exchange guesser (`X-MICROSOFT-CDO-TZID`, a zone given by a
 * number), with today's names for the three zones it still calls by a name
 * that was renamed since (#1238).
 *
 * vobject maps 23, 60 and 61 to Asia/Calcutta, America/Godthab and
 * Asia/Rangoon. Those survive only as links in the time zone database, and
 * a PHP that reads the system's data without them (Ubuntu 24.04 without
 * `tzdata-legacy`) refuses the name with an exception: the same calendar
 * imported its Indian, Greenlandic and Burmese events on one host and not
 * on the next. The current names are known everywhere, so they are used
 * instead; every other number is vobject's own answer.
 */
final class ExchangeZoneGuesser implements TimezoneGuesser
{
    /** vobject's key for its Exchange guesser: registering under it replaces that one, in place. */
    private const GUESSER_KEY = 'msTzId';

    /** @var array<int, string> Exchange number => the zone's current name */
    public const RENAMED = [
        23 => 'Asia/Kolkata',
        60 => 'America/Nuuk',
        61 => 'Asia/Yangon',
    ];

    public function __construct(private GuessFromMsTzId $vobject = new GuessFromMsTzId())
    {
    }

    /**
     * Puts this guesser in vobject's place. Called before every reading
     * rather than once: `TimeZoneUtil::clean()` drops every registration,
     * and an array write is all it costs.
     */
    public static function register(): void
    {
        TimeZoneUtil::addTimezoneGuesser(self::GUESSER_KEY, new self());
    }

    public function guess(VTimeZone $vtimezone, bool $throwIfUnsure = false): ?\DateTimeZone
    {
        if (isset($vtimezone->{'X-MICROSOFT-CDO-TZID'})) {
            $number = (int) $vtimezone->{'X-MICROSOFT-CDO-TZID'}->getValue();
            if (isset(self::RENAMED[$number])) {
                return new \DateTimeZone(self::RENAMED[$number]);
            }
        }

        return $this->vobject->guess($vtimezone, $throwIfUnsure);
    }
}
