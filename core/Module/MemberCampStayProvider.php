<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to say which stays a member went
 * on, for the « parcours » of the admin member page — without core
 * knowing what a camp is. Same precedent as
 * Core\Module\FormationPathProvider (ARCHITECTURE.md §7.4): core defines
 * the interface, the module implements it, and the composition root
 * wires it only while that module is enabled. The `camps` module
 * implements this today.
 *
 * **What "went on" means, and why it is an inference.** Nothing records
 * a camp's participants one by one — `camp_camps` links to *sections*,
 * not to people. So a stay counts as this member's when their section
 * went on it during a scout year in which they belonged to that section,
 * read from `member_section_periods`. That is genuinely what the site
 * knows, and a block claiming more would be inventing a roster it does
 * not have.
 *
 * **This hook decides nothing about who may look.** The page is
 * `role_min: admin` and the stay pages it links to are `chief`, so a
 * reader who sees a link can always open it. A provider that re-derived
 * its own audience would be a second answer waiting to disagree with the
 * router's.
 */
interface MemberCampStayProvider
{
    /**
     * How many stays the list may carry. On the interface so the page can
     * say how many it is showing without guessing, and a second
     * implementation cannot answer at a different scale — same reason as
     * MemberPaymentProvider::SETTLED_LIMIT.
     */
    public const LIMIT = 20;

    /**
     * The stays this member's sections went on while they were in them,
     * most recent first, capped at self::LIMIT — or an empty list when
     * there are none, which is the ordinary answer for a member who
     * joined this year.
     *
     * The id is a `members.id`, the persistent identity: a camp somebody
     * went on in 2019 is still a camp they went on.
     *
     * @return list<MemberCampStayView>
     */
    public function getCampStays(int $memberId): array;
}
