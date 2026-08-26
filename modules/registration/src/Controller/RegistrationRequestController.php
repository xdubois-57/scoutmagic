<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\FeeCategoryRepository;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Member\FeeEstimationService;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CsrfGuard;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\MigrationService;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use Twig\Environment;

/**
 * "Fiche d'une demande" (module spec Deliverable 2): the fields Desk itself
 * asks for, in the exact order Desk encodes them, everything parent-
 * submitted strictly read-only except "section prévue" (staff-decided,
 * scoped to the slot's own branch, never shown to a parent), "tarif"
 * (a suggestion, always overridable) and the internal notes (encrypted,
 * never journaled). Status transitions, email sending, and manual Desk-
 * tiers-number linking all live here too — see module.json's own
 * /config/inscriptions/demandes/{id}/* route family.
 */
class RegistrationRequestController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private RegistrationRequestRepository $requestRepository,
        private AgeBracketRepository $ageBracketRepository,
        private SectionService $sectionService,
        private FeeCategoryRepository $feeCategoryRepository,
        private FeeEstimationService $feeEstimationService,
        private RequestStatusService $statusService,
        private RequestEmailService $emailService,
        private MigrationService $migrationService,
        private MemberRepository $memberRepository,
        private MemberYearRepository $memberYearRepository,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private SlotService $slotService,
        private MemberService $memberService
    ) {
    }

    /**
     * GET /config/inscriptions/demandes/{id}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $registrationRequest = $this->findOrNull($params);
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('@registration/request_detail.html.twig', $this->buildContext($registrationRequest));
    }

    /**
     * POST /config/inscriptions/demandes/{id}/accept
     *
     * @param array<string, string> $params
     */
    public function accept(Request $request, array $params): Response
    {
        return $this->applyTransition($request, $params, fn($r) => $this->statusService->accept($r));
    }

    /**
     * POST /config/inscriptions/demandes/{id}/refuse
     *
     * @param array<string, string> $params
     */
    public function refuse(Request $request, array $params): Response
    {
        return $this->applyTransition($request, $params, fn($r) => $this->statusService->refuse($r));
    }

    /**
     * POST /config/inscriptions/demandes/{id}/withdraw
     *
     * @param array<string, string> $params
     */
    public function withdraw(Request $request, array $params): Response
    {
        return $this->applyTransition($request, $params, fn($r) => $this->statusService->withdraw($r));
    }

    /**
     * POST /config/inscriptions/demandes/{id}/revert
     *
     * @param array<string, string> $params
     */
    public function revert(Request $request, array $params): Response
    {
        return $this->applyTransition($request, $params, fn($r) => $this->statusService->revertToPending($r));
    }

    /**
     * POST /config/inscriptions/demandes/{id}/intended-section — restricted
     * to a section belonging to the slot (birth date-derived branch) the
     * request itself falls into, exactly like the public form's own
     * "desired section" validation (never trusts the submitted id blindly).
     *
     * @param array<string, string> $params
     */
    public function saveIntendedSection(Request $request, array $params): Response
    {
        $registrationRequest = $this->findOrNull($params);
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }
        if (($guard = $this->guardCsrf($request, '/config/inscriptions/demandes/' . $registrationRequest->id)) !== null) {
            return $guard;
        }

        $submitted = (int) $request->getBody('intended_section_id', '0');
        $sectionId = $submitted > 0 && $this->sectionBelongsToRequestSlot($registrationRequest, $submitted) ? $submitted : null;

        $this->requestRepository->updateIntendedSection($registrationRequest->id, $sectionId);
        FlashMessage::set('success', 'Section prévue mise à jour.');

        return $this->redirectToFiche($registrationRequest->id);
    }

    /**
     * POST /config/inscriptions/demandes/{id}/fee-category
     *
     * @param array<string, string> $params
     */
    public function saveFeeCategory(Request $request, array $params): Response
    {
        $registrationRequest = $this->findOrNull($params);
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }
        if (($guard = $this->guardCsrf($request, '/config/inscriptions/demandes/' . $registrationRequest->id)) !== null) {
            return $guard;
        }

        $submitted = (int) $request->getBody('fee_category_id', '0');
        $feeCategoryId = null;
        if ($submitted > 0) {
            foreach ($this->feeCategoryRepository->findAll() as $category) {
                if ($category['id'] === $submitted) {
                    $feeCategoryId = $submitted;
                    break;
                }
            }
        }

        $this->requestRepository->updateFeeCategory($registrationRequest->id, $feeCategoryId);
        FlashMessage::set('success', 'Tarif mis à jour.');

        return $this->redirectToFiche($registrationRequest->id);
    }

    /**
     * POST /config/inscriptions/demandes/{id}/notes — encrypted at rest,
     * never journaled (module spec's own stricter rule for this one field:
     * only the fact that a save happened is worth logging, never a diff of
     * free-form staff text).
     *
     * @param array<string, string> $params
     */
    public function saveNotes(Request $request, array $params): Response
    {
        $registrationRequest = $this->findOrNull($params);
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }
        if (($guard = $this->guardCsrf($request, '/config/inscriptions/demandes/' . $registrationRequest->id)) !== null) {
            return $guard;
        }

        $notes = trim((string) $request->getBody('internal_notes', ''));
        $this->requestRepository->updateInternalNotes($registrationRequest->id, $notes !== '' ? $notes : null);
        FlashMessage::set('success', 'Notes internes enregistrées.');

        return $this->redirectToFiche($registrationRequest->id);
    }

    /**
     * POST /config/inscriptions/demandes/{id}/link — manual rapprochement
     * by Desk "numéro de tiers" (members.desk_id). Refuses an unknown
     * number and one already linked to another request; on success this
     * goes through the exact same Service\MigrationService::migrate() an
     * automatic reconciliation match would (module spec: "un seul chemin
     * de code, pas deux").
     *
     * @param array<string, string> $params
     */
    public function link(Request $request, array $params): Response
    {
        $registrationRequest = $this->findOrNull($params);
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }
        if (($guard = $this->guardCsrf($request, '/config/inscriptions/demandes/' . $registrationRequest->id)) !== null) {
            return $guard;
        }

        $deskId = trim((string) $request->getBody('desk_id', ''));
        $member = $deskId !== '' ? $this->memberRepository->findByDeskId($deskId) : null;
        if ($member === null) {
            FlashMessage::set('error', "Aucun membre importé de Desk ne correspond à ce numéro de tiers.");
            return $this->redirectToFiche($registrationRequest->id);
        }

        try {
            $this->migrationService->migrate($registrationRequest->id, $member['id']);
            FlashMessage::set('success', 'Demande rapprochée du membre et encodée.');
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirectToFiche($registrationRequest->id);
    }

    /**
     * POST /config/inscriptions/demandes/{id}/send-accepted-email
     *
     * @param array<string, string> $params
     */
    public function sendAcceptedEmail(Request $request, array $params): Response
    {
        return $this->sendEmail($request, $params, 'sendAccepted');
    }

    /**
     * POST /config/inscriptions/demandes/{id}/send-refused-email
     *
     * @param array<string, string> $params
     */
    public function sendRefusedEmail(Request $request, array $params): Response
    {
        return $this->sendEmail($request, $params, 'sendRefused');
    }

    /**
     * @param array<string, string> $params
     */
    private function sendEmail(Request $request, array $params, string $method): Response
    {
        $registrationRequest = $this->findOrNull($params);
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }
        if (($guard = $this->guardCsrf($request, '/config/inscriptions/demandes/' . $registrationRequest->id)) !== null) {
            return $guard;
        }

        $scoutYear = $this->scoutYearService->findById($registrationRequest->scoutYearId);
        $yearLabel = $scoutYear['label'] ?? '';

        try {
            $this->emailService->$method($registrationRequest, $yearLabel);
            FlashMessage::set('success', 'Email envoyé.');
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirectToFiche($registrationRequest->id);
    }

    /**
     * @param array<string, string> $params
     * @param callable(\Modules\Registration\Repository\RegistrationRequest): void $transition
     */
    private function applyTransition(Request $request, array $params, callable $transition): Response
    {
        $registrationRequest = $this->findOrNull($params);
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }
        if (($guard = $this->guardCsrf($request, '/config/inscriptions/demandes/' . $registrationRequest->id)) !== null) {
            return $guard;
        }

        try {
            $transition($registrationRequest);
            FlashMessage::set('success', 'Statut mis à jour.');
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirectToFiche($registrationRequest->id);
    }

    /**
     * @param array<string, string> $params
     */
    private function findOrNull(array $params): ?\Modules\Registration\Repository\RegistrationRequest
    {
        $id = (int) ($params['id'] ?? 0);
        return $id > 0 ? $this->requestRepository->findById($id) : null;
    }

    private function redirectToFiche(int $id): Response
    {
        return $this->redirect('/config/inscriptions/demandes/' . $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(\Modules\Registration\Repository\RegistrationRequest $registrationRequest): array
    {
        $scoutYear = $this->scoutYearService->findById($registrationRequest->scoutYearId);
        $scoutYearLabel = $scoutYear['label'] ?? '';

        $brackets = $this->ageBracketRepository->findAllOrdered();
        $slot = $this->requestSlot($registrationRequest, $brackets, $scoutYearLabel);

        $sections = $this->sectionService->getAllWithBranches(true);
        $sectionLabels = [];
        foreach ($sections as $section) {
            $sectionLabels[$section['id']] = $section['name'] ?? $section['desk_code'];
        }
        $sectionsInBranch = $slot !== null
            ? array_values(array_filter($sections, static fn(array $s) => $s['age_branch_id'] === $slot['age_branch_id']))
            : [];

        $feeEstimate = $this->feeEstimationService->estimate(
            $registrationRequest->street,
            $registrationRequest->number,
            null,
            $registrationRequest->postalCode,
            $registrationRequest->scoutYearId,
            $registrationRequest->id
        );

        $linkedMemberYearId = null;
        if ($registrationRequest->linkedMemberId !== null) {
            $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
            $memberYear = $this->memberYearRepository->findByMemberAndYear($registrationRequest->linkedMemberId, (int) $publicYear['id']);
            $linkedMemberYearId = $memberYear['id'] ?? null;
        }

        return [
            'r' => $registrationRequest,
            // The child's name is already decrypted on the entity — no
            // extra decryption for the breadcrumb title.
            'breadcrumb_current' => trim($registrationRequest->childFirstName . ' ' . $registrationRequest->childLastName),
            'scout_year_label' => $scoutYearLabel,
            'slot_label' => $this->slotLabel($brackets, $slot),
            'desired_section_label' => $registrationRequest->desiredSectionId !== null
                ? ($sectionLabels[$registrationRequest->desiredSectionId] ?? '—')
                : null,
            'sections_in_branch' => $sectionsInBranch,
            'fee_categories' => $this->feeCategoryRepository->findAll(),
            'fee_estimate' => $feeEstimate,
            'siblings' => $this->siblingDetails($registrationRequest, $scoutYearLabel),
            'visible_status' => $this->statusService->visibleStatus($registrationRequest),
            'accepted_email_ready' => $this->emailService->isAcceptedBodyReady(),
            'refused_email_ready' => $this->emailService->isRefusedBodyReady(),
            'linked_member_year_id' => $linkedMemberYearId,
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }

    /**
     * A declared sibling's name and where they're projected to sit for
     * THIS request's target scout year — same "never recompute an age by
     * hand, always go through MemberYearService::getEffectiveAge()" rule
     * as everywhere else (Service\SlotService::projectedHeadcountBySlot(),
     * member_stats), fed with the sibling's real birth date and
     * scout_year_offset from their current member_year row rather than a
     * hardcoded assumption. A sibling with no member_year for the current
     * public year (left the unit, data not yet imported...) still shows
     * by name if findable at all, with a placeholder section — never
     * silently dropped from the list, unlike the old bare count which
     * gave staff no way to tell who these siblings even were.
     *
     * @return array<int, array{name: string, section_label: string}>
     */
    private function siblingDetails(\Modules\Registration\Repository\RegistrationRequest $registrationRequest, string $targetScoutYearLabel): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($targetScoutYearLabel),
            $this->slotService->referenceMonthDay()
        );
        $memberYearService = new MemberYearService();

        $details = [];
        foreach ($this->requestRepository->findSiblingMemberIds($registrationRequest->id) as $memberId) {
            $profile = $this->memberService->findProfileByMemberAndYear($memberId, (int) $publicYear['id']);
            if ($profile === null) {
                $details[] = ['name' => "Membre #{$memberId}", 'section_label' => 'Section inconnue'];
                continue;
            }

            $birthYear = MemberYearService::extractBirthYear($profile->birthDate);
            $effective = $memberYearService->getEffectiveAge($birthYear, $profile->scoutYearOffset, $referenceYear);
            $sectionLabel = $effective->branchName !== null
                ? $effective->branchName . ' — ' . $effective->yearInBranch . 'ᵉ année'
                : 'Hors tranche d\'âge';

            $details[] = [
                'name' => trim($profile->firstName . ' ' . $profile->lastName),
                'section_label' => $sectionLabel,
            ];
        }

        return $details;
    }

    /**
     * @param array<\Modules\Registration\Repository\AgeBracket> $brackets
     * @return array{age_branch_id: int, year_in_branch: int}|null
     */
    private function requestSlot(\Modules\Registration\Repository\RegistrationRequest $registrationRequest, array $brackets, string $scoutYearLabel): ?array
    {
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($scoutYearLabel),
            $this->slotService->referenceMonthDay()
        );

        return SlotMath::slotForBirthDate($brackets, $registrationRequest->birthDate, $referenceYear);
    }

    private function sectionBelongsToRequestSlot(\Modules\Registration\Repository\RegistrationRequest $registrationRequest, int $sectionId): bool
    {
        $scoutYear = $this->scoutYearService->findById($registrationRequest->scoutYearId);
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $slot = $this->requestSlot($registrationRequest, $brackets, $scoutYear['label'] ?? '');
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
