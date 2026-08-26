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
use Core\Member\AdminMemberPageService;
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
        private JournalService $journalService,
        private AdminMemberPageService $adminMemberPageService
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

        return $this->render('admin/members/search.html.twig', [
            'query' => $query,
            'results' => $results,
            'year_label' => $effective->label,
        ]);
    }

    /**
     * GET /admin/members/{id} — one member's own page, `role_min: admin`.
     *
     * The detail used to render below the search results, reached as
     * `/admin/members?q=…&member={id}`. It is dense — every Desk field,
     * the year's functions, the effective age, the scout-year offset, the
     * departure marker — and a route with an identifier is what this
     * codebase does everywhere else a person carries that much
     * (`/config/inscriptions/demandes/{id}`, `/members/{id}`). It also
     * makes the address shareable, which `?member=42` bolted onto a
     * search never was: that link dragged the `q=` along and replayed an
     * unrelated search for whoever opened it.
     *
     * The check that came with it: the member must belong to the
     * effective scout year, or 404. It moved here unchanged.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effective = $this->resolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $memberYearId = (int) ($params['id'] ?? 0);

        if ($memberYearId <= 0 || $this->searchService->findById($effective->id, $memberYearId) === null) {
            return $this->notFound();
        }

        try {
            $profile = $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException) {
            return $this->notFound();
        }

        $effectiveAge = $this->memberYearService->getEffectiveAge(
            MemberYearService::extractBirthYear($profile->birthDate),
            $profile->scoutYearOffset,
            MemberYearService::referenceYearFromScoutYearLabel($profile->scoutYearLabel)
        );
        $departureStatus = $this->departureService->getStatus($profile->memberYearId);

        return $this->render('admin/members/show.html.twig', array_merge(
            $this->adminMemberPageService->buildPageData($profile, $effective->id),
            [
                'member' => $profile,
                'breadcrumb_current' => trim($profile->lastName . ' ' . $profile->firstName),
                'effective_age' => $effectiveAge,
                'departure_leaving' => $departureStatus?->leaving ?? false,
                'departure_comment' => $departureStatus?->comment ?? '',
                'is_temporary_member' => TemporaryMemberSession::get() === $profile->memberYearId,
                'year_label' => $effective->label,
            ]
        ));
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
        $xlsx = $this->exportService->buildSpreadsheet($rows, $role, 'Membres ' . $effective->label);

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

        return \Core\Http\SpreadsheetResponse::download($xlsx, $filename);
    }
}
