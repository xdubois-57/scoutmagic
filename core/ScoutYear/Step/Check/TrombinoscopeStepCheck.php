<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Config\ScoutYearService;
use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\PhotoOrigin;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;

/**
 * Step « trombinoscope », human: the individual photo of every animateur
 * of the target year (role chief/admin with a section that year — the
 * trombinoscope's own rule, SectionService::getStaffForSections()), per
 * section checked, the Staff d'U included (chantier « Année scoute » §2.7).
 *
 * For each, the photo the site shows is that year's, one carried over
 * from an earlier year, or none (MemberPhotoRepository::findPhotoYears()).
 * « None » and « carried over » are points to check, never a block, and
 * each such person is named with a link to their sheet. A person who
 * animates two sections counts in both rows and once in the points.
 *
 * The animateurs are the target year's import, so the check waits for it.
 *
 * $details: sections (list of {label, total, exact, earlier, none}),
 * people (list of {name, section, url, origin: earlier|none,
 * photo_year: ?string}); absent before the import.
 */
final class TrombinoscopeStepCheck implements StepCheckProvider
{
    public function __construct(
        private SectionService $sections,
        private MemberPhotoRepository $photos,
        private ScoutYearService $scoutYears
    ) {
    }

    public function stepKey(): string
    {
        return 'trombinoscope';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('trombinoscope', 'Aller aux animateurs', '/trombinoscope');
        if (!$context->hasSuccessfulImport()) {
            return new StepCheck(
                primaryAction: $action,
                actions: [$action],
                pendingMessage: $context->waitingForImportMessage()
            );
        }

        $target = $context->targetId();
        $checked = CheckText::checkedSections($this->sections, $target, withUnitStaff: true);
        $staff = $this->sections->getStaffForSections(
            array_map(static fn($section): int => $section->sectionId, $checked),
            $target
        );
        $memberIds = [];
        foreach ($staff as $profiles) {
            foreach ($profiles as $profile) {
                $memberIds[] = $profile->memberId;
            }
        }
        $photos = $this->photos->findPhotoYears($memberIds, $target);

        $rows = [];
        /**
         * @var array<int, array{
         *     name: string, section: string, url: string, origin: string, photo_year: ?string
         * }> $people
         */
        $people = [];
        foreach ($checked as $section) {
            $counts = ['exact' => 0, 'earlier' => 0, 'none' => 0];
            foreach ($staff[$section->sectionId] ?? [] as $profile) {
                $photo = $photos[$profile->memberId] ?? null;
                $origin = $photo->origin ?? PhotoOrigin::None;
                $counts[$origin->value]++;
                if ($origin !== PhotoOrigin::Exact && !isset($people[$profile->memberId])) {
                    $people[$profile->memberId] = $this->person(
                        $profile,
                        $section->sectionId,
                        $section->label,
                        $origin,
                        $photo?->photoScoutYearId
                    );
                }
            }
            $rows[] = ['label' => $section->label, 'total' => array_sum($counts)] + $counts;
        }

        $none = count(array_filter($people, static fn(array $p): bool => $p['origin'] === PhotoOrigin::None->value));
        $earlier = count($people) - $none;
        $points = [];
        if ($none > 0) {
            $points[] = CheckText::count($none, 'animateur sans photo', 'animateurs sans photo');
        }
        if ($earlier > 0) {
            $points[] = CheckText::count($earlier, 'photo héritée à vérifier', 'photos héritées à vérifier');
        }

        return new StepCheck(
            pointsToCheck: $points,
            primaryAction: $action,
            actions: [$action],
            details: ['sections' => $rows, 'people' => array_values($people)]
        );
    }

    /**
     * @return array{name: string, section: string, url: string, origin: string, photo_year: ?string}
     */
    private function person(
        MemberProfile $profile,
        int $sectionId,
        string $sectionLabel,
        PhotoOrigin $origin,
        ?int $photoYearId
    ): array {
        $photoYear = $photoYearId !== null ? $this->scoutYears->findById($photoYearId) : null;

        return [
            'name' => $profile->getDisplayNameFull($sectionId),
            'section' => $sectionLabel,
            'url' => CheckText::memberUrl($profile->memberYearId),
            'origin' => $origin->value,
            'photo_year' => $photoYear !== null ? (string) $photoYear['label'] : null,
        ];
    }
}
