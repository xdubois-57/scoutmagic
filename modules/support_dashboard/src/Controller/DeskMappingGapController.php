<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\DeskMappingGapKind;
use Core\Journal\JournalService;
use Modules\SupportDashboard\Repository\DeskMappingGapRepository;
use Modules\SupportDashboard\Service\DeskMappingGapReport;
use Modules\SupportDashboard\Service\DeskMappingGapRow;
use Twig\Environment;

/**
 * `/support-dashboard/correspondances` (`role_min: superadmin`) — the
 * third screen of Supervision (issue #356): the Desk values reporting
 * installations carry and that this code does not recognise.
 *
 * The list is recomputed on every load from the reports already held, so
 * a value a later version learns leaves the page by itself. The only
 * thing this controller writes is a maintainer's judgement that a value
 * is one unit's typo — and even that is a flag, never a delete.
 *
 * The controller orchestrates only: one service call, one render.
 */
class DeskMappingGapController extends AbstractController
{
    private const PAGE_PATH = '/support-dashboard/correspondances';

    public function __construct(
        protected Environment $twig,
        private DeskMappingGapReport $report,
        private DeskMappingGapRepository $gaps,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $showIgnored = $request->getQuery('ecartees') === '1';

        return $this->render('@support_dashboard/correspondances.html.twig', [
            'rows' => array_map(
                static fn(DeskMappingGapRow $row): array => [
                    'id' => $row->id,
                    'kind' => $row->kind,
                    'kind_label' => self::kindLabel($row->kind),
                    'code_table' => DeskMappingGapKind::from($row->kind)->codeTable(),
                    'value' => $row->valueRaw,
                    'installations' => $row->installations,
                    'instances' => $row->instances,
                    'first_seen_at' => $row->firstSeenAt,
                    'last_seen_at' => $row->lastSeenAt,
                    'oldest_version' => $row->oldestVersion,
                    'ignored' => $row->ignored,
                ],
                $this->report->rows($showIgnored)
            ),
            'show_ignored' => $showIgnored,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function ignore(Request $request, array $params): Response
    {
        return $this->setIgnored($request, $params, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function restore(Request $request, array $params): Response
    {
        return $this->setIgnored($request, $params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function setIgnored(Request $request, array $params, bool $ignored): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_PATH)) !== null) {
            return $guard;
        }

        $gap = $this->gaps->findById((int) ($params['id'] ?? 0));
        if ($gap === null) {
            FlashMessage::set('error', 'Cette correspondance n\'existe plus.');

            return $this->redirect(self::PAGE_PATH);
        }

        $this->gaps->setIgnored($gap['id'], $ignored);

        // Federal vocabulary, and a decision somebody took — both belong
        // in the journal, and neither is personal data (SECURITY.md §11).
        $this->journalService->log(
            'support_dashboard',
            $ignored ? 'desk_mapping_ignored' : 'desk_mapping_restored',
            'info',
            ($ignored ? 'Correspondance Desk écartée' : 'Correspondance Desk réactivée')
            . ' : « ' . $gap['value_raw'] . ' »',
            ['kind' => $gap['kind'], 'value' => $gap['value_raw']]
        );

        FlashMessage::set(
            'success',
            $ignored
                ? 'Valeur écartée. Elle reste consultable en cochant « Montrer les valeurs écartées ».'
                : 'Valeur réactivée.'
        );

        return $this->redirect(self::PAGE_PATH . ($ignored ? '' : '?ecartees=1'));
    }

    private static function kindLabel(string $kind): string
    {
        return match (DeskMappingGapKind::tryFrom($kind)) {
            DeskMappingGapKind::FUNCTION => 'Fonction',
            DeskMappingGapKind::BRANCH => 'Branche',
            DeskMappingGapKind::CSV_HEADER => 'En-tête CSV',
            default => $kind,
        };
    }
}
