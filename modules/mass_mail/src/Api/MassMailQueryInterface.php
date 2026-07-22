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
     * errored row was never actually delivered to this member.
     *
     * @return array<int, array{subject: string, sent_at: string, section_name: string}>
     */
    public function getRecentEmailsForMember(int $memberId, int $limit): array;
}
