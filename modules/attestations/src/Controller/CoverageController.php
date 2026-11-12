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
use Core\ScoutYear\ScoutYearResolver;
use Core\Service\IntegerInput;
use Modules\Attestations\Service\CoverageService;
use Modules\Attestations\Value\AttestationCategory;
use Twig\Environment;

/**
 * Who is still missing a certificate.
 *
 * The federation sends several partial files a season, each one a batch of
 * its own. After three of them nobody reconciles three lists by hand, and
 * this is the only screen from which a chef d'unité can ask for a
 * complement while knowing whose.
 *
 * Read-only, and `role_min: admin` like everything else here — it names
 * every member of a season, which is the whole unit's roster.
 */
class CoverageController extends AbstractController
{
    public const PATH = '/admin/attestations/couverture';

    public function __construct(
        protected Environment $twig,
        private CoverageService $coverage,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYears
    ) {
    }

    /**
     * GET /admin/attestations/couverture
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $years = $this->scoutYearResolver->listYears();

        // A query string, never a form post: this is a reading, and a
        // reading has to survive being bookmarked and sent to the chef
        // d'unité who will write to the federation.
        $category = AttestationCategory::tryFromValue((string) $request->getQuery('category', ''))
            ?? AttestationCategory::Tax;
        // The current year, same default and same reason as the deposit
        // form: the public year lags behind during a transition, and the
        // question being asked is about the season the reader is living in.
        $currentYearId = (int) $this->scoutYears->getCurrentYear()['id'];
        $scoutYearId = IntegerInput::id($request->getQuery('scout_year_id', '')) ?? $currentYearId;
        // A year id the site does not hold — a stale bookmark, a hand-edited
        // address — reads as the default, so the selector always shows the
        // year the figures are about (issue #1097).
        if (!in_array($scoutYearId, array_map(static fn(array $year): int => (int) $year['id'], $years), true)) {
            $scoutYearId = $currentYearId;
        }

        $coverage = $this->coverage->forYear($category, $scoutYearId);

        return $this->render('@attestations/coverage.html.twig', [
            'coverage' => $coverage,
            'category' => $category,
            'scout_year_id' => $scoutYearId,
            'year_options' => array_map(
                static fn(array $year): array => [
                    'value' => (string) $year['id'],
                    'label' => (string) $year['label'],
                    'selected' => (int) $year['id'] === $scoutYearId,
                ],
                $years
            ),
            'category_options' => $this->options(AttestationCategory::labels(), $category->value),
        ]);
    }
}
