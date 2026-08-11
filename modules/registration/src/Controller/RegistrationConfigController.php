<?php

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CsrfGuard;
use Core\View\EditableContentService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use Twig\Environment;

/**
 * "Configuration > Inscriptions" — the module spec's "page de gestion":
 * capacities/year code (iteration 4) plus, since iteration 5, the whole
 * staff-facing overview of a scout year's requests — capacity verification
 * table, request list (searchable/filterable), the two encarts ("non
 * rapprochées" / "non clôturées"), and the two bulk actions. Age brackets
 * are read-only here (federation-fixed, see AgeBracketRepository) — there
 * is nothing to configure about them, only capacities per branch/year.
 * Row-level actions on a single request (accept/refuse/link/emails/notes...)
 * live in Controller\RegistrationRequestController's fiche instead.
 */
class RegistrationConfigController extends AbstractController
{
    /**
     * No federation branch spans more than this many years — bounds the
     * capacity grid so the template can render a fixed number of columns
     * without JS row add/remove (module spec: "minimal" admin screen).
     */
    private const MAX_YEARS_IN_BRANCH = 4;

    /**
     * Content key for the page's own editable explanatory block (module
     * spec Deliverable 1's "bloc de texte modifiable") — deliberately
     * distinct from the hardcoded state legend below it, which is never
     * editable since it documents behavior the code itself enforces.
     */
    private const NOTICE_CONTENT_KEY = 'registration_management_notice';

    public function __construct(
        protected Environment $twig,
        private AgeBracketRepository $ageBracketRepository,
        private SlotCapacityRepository $slotCapacityRepository,
        private RegistrationYearCodeRepository $yearCodeRepository,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private RegistrationRequestRepository $requestRepository,
        private SlotService $slotService,
        private SectionService $sectionService,
        private EditableContentService $editableContentService,
        private RequestStatusService $statusService,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/inscriptions
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $requestedYearId = (int) $request->getQuery('year', '0');
        $statusFilter = (string) $request->getQuery('status', '');
        $search = trim((string) $request->getQuery('q', ''));

        return $this->render('@registration/config.html.twig', $this->buildPageContext(
            $requestedYearId > 0 ? $requestedYearId : null,
            $statusFilter !== '' ? $statusFilter : null,
            $search !== '' ? $search : null
        ));
    }

