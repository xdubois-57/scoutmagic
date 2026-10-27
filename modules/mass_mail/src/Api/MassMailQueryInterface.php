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
 * Core\Member\MemberPageService takes this as a nullable dependency to
 * render a member's « Communications récentes »; when mass_mail is
 * disabled, that dependency is never wired and the section simply doesn't
 * appear — no other part of core ever references this module by name.
 *
 * **An entry is a TARGET** (issue #999): one communication meant for this
 * member — a classic list's email, or one line of a publipostage that names
 * them — however many of its messages went to however many addresses. Its
 * `id` is the target id, the second segment of
 * `/members/{member_year_id}/emails/{target_id}`.
 */
interface MassMailQueryInterface
{
    /**
     * The member's latest entries, newest first (by the first message that
     * left, or for an entry « Non envoyée » by when the communication was
     * launched). An entry still going out is not listed until at least one
     * of its messages has left or all of them have failed.
     *
     * **`subject` is what THIS member received**, not what was composed: a
     * publipostage stores one template and substitutes per line at send
     * time (issue #287). The one exception is `merge_purged`: a
     * publipostage past its retention has no values left to put back, so
     * `subject` is then the template, `{{tokens}}` and all, and a view that
     * renders it must say so.
     *
     * `not_sent` is true when no message of the entry left — every one in
     * error, or the member had no address it could be sent to. The entry is
     * listed all the same, and a view must say « Non envoyée ».
     *
     * @return array<int, array{
     *     id: int, subject: string, sent_at: string, section_name: string,
     *     merge_purged: bool, not_sent: bool
     * }>
     */
    public function getRecentEmailsForMember(int $memberId, int $limit): array;

    /**
     * One entry as it was sent to $memberId, identified by the target id
     * from getRecentEmailsForMember() above — null when there is no such
     * entry for that member (wrong id, one belonging to a different member,
     * or one still going out; the caller must not assume role_min alone
     * makes this safe, see the member page's email-detail route).
     *
     * As above, the copy THIS member received, variables substituted, with
     * `merge_purged` for the one case where it cannot be and `not_sent` for
     * an entry nothing of which left.
     *
     * `other_targets` lists the OTHER members the same classic-list
     * communication was meant for (always empty for a publipostage, whose
     * line has one target) — every one of them, with the year their entry
     * goes through. **The caller names only those the reader may already
     * see** (`MemberService::canAccess()`), and never the others: this list
     * is the candidates, not what to show.
     *
     * @return array{
     *     subject: string, body_html: string, sent_at: string, section_name: string,
     *     merge_purged: bool, not_sent: bool,
     *     other_targets: list<array{target_id: int, member_id: int, scout_year_id: int|null}>
     * }|null
     */
    public function findEmailDetailForMember(int $memberId, int $targetId): ?array;
}
