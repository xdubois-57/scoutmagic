<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Badge\BadgeHolderService;
use Core\Config\ScoutYearService;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Twig\Environment;

/**
 * Espace chefs d'U > Badges — who wears which badge, year by year
 * (issue #621, docs/chantiers/CHANTIER-badges.md).
 *
 * Read only. Assigning or removing a badge stays on /chefs/staffs, where
 * each member carries their own switches; the page says so and links
 * there.
 */
class BadgeHoldersController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private BadgeHolderService $holders,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYears
    ) {
    }

    /**
     * GET /admin/badges — the badges worn in the year in effect, the one
     * /chefs/staffs assigns them in.
     *
     * @param array<string, string> $params
     */
    public function current(Request $request, array $params): Response
    {
        $year = $this->effectiveYear();

        return $this->render('admin/badges/holders.html.twig', [
            'year_label' => $year->label,
            'groups' => $this->holders->holdersForYear($year->id),
            'is_previous_year' => false,
            'badges_current_year_label' => $year->label,
            'badges_previous_year_label' => ScoutYearService::previousLabel($year->label),
        ]);
    }

    /**
     * GET /admin/badges/annee-precedente — the same page on the scout year
     * before, with no action at all: a closed year is where one finds who
     * held which role, not where one changes it.
     *
     * The tab is always there, even on a unit in its first year or one
     * that assigned nothing that year — far the more common case — and the
     * page then says there is nothing, rather than the tab vanishing.
     *
     * @param array<string, string> $params
     */
    public function previous(Request $request, array $params): Response
    {
        $current = $this->effectiveYear();
        $label = ScoutYearService::previousLabel($current->label);
        // findByLabel(), never ensureYear(): a year nobody imported is not
        // one to fabricate for a page that only reads.
        $year = $this->scoutYears->findByLabel($label);

        return $this->render('admin/badges/holders.html.twig', [
            'year_label' => $label,
            'groups' => $year !== null ? $this->holders->holdersForYear($year['id']) : [],
            'is_previous_year' => true,
            'badges_current_year_label' => $current->label,
            'badges_previous_year_label' => $label,
        ]);
    }

    private function effectiveYear(): \Core\ScoutYear\EffectiveScoutYear
    {
        return $this->scoutYearResolver->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString(AuthSession::getRole())
        );
    }
}
