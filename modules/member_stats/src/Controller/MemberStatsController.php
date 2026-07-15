<?php

declare(strict_types=1);

namespace Modules\MemberStats\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\MemberStats\Service\MemberStatsService;
use Twig\Environment;

class MemberStatsController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberStatsService $statsService,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    /**
     * GET /chiefs/stats — member statistics per branch-year for the current year.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        // Ages are computed from the scout year's start year (not today's date),
        // so the figures are stable and correct for past years too.
        $referenceYear = MemberStatsService::referenceYearFromLabel($effectiveYear->label);

        $stats = $this->statsService->getStatistics($effectiveYear->id, $referenceYear);

        return $this->render('@member_stats/index.html.twig', [
            'stats' => $stats,
            'scout_year_label' => $effectiveYear->label,
        ]);
    }
}
