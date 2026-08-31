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
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\PassageCommentReviewService;
use Modules\Registration\Service\PassagePlanningService;
use Modules\Registration\Service\PassageStatisticsService;
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
        private ScoutYearService $scoutYearService,
        private PassageStatisticsService $statisticsService,
        private PassagePlanningService $planningService,
        private \Modules\Registration\Repository\PassageNoteRepository $passageNoteRepository,
        private \Modules\Registration\Repository\ReenrollmentRepository $reenrollmentRepository,
        private ?PassageCommentReviewService $commentReview = null
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
        // Read-only: assigning the obvious single-option destinations is
        // Task\AutoAssignPassageHandler's job now. Doing it here meant a
        // plain GET wrote to the database on every display of the page.
        $branchChanges = $this->passageService->getBranchChanges(
            (int) $publicYear['id'], (string) $publicYear['label'], (int) $targetYear['id']
        );

        // IT-17 — what the families answered, beside the decision each
        // line is about. The arrival branch travels with each row because
        // it is what a typed « Léo » is looked for among: the sections
        // already computed for the row ARE that branch's, so nothing is
        // recomputed to know it.
        $branchByRequest = [];
        foreach ($newRegistrations as $row) {
            $branchByRequest[$row['request']->id] = $row['slot']['age_branch_id'] ?? null;
        }
        $branchByMember = [];
        foreach ($branchChanges as $group) {
            foreach ($group['members'] as $member) {
                $branchByMember[$member['member_id']] = $member['destination_options'][0]['age_branch_id'] ?? null;
            }
        }

        return $this->render('@registration/passage.html.twig', [
            'target_year_label' => $targetYear['label'],
            'current_year_label' => $publicYear['label'],
            'new_registrations' => $newRegistrations,
            'branch_changes' => $branchChanges,
            'planning_requests' => $this->planningService->forRequests(
                $branchByRequest,
                (int) $publicYear['id'],
                (int) $targetYear['id']
            ),
            'planning_members' => $this->planningService->forMembers(
                $branchByMember,
                (int) $publicYear['id'],
                (int) $targetYear['id']
            ),
            'statistics' => $this->statisticsService->forTargetYear((int) $targetYear['id']),
            // IT-17 — the optional AI re-reading. Absent module, absent
            // provider, absent block: the page must not mention a feature
            // this unit does not have (ARCHITECTURE.md §7.5).
            'ai_available' => $this->commentReview !== null && $this->commentReview->isAvailable(),
            'ai_pending' => $this->commentReview !== null ? $this->commentReview->pendingCount((int) $targetYear['id']) : 0,
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
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
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

        return $this->json([
            'success' => true,
            'intended_section_id' => $sectionId,
            'statistics_html' => $this->renderStatistics(),
        ]);
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
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $memberId = (int) ($params['id'] ?? 0);
        $submittedSectionId = (int) ($data['destination_section_id'] ?? 0);
        if ($memberId <= 0) {
            return $this->json(['success' => false, 'error' => 'Membre introuvable.'], 404);
        }

        [$publicYear, $targetYear] = $this->resolveYears();

        if ($submittedSectionId === 0) {
            $this->transferRepository->clearDestination($memberId, (int) $targetYear['id']);

            return $this->json([
                'success' => true,
                'destination_section_id' => null,
                'statistics_html' => $this->renderStatistics((int) $targetYear['id']),
            ]);
        }

        $allowedSectionIds = $this->arrivalSectionIdsForMember($memberId, (int) $publicYear['id'], (string) $publicYear['label']);
        if (!in_array($submittedSectionId, $allowedSectionIds, true)) {
            return $this->json(
                ['success' => false, 'error' => "Cette section n'appartient pas à la branche d'arrivée de ce membre."],
                422
            );
        }

        $this->transferRepository->setDestination($memberId, (int) $targetYear['id'], $submittedSectionId);

        return $this->json([
            'success' => true,
            'destination_section_id' => $submittedSectionId,
            'statistics_html' => $this->renderStatistics((int) $targetYear['id']),
        ]);
    }

    /**
     * POST /passage/membre/{id}/souhait — the section the STAFF read the
     * family as wanting (roadmap IT-17).
     *
     * A value of the staff's own, in a table of their own, never a write
     * into the family's answer: every reader of registration_reenrollments
     * takes a row there as « this family has answered », so a chief
     * recording a wish for a silent family would take them out of the
     * reminder list by typing about them. It is also why a chief may fill
     * this in for a family who never answered at all, which is exactly
     * what the roadmap asks for.
     *
     * Not a destination: the destination is the decision, saved by
     * saveDestination() above and constrained to the arrival branch. This
     * is the wish that informs it.
     *
     * @param array<string, string> $params
     */
    public function savePreferredSection(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $memberId = (int) ($params['id'] ?? 0);
        if ($memberId <= 0) {
            return $this->json(['success' => false, 'error' => 'Membre introuvable.'], 404);
        }

        [$publicYear, $targetYear] = $this->resolveYears();
        $sectionId = (int) ($data['preferred_section_id'] ?? 0);

        if ($sectionId !== 0) {
            $allowed = $this->arrivalSectionIdsForMember($memberId, (int) $publicYear['id'], (string) $publicYear['label']);
            if (!in_array($sectionId, $allowed, true)) {
                return $this->json(
                    ['success' => false, 'error' => "Cette section n'appartient pas à la branche d'arrivée de ce membre."],
                    422
                );
            }
        }

        $this->passageNoteRepository->setPreferredSection(
            $memberId,
            (int) $targetYear['id'],
            $sectionId !== 0 ? $sectionId : null,
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'preferred_section_id' => $sectionId !== 0 ? $sectionId : null]);
    }

    /**
     * POST /passage/membre/{id}/note — the staff's internal note.
     *
     * A write of its own, separate from the section above it, for the same
     * reason « Départs » splits its checkbox from its comment: the page
     * saves each field as it is edited, and one save must never clobber
     * the other's field.
     *
     * Never journaled and never returned in anything a family receives:
     * this is a note about a child, written by the staff, for the staff
     * (SECURITY.md §11 — count, do not name).
     *
     * @param array<string, string> $params
     */
    public function saveStaffNote(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $memberId = (int) ($params['id'] ?? 0);
        if ($memberId <= 0) {
            return $this->json(['success' => false, 'error' => 'Membre introuvable.'], 404);
        }

        [, $targetYear] = $this->resolveYears();

        $this->passageNoteRepository->setStaffNote(
            $memberId,
            (int) $targetYear['id'],
            (string) ($data['note'] ?? ''),
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /passage/souhait/{id}/rattacher — a chief deciding which of
     * several candidates a typed name meant.
     *
     * The raw name is untouched: what a parent wrote is what a parent
     * wrote, and this records a second fact beside it. The chosen member
     * is re-checked against the wish's OWN candidate list rather than
     * trusted from the body — a member id in a request is not a boundary,
     * and a forged one would attach a child nobody named.
     *
     * @param array<string, string> $params
     */
    public function resolveWish(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $wishId = (int) ($params['id'] ?? 0);
        $chosen = (int) ($data['matched_member_id'] ?? 0);
        if ($wishId <= 0 || $chosen <= 0) {
            return $this->json(['success' => false, 'error' => 'Souhait introuvable.'], 404);
        }

        $owner = $this->reenrollmentRepository->findWishOwner($wishId);
        if ($owner === null) {
            return $this->json(['success' => false, 'error' => 'Souhait introuvable.'], 404);
        }

        [$publicYear, $targetYear] = $this->resolveYears();
        if ($owner['scout_year_id'] !== (int) $targetYear['id']) {
            return $this->json(['success' => false, 'error' => "Ce souhait ne concerne pas l'année préparée."], 422);
        }

        $label = $this->planningService->resolveWish(
            $wishId,
            $chosen,
            (int) $publicYear['id'],
            (int) $targetYear['id'],
            $this->arrivalBranchIdForMember($owner['member_id'], (int) $publicYear['id'], (string) $publicYear['label'])
        );

        if ($label === null) {
            return $this->json(
                ['success' => false, 'error' => 'Ce membre ne fait pas partie des correspondances possibles.'],
                422
            );
        }

        return $this->json(['success' => true, 'label' => $label]);
    }

    /**
     * POST /passage/relire-commentaires — a chief asking the model to read
     * the free comments that have not been read yet (roadmap IT-17).
     *
     * A gesture, never a page load: a family comment sent to an external
     * provider is a transmission of personal data, and it happens because
     * somebody asked for it. Idempotent by construction — a comment whose
     * hash is already on file is skipped, so a double click costs one round
     * and then nothing.
     *
     * @param array<string, string> $params
     */
    public function reviewComments(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        if ($this->commentReview === null || !$this->commentReview->isAvailable()) {
            return $this->json(['success' => false, 'error' => "La relecture par IA n'est pas disponible."], 422);
        }

        [, $targetYear] = $this->resolveYears();
        $reviewed = $this->commentReview->reviewPending((int) $targetYear['id']);

        return $this->json(['success' => true, 'reviewed' => $reviewed]);
    }

    /**
     * POST /passage/membre/{id}/ia — the chief validating, or taking back,
     * what the model read into a family's comment.
     *
     * Until this is set, the suggestion is a sentence on a screen and
     * nothing else: the optimiser (IT-18) reads only confirmed ones. There
     * is deliberately no way to EDIT the suggestion here — a chief who
     * disagrees writes their own internal note, which is theirs, rather
     * than rewriting a machine's reading into something that then looks
     * like one.
     *
     * @param array<string, string> $params
     */
    public function confirmAiSuggestion(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $memberId = (int) ($params['id'] ?? 0);
        if ($memberId <= 0) {
            return $this->json(['success' => false, 'error' => 'Membre introuvable.'], 404);
        }

        [, $targetYear] = $this->resolveYears();
        $this->passageNoteRepository->confirmAiSuggestion(
            $memberId,
            (int) $targetYear['id'],
            ($data['confirmed'] ?? false) === true
        );

        return $this->json(['success' => true]);
    }

    /**
     * The branch a member is heading into — the population a typed name is
     * looked for among. Read off the same arrivalSectionsForMember() the
     * destination picker is built from, so the candidate list a chief is
     * offered can never be drawn from a different branch than the one the
     * page put them in.
     */
    private function arrivalBranchIdForMember(int $memberId, int $publicYearId, string $publicYearLabel): ?int
    {
        foreach ($this->passageService->arrivalSectionsForMember($memberId, $publicYearId, $publicYearLabel) as $section) {
            return (int) $section['age_branch_id'];
        }

        return null;
    }

    /**
     * The statistics box, re-rendered, for a save response to carry back.
     *
     * **In the save's own answer, never behind an endpoint of its own**
     * (roadmap IT-12): one round trip, and no question of when a cached
     * box goes stale — the numbers a chief sees are the numbers as of the
     * decision they just made.
     *
     * Rendered here rather than reassembled in the browser so the box has
     * ONE template. A second renderer in JavaScript would be a second
     * place for « 3 G · 2 F » to be formatted, and the two would drift.
     *
     * Computed once per request, over the whole unit — never once per row.
     */
    private function renderStatistics(?int $targetYearId = null): string
    {
        if ($targetYearId === null) {
            [, $targetYear] = $this->resolveYears();
            $targetYearId = (int) $targetYear['id'];
        }

        return $this->renderToString('@registration/_passage_statistics.html.twig', [
            'statistics' => $this->statisticsService->forTargetYear($targetYearId),
        ]);
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
     * Delegates to Service\PassageService::arrivalSectionsForMember(),
     * which resolves one single member's arrival branch. This used to run
     * getBranchChanges() in full — decrypting every animé of the year and
     * resolving every household — just to read one row's options back out,
     * on every save.
     *
     * @return array<int>
     */
    private function arrivalSectionIdsForMember(int $memberId, int $publicYearId, string $publicYearLabel): array
    {
        return array_column(
            $this->passageService->arrivalSectionsForMember($memberId, $publicYearId, $publicYearLabel),
            'id'
        );
    }
}
