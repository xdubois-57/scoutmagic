<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

/**
 * « père - mère - tuteur - répondant », the four the form prints and asks to
 * strike down to one.
 *
 * An enum rather than a string because it decides which THREE strokes are
 * drawn on a document somebody signs: a value the form does not know would
 * leave all four standing, which reads as a parent who did not fill the form
 * in.
 */
enum SignatoryCapacity: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Guardian = 'guardian';
    case Sponsor = 'sponsor';

    /** The name of the strike zone that cancels this mention. */
    public function strikeZone(): string
    {
        return match ($this) {
            self::Father => 'capacity_father',
            self::Mother => 'capacity_mother',
            self::Guardian => 'capacity_guardian',
            self::Sponsor => 'capacity_sponsor',
        };
    }

    /** As the form prints it, and as the screen offers it. */
    public function label(): string
    {
        return match ($this) {
            self::Father => 'Père',
            self::Mother => 'Mère',
            self::Guardian => 'Tuteur',
            self::Sponsor => 'Répondant',
        };
    }

    /** @return list<self> in the order the form prints them. */
    public static function all(): array
    {
        return [self::Father, self::Mother, self::Guardian, self::Sponsor];
    }
}
