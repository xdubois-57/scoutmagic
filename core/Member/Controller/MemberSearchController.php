<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\DepartureService;
use Core\Member\Export\MemberExportRowBuilder;
use Core\Member\Export\MemberExportService;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\Service\MemberSearchService;
use Core\Member\TemporaryMemberSession;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Twig\Environment;

/**
 * GET /admin/members — search and view members of the effective scout year.
 */
class MemberSearchController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberSearchService $searchService,
        private MemberService $memberService,
        private ScoutYearResolver $resolver,
        private MemberYearService $memberYearService,
        private DepartureService $departureService,
        private MemberExportRowBuilder $exportRowBuilder,
        private MemberExportService $exportService,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effective = $this->resolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $yearId = $effective->id;

        $query = trim((string) $request->getQuery('q', ''));
        $results = $query !== '' ? $this->searchService->search($yearId, $query) : [];

        $detail = null;
        $effectiveAge = null;
        $departureStatus = null;
        $memberId = (int) $request->getQuery('member', 0);
        if ($memberId > 0) {
            // The member must belong to the effective scout year.
            if ($this->searchService->findById($yearId, $memberId) === null) {
                return $this->notFound();
            }
            try {
                $detail = $this->memberService->getMemberProfile($memberId);
            } catch (MemberNotFoundException) {
                return $this->notFound();
            }

            $effectiveAge = $this->memberYearService->getEffectiveAge(
                MemberYearService::extractBirthYear($detail->birthDate),
                $detail->scoutYearOffset,
                MemberYearService::referenceYearFromScoutYearLabel($detail->scoutYearLabel)
            );
            $departureStatus = $this->departureService->getStatus($detail->memberYearId);
        }

        return $this->render('admin/members/search.html.twig', [
            'query' => $query,
            'results' => $results,
            'detail' => $detail,
            'effective_age' => $effectiveAge,
            'departure_leaving' => $departureStatus?->leaving ?? false,
            'departure_comment' => $departureStatus?->comment ?? '',
            'selected_member_id' => $memberId,
            'is_temporary_member' => $memberId > 0 && TemporaryMemberSession::get() === $memberId,
            'year_label' => $effective->label,
        ]);
    }

    /**
     * GET /admin/members/export — the current search's results (?q=...) or
     * an explicit selection of them (?selected[]=...) as a canonical
     * member .xlsx (Core\Member\Export — same generic, mail-merge-reusable
     * format as every member export on the site, ARCHITECTURE.md §8.61).
     * Selected ids are re-validated server-side against the effective
     * scout year — a stale or forged id is silently dropped, never
     * exported.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effective = $this->resolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $selectedRaw = $request->getQuery('selected');
        $selectedIds = is_array($selectedRaw) ? array_values(array_filter(array_map('intval', $selectedRaw), fn(int $id) => $id > 0)) : [];

        if ($selectedIds !== []) {
            $memberYearIds = array_values(array_filter(
                array_unique($selectedIds),
                fn(int $id) => $this->searchService->findById($effective->id, $id) !== null
            ));
            $scope = 'selection';
        } else {
            $query = trim((string) $request->getQuery('q', ''));
            $memberYearIds = array_map(
                fn(\Core\Member\Service\MemberSearchResult $r) => $r->memberYearId,
                $this->searchService->search($effective->id, $query)
            );
            $scope = 'search';
        }

        if ($memberYearIds === []) {
            return new Response('Aucun membre à exporter.', 400);
        }

        $rows = $this->exportRowBuilder->buildForMemberYears($memberYearIds, $effective->id);
        $xlsx = $this->exportService->build($rows, $role, 'Membres ' . $effective->label);

        // Counts only — the search query itself can contain a person's
        // name, so it never reaches the journal.
        $this->journalService->log(
            'core',
            'member_search_exported',
            'info',
            'Export des membres depuis la recherche',
            ['scout_year_id' => $effective->id, 'scope' => $scope, 'row_count' => count($rows)],
            AuthSession::getUserAccountId()
        );

        $filename = 'membres-' . preg_replace('/[^0-9A-Za-z_-]/', '_', $effective->label) . '.xlsx';

        return (new Response($xlsx))
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Content-Length', (string) strlen($xlsx));
    }
}
