<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Mail\MailException;

/** Only refusals known not to have accepted the message can be retried. */
final class DecisionEmailRetryPolicy
{
    public const DELAYS_HOURS = [1, 6];

    public static function isTemporary(?\Throwable $error): bool
    {
        if (!$error instanceof MailException) {
            return false;
        }
        $reason = mb_strtolower($error->getMessage());
        // PHPMailer can report failed recipients after delivering to the
        // accepted ones (including the unit's CC). Never repeat that send.
        if (str_contains($reason, 'recipients failed')) {
            return false;
        }
        // Match SMTP replies, not port numbers such as 443 or 587 in an
        // ambiguous timeout. The lane wrapper preserves its last reason.
        preg_match_all(
            '/(?:^|smtp(?: error| code| server error)?\s*:|dernière raison\s*:)\s*([45][0-9]{2})(?:[ -]|$)/u',
            $reason,
            $codes
        );
        foreach ($codes[1] as $code) {
            if ($code[0] === '5') {
                return false;
            }
        }
        if ($codes[1] !== []) {
            return true;
        }
        foreach (['certificate verify failed', 'self-signed certificate', 'could not authenticate'] as $permanent) {
            if (str_contains($reason, $permanent)) {
                return false;
            }
        }
        foreach (['could not connect to smtp host', 'smtp connect() failed', 'connection refused'] as $marker) {
            if (str_contains($reason, $marker)) {
                return true;
            }
        }
        // A timeout during DATA might have delivered it: leave that to staff.
        return false;
    }
}
