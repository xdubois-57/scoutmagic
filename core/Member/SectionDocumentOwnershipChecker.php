<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;

/**
 * Grants /files/{id} access to a section document (owner_type =
 * 'section_document', owner_id = section_documents.id) to chief+ staff,
 * or to a member who was active in that document's section for that
 * scout year — membership history only (Core\Member\
 * SectionMembershipRepository::hasPeriodCovering()), never section
 * visibility (module addendum: hiding a section must not silently break
 * downloads for members who still legitimately see the row on their own
 * member page).
 */
class SectionDocumentOwnershipChecker implements FileOwnershipCheckerInterface
{
    public function __construct(
        private SectionDocumentRepository $documentRepository,
        private SectionMembershipRepository $membershipRepository,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === 'section_document';
    }

    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        if ($currentRole->hasAccess(Role::CHIEF)) {
            return true;
        }

        $document = $this->documentRepository->findById($ownerId);
        if ($document === null) {
            return false;
        }

        $scoutYear = $this->scoutYearService->findById($document->scoutYearId);
        if ($scoutYear === null) {
            return false;
        }

        $referenceDateSetting = (string) ($this->settingService->get('section_document_reference_date') ?: '30-09');
        $referenceDate = SectionMembershipService::resolveReferenceDate($referenceDateSetting, $scoutYear);

        foreach ($linkedMemberIds as $memberId) {
            if ($this->membershipRepository->hasPeriodCovering($memberId, $document->sectionId, $document->scoutYearId, $referenceDate)) {
                return true;
            }
        }

        return false;
    }
}
