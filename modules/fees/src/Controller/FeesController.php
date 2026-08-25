<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Fees\Repository\FeesImportRepository;
use Core\Import\RosterSnapshotRepository;
use Twig\Environment;

/**
 * The module's home page: what it will do, and what it can already say.
 *
 * It exists this early for one reason — the snapshot only starts existing
 * the day the module is activated, so an installation that turns it on in
 * February will never be able to check November's deposit invoice. That is
 * a fact the screen states, rather than something a treasurer discovers
 * three months later.
 */
class FeesController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private RosterSnapshotRepository $snapshots,
        private FeesImportRepository $imports,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    /**
     * GET /admin/fees
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $year = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        return $this->render('@fees/index.html.twig', [
            'scout_year_label' => $year->label,
            'last_import_at' => $this->imports->findLastImportAt($year->id),
            'latest_snapshot' => $this->snapshots->findLatestForYear($year->id),
            'snapshot_count' => $this->snapshots->countForYear($year->id),
        ]);
    }
}
