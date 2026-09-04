<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Trombinoscope\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Trombinoscope\Service\TrombinoscopePdfService;
use Modules\Trombinoscope\Service\TrombinoscopeService;
use Twig\Environment;

class TrombinoscopeController extends AbstractController
{
    /** Sentinel section id for the "Toutes" (all sections) picker entry. */
    public const ALL_SECTIONS_ID = 0;

    /**
     * The module's single setting: whether an animateur's own phone number
     * and e-mail address are shown at all. It governs personal data only —
     * a section's own e-mail address is organizational (design.md §2.6,
     * "Section email (organizational) -> Clear VARCHAR"), survives a change
     * of responsable, and stays on screen either way.
     */
    public const SETTING_SHOW_CONTACTS = 'trombinoscope_show_contacts';

    public function __construct(
        protected Environment $twig,
        private SectionService $sectionService,
        private TrombinoscopeService $trombinoscopeService,
        private ScoutYearResolver $scoutYearResolver,
        private SettingService $settingService,
        private TrombinoscopePdfService $pdfService,
        /** Optional so the many existing constructions keep working; without it every portrait resolves its own photo. */
        private ?\Core\Photo\MemberPhotoService $memberPhotoService = null
    ) {
    }

    /**
     * Whether personal contact details may be rendered at all. One switch
     * for the whole module — the wall, the printable directory page and
     * the printable section pages — never two.
     */
    private function showsContacts(): bool
    {
        return $this->settingService->get(self::SETTING_SHOW_CONTACTS, 'trombinoscope', '1') === '1';
    }

    /**
     * GET /trombinoscope — staff photo wall for the site's current scout
     * year. Shows every section stacked by default ("Toutes"), or a single
     * section when picked.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $allSections = $this->sectionService->getAllWithBranches();

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
            $section['color'] = SectionService::colorForSection($section);
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

        $staffBySection = $this->trombinoscopeService->getSectionStaffForSections(
            array_map(static fn(array $s): int => (int) $s['id'], $sectionsToRender),
            $effectiveYear->id
        );
        $sectionBlocks = [];
        $memberIds = [];
        foreach ($sectionsToRender as $section) {
            $data = $staffBySection[(int) $section['id']] ?? ['lead' => null, 'staff' => []];
            $sectionBlocks[] = [
                'section' => $section,
                'lead' => $data['lead'],
                'staff' => $data['staff'],
            ];
            foreach (array_merge($data['lead'] !== null ? [$data['lead']] : [], $data['staff']) as $profile) {
                $memberIds[] = $profile->memberId;
            }
        }
        // One photo query for the whole wall instead of one per portrait.
        $this->memberPhotoService?->primeFileIds($memberIds, $effectiveYear->id);

        // The section picker changes what this page shows without changing
        // its URL structure (?section={id}) — the breadcrumb's own segment
        // must reflect the current selection, same as Staffs.
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
            'show_contacts' => $this->showsContacts(),
            // The printable document always covers the whole unit and the
            // effective year, whatever this page is currently filtered to
            // — so the count is every section plus the directory page.
            'pdf_page_count' => count($allSections) + 1,
            'scout_year_label' => $effectiveYear->label,
        ];
        if ($selectedLabel !== null) {
            $context['breadcrumb_current'] = 'Trombinoscope · ' . $selectedLabel;
        }

        return $this->render('@trombinoscope/index.html.twig', $context);
    }

    /**
     * GET /trombinoscope/pdf — the printable trombinoscope, streamed as a
     * PDF attachment.
     *
     * Same role as the page it is downloaded from (`identified`), and
     * deliberately: a visitor here already sees exactly these names,
     * photos and — when the setting allows it — contact details on screen.
     * The point of the document is that they can produce it themselves and
     * put it on a wall, not that it discloses anything new.
     *
     * @param array<string, string> $params
     */
    public function pdf(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $pdf = $this->pdfService->generate(
            $effectiveYear->id,
            $effectiveYear->label,
            (string) ($this->settingService->get('site_name') ?: ''),
            rtrim((string) ($this->settingService->get('base_url') ?: ''), '/'),
            $this->showsContacts()
        );

        return (new Response($pdf))
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition',
                'attachment; filename="' . $this->pdfService->fileName($effectiveYear->label) . '"')
            ->setHeader('Content-Length', (string) strlen($pdf));
    }
}
