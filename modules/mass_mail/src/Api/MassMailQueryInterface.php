<?php

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
     * @return array<int, array{id: int, subject: string, sent_at: string, section_name: string}>
     */
    public function getRecentEmailsForMember(int $memberId, int $limit): array;

    /**
     * The full email (subject + body) as sent to $memberId, identified by
     * the recipient id from getRecentEmailsForMember() above — null when
     * no such 'sent' recipient exists for that member (wrong id, or one
     * belonging to a different member; the caller must not assume role_min
     * alone makes this safe, see the member page's email-detail route).
     *
     * @return array{subject: string, body_html: string, sent_at: string, section_name: string}|null
     */
    public function findEmailDetailForMember(int $memberId, int $recipientId): ?array;
}
