<?php

declare(strict_types=1);

namespace Modules\Trombinoscope\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Trombinoscope\Service\TrombinoscopeService;
use Twig\Environment;

class TrombinoscopeController extends AbstractController
{
    /** Sentinel section id for the "Toutes" (all sections) picker entry. */
    public const ALL_SECTIONS_ID = 0;

    public function __construct(
        protected Environment $twig,
        private SectionService $sectionService,
        private TrombinoscopeService $trombinoscopeService,
        private ScoutYearResolver $scoutYearResolver
    ) {
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
            ['id' => self::ALL_SECTIONS_ID, 'desk_code' => '__all__', 'name' => 'Toutes', 'branch_name' => '', 'color' => null],
        ];
        foreach ($allSections as $section) {
            $section['color'] = MemberYearService::colorForBranchSortOrder($section['branch_sort_order']);
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

        $sectionBlocks = [];
        foreach ($sectionsToRender as $section) {
            $data = $this->trombinoscopeService->getSectionStaff((int) $section['id'], $effectiveYear->id);
            $sectionBlocks[] = [
                'section' => $section,
                'lead' => $data['lead'],
                'staff' => $data['staff'],
            ];
        }

        return $this->render('@trombinoscope/index.html.twig', [
            'picker_sections' => $pickerSections,
            'selected_id' => $selectedId,
            'section_blocks' => $sectionBlocks,
        ]);
    }
}
