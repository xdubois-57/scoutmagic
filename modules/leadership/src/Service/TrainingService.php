<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Core\Import\AgeBranchRepository;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Value\PersonLine;
use Modules\Leadership\Value\SupervisionSituation;

/**
 * The Formations page: two lists of people to talk to, and what the ONE
 * ratio asks of each section.
 */
class TrainingService
{
    /**
     * `MemberYearService::BRANCHES`' own key for the Pionniers branch — the
     * effective age's side of the "section and age must agree" check in
     * lastYearPionniers().
     */
    private const PIONNIER_BRANCH_KEY = 'pionnier';

    public function __construct(
        private LeadershipRepository $repository,
        private SectionService $sectionService,
        private MemberYearService $memberYearService,
        private SupervisionCalculator $supervisionCalculator
    ) {
    }

    /**
     * "À convaincre de commencer" — exactly two profiles, in this order,
     * and deliberately not a third.
     *
     * The obvious candidate for a third — an animateur of several years
     * with no formation — is left out on purpose: somebody who starts the
     * path in their fourth year will not finish it before they stop
     * animating, so putting them on a list headed "to convince to start"
     * misrepresents what starting would achieve. The two profiles here are
     * the two where the path still fits inside the person's time in post.
     *
     * @param list<\Modules\Leadership\Value\StaffFunctionRow> $staff
     * @return list<PersonLine>
     */
    public function toConvince(
        array $staff,
        int $scoutYearId,
        string $scoutYearLabel,
        ?int $previousScoutYearId,
        ?FormationLevelResolver $resolver = null
    ): array {
        $lines = $this->lastYearPionniers($scoutYearId, $scoutYearLabel);

        if ($previousScoutYearId !== null) {
            foreach ($this->firstYearAnimators($staff, $previousScoutYearId, $resolver) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Pionniers in the last year of their branch — next year's animateurs,
     * and the moment the path is worth raising with them.
     *
     * Membership comes from `member_section_periods` and not from the age
     * alone: a member's age says which branch they *would* be in, whereas
     * the periods say which section they are actually in, which is the one
     * that will ask them. The branch-year itself then comes from
     * MemberYearService::getEffectiveAge(), the single source of truth for
     * that question site-wide — never recomputed here — so a chief's
     * scout-year offset for somebody who skipped or repeated a year is
     * respected exactly as it is everywhere else.
     *
     * **Both have to agree, and somebody they disagree about is left out.**
     * The two answers can differ: a member sitting in a Pionniers section
     * whose effective age still places them among the Éclaireurs is in the
     * last year of *that* branch, not of this one. Listing them would print
     * "dernière année chez les pionniers" beside a line reading "4e année
     * éclaireurs" — a contradiction on the same row, and the wrong year to
     * raise the training in. The offset field exists precisely so a chief
     * can align the two, so a disagreement means the site does not know,
     * and silence is what it owes an unknown (never a third guess about
     * which of the two is right).
     *
     * @return list<PersonLine>
     */
    private function lastYearPionniers(int $scoutYearId, string $scoutYearLabel): array
    {
        // The canonical sort order of the Pionniers branch, from the single
        // helper that assigns it at import time — never a literal 40 here,
        // which would silently stop matching if that mapping ever moved.
        $pionniers = AgeBranchRepository::canonicalSortOrder('Pionniers');

        $referenceYear = MemberYearService::referenceYearFromScoutYearLabel($scoutYearLabel);
        // Membership is asked about the scout year's own start date rather
        // than today's, so the answer does not change halfway through the
        // year and is the same when looking back at a past year.
        $referenceDate = $referenceYear . '-09-01';

        $lines = [];
        // One line per PERSON. `member_section_periods` has a row per
        // period, so a pionnier who moved between two Pionniers sections
        // during the year — or whose section was re-encoded — comes back
        // from the query twice, and the page printed them twice.
        $seen = [];
        foreach ($this->repository->findMembersInBranchSections($scoutYearId, $pionniers, $referenceDate) as $row) {
            $memberYearId = (int) $row['member_year_id'];
            if (isset($seen[$memberYearId])) {
                continue;
            }

            $effectiveAge = $this->memberYearService->getEffectiveAge(
                MemberYearService::extractBirthYear($row['birth_date']),
                $row['scout_year_offset'],
                $referenceYear
            );

            if ($effectiveAge->branchKey !== self::PIONNIER_BRANCH_KEY
                || $effectiveAge->yearInBranch !== $effectiveAge->totalYearsInBranch) {
                continue;
            }

            $seen[$memberYearId] = true;
            $lines[] = new PersonLine(
                memberYearId: $row['member_year_id'],
                totem: ($row['totem'] !== null && $row['totem'] !== '') ? $row['totem'] : null,
                fullName: trim($row['first_name'] . ' ' . $row['last_name']),
                sectionName: $row['section_name'],
                detail: $effectiveAge->getBranchYearLabel(),
                note: "Dernière année chez les pionniers : c'est l'année où proposer la formation.",
            );
        }

        usort(
            $lines,
            static fn (PersonLine $a, PersonLine $b) => TextMatcher::compareNames($a->fullName, $b->fullName)
        );

        return $lines;
    }

    /**
     * Animateurs in their first year: nobody who held an animation function
     * anywhere in the unit during the previous scout year.
     *
     * The comparison is on the persistent `members.id` and on the previous
     * year's function rows — never on `member_functions.start_date`, which
     * belongs to one function and restarts the day somebody changes
     * section. Reading a start date here would file a chief of eight years
     * standing who moved from Louveteaux to Éclaireurs as a beginner, and
     * would do it silently.
     *
     * **Somebody already on the path is not on this list.** "À convaincre
     * de commencer" and "Parcours à terminer" are two answers to two
     * different questions, and a first-year animateur who arrives with a
     * T1 already behind them belongs to the second: telling a chief to
     * convince them to start is telling them to have a conversation that
     * has already happened. UNKNOWN stays listed, which is this module's
     * standing doctrine — an unrecognised Desk value is never counted as
     * anything, so it cannot be counted as "already started" either, and
     * the honest answer is to let a human look.
     *
     * @param list<\Modules\Leadership\Value\StaffFunctionRow> $staff
     * @return list<PersonLine>
     */
    private function firstYearAnimators(
        array $staff,
        int $previousScoutYearId,
        ?FormationLevelResolver $resolver = null
    ): array {
        $previous = array_flip($this->repository->findMemberIdsWithAnimationFunction($previousScoutYearId));

        $lines = [];
        $seen = [];
        foreach ($staff as $row) {
            if (!$row->isAnimation() || isset($previous[$row->memberId]) || isset($seen[$row->memberId])) {
                continue;
            }
            if ($resolver !== null && self::hasStartedThePath($resolver->resolve($row->formationLevel))) {
                continue;
            }
            $seen[$row->memberId] = true;

            $lines[] = new PersonLine(
                memberYearId: $row->memberYearId,
                totem: $row->totem,
                fullName: $row->fullName(),
                email: $row->email,
                sectionName: $row->sectionName,
                detail: $row->functionLabel,
                note: "Première année d'animation dans l'unité : le parcours peut commencer maintenant.",
            );
        }

        usort(
            $lines,
            static fn (PersonLine $a, PersonLine $b) => TextMatcher::compareNames($a->fullName, $b->fullName)
        );

        return $lines;
    }

    /**
     * Whether this step means the person is already somewhere on the path
     * or past it.
     *
     * NONE and UNKNOWN are not, and they are the ONLY two that are not:
     * NONE is Desk saying the column is empty, which is the very profile
     * "à convaincre" is about, and UNKNOWN is the site saying it does not
     * know — which this module never turns into a conclusion in either
     * direction. Everything else means somebody has done some training,
     * the Woodbadge included: it is off the animator path, but printing
     * "à convaincre de commencer" beside a Woodbadge holder would be
     * plainly wrong. Staying off the « parcours à terminer » list is a
     * different question, and toFinish() answers it with
     * `isPathInProgress()`.
     */
    private static function hasStartedThePath(FormationStep $step): bool
    {
        return $step !== FormationStep::NONE && $step !== FormationStep::UNKNOWN;
    }

    /**
     * How many animateurs carry the legacy box — a brevet whose kind
     * nobody recorded (roadmap IT-20).
     *
     * They stopped counting towards the ONE ratio the day the BACV and the
     * Woodbadge got boxes of their own, because only the BACV is
     * recognised and an unspecified brevet cannot be asserted to be one.
     * The figure therefore FELL on that day for the units concerned, which
     * is exactly the kind of silent change that destroys trust in a page —
     * so the count exists to say so above the ratio, with the one action
     * that fixes it. Without the sentence, not changing the number at all
     * would have been the better option.
     *
     * One entry per person, like every other count on this page: two
     * animation functions is one animateur.
     *
     * @param list<\Modules\Leadership\Value\StaffFunctionRow> $staff
     */
    public function unspecifiedBrevetCount(array $staff, FormationLevelResolver $resolver): int
    {
        $seen = [];
        foreach ($staff as $row) {
            if (!$row->isAnimation() || isset($seen[$row->memberId])) {
                continue;
            }
            if ($resolver->resolve($row->formationLevel) === FormationStep::BREVET) {
                $seen[$row->memberId] = true;
            }
        }

        return count($seen);
    }

    /**
     * "Parcours à terminer" — everybody between T1 and T3, the step reached
     * descending, so the people closest to their brevet come first.
     *
     * @param list<\Modules\Leadership\Value\StaffFunctionRow> $staff
     * @return list<PersonLine>
     */
    public function toFinish(array $staff, FormationLevelResolver $resolver): array
    {
        $entries = [];
        $seen = [];
        foreach ($staff as $row) {
            if (!$row->isAnimation() || isset($seen[$row->memberId])) {
                continue;
            }

            $step = $resolver->resolve($row->formationLevel);
            if (!$step->isPathInProgress()) {
                continue;
            }
            $seen[$row->memberId] = true;

            $next = $step->next();
            $entries[] = [
                'rank' => $step->rank(),
                'line' => new PersonLine(
                    memberYearId: $row->memberYearId,
                    totem: $row->totem,
                    fullName: $row->fullName(),
                    email: $row->email,
                    sectionName: $row->sectionName,
                    detail: $row->functionLabel,
                    note: $next === null
                        ? $step->description() . '.'
                        : $step->description() . ' — étape suivante : ' . $next->label() . '.',
                ),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return ($b['rank'] <=> $a['rank'])
                ?: TextMatcher::compareNames($a['line']->fullName, $b['line']->fullName);
        });

        return array_map(static fn (array $e): PersonLine => $e['line'], $entries);
    }

    /**
     * One SupervisionSituation per visible, active section: headcount,
     * animateurs, brevets, and what the ONE ratio asks for.
     *
     * Sections come from SectionService::getAllWithBranches(), so a hidden
     * or member-less section is absent here exactly as it is from every
     * other section list on the site (§8.8) rather than showing up as a
     * section with nobody in it.
     *
     * @param list<\Modules\Leadership\Value\StaffFunctionRow> $staff
     * @return list<array{section_id: int, section_name: string, branch_name: string, situation: SupervisionSituation}>
     */
    public function sectionSituations(array $staff, int $scoutYearId, FormationLevelResolver $resolver): array
    {
        $headcounts = $this->repository->countAnimesBySection($scoutYearId);

        // One entry per (section, animateur), deduplicated: two animation
        // functions in the same section is one person to the ratio.
        $stepsBySection = [];
        $seen = [];
        foreach ($staff as $row) {
            if (!$row->isAnimation() || $row->sectionId === null) {
                continue;
            }
            $key = $row->sectionId . ':' . $row->memberId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $stepsBySection[$row->sectionId][] = $resolver->resolve($row->formationLevel);
        }

        $situations = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $situations[] = [
                'section_id' => $section['id'],
                'section_name' => $section['name'] ?? $section['desk_code'],
                'branch_name' => $section['branch_name'],
                'situation' => $this->supervisionCalculator->evaluate(
                    $headcounts[$section['id']] ?? 0,
                    $stepsBySection[$section['id']] ?? []
                ),
            ];
        }

        return $situations;
    }

    /**
     * Raw Desk values in use by this year's staff that the site cannot
     * resolve, with how many people carry each and what an admin has
     * already decided about them.
     *
     * This is the module's only editing surface, and it lives on the page
     * whose numbers the mapping affects rather than on a configuration page
     * of its own — somebody who has just read "le calcul peut être
     * incomplet" is one collapsible block away from fixing it.
     *
     * @return list<array{raw_value: string, holders: int}>
     */
    public function unresolvedLevels(int $scoutYearId, FormationLevelResolver $resolver): array
    {
        $rows = [];
        foreach ($this->repository->countFormationLevels($scoutYearId) as $rawValue => $holders) {
            if ($resolver->resolve($rawValue) !== FormationStep::UNKNOWN) {
                continue;
            }
            $rows[] = ['raw_value' => (string) $rawValue, 'holders' => $holders];
        }

        usort($rows, static fn (array $a, array $b) => TextMatcher::compareNames($a['raw_value'], $b['raw_value']));

        return $rows;
    }

    /**
     * Raw values somebody has already decided about, so the same block can
     * correct a decision as well as make one. Each carries how many of this
     * year's staff still use it, which is what tells an admin whether a
     * mapping is live or a leftover from a wording Desk has stopped
     * exporting.
     *
     * @param list<array{raw_value: string, step: string}> $mappings
     * @return list<array{raw_value: string, step: string, step_label: string, holders: int}>
     */
    public function decidedLevels(array $mappings, int $scoutYearId): array
    {
        $counts = $this->repository->countFormationLevels($scoutYearId);
        $foldedCounts = [];
        foreach ($counts as $rawValue => $holders) {
            $foldedCounts[FormationLevelResolver::keyFor((string) $rawValue)] = $holders;
        }

        $rows = [];
        foreach ($mappings as $mapping) {
            $step = FormationStep::tryFromValue($mapping['step']);
            if ($step === null) {
                continue;
            }
            $rows[] = [
                'raw_value' => $mapping['raw_value'],
                'step' => $step->value,
                'step_label' => $step->label(),
                'holders' => $foldedCounts[FormationLevelResolver::keyFor($mapping['raw_value'])] ?? 0,
            ];
        }

        return $rows;
    }
}
