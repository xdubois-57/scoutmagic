<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

/**
 * Public contract for consuming modules/core — the only entry point into
 * mass_mail for anything outside the module itself (ARCHITECTURE.md §7.5).
 * Core\Http\Controller\MemberController takes this as a nullable
 * dependency to render a member's "Emails reçus" section; when mass_mail
 * is disabled, that dependency is never wired and the section simply
 * doesn't appear — no other part of core ever references this module by
 * name.
 */
interface MassMailQueryInterface
{
    /**
     * The member's most recent successfully-delivered mass emails, newest
     * first. Only ever 'sent'-status recipients — a still-pending or
     * errored row was never actually delivered to this member. `id` is the
     * recipient id — pass it to findEmailDetailForMember() below to open
     * the "view as sent" detail page.
     *
     * **`subject` is what THIS member received**, not what was composed: a
     * publipostage stores one template and substitutes per recipient at
     * send time, so the two differ whenever the subject carries a
     * variable (issue #287). The one exception is `merge_purged`, and it
     * carries the same meaning as on findEmailDetailForMember() below —
     * a publipostage past its retention has no values left to put back,
     * so `subject` is then the template, `{{tokens}}` and all, and a view
     * that renders it must say so.
     *
     * @return array<int, array{
     *     id: int, subject: string, sent_at: string, section_name: string,
     *     merge_purged: bool
     * }>
     */
    public function getRecentEmailsForMember(int $memberId, int $limit): array;

    /**
     * The full email (subject + body) as sent to $memberId, identified by
     * the recipient id from getRecentEmailsForMember() above — null when
     * no such 'sent' recipient exists for that member (wrong id, or one
     * belonging to a different member; the caller must not assume role_min
     * alone makes this safe, see the member page's email-detail route).
     *
     * As above, this is the copy THIS member received, variables
     * substituted. `merge_purged` is the one case where it cannot be: a
     * publipostage whose audience has passed its 18-month retention has
     * no values left to substitute, and the subject and body then still
     * carry their `{{tokens}}`. A view that renders them must say so
     * rather than let a reader take a template for their own mail.
     *
     * @return array{
     *     subject: string, body_html: string, sent_at: string, section_name: string,
     *     merge_purged: bool
     * }|null
     */
    public function findEmailDetailForMember(int $memberId, int $recipientId): ?array;
}
