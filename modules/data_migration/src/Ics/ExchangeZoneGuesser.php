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
 * number), with today's names for the zones it still calls by a name that
 * was renamed since (#1238).
 *
 * vobject maps 23, 60 and 61 to Asia/Calcutta, America/Godthab and
 * Asia/Rangoon, which a PHP without the time zone database's legacy links
 * refuses with an exception: the same calendar imported its Indian,
 * Greenlandic and Burmese events on one host and not on the next. Those
 * numbers are answered with the current names
 * ({@see CurrentZoneNameFinder::RENAMED}), known everywhere; every other
 * number is vobject's own answer.
 */
final class ExchangeZoneGuesser implements TimezoneGuesser
{
    /** vobject's key for its Exchange guesser: registering under it replaces that one, in place. */
    public const GUESSER_KEY = 'msTzId';

    public function __construct(private TimezoneGuesser $vobject = new GuessFromMsTzId())
    {
    }

    /**
     * Puts this guesser and {@see CurrentZoneNameFinder} in vobject's
     * place. Called before every reading rather than once:
     * `TimeZoneUtil::clean()` drops every registration, and two array
     * writes are all it costs.
     */
    public static function register(): void
    {
        TimeZoneUtil::addTimezoneGuesser(self::GUESSER_KEY, new self());
        TimeZoneUtil::addTimezoneFinder(CurrentZoneNameFinder::FINDER_KEY, new CurrentZoneNameFinder());
    }

    public function guess(VTimeZone $vtimezone, bool $throwIfUnsure = false): ?\DateTimeZone
    {
        if (isset($vtimezone->{'X-MICROSOFT-CDO-TZID'})) {
            $number = (int) $vtimezone->{'X-MICROSOFT-CDO-TZID'}->getValue();
            $name = GuessFromMsTzId::$microsoftExchangeMap[$number] ?? null;
            if ($name !== null && isset(CurrentZoneNameFinder::RENAMED[$name])) {
                return new \DateTimeZone(CurrentZoneNameFinder::RENAMED[$name]);
            }
        }

        return $this->vobject->guess($vtimezone, $throwIfUnsure);
    }
}
