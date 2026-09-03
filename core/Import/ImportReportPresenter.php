<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * Assembles one import's report from its stored diff.
 *
 * **It resolves, it does not recompute.** Every figure on the page comes
 * out of `import_journal.diff_json` exactly as the import wrote it; the
 * only thing done here is turning ids into names, which is what makes
 * the page honest months later. The one deliberate exception is stated
 * where it happens: whether a new function is still unqualified is a call
 * to action, not a fact about the past, so it is re-asked.
 *
 * The reading order is the page's, and it is not alphabetical: access
 * impact first (somebody just gained or lost the Configuration),
 * structural impact next (a section that vanished from every picker),
 * data quality last. Heaviest consequences first, so the reader who stops
 * after ten seconds has read the part that mattered.
 */
class ImportReportPresenter
{
    public function __construct(private ImportReportRepository $repository)
    {
    }

    /**
     * @return array<string, mixed> view data
     */
    public function present(ImportRecord $import, ImportDiff $diff): array
    {
        if (!$diff->available) {
            return [
                'available' => false,
                'unavailable_reason' => $diff->unavailableReason,
                'quality' => $this->quality($diff),
            ];
        }

        $identities = $this->repository->findMemberIdentities(
            [
                ...$diff->arrivedMemberIds,
                ...$diff->departedMemberIds,
                ...array_keys($diff->sectionChanges),
                ...array_keys($diff->functionChanges),
                ...array_keys($diff->roleChanges),
                ...array_keys($diff->feeCategoryChanges),
                ...$diff->adminGainedMemberIds,
                ...$diff->adminLostMemberIds,
            ],
            $import->scoutYearId
        );

        $sectionLabels = $this->repository->findSectionLabels([
            ...$diff->newSectionIds,
            ...$diff->sectionsGoneInactiveIds,
            ...$diff->sectionsGoneActiveIds,
            ...$this->endpointsOf($diff->sectionChanges),
        ]);
        $functionLabels = $this->repository->findFunctionLabels([
            ...$diff->newFunctionIds,
            ...$this->endpointsOf($diff->functionChanges),
        ]);
        $feeLabels = $this->repository->findFeeCategoryLabels([
            ...$diff->newFeeCategoryIds,
            ...$this->endpointsOf($diff->feeCategoryChanges),
        ]);
        $branchLabels = $this->repository->findBranchLabels($diff->newBranchIds);

        $stillUnconfirmed = $this->repository->findStillUnconfirmed($diff->newFunctionIds);

        return [
            'available' => true,
            'unavailable_reason' => null,
            'previous_import_id' => $diff->previousImportId,

            'access' => [
                'admin_gained' => $this->people($diff->adminGainedMemberIds, $identities),
                'admin_lost' => $this->people($diff->adminLostMemberIds, $identities),
                'new_functions' => array_map(
                    static fn(int $id): array => [
                        'id' => $id,
                        'label' => $functionLabels[$id] ?? ('#' . $id),
                        'still_unconfirmed' => in_array($id, $stillUnconfirmed, true),
                    ],
                    $diff->newFunctionIds
                ),
            ],

            'structure' => [
                'sections_gone_inactive' => $this->labelled($diff->sectionsGoneInactiveIds, $sectionLabels),
                'sections_gone_active' => $this->labelled($diff->sectionsGoneActiveIds, $sectionLabels),
                'new_sections' => $this->labelled($diff->newSectionIds, $sectionLabels),
                'new_branches' => $this->labelled($diff->newBranchIds, $branchLabels),
                'new_fee_categories' => $this->labelled($diff->newFeeCategoryIds, $feeLabels),
                'arrived' => $this->people($diff->arrivedMemberIds, $identities),
                'departed' => $this->people($diff->departedMemberIds, $identities),
                'section_changes' => $this->changes($diff->sectionChanges, $identities, $sectionLabels),
                'function_changes' => $this->changes($diff->functionChanges, $identities, $functionLabels),
                'fee_category_changes' => $this->changes($diff->feeCategoryChanges, $identities, $feeLabels),
            ],

            'quality' => $this->quality($diff),

            'counts' => [
                'arrived' => count($diff->arrivedMemberIds),
                'departed' => count($diff->departedMemberIds),
                'moved' => count($diff->sectionChanges) + count($diff->functionChanges) + count($diff->feeCategoryChanges),
                'access' => $diff->accessImpactCount(),
            ],
            'is_empty' => $diff->isEmpty(),
        ];
    }

    /**
     * @param array<int, array{from: ?int, to: ?int}> $changes
     * @return int[]
     */
    private function endpointsOf(array $changes): array
    {
        $ids = [];
        foreach ($changes as $change) {
            if ($change['from'] !== null) {
                $ids[] = $change['from'];
            }
            if ($change['to'] !== null) {
                $ids[] = $change['to'];
            }
        }

        return $ids;
    }

    /**
     * @param int[] $memberIds
     * @param array<int, array{totem: ?string, first_name: string, last_name: string}> $identities
     * @return array<int, array<string, mixed>>
     */
    private function people(array $memberIds, array $identities): array
    {
        return array_values(array_map(
            static fn(int $id): array => [
                'member_id' => $id,
                // A member whose year row is gone (a purge, a manual
                // repair) still counts — the page says so rather than
                // dropping a line and changing the totals.
                'identity' => $identities[$id] ?? null,
            ],
            $memberIds
        ));
    }

    /**
     * @param int[] $ids
     * @param array<int, string> $labels
     * @return array<int, array{id: int, label: string}>
     */
    private function labelled(array $ids, array $labels): array
    {
        return array_values(array_map(
            static fn(int $id): array => ['id' => $id, 'label' => $labels[$id] ?? ('#' . $id)],
            $ids
        ));
    }

    /**
     * @param array<int, array{from: ?int, to: ?int}> $changes
     * @param array<int, array{totem: ?string, first_name: string, last_name: string}> $identities
     * @param array<int, string> $labels
     * @return array<int, array<string, mixed>>
     */
    private function changes(array $changes, array $identities, array $labels): array
    {
        $rows = [];
        foreach ($changes as $memberId => $change) {
            $rows[] = [
                'member_id' => $memberId,
                'identity' => $identities[$memberId] ?? null,
                'from' => $change['from'] !== null ? ($labels[$change['from']] ?? ('#' . $change['from'])) : null,
                'to' => $change['to'] !== null ? ($labels[$change['to']] ?? ('#' . $change['to'])) : null,
            ];
        }

        return $rows;
    }

    /** @return array<int, array{label: string, value: int, why: ?string}> */
    private function quality(ImportDiff $diff): array
    {
        $quality = $diff->quality;

        return [
            [
                'label' => 'Membres sans adresse exploitable',
                'value' => $quality->withoutUsableAddress,
                'why' => 'échappent au calcul de foyer, donc à la vérification des cotisations',
            ],
            [
                'label' => 'Membres sans adresse e-mail',
                'value' => $quality->withoutEmail,
                'why' => 'ne pourront pas se connecter au site',
            ],
            [
                'label' => 'Membres sans fonction ni section',
                'value' => $quality->withoutFunctionOrSection,
                'why' => null,
            ],
            [
                'label' => 'Lignes du CSV non retenues',
                'value' => $quality->linesNotRetained,
                'why' => "une même personne occupe plusieurs lignes (une par fonction et par adresse) — comportement "
                    . "normal",
            ],
        ];
    }
}
