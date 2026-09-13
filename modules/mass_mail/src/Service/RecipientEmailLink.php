<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

/**
 * The deep link to one recipient's own copy of a sent email —
 * Controller\MemberEmailController's
 * `/members/{member_year_id}/emails/{recipient_id}` route.
 *
 * One definition because two very different callers have to agree on it
 * character for character. Task\SendBatchHandler writes it into
 * `notifications.url` when the email goes out; Task\PurgeMergeAudiencesHandler
 * rebuilds it, months later, to find those same notification rows again and
 * delete them along with the merge audience they describe (issue #292).
 * `notifications` has no column pointing at a recipient, so this string IS
 * the correlation key — and a purge that built it even slightly differently
 * would match nothing, delete nothing, and report success.
 */
final class RecipientEmailLink
{
    public static function url(int $memberYearId, int $recipientId): string
    {
        return '/members/' . $memberYearId . '/emails/' . $recipientId;
    }
}