    /**
     * POST /config/inscriptions — saves the capacity grid (module spec:
     * minimal screen). Age brackets (entry age/duration per branch) are no
     * longer configured here — Repository\AgeBracketRepository resolves
     * them directly from Core\Member\MemberYearService::BRANCHES, the same
     * central federation age ranges member_stats already uses, so there is
     * nothing to save for them.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/inscriptions');
        }

        $capacities = $request->getBody('capacity', []);

        foreach ($this->ageBracketRepository->findAllOrdered() as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $capacity = (int) ($capacities[$bracket->ageBranchId][$yearInBranch] ?? 0);
                $this->slotCapacityRepository->upsert($bracket->ageBranchId, $yearInBranch, max(0, $capacity));
            }
        }

        FlashMessage::set('success', 'Configuration enregistrée.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * POST /config/inscriptions/code/regenerate — new active code for the
     * current public scout year.
     *
     * @param array<string, string> $params
     */
    public function regenerateCode(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/inscriptions');
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $this->yearCodeRepository->regenerate((int) $publicYear['id']);

        FlashMessage::set('success', 'Nouveau code généré.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * POST /config/inscriptions/code/deactivate — closes in-year
     * registration for the current public scout year.
     *
     * @param array<string, string> $params
     */
    public function deactivateCode(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/inscriptions');
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $this->yearCodeRepository->deactivate((int) $publicYear['id']);

        FlashMessage::set('success', 'Code désactivé.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * POST /config/inscriptions/bulk/refuse — refuses every non-final
     * request of the selected year in one action. Never available for a
     * past (consultation-only) year.
     *
     * @param array<string, string> $params
     */
    public function bulkRefuse(Request $request, array $params): Response
    {
        return $this->bulkTransition($request, 'refuse');
    }

    /**
     * POST /config/inscriptions/bulk/withdraw — same as bulkRefuse() but
     * marks every non-final request "retirée" instead.
     *
     * @param array<string, string> $params
     */
    public function bulkWithdraw(Request $request, array $params): Response
    {
        return $this->bulkTransition($request, 'withdraw');
    }

    private function bulkTransition(Request $request, string $action): Response
    {
        $yearId = (int) $request->getBody('scout_year_id', '0');

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/inscriptions?year=' . $yearId);
        }

        if ($yearId <= 0 || $this->isPastYear($yearId)) {
            FlashMessage::set('error', 'Action impossible : année invalide ou passée (consultation seule).');
            return $this->redirect('/config/inscriptions?year=' . $yearId);
        }

        $count = 0;
        foreach ($this->requestRepository->findNonFinalForYear($yearId) as $registrationRequest) {
            try {
                if ($action === 'refuse') {
                    $this->statusService->refuse($registrationRequest);
                } else {
                    $this->statusService->withdraw($registrationRequest);
                }
                $count++;
            } catch (RegistrationException) {
                // Left the non-final set between the read above and here — skip it.
            }
        }

        $this->journalService->log(
            'registration',
            'registration_bulk_action',
            'info',
            $action === 'refuse'
                ? 'Refus en masse des demandes non clôturées'
                : 'Retrait en masse des demandes non clôturées',
            ['scout_year_id' => $yearId, 'count' => $count]
        );

        FlashMessage::set('success', $count . ' demande(s) traitée(s).');
        return $this->redirect('/config/inscriptions?year=' . $yearId);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPageContext(?int $requestedYearId, ?string $statusFilter, ?string $search): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $capacities = $this->slotCapacityRepository->findAllAsMap();

        $years = $this->resolveSelectableYears($publicYear);
        $selectedYear = $this->pickSelectedYear($years['selectable'], $requestedYearId) ?? $years['target'];
        $selectedYearId = (int) $selectedYear['id'];
        $isPastYear = $selectedYear['start_date'] < $publicYear['start_date'];

        $capacityBreakdown = $this->slotService->capacityBreakdownForYear(
            $selectedYearId,
            (string) $selectedYear['label'],
            (int) $publicYear['id']
        );

        $sectionLabels = $this->sectionLabels();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel((string) $selectedYear['label']),
            $this->slotService->referenceMonthDay()
        );

        $requests = $this->requestRepository->findAllForYear($selectedYearId);
        $requestRows = $this->buildRequestRows($requests, $brackets, $referenceYear, $sectionLabels);
        $requestRows = $this->filterRows($requestRows, $statusFilter, $search);

        return [
            'brackets' => $brackets,
            'capacities' => $capacities,
            'max_years_in_branch' => self::MAX_YEARS_IN_BRANCH,
            'csrf_token' => CsrfGuard::generateToken(),
            'public_year_label' => $publicYear['label'],
            'year_code' => $this->yearCodeRepository->findForYear((int) $publicYear['id']),

            'notice_content' => $this->editableContentService->get(self::NOTICE_CONTENT_KEY, ''),
            'notice_content_key' => self::NOTICE_CONTENT_KEY,

            'selectable_years' => $years['selectable'],
            'target_year_id' => (int) $years['target']['id'],
            'current_year_id' => (int) $publicYear['id'],
            'selected_year' => $selectedYear,
            'is_past_year' => $isPastYear,

            'capacity_breakdown' => $capacityBreakdown,

            'request_rows' => $requestRows,
            'status_filter' => $statusFilter,
            'search' => $search,

            'unreconciled' => $this->requestRepository->findUnreconciledAcceptedForYear($selectedYearId),
            'non_final_count' => count($this->requestRepository->findNonFinalForYear($selectedYearId)),
        ];
    }

    /**
     * @param array{id: int, label: string, start_date: string, end_date: string} $publicYear
     * @return array{
     *   public: array{id: int, label: string, start_date: string, end_date: string},
     *   target: array{id: int, label: string, start_date: string, end_date: string},
     *   selectable: array<int, array{id: int, label: string, start_date: string, end_date: string}>
     * }
     */
    private function resolveSelectableYears(array $publicYear): array
    {
        $targetLabel = ScoutYearService::nextLabel($publicYear['label']);
        $targetYearId = $this->scoutYearService->ensureYear($targetLabel);
        $targetYear = $this->scoutYearService->findById($targetYearId) ?? $publicYear;

        // Consultation-only past years still in the database, plus the
        // current and target years — never a year beyond the target
        // (module spec: "jamais future au-delà de N+1").
        $selectable = array_values(array_filter(
            $this->scoutYearService->getAll(),
            static fn(array $year) => $year['start_date'] <= $targetYear['start_date']
        ));
        usort($selectable, static fn(array $a, array $b) => $b['start_date'] <=> $a['start_date']);

        return ['public' => $publicYear, 'target' => $targetYear, 'selectable' => $selectable];
    }

    /**
     * @param array<int, array{id: int, label: string, start_date: string, end_date: string}> $selectableYears
     * @return array{id: int, label: string, start_date: string, end_date: string}|null
     */
    private function pickSelectedYear(array $selectableYears, ?int $requestedYearId): ?array
    {
        if ($requestedYearId === null) {
            return null;
        }
        foreach ($selectableYears as $year) {
            if ($year['id'] === $requestedYearId) {
                return $year;
            }
        }

        return null;
    }

    private function isPastYear(int $yearId): bool
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $year = $this->scoutYearService->findById($yearId);

        return $year !== null && $year['start_date'] < $publicYear['start_date'];
    }

    /**
     * @return array<int, string> section id => display label
     */
    private function sectionLabels(): array
    {
        $labels = [];
        foreach ($this->sectionService->getAllWithBranches(true) as $section) {
            $labels[$section['id']] = $section['name'] ?? $section['desk_code'];
        }

        return $labels;
    }

    /**
     * @param array<\Modules\Registration\Repository\RegistrationRequest> $requests
     * @param array<\Modules\Registration\Repository\AgeBracket> $brackets
     * @param array<int, string> $sectionLabels
     * @return array<int, array<string, mixed>>
     */
    private function buildRequestRows(array $requests, array $brackets, int $referenceYear, array $sectionLabels): array
    {
        $rows = [];
        foreach ($requests as $registrationRequest) {
            $slot = SlotMath::slotForBirthDate($brackets, $registrationRequest->birthDate, $referenceYear);

            $rows[] = [
                'request' => $registrationRequest,
                'slot_label' => $this->slotLabel($brackets, $slot),
                'intended_section_label' => $registrationRequest->intendedSectionId !== null
                    ? ($sectionLabels[$registrationRequest->intendedSectionId] ?? '—')
                    : null,
                'sibling_count' => count($this->requestRepository->findSiblingMemberIds($registrationRequest->id)),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterRows(array $rows, ?string $statusFilter, ?string $search): array
    {
        if ($statusFilter !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row) => $row['request']->status === $statusFilter
            ));
        }

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($needle) {
                $registrationRequest = $row['request'];
                $haystack = mb_strtolower(
                    $registrationRequest->childFirstName . ' ' . $registrationRequest->childLastName
                    . ' ' . $registrationRequest->parentName
                );

                return mb_stripos($haystack, $needle) !== false;
            }));
        }

        return $rows;
    }

    /**
     * @param array<\Modules\Registration\Repository\AgeBracket> $brackets
     * @param array{age_branch_id: int, year_in_branch: int}|null $slot
     */
    private function slotLabel(array $brackets, ?array $slot): string
    {
        if ($slot === null) {
            return 'Non déterminé';
        }
        foreach ($brackets as $bracket) {
            if ($bracket->ageBranchId === $slot['age_branch_id']) {
                return $bracket->branchLabel . ' — ' . $slot['year_in_branch'] . 'ᵉ année';
            }
        }

        return 'Non déterminé';
    }
}
