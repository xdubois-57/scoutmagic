<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Service\TextNormalizerService;

/**
 * The one comparison key for "is this the same person?" —
 * surname + first name + date of birth, normalised.
 *
 * **Name AND first name AND date of birth, never two of the three.** Twins
 * share a surname and a birth date; a family often reuses a first name
 * across generations. Any pair of the three produces false matches that a
 * human then has to disprove, one by one, which is how a matching feature
 * stops being used.
 *
 * It was born in `Modules\Registration` — matching a registration request
 * against an imported member — and lived there as a static method on that
 * module's repository. It is core's now, for the reason
 * `ARCHITECTURE.md` §7.4 gives and `Core\Member\FeeEstimationService`
 * already illustrated: a rule about members belongs to the core, and a
 * second consumer (`Core\Member\Duplicate\DuplicateMemberDetector`) must
 * not have to reach into an optional module for it. Two normalisations of
 * "the same person" would be two normalisations one edit away from
 * disagreeing, and a blind index that misses reports "not found" rather
 * than failing.
 *
 * **Comparison only, never displayed.** The output is fed to
 * `EncryptionService::blindIndex()` and compared; it is not a name.
 */
final class NameDobKey
{
    /**
     * The blind-index context the registration module has always used for
     * this key. Shared deliberately: two consumers asking the same
     * question have to land on the same index, or one of them silently
     * never matches.
     */
    public const BLIND_INDEX_CONTEXT = 'registration_name_dob';

    /**
     * `TextNormalizerService::normalizeName()` folds accents, spacing and
     * particle casing the same way regardless of how the source spelled
     * it; the final lowercase pass makes the index fully case-insensitive.
     */
    public static function normalize(string $lastName, string $firstName, string $birthDate): string
    {
        $fold = static fn(string $s): string => mb_strtolower(TextNormalizerService::normalizeName($s), 'UTF-8');

        return $fold($lastName) . '|' . $fold($firstName) . '|' . trim($birthDate);
    }
}
