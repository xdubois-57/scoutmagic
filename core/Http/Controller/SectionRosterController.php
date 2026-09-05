<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\Export\MemberExportRowBuilder;
use Core\Member\Export\MemberExportService;
use Core\Member\Movement\MemberMovementStatus;
use Core\Member\SectionRosterPdfService;
use Core\Member\SectionRosterService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Twig\Environment;

/**
 * "Membres par section" — core, read-only consultation of every member
 * (animateurs, intendants, animés) grouped by section, with contact info
 * and a year-over-year movement classification. Purely informational: no
 * route here ever modifies a member. Reuses the same generic section
 * picker as the trombinoscope module, and the same effective-scout-year
 * resolution (including the admin year preview).
 */
class SectionRosterController extends AbstractController
{
    /**
     * Sentinel section id for the "Toutes" (all sections) picker entry — same convention as the trombinoscope module.
     */
    public const ALL_SECTIONS_ID = 0;

    public function __construct(
        protected Environment $twig,
        private SectionService $sectionService,
        private SectionRosterService $rosterService,
        private MemberExportRowBuilder $exportRowBuilder,
        private MemberExportService $exportService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService,
        /**
         * Trailing and nullable so every existing construction of this
         * controller keeps working — the same posture as the module
         * capabilities elsewhere. Null means the button is not offered
         * and the route answers 404, never a page that offers a download
         * nothing can produce.
         */
        private ?SectionRosterPdfService $pdfService = null,
        /**
         * Only the unit name and the site address, for the sheet's own
         * header and footer — both organizational, both already public on
         * every page of the site. Nullable and trailing for the same
         * backward-compatibility reason as the service above.
         */
        private ?SettingService $settingService = null
    ) {
    }

