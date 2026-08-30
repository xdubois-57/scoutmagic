<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Modules\Attestations\Repository\BatchRepository;
use Twig\Environment;

/**
 * The module's page, in the Espace chefs d'U at `role_min: admin` — the
 * same floor as the member sheet and the journal, and never lower. What is
 * deposited here is the whole unit's nominative paperwork in one document;
 * an animateur de section has no business opening it.
 *
 * This iteration has one route and it only reads: the deposit form, the
 * verification screen and publication arrive with the iterations that own
 * them. The page exists now so the module is real, its route is testable,
 * and its RBAC boundary is pinned from the first line of code.
 */
class AttestationsController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private BatchRepository $batches,
        private ScoutYearService $scoutYears
    ) {
    }

    /**
     * GET /admin/attestations
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $yearLabels = [];
        foreach ($this->scoutYears->getAll() as $year) {
            $yearLabels[$year['id']] = $year['label'];
        }

        return $this->render('@attestations/index.html.twig', [
            'batches' => $this->batches->findRecent(),
            'year_labels' => $yearLabels,
        ]);
    }
}
