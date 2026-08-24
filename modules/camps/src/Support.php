<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps;

/**
 * The two one-liners this module kept rewriting, six times between them.
 *
 * Both answer the same question — is there a value here, or is this field
 * simply not filled in — from the two ends: `clean()` for something a
 * person typed, `nullableString()` for something a database row carried.
 *
 * They are worth naming because getting either half wrong is invisible.
 * A copy that forgets the `trim()` stores a single space: not null, not
 * empty, renders as nothing, and passes every "is it filled in" check
 * there is. A copy that treats `''` as a value writes an empty string into
 * a column whose whole point is that NULL means "we do not know" — and a
 * place with no postal code then stops being a place needing geocoding.
 *
 * Deliberately not a service: no state, no collaborator, nothing to swap.
 */
final class Support
{
    /**
     * A typed value, or null when the box was left blank (spaces
     * included).
     */
    public static function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * A database value as a string, or null when the column holds NULL or
     * an empty string — the two spellings of "not known" that a schema
     * written over several years ends up carrying side by side.
     */
    public static function nullableString(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
