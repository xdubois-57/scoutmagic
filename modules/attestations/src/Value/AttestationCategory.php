<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Value;

/**
 * What kind of certificate a batch holds — tax, attendance, or something
 * else the unit hands out.
 *
 * **It configures nothing**, and that is worth stating because a category
 * usually does. It exists for exactly one job: reconciling batches with
 * each other. Partial files make two questions unavoidable, and neither can
 * be answered on the free label alone.
 *
 * *Has this member already had theirs?* Matching on the label would confuse
 * a tax certificate with an attendance certificate — two perfectly
 * legitimate documents for the same person in the same year.
 *
 * *Who still has none?* After three partial files only the site can say. A
 * chef d'unité will not cross-reference three batches by hand, and that is
 * precisely where somebody gets forgotten.
 */
enum AttestationCategory: string
{
    case Tax = 'tax';
    case Attendance = 'attendance';
    case Other = 'other';

    /** The French label shown on screen. */
    public function label(): string
    {
        return match ($this) {
            self::Tax => 'Attestation fiscale',
            self::Attendance => 'Attestation de présence',
            self::Other => 'Autre attestation',
        };
    }

    /**
     * The stored value read back, or null when it names no category —
     * never a silent fallback onto Tax, which would file an attendance
     * certificate under the heading the coverage screen counts.
     */
    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * value => French label, in the order a picker should offer them.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
