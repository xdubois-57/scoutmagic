<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * A raw header block on its way into a database column.
 *
 * Two tables keep one now — the message a consumer asked to keep the
 * headers of (`Modules\InboundMail`, roadmap IT-22) and the diagnostic
 * probe that arrived (`Modules\SupportDashboard`, IT-27) — and neither
 * may reach into the other's repository for the rule. It lives in core
 * because the rule is about what a header block IS, not about either
 * table.
 */
final class RawHeaderBlock
{
    /**
     * 16 KiB holds the whole chain of anything ordinary. Past it the
     * value is cut and **says so inside itself**, the way the support
     * package's collectors declare their own truncation: a diagnosis read
     * from a silently shortened header block is a diagnosis of the wrong
     * message.
     */
    public const MAX_BYTES = 16384;

    /** The marker a truncated block ends with. */
    public const TRUNCATION_MARKER = '(… en-têtes tronqués à ';

    /**
     * Bound a raw header block, declaring the cut inside the value itself.
     *
     * Cut on a line boundary where there is one within reach, so the last
     * header kept is a whole header rather than half of one that a reader
     * would parse as something else.
     */
    public static function bounded(string $rawHeaders): string
    {
        if (strlen($rawHeaders) <= self::MAX_BYTES) {
            return $rawHeaders;
        }

        $cut = substr($rawHeaders, 0, self::MAX_BYTES);
        $lastBreak = strrpos($cut, "\n");
        if ($lastBreak !== false && $lastBreak > 0) {
            $cut = substr($cut, 0, $lastBreak);
        }

        return $cut . "\n" . self::TRUNCATION_MARKER . self::MAX_BYTES . ' octets)';
    }
}
