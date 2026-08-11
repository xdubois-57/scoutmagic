<?php

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CsrfGuard;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use Twig\Environment;

/**
 * "Passage" (module spec iteration 6, Espace des chefs, role_min: chief):
 * splits arriving families between sections. Deliberately NOT scoped by
 * section (unlike "Départs") — spreading arrivals across sections
 * requires seeing the whole unit at once.
 *
 * Anchored on the PUBLIC year + 1, always — a deliberate, explicit
 * exception to Core\ScoutYear\ScoutYearResolver::getEffectiveYear(),
 * which governs every other page on the site. An admin's session preview
 * or an active staff year must NOT move this page's target: the whole
 * point of Passage is preparing for a specific, real upcoming year, not
 * whatever year a chief happens to be previewing right now. Written down
 * here, explicitly, so a future change never "fixes" this into using
 * getEffectiveYear() by mistake.
 */
class PassageController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private PassageService $passageService,
        private RegistrationRequestRepository $requestRepository,
        private SectionTransferRepository $transferRepository,
        private SectionService $sectionService,
        private AgeBracketRepository $ageBracketRepository,
        private SlotService $slotService,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * GET /passage
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        [$publicYear, $targetYear] = $this->resolveYears();

        $referenceMonthDay = $this->slotService->referenceMonthDay();

        $newRegistrations = $this->passageService->getNewRegistrations(
            (int) $targetYear['id'], (string) $targetYear['label'], $referenceMonthDay, (int) $publicYear['id']
        );
        $branchChanges = $this->passageService->getBranchChanges(
            (int) $publicYear['id'], (string) $publicYear['label'], (int) $targetYear['id']
        );

        return $this->render('@registration/passage.html.twig', [
            'target_year_label' => $targetYear['label'],
            'current_year_label' => $publicYear['label'],
            'new_registrations' => $newRegistrations,
            'branch_changes' => $branchChanges,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /passage/inscription/{id}/section — the exact same field as
     * the fiche's own "section prévue" (module spec: "une seule donnée,
     * deux surfaces"), restricted to the request's own slot branch just
     * like Controller\RegistrationRequestController::saveIntendedSection().
     *
     * @param array<string, string> $params
     */
    public function saveIntendedSection(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/passage');
        }

        $requestId = (int) ($params['id'] ?? 0);
        $registrationRequest = $requestId > 0 ? $this->requestRepository->findById($requestId) : null;
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }

        $submitted = (int) $request->getBody('intended_section_id', '0');
        $sectionId = $submitted > 0 && $this->sectionBelongsToRequestSlot($registrationRequest, $submitted) ? $submitted : null;

        $this->requestRepository->updateIntendedSection($registrationRequest->id, $sectionId);
        FlashMessage::set('success', 'Section prévue mise à jour.');

        return $this->redirect('/passage');
    }

    /**
     * POST /passage/membre/{id}/destination — the destination section for
     * a member changing branch. $id is the member's own permanent id
     * (never a member_year id — module spec: this is keyed on member_id
     * so it survives regardless of exactly when during the transition it
     * was picked). Re-derives the arrival branch server-side rather than
     * trusting the submitted section directly, so a request can never
     * assign a destination outside the member's own arrival branch.
     *
     * @param array<string, string> $params
     */
    public function saveDestination(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/passage');
        }

        $memberId = (int) ($params['id'] ?? 0);
        $submittedSectionId = (int) $request->getBody('destination_section_id', '0');
        if ($memberId <= 0 || $submittedSectionId <= 0) {
            FlashMessage::set('error', 'Sélection invalide.');
            return $this->redirect('/passage');
        }

        [$publicYear, $targetYear] = $this->resolveYears();

        $allowedSectionIds = $this->arrivalSectionIdsForMember($memberId, (int) $publicYear['id'], (string) $publicYear['label']);
        if (!in_array($submittedSectionId, $allowedSectionIds, true)) {
            FlashMessage::set('error', "Cette section n'appartient pas à la branche d'arrivée de ce membre.");
            return $this->redirect('/passage');
        }

        $this->transferRepository->setDestination($memberId, (int) $targetYear['id'], $submittedSectionId);
        FlashMessage::set('success', 'Destination enregistrée.');

        return $this->redirect('/passage');
    }

    /**
     * @return array{0: array{id: int, label: string, start_date: string, end_date: string}, 1: array{id: int, label: string, start_date: string, end_date: string}}
     */
    private function resolveYears(): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetLabel = ScoutYearService::nextLabel($publicYear['label']);
        $targetYearId = $this->scoutYearService->ensureYear($targetLabel);
        $targetYear = $this->scoutYearService->findById($targetYearId) ?? $publicYear;

        return [$publicYear, $targetYear];
    }

    private function sectionBelongsToRequestSlot(\Modules\Registration\Repository\RegistrationRequest $registrationRequest, int $sectionId): bool
    {
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($this->targetYearLabelFor($registrationRequest)),
            $this->slotService->referenceMonthDay()
        );
        $slot = SlotMath::slotForBirthDate($brackets, $registrationRequest->birthDate, $referenceYear);
        if ($slot === null) {
            return false;
        }

        foreach ($this->sectionService->getAllWithBranches(true) as $section) {
            if ($section['id'] === $sectionId && $section['age_branch_id'] === $slot['age_branch_id']) {
                return true;
            }
        }

        return false;
    }

    private function targetYearLabelFor(\Modules\Registration\Repository\RegistrationRequest $registrationRequest): string
    {
        $year = $this->scoutYearService->findById($registrationRequest->scoutYearId);

        return $year['label'] ?? '';
    }

    /**
     * @return array<int>
     */
    private function arrivalSectionIdsForMember(int $memberId, int $publicYearId, string $publicYearLabel): array
    {
        foreach ($this->passageService->getBranchChanges($publicYearId, $publicYearLabel, 0) as $group) {
            foreach ($group['members'] as $member) {
                if ($member['member_id'] === $memberId) {
                    return array_column($member['destination_options'], 'id');
                }
            }
        }

        return [];
    }
}
