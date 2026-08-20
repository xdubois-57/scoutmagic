<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Registration\Api\ScoutYearPreparationProvider;

/**
 * The module's implementation of its own Api\ScoutYearPreparationProvider
 * (ARCHITECTURE.md §7.5) — wired nullable into Core\ScoutYear\
 * ScoutYearTransitionService by the composition root, only when this
 * module is enabled.
 *
 * Unlike ScoutYearTransitionVetoService next door, this is not a
 * pass-through to one query: "who changes branch next year" is a rule
 * (last rank of a branch, oldest branch excluded, leavers excluded) that
 * PassageService already owns, and the Passage page's own numbers must
 * match the workflow's to the unit. So this asks PassageService the exact
 * same question the page asks, rather than re-deriving it from SQL.
 *
 * It resolves the target year itself — public year plus one, module spec
 * §18.2 — because that rule belongs to this module (see the interface's
 * own comment on why core passes no year).
 */
class ScoutYearPreparationService implements ScoutYearPreparationProvider
{
    /**
     * getBranchChanges() decrypts every animé of the year and resolves
     * every household; the two counts below are two questions about one
     * answer, so it runs at most once per request rather than once per
     * question.
     *
     * @var array{total: int, unassigned: int}|null
     */
    private ?array $counts = null;

    public function __construct(
        private PassageService $passageService,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService
    ) {
    }

    public function countPassages(): int
    {
        return $this->counts()['total'];
    }

    public function countUnassignedPassages(): int
    {
        return $this->counts()['unassigned'];
    }

    /**
     * @return array{total: int, unassigned: int}
     */
    private function counts(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetId = $this->scoutYearService->ensureYear(ScoutYearService::nextLabel($publicYear['label']));

        $total = 0;
        $unassigned = 0;
        $groups = $this->passageService->getBranchChanges(
            (int) $publicYear['id'],
            (string) $publicYear['label'],
            $targetId
        );

        foreach ($groups as $group) {
            foreach ($group['members'] as $member) {
                $total++;
                if ($member['destination_section_id'] === null) {
                    $unassigned++;
                }
            }
        }

        return $this->counts = ['total' => $total, 'unassigned' => $unassigned];
    }
}