    /**
     * GET /chefs/membres
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $allSections = $this->sectionService->getAllWithBranches();
        foreach ($allSections as &$section) {
            $section['color'] = SectionService::colorForSection($section);
        }
        unset($section);

        [$selectedId, $pickerSections, $sectionsToRender] = $this->resolveSelection($request, $allSections);

        $sectionIds = array_map(fn(array $s) => (int) $s['id'], $sectionsToRender);
        $roster = $this->rosterService->buildRoster($sectionIds, $effectiveYear->id);

        $sectionBlocks = [];
        foreach ($sectionsToRender as $section) {
            $sectionId = (int) $section['id'];
            $sectionBlocks[] = [
                'section' => $section,
                'animateurs' => $roster[$sectionId]['animateurs'] ?? [],
                'intendants' => $roster[$sectionId]['intendants'] ?? [],
                'animes' => $roster[$sectionId]['animes'] ?? [],
            ];
        }

        $selectedLabel = null;
        foreach ($pickerSections as $section) {
            if ($section['id'] === $selectedId) {
                $selectedLabel = $section['name'];
                break;
            }
        }

        $context = [
            'picker_sections' => $pickerSections,
            'selected_id' => $selectedId,
            'section_blocks' => $sectionBlocks,
            'can_view_member_link' => $role->hasAccess(Role::ADMIN),
            'export_url' => '/chefs/membres/export'
                . ($selectedId !== self::ALL_SECTIONS_ID ? '?section=' . $selectedId : ''),
            // Same perimeter as the screen, deliberately: the sheet prints
            // what is being looked at, so both exports carry the filter.
            'pdf_url' => $this->pdfService === null ? null : '/chefs/membres/pdf'
                . ($selectedId !== self::ALL_SECTIONS_ID ? '?section=' . $selectedId : ''),
            'scout_year_label' => $effectiveYear->label,
            'movement_statuses' => array_values(array_map(
                fn(MemberMovementStatus $status) => [
                    'tone' => $status->tone(),
                    'label' => $status->label(),
                    'description' => $status->description()
                ],
                array_filter(MemberMovementStatus::cases(), fn(MemberMovementStatus $s) => $s->isNotable())
            )),
        ];
        if ($selectedLabel !== null) {
            $context['breadcrumb_current'] = 'Membres par section · ' . $selectedLabel;
        }

        return $this->render('chefs/section_roster.html.twig', $context);
    }

    /**
     * GET /chefs/membres/export — same section-picker filter as index(),
     * exported to a canonical, exhaustive .xlsx via the core member export
     * mechanism (Core\Member\Export).
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $allSections = $this->sectionService->getAllWithBranches();
        [, , $sectionsToRender] = $this->resolveSelection($request, $allSections);
        $sectionIds = array_map(fn(array $s) => (int) $s['id'], $sectionsToRender);

        $rows = $this->exportRowBuilder->buildForSections($sectionIds, $effectiveYear->id);
        $xlsx = $this->exportService->buildSpreadsheet($rows, $role, 'Membres ' . $effectiveYear->label);

        $this->journalService->log(
            'core',
            'section_roster_exported',
            'info',
            'Export des membres par section',
            ['scout_year_id' => $effectiveYear->id, 'section_count' => count($sectionIds), 'row_count' => count($rows)],
            AuthSession::getUserAccountId()
        );

        $filename = 'membres-' . preg_replace('/[^0-9A-Za-z_-]/', '_', $effectiveYear->label) . '.xlsx';

        return \Core\Http\SpreadsheetResponse::download($xlsx, $filename);
    }

    /**
     * GET /chefs/membres/pdf — the roll-call sheet, one page per section,
     * for the same section filter the screen is showing.
     *
     * `role_min: intendant`, the page's own: what it prints is what the
     * page already displays, minus every contact.
     *
     * @param array<string, string> $params
     */
    public function pdf(Request $request, array $params): Response
    {
        if ($this->pdfService === null) {
            return $this->notFound();
        }

        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $allSections = $this->sectionService->getAllWithBranches();
        foreach ($allSections as &$section) {
            $section['color'] = SectionService::colorForSection($section);
        }
        unset($section);

        [$selectedId, , $sectionsToRender] = $this->resolveSelection($request, $allSections);
        $sectionIds = array_map(fn(array $s) => (int) $s['id'], $sectionsToRender);
        $roster = $this->rosterService->buildRoster($sectionIds, $effectiveYear->id);

        $pdf = $this->pdfService->generate(
            $effectiveYear->id,
            $effectiveYear->label,
            (string) ($this->settingService?->get('site_name') ?: ''),
            rtrim((string) ($this->settingService?->get('base_url') ?: ''), '/'),
            $sectionsToRender,
            $roster,
            $selectedId !== self::ALL_SECTIONS_ID ? $selectedId : null
        );

        // Counters only, never a name — the same shape as the spreadsheet
        // export's own entry above (AGENTS.md § Security checklist, "no
        // personal data in log entries").
        $this->journalService->log(
            'core',
            'section_roster_pdf_exported',
            'info',
            'Export PDF des membres par section',
            [
                'scout_year_id' => $effectiveYear->id,
                'section_count' => count($sectionIds),
                'member_count' => array_sum(array_map(
                    fn(array $buckets) => count($buckets['animateurs']) + count($buckets['animes']),
                    $roster
                )),
            ],
            AuthSession::getUserAccountId()
        );

        $sectionName = null;
        if ($selectedId !== self::ALL_SECTIONS_ID && $sectionsToRender !== []) {
            $first = $sectionsToRender[array_key_first($sectionsToRender)];
            $sectionName = (string) (($first['name'] ?? '') !== '' ? $first['name'] : $first['desk_code']);
        }

        return (new Response($pdf))
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->pdfService->fileName($effectiveYear->label, $sectionName) . '"'
            )
            ->setHeader('Content-Length', (string) strlen($pdf));
    }

    /**
     * @param array<int, array<string, mixed>> $allSections
     * @return array{0: int, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    private function resolveSelection(Request $request, array $allSections): array
    {
        $pickerSections = [
            [
                'id' => self::ALL_SECTIONS_ID,
                'desk_code' => '__all__',
                'name' => 'Toutes',
                'branch_name' => '',
                'color' => null
            ],
        ];
        foreach ($allSections as $section) {
            $pickerSections[] = $section;
        }

        $validIds = array_map(fn(array $s) => $s['id'], $allSections);
        $requestedId = $request->getQuery('section');
        $selectedId = ($requestedId !== null && $requestedId !== '') ? (int) $requestedId : self::ALL_SECTIONS_ID;
        if ($selectedId !== self::ALL_SECTIONS_ID && !in_array($selectedId, $validIds, true)) {
            $selectedId = self::ALL_SECTIONS_ID;
        }

        $sectionsToRender = $selectedId === self::ALL_SECTIONS_ID
            ? $allSections
            : array_values(array_filter($allSections, fn(array $s) => $s['id'] === $selectedId));

        return [$selectedId, $pickerSections, $sectionsToRender];
    }
}
