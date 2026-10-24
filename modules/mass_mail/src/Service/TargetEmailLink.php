<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

/**
 * The deep link to one entry of a member's history — one target of a
 * communication (mass_mail_targets) — on Controller\MemberEmailController's
 * `/members/{member_year_id}/emails/{target_id}` route. The first segment is
 * a `member_years` id, never a `members` one.
 *
 * One definition because two very different callers have to agree on it
 * character for character. Task\SendBatchHandler writes it into
 * `notifications.url` when a message goes out; Task\PurgeMergeAudiencesHandler
 * rebuilds it, months later, for the targets of the audience it erases, to
 * find those same notification rows again and delete them (issue #292).
 * `notifications` has no column pointing at a target, so this string IS the
 * correlation key — and a purge that built it even slightly differently
 * would match nothing, delete nothing, and report success.
 */
final class TargetEmailLink
{
    public static function url(int $memberYearId, int $targetId): string
    {
        return '/members/' . $memberYearId . '/emails/' . $targetId;
    }
}
