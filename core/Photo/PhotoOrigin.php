<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

/**
 * Where the photo shown for a member in a given scout year comes from.
 *
 * The site never shows an empty face when an older photo exists
 * (MemberPhotoRepository::findFileIdForYearOrEarlier()), so "a photo is
 * shown" does not mean "this year's photo was taken". This says which of
 * the two it is — the question the "Année scoute" workflow asks, where a
 * photo carried over from last year is a photo still to take
 * (chantier « Année scoute » §2.7).
 */
enum PhotoOrigin: string
{
    /** A photo stored for exactly that scout year. */
    case Exact = 'exact';

    /** No photo for that year: the one shown is from an earlier year. */
    case Earlier = 'earlier';

    /** No photo for that year nor any earlier one. */
    case None = 'none';
}
