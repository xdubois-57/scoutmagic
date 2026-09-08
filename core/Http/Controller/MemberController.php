<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\DepartureService;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberPageService;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Twig\Environment;

class MemberController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberService $memberService,
        private MemberYearService $memberYearService,
        private JournalService $journalService,
        private MemberPageService $memberPageService,
        private DepartureService $departureService,
        private SectionStaffAuthorizationService $sectionStaffAuthorizationService,
        private SectionService $sectionService
    ) {
    }

    /**
     * GET /members/{id} — display a member's detail page ("Espace des
     * animés"). All the page's data (branch card, section info, optional-
     * module blocks) is built by MemberPageService — this method only
     * handles HTTP concerns (access check, param parsing, render).
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $userEmail = AuthSession::getEmail() ?? '';
        $userRole = AuthSession::getRole();

        // Fine-grained access check — role_min:identified alone only
        // proves the visitor is logged in; canAccess() is what actually
        // scopes this page to "chief/admin, or the member themselves".
        if (!$this->memberService->canAccess($userEmail, $memberYearId, $userRole)) {
            return new Response('Forbidden', 403);
        }

        try {
            $profile = $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException $e) {
            return new Response('Member not found', 404);
        }

        $scoutYearId = $this->memberService->getScoutYearIdForMemberYear($memberYearId) ?? 0;
        $isSelf = $this->memberService->canAccess($userEmail, $memberYearId, 'identified');
        $isChiefOrAbove = Role::fromString($userRole)->hasAccess(Role::CHIEF);

        $pageData = $this->memberPageService->buildPageData($profile, $scoutYearId, $isSelf, $isChiefOrAbove,
            Role::fromString($userRole));

        return $this->render('members/show.html.twig', array_merge($pageData, [
            'member' => $profile,
            'is_self' => $isSelf,
            'show_contact' => $isSelf || $isChiefOrAbove,
            'show_addresses' => $isSelf || $isChiefOrAbove,
            // Replaces the route's static breadcrumb label ("Membre") with
            // this member's own display name (partials/breadcrumb_bar.html.twig).
            'breadcrumb_current' => $profile->getDisplayName(),
        ]));
    }

    /**
     * Whether the signed-in account animates the section this member-year
     * belongs to — the write-side boundary, built out of the same two
     * vetted lookups DeparturesController uses rather than a second,
     * hand-rolled version of the rule.
     *
     * The year asked about is the member-year's OWN scout year, not the
     * effective one: this row is the thing being written, and whoever
     * animates its section that year is who may write it.
     */
    private function accountStaffsMemberYear(int $memberYearId): bool
    {
        $scoutYearId = $this->memberService->getScoutYearIdForMemberYear($memberYearId);
        if ($scoutYearId === null) {
            return false;
        }

        $sections = $this->sectionStaffAuthorizationService->getStaffedSections(
            AuthSession::getEmail() ?? '',
            AuthSession::getRole(),
            $scoutYearId
        );

        foreach ($sections as $section) {
            if (in_array(
                $memberYearId,
                $this->sectionService->getSectionAnimeMemberYearIds((int) $section['id'], $scoutYearId),
                true
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * POST /members/{id}/scout-year-offset — update a member's scout year
     * offset (AJAX, JSON). role_min: chief, enforced by the router.
     *
     * @param array<string, string> $params
     */
    public function updateScoutYearOffset(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];

        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($json['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $offset = isset($json['offset']) ? (int) $json['offset'] : null;
        if (!in_array($offset, [-1, 0, 1], true)) {
            return $this->json(['success' => false, 'error' => 'Décalage invalide.'], 400);
        }

        try {
            $profile = $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException $e) {
            return $this->json(['success' => false, 'error' => 'Membre introuvable.'], 404);
        }

        // `role_min: chief` on the route proves the caller animates
        // SOMETHING; it says nothing about whether they animate THIS animé.
        // The other write on this very page — Modules\Registration\
        // Controller\DeparturesController::update() — re-derives the
        // member's section among the caller's own and answers 403 when it
        // is not one of them (ARCHITECTURE.md §8.33). Two writes on the
        // same member from the same card must not answer to two rules, and
        // MemberService::canAccess() is the READ rule (§8.14): it lets
        // every chief through by design.
        if (!$this->accountStaffsMemberYear($memberYearId)) {
            return $this->json(['success' => false, 'error' => "Cette section n'est pas la vôtre."], 403);
        }

        $oldOffset = $profile->scoutYearOffset;
        $this->memberService->updateScoutYearOffset($memberYearId, $offset);

        if ($oldOffset !== $offset) {
            $this->journalService->log(
                'core',
                'member_scout_year_offset_changed',
                'info',
                'Décalage année scoute modifié pour un membre',
                ['member_year_id' => $memberYearId, 'old_offset' => $oldOffset, 'new_offset' => $offset],
                AuthSession::getUserAccountId()
            );
        }

        $effectiveAge = $this->memberYearService->getEffectiveAge(
            MemberYearService::extractBirthYear($profile->birthDate),
            $offset,
            MemberYearService::referenceYearFromScoutYearLabel($profile->scoutYearLabel)
        );

        return $this->json([
            'success' => true,
            'branch_year_label' => $effectiveAge->getBranchYearLabel(),
            'branch_color' => $effectiveAge->branchColor,
        ]);
    }

    /**
     * POST /members/{id}/departure — mark/unmark a member as leaving next
     * scout year, or update the accompanying comment (AJAX, JSON,
     * role_min: admin enforced by the router — this action currently only
     * ever renders on the admin member-search page's detail card,
     * Core\Member\Controller\MemberSearchController). Exactly the same
     * Core\Member\DepartureService the registration module's own "Départs"
     * page uses — this is the same fact about a member_year, just reachable
     * by searching for *any* member (staff included) rather than only the
     * animés of a section a chief actually staffs. One field at a time
     * (`leaving` XOR `comment` present in the body), same concurrency
     * reasoning as Modules\Registration\Controller\DeparturesController::
     * update()'s own docblock.
     *
     * @param array<string, string> $params
     */
    public function updateDeparture(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];

        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($json['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        try {
            $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException) {
            return $this->json(['success' => false, 'error' => 'Membre introuvable.'], 404);
        }

        $userId = AuthSession::getUserAccountId();

        if (array_key_exists('leaving', $json)) {
            if ($json['leaving']) {
                $this->departureService->markLeaving($memberYearId, null, $userId);
            } else {
                $this->departureService->unmarkLeaving($memberYearId, $userId);
            }
        }
        if (array_key_exists('comment', $json)) {
            $this->departureService->updateComment($memberYearId, (string) $json['comment']);
        }

        return $this->json(['success' => true]);
    }
}
