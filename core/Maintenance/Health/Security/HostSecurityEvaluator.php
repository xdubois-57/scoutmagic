<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * Matches what was measured against what the sources say, and words the
 * verdict (issue #1358, lot 2). Pure: no I/O, no clock of its own, so every
 * rule below has a synthetic test.
 *
 * The rules, in the order they decide:
 *
 * 1. **A component nobody measured recently, or whose version cannot be
 *    read, is « non vérifiable ».** So is a pre-release: no release data
 *    describes « 8.5.0RC1 ».
 * 2. **The update half** compares only within the branch in use. A newer
 *    patch in that branch is « correctif disponible »; a branch past its
 *    security support is « hors support ». A newer major version, on its
 *    own, says nothing — the branch in use may well still be maintained.
 * 3. **The advisory half** reads what the NVD matched for the exact CPE.
 *    One advisory at CVSS ≥ 7 makes the component « potentiellement
 *    vulnérable », whatever the update half says; an unknown score is an
 *    uncertainty, never a zero.
 * 4. **A backport is never ruled out by a number.** When the version
 *    carries a distribution's build suffix, or the family's distributions
 *    do not show their patches in it at all ({@see ComponentFamily::
 *    $backportsVisible}), every « en retard » and every advisory is
 *    qualified « à confirmer auprès de l'hébergeur » rather than stated.
 *
 * « Une mise à jour est nécessaire » — half of the notification condition —
 * is the update half when it concluded (current, behind, unsupported).
 * When it did not — a family no source covers (cURL), a source that could
 * not be read, a branch absent from the data or naming no latest release —
 * it is the advisory half itself: the NVD matched this version
 * inside a CVE's affected range, and leaving that range is what an update
 * does. A source that failed is never, on its own, a reason to say an
 * update is needed.
 */
final class HostSecurityEvaluator
{
    /**
     * @param list<ObservedComponent> $components
     * @param array<string, BranchLookup> $branches by {@see ComponentFamily::branchSourceKey()}
     * @param array<string, AdvisoryLookup> $advisories by CPE name
     * @param int|null $now the moment a branch's end of support is compared with — a cached
     *                      « supported » may have expired since it was fetched
     * @return list<ComponentAssessment> absent components excluded
     */
    public function evaluate(array $components, array $branches, array $advisories, ?int $now = null): array
    {
        $assessments = [];
        foreach ($components as $component) {
            if ($component->presence === ObservedComponent::ABSENT) {
                continue;
            }
            $assessments[] = $this->assess($component, $branches, $advisories, $now);
        }

        return $assessments;
    }

    /**
     * @param array<string, BranchLookup> $branches
     * @param array<string, AdvisoryLookup> $advisories
     */
    private function assess(ObservedComponent $component, array $branches, array $advisories, ?int $now): ComponentAssessment
    {
        $family = ComponentFamily::get($component->family);
        $familyLabel = $family !== null ? $family->label : $component->family;
        $notes = $component->note !== '' ? [$component->note] : [];

        $version = $family !== null && $component->presence === ObservedComponent::PRESENT && $component->version !== null
            ? VersionNumber::parse($component->version, $family)
            : null;

        if ($family === null || $version === null || $version->isPrerelease()) {
            if ($component->presence === ObservedComponent::PRESENT) {
                $notes[] = $version !== null && $version->isPrerelease()
                    ? 'Version préliminaire (' . $version->prerelease . ') : aucune donnée de référence ne la décrit.'
                    : 'Version illisible : impossible de la comparer.';
            }

            return $this->unverifiable($component, $familyLabel, $notes);
        }

        $backportPossible = $version->buildSuffix !== null || !$family->backportsVisible;

        // ————— The update half —————
        $updateStatus = ComponentAssessment::UPDATE_UNKNOWN;
        $branch = null;
        $sourceKey = $family->branchSourceKey();
        $lookup = $sourceKey !== null ? ($branches[$sourceKey] ?? new BranchLookup(BranchLookup::UNAVAILABLE)) : null;
        if ($lookup === null) {
            $notes[] = 'Aucune source ne publie les versions maintenues de ' . $familyLabel
                . ' : seuls les avis de sécurité sont comparés.';
        } elseif (!$lookup->hasData()) {
            $notes[] = 'La source des versions (' . $family->versionSource . ') n\'a pas pu être consultée.';
        } else {
            if ($lookup->status === BranchLookup::STALE && $lookup->fetchedAt !== null) {
                $notes[] = 'Versions de référence relevées le ' . gmdate('d/m/Y', $lookup->fetchedAt)
                    . ' : la source n\'a pas répondu depuis.';
            }
            $branch = $lookup->branchFor($version);
            $latest = $branch?->latest !== null ? VersionNumber::parse($branch->latest, $family) : null;
            if ($branch === null) {
                $notes[] = 'La branche ' . implode('.', array_slice($version->segments, 0, 2))
                    . ' est absente des données de référence.';
            } elseif ($branch->supported === false || self::supportEnded($branch, $now)) {
                $updateStatus = ComponentAssessment::UPDATE_UNSUPPORTED;
            } elseif ($latest !== null && $latest->compare($version) > 0) {
                $updateStatus = ComponentAssessment::UPDATE_BEHIND;
            } elseif ($latest !== null) {
                // Only a comparison makes a version current: a branch said
                // to be maintained but naming no latest release proves
                // nothing about this one, and leaves UPDATE_UNKNOWN —
                // which neither reads « À jour » nor re-arms an alert.
                $updateStatus = ComponentAssessment::UPDATE_CURRENT;
            } else {
                $notes[] = 'La source des versions ne donne pas la dernière version de la branche '
                    . implode('.', array_slice($version->segments, 0, 2)) . '.';
            }
        }

        // ————— The advisory half —————
        $cpe = $family->cpeName($version);
        $found = $advisories[$cpe] ?? new AdvisoryLookup(AdvisoryLookup::UNAVAILABLE);
        $applicable = $found->hasData() ? Advisory::sorted(Advisory::deduplicated($found->advisories)) : [];
        // Every identifier at CVSS ≥ 7: those listed in detail, and those
        // the source reported beyond the detail that was kept — the
        // notification decides on this set, so it is never truncated.
        $high = array_values(array_unique(array_merge(
            array_map(
                static fn(Advisory $a): string => $a->id,
                array_filter($applicable, static fn(Advisory $a): bool => $a->isHigh())
            ),
            $found->hasData() ? $found->highIds : []
        )));
        $unknownScore = array_filter($applicable, static fn(Advisory $a): bool => $a->score === null) !== [];
        if ($found->status === AdvisoryLookup::PENDING) {
            $notes[] = 'Les avis de sécurité seront consultés au prochain passage (limite de requêtes de la NVD).';
        } elseif (!$found->hasData()) {
            $notes[] = 'La base des avis de sécurité (NVD) n\'a pas pu être consultée.';
        } elseif ($found->status === AdvisoryLookup::STALE && $found->fetchedAt !== null) {
            $notes[] = 'Avis de sécurité relevés le ' . gmdate('d/m/Y', $found->fetchedAt)
                . ' : la NVD n\'a pas répondu depuis.';
        }
        if ($unknownScore) {
            $notes[] = 'Au moins un avis applicable n\'a pas encore de score : sa gravité est inconnue.';
        }

        $outOfDate = $updateStatus === ComponentAssessment::UPDATE_BEHIND
            || $updateStatus === ComponentAssessment::UPDATE_UNSUPPORTED;
        if ($backportPossible && ($outOfDate || $applicable !== [])) {
            $notes[] = $version->buildSuffix !== null
                ? 'La version porte la révision « ' . $version->buildSuffix . ' » de l\'hébergeur ou de la '
                    . 'distribution, qui peut avoir intégré des correctifs : à confirmer auprès de l\'hébergeur.'
                : 'Les distributions intègrent souvent les correctifs de ' . $familyLabel . ' sans changer son '
                    . 'numéro de version : à confirmer auprès de l\'hébergeur.';
        }

        // Decided by the update half only when it actually concluded: a
        // branch absent from the data, or one naming no latest release,
        // leaves it unknown exactly like a source that failed — and an
        // end-of-life branch nobody lists is where high advisories gather.
        $needsUpdate = $updateStatus !== ComponentAssessment::UPDATE_UNKNOWN
            ? $outOfDate
            : $high !== [];

        $state = match (true) {
            $high !== [] => ComponentAssessment::STATE_VULNERABLE,
            $outOfDate => ComponentAssessment::STATE_OUTDATED,
            // A family no source publishes versions for (cURL) is judged on
            // its advisories alone, as its note says: a complete answer with
            // no high score is « À jour », not « Non vérifiable » for ever.
            $updateStatus === ComponentAssessment::UPDATE_UNKNOWN && $lookup !== null,
            !$found->hasData(),
            $unknownScore => ComponentAssessment::STATE_UNVERIFIABLE,
            default => ComponentAssessment::STATE_UP_TO_DATE,
        };

        // Trusted either way: a verified current version cannot need an
        // update, and a complete advisory answer without a high score — and
        // without an advisory whose score is still unknown, which may yet
        // be 7 or more — cannot meet the condition. Anything else must not
        // re-arm.
        $conclusive = $updateStatus === ComponentAssessment::UPDATE_CURRENT
            || ($found->hasData() && $high === [] && !$unknownScore);

        $references = [];
        if ($family->versionReferenceUrl() !== null) {
            $references[] = ['label' => 'Versions maintenues (' . $family->versionSource . ')', 'url' => $family->versionReferenceUrl()];
        }
        if ($branch?->link !== null && $branch->link !== $family->versionReferenceUrl()) {
            $references[] = ['label' => 'Notes de version ' . $branch->latest, 'url' => $branch->link];
        }

        return new ComponentAssessment(
            subject: $component->subject,
            label: $component->label(),
            familyLabel: $familyLabel,
            version: $component->version,
            measuredAt: $component->measuredAt,
            state: $state,
            updateStatus: $updateStatus,
            branch: $branch?->name,
            latest: $branch?->latest,
            supportEnds: $branch?->supportEnds,
            certain: !$backportPossible,
            advisories: array_slice($applicable, 0, ComponentAssessment::DISPLAYED_ADVISORIES),
            advisoryCount: max(count($applicable), $found->total),
            highAdvisoryIds: $high,
            advisoryStatus: $found->status,
            needsUpdate: $needsUpdate,
            conclusive: $conclusive,
            notes: $notes,
            action: $this->action($state, $updateStatus, $needsUpdate, $familyLabel, $branch, $component),
            references: $references,
            versionsCheckedAt: $lookup?->fetchedAt,
            advisoriesCheckedAt: $found->fetchedAt
        );
    }

    /**
     * A branch the source said was supported, whose stated end of security
     * support has passed since — the cache may be days old, and believed for
     * up to a month after its source stops answering.
     */
    private static function supportEnded(ReleaseBranch $branch, ?int $now): bool
    {
        return $now !== null
            && $branch->supportEnds !== null
            && $branch->supportEnds < gmdate('Y-m-d', $now);
    }

    /**
     * @param list<string> $notes
     */
    private function unverifiable(ObservedComponent $component, string $familyLabel, array $notes): ComponentAssessment
    {
        return new ComponentAssessment(
            subject: $component->subject,
            label: $component->label(),
            familyLabel: $familyLabel,
            version: $component->version,
            measuredAt: $component->measuredAt,
            state: ComponentAssessment::STATE_UNVERIFIABLE,
            updateStatus: ComponentAssessment::UPDATE_UNKNOWN,
            branch: null,
            latest: null,
            supportEnds: null,
            certain: false,
            advisories: [],
            advisoryCount: 0,
            highAdvisoryIds: [],
            advisoryStatus: AdvisoryLookup::UNAVAILABLE,
            needsUpdate: false,
            conclusive: false,
            notes: $notes,
            action: $component->presence === ObservedComponent::PRESENT
                ? 'Demander à l\'hébergeur la version exacte de ' . $familyLabel . ' et la provenance de ses correctifs.'
                : 'Rien à demander pour l\'instant : la prochaine mesure complétera cette ligne.',
            references: [],
            versionsCheckedAt: null,
            advisoriesCheckedAt: null
        );
    }

    private function action(
        string $state,
        string $updateStatus,
        bool $needsUpdate,
        string $familyLabel,
        ?ReleaseBranch $branch,
        ObservedComponent $component
    ): string {
        $where = str_ends_with($component->subject, '_cron') ? ' utilisé par la tâche planifiée' : '';

        if ($updateStatus === ComponentAssessment::UPDATE_UNSUPPORTED) {
            return 'Demander à l\'hébergeur de passer ' . $familyLabel . $where . ' à une branche encore maintenue : '
                . 'la branche ' . $branch?->name . ' ne reçoit plus de correctifs de sécurité.';
        }
        if ($updateStatus === ComponentAssessment::UPDATE_BEHIND) {
            return 'Demander à l\'hébergeur d\'installer ' . $familyLabel . ' ' . $branch?->latest . $where
                . ', ou de confirmer que ses paquets intègrent déjà les correctifs de cette version.';
        }
        if ($state === ComponentAssessment::STATE_VULNERABLE && $needsUpdate) {
            return 'Demander à l\'hébergeur de mettre à jour ' . $familyLabel . $where
                . ' vers une version qui corrige ces avis, ou de confirmer que ses paquets les corrigent déjà.';
        }
        if ($state === ComponentAssessment::STATE_VULNERABLE) {
            return 'Aucune version plus récente n\'est publiée dans cette branche : demander à l\'hébergeur s\'il '
                . 'applique une mesure de contournement en attendant le correctif de l\'éditeur.';
        }
        if ($state === ComponentAssessment::STATE_UNVERIFIABLE) {
            return 'Rien à demander pour l\'instant : le prochain contrôle réessaiera.';
        }

        return '';
    }
}
