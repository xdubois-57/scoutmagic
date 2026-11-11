<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\MassMail\Repository\Email;

/**
 * Resolves what a given account is allowed to do in mass_mail based on
 * their own section membership — a plain section chief (role 'chief')
 * may only target their own section(s)' default list or "Chefs
 * uniquement", and always sends from their own (highest-role) section;
 * a chef d'unité (role 'admin') or above is unrestricted. Kept separate
 * from Service\MailingListService (which only knows how to resolve a
 * list's *members*, not who's allowed to *pick* it).
 */
class MassMailAccessService
{
    public function __construct(
        private SectionStaffAuthorizationService $sectionStaffAuthorization,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    /**
     * The year every decision here is taken in: the authorization year,
     * like the rest of the core (ScoutYearResolver::getAuthorizationYear())
     * — the one the session's role was resolved in. The date-computed year
     * this module used to read holds no roster at all between 1 September
     * and the passage, which left every animateur with no section.
     */
    public function authorizationYearId(): int
    {
        return $this->scoutYearResolver->getAuthorizationYear()->id;
    }

    /**
     * The sections this account STAFFS this scout year — through a member
     * linked to it (Desk address or currently-valid secondary address) that
     * holds a chief or admin function there. The set a plain chief may
     * target a "Section - {nom}" default list for, and see the mailings of.
     *
     * **Never « any function »** (issue #1105). It was, and an animé's own
     * function carries their section: an animateur of the Pionniers whose
     * child — sharing the parent's Desk address, as children often do — is
     * a Louveteau was staff of the Louveteaux here, free to mail every
     * family of that section in the unit's name and to open, rewrite, send
     * and delete its staff's drafts. The role-filtered resolution is the
     * core's, StaffedSectionRepository::staffedSectionIds(), which exists
     * for exactly this trap.
     *
     * @return int[]
     */
    public function getUserSectionIds(string $email, int $scoutYearId): array
    {
        return $this->sectionStaffAuthorization->ownStaffedSectionIds($email, $scoutYearId);
    }

    /**
     * Whether $listType/$listId/$listSectionId is a valid target for this
     * account. A chef d'unité (role 'admin') or above may target anything;
     * a plain chief may only target "Chefs uniquement" or one of their own
     * sections' default list — never "Membres actifs", another section,
     * or any custom list. Static/pure — Service\MassMailService calls this
     * directly rather than taking a constructor dependency on this class.
     *
     * @param int[] $userSectionIds
     */
    public static function canUseList(
        bool $isChefDUniteOrAbove,
        array $userSectionIds,
        string $listType,
        ?int $listSectionId
    ): bool
    {
        if ($isChefDUniteOrAbove) {
            return true;
        }
        if ($listType === Email::LIST_TYPE_DEFAULT_CHIEFS) {
            return true;
        }
        if ($listType === Email::LIST_TYPE_DEFAULT_SECTION) {
            return $listSectionId !== null && in_array($listSectionId, $userSectionIds, true);
        }
        // Mail-merge is open to every chief (unit decision, documented in
        // ARCHITECTURE.md): the uploaded file — not a section list — names
        // the recipients, and its audience is locked to whoever imported
        // it (MassMailService::assertAudienceUsable()). Every import is
        // journaled.
        if ($listType === Email::LIST_TYPE_MAIL_MERGE) {
            return true;
        }

        return false;
    }
}
