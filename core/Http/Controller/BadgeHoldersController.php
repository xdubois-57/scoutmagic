<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Badge\BadgeHolderService;
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
        private ScoutYearResolver $scoutYearResolver
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
        $year = $this->scoutYearResolver->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString(AuthSession::getRole())
        );

        return $this->render('admin/badges/holders.html.twig', [
            'year_label' => $year->label,
            'groups' => $this->holders->holdersForYear($year->id),
            'badges_current_year_label' => $year->label,
        ]);
    }
}
