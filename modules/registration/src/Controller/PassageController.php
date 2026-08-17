<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
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
 * "Passage" (module spec iteration 6, Espace chefs d'U, role_min: admin):
 * splits arriving families between sections. Deliberately NOT scoped by
 * section (unlike "Départs") — spreading arrivals across sections
 * requires seeing the whole unit at once, which is why this lives at the
 * chef d'unité level rather than the same espace_chefs floor as Départs.
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
        $branchChanges = $this->passageService->autoAssignSingleOptionDestinations($branchChanges, (int) $targetYear['id']);

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
     * JSON in, JSON out — same contract as Controller\DeparturesController
     * ::update(), the module's other in-place editor: the page saves
     * without reloading, so it never answers with a redirect + flash the
     * way the fiche (a full page submit) does.
     *
     * @param array<string, string> $params
     */
    public function saveIntendedSection(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (!CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Session expirée.'], 403);
        }

        $requestId = (int) ($params['id'] ?? 0);
        $registrationRequest = $requestId > 0 ? $this->requestRepository->findById($requestId) : null;
        if ($registrationRequest === null) {
            return $this->json(['success' => false, 'error' => 'Demande introuvable.'], 404);
        }

        $submitted = (int) ($data['intended_section_id'] ?? 0);
        // 0 ("— Non défini —") clears the field, exactly like the fiche's
        // own picker; anything else must belong to the request's own slot
        // branch or it is treated as "no section" rather than trusted.
        $sectionId = $submitted > 0 && $this->sectionBelongsToRequestSlot($registrationRequest, $submitted) ? $submitted : null;

        $this->requestRepository->updateIntendedSection($registrationRequest->id, $sectionId);

        return $this->json(['success' => true, 'intended_section_id' => $sectionId]);
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
     * A submitted 0 ("— Non défini —") clears the destination instead of
     * being refused as an invalid selection: without it a pick — including
     * one made automatically by Service\PassageService::
     * autoAssignSingleOptionDestinations() — could never be taken back.
     *
     * @param array<string, string> $params
     */
    public function saveDestination(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (!CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Session expirée.'], 403);
        }

        $memberId = (int) ($params['id'] ?? 0);
        $submittedSectionId = (int) ($data['destination_section_id'] ?? 0);
        if ($memberId <= 0) {
            return $this->json(['success' => false, 'error' => 'Membre introuvable.'], 404);
        }

        [$publicYear, $targetYear] = $this->resolveYears();

        if ($submittedSectionId === 0) {
            $this->transferRepository->clearDestination($memberId, (int) $targetYear['id']);

            return $this->json(['success' => true, 'destination_section_id' => null]);
        }

        $allowedSectionIds = $this->arrivalSectionIdsForMember($memberId, (int) $publicYear['id'], (string) $publicYear['label']);
        if (!in_array($submittedSectionId, $allowedSectionIds, true)) {
            return $this->json(
                ['success' => false, 'error' => "Cette section n'appartient pas à la branche d'arrivée de ce membre."],
                422
            );
        }

        $this->transferRepository->setDestination($memberId, (int) $targetYear['id'], $submittedSectionId);

        return $this->json(['success' => true, 'destination_section_id' => $submittedSectionId]);
    }

    /**
     * The JSON request body as an array, or null when the body isn't a
     * JSON object at all — same shape check as Controller\
     * DeparturesController::update(), so a malformed body is refused
     * before anything reads a field out of it.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(Request $request): ?array
    {
        $data = json_decode($request->getRawBody(), true);

        return is_array($data) ? $data : null;
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
