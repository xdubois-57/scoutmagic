<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

/**
 * Every timestamp this module writes and compares is UTC, explicitly —
 * never PHP's ambient default timezone. The post edit window (15 minutes
 * from creation, Service\PostService) is a security-relevant comparison
 * between a stored value and "now": if the two were produced under
 * different timezones the window would silently widen or vanish, so both
 * ends go through here.
 */
final class Timestamps
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Parse a value this module stored (or a core DEFAULT CURRENT_TIMESTAMP
     * one) as UTC, so arithmetic on it never picks up a display timezone.
     */
    public static function toUtc(string $stored): \DateTimeImmutable
    {
        return new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
    }
}
