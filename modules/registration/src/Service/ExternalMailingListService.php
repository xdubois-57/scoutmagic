<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Registration\Api\ExternalMailingListProvider;
use Modules\Registration\Repository\ImportedMemberRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * The registration module's own mailing list, contributed to mass_mail
 * (ARCHITECTURE.md §7.5/§8.36) — always the TARGET scout year (public
 * year + 1, same anchor as Service\PassageService, never the effective
 * year: a chief previewing a different year must not change what this
 * list means).
 *
 * Content is 'encoded' requests only, not 'accepted' ones — deliberately
 * narrower than a literal reading of the module spec's "les demandes
 * acceptées et encodées dans Desk" might suggest. Every 'encoded' request
 * WAS accepted first (that's the only way to reach 'encoded' at all), so
 * "acceptées et encodées" reads as one path, not two separate inclusion
 * rules — and it can't mean anything else in practice: mass_mail's own
 * `mass_mail_recipients.member_id` is a NOT NULL foreign key to a real
 * `members` row, which an 'accepted'-but-not-yet-encoded request simply
 * doesn't have yet. This also matches the module spec's own "point à
 * vérifier" about deduplication against section lists, which only makes
 * sense for members that already exist in Desk (i.e. are encoded).
 */
class ExternalMailingListService implements ExternalMailingListProvider
{
    public function __construct(
        private ImportedMemberRepository $importedMembers,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private RegistrationRequestRepository $requestRepository
    ) {
    }

    public function describeMailingList(): array
    {
        $label = $this->targetYearLabel();

        return [
            'label' => "Inscriptions {$label}",
            'description' => "Demandes d'inscription acceptées et encodées dans Desk pour l'année {$label} — "
                . 'liste recomposée à chaque envoi, jamais modifiable ici.',
        ];
    }

    public function targetScoutYearId(): int
    {
        return $this->targetYearId();
    }

    public function resolveMailingListMembers(): array
    {
        $targetYearId = $this->targetYearId();
        $memberIds = $this->requestRepository->findEncodedMemberIdsForYear($targetYearId);

        return $this->importedMembers->findEmailsForYear($memberIds, $targetYearId);
    }

    private function targetYearId(): int
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetLabel = ScoutYearService::nextLabel($publicYear['label']);

        return $this->scoutYearService->ensureYear($targetLabel);
    }

    private function targetYearLabel(): string
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();

        return ScoutYearService::nextLabel($publicYear['label']);
    }
}
