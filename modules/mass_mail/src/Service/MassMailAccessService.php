<?php

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Member\MemberService;
use Core\Member\SectionService;
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
        private MemberService $memberService,
        private SectionService $sectionService
    ) {
    }

    /**
     * Every section id where any member linked to this account holds any
     * function, this scout year — the set a plain chief may target a
     * "Section - {nom}" default list for.
     *
     * @return int[]
     */
    public function getUserSectionIds(string $email, int $scoutYearId): array
    {
        $sections = $this->sectionService->getAllWithBranches();
        $idByDeskCode = array_column($sections, 'id', 'desk_code');

        $sectionIds = [];
        foreach ($this->memberService->getLinkedMembers($email, $scoutYearId) as $profile) {
            foreach ($profile->functions as $function) {
                if ($function->sectionCode !== null && isset($idByDeskCode[$function->sectionCode])) {
                    $sectionIds[$idByDeskCode[$function->sectionCode]] = true;
                }
            }
        }

        return array_keys($sectionIds);
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
    public static function canUseList(bool $isChefDUniteOrAbove, array $userSectionIds, string $listType, ?int $listSectionId): bool
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

        return false;
    }
}
