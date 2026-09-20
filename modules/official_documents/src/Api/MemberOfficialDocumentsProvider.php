<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Api;

/**
 * What this module offers a member's own page (ARCHITECTURE.md §7.5).
 *
 * `Core\Member\MemberPageService` holds it as a nullable constructor
 * dependency and draws nothing when it is null — module disabled, block
 * absent, no error and no empty card.
 *
 * **It answers for the member's own page and nowhere else.** There is no
 * staff view of these documents and there will not be one: what the site
 * stores is a draft for pre-filling, so an animateur reading it would be
 * reading an unsigned version — without value, and believed. That decision
 * is written into this contract rather than left to its callers, which is
 * why there is no « for this section » or « for this year » method here to
 * grow one from.
 */
interface MemberOfficialDocumentsProvider
{
    /**
     * The block for one member, as their own page should draw it.
     *
     * $memberYearId is what the page's URLs are keyed on; $memberId is the
     * persistent identity, which is what anything stored hangs off (the
     * health sheet describes a person, not one of their seasons).
     *
     * Whether the caller MAY see this is settled before the call: the page
     * has already answered it, and a provider re-deriving its own audience
     * would be a second answer waiting to disagree with the router's.
     */
    public function summaryFor(int $memberYearId, int $memberId): OfficialDocumentsSummary;
}
