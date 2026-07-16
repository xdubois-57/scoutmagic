<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\EffectiveAge;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
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
        private JournalService $journalService
    ) {
    }

    /**
     * GET /members/{id} — display a member's detail page.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $userEmail = AuthSession::getEmail() ?? '';
        $userRole = AuthSession::getRole();

        // Fine-grained access check
        if (!$this->memberService->canAccess($userEmail, $memberYearId, $userRole)) {
            return new Response('Forbidden', 403);
        }

        try {
            $profile = $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException $e) {
            return new Response('Member not found', 404);
        }

        // Determine visibility flags
        $isSelf = $this->memberService->canAccess($userEmail, $memberYearId, 'identified');
        $isChiefOrAbove = Role::fromString($userRole)->hasAccess(Role::CHIEF);

        // Contact info: visible to self always, visible to chiefs for members in their sections
        $showContact = $isSelf || $isChiefOrAbove;

        // Addresses: visible only to self and chiefs/admin
        $showAddresses = $isSelf || $isChiefOrAbove;

        return $this->render('members/show.html.twig', [
            'member' => $profile,
            'show_contact' => $showContact,
            'show_addresses' => $showAddresses,
            'show_site_data' => $isChiefOrAbove,
            'effective_age' => $this->computeEffectiveAge($profile),
        ]);
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

        if (!CsrfGuard::validateToken((string) ($json['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
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

    private function computeEffectiveAge(MemberProfile $profile): EffectiveAge
    {
        return $this->memberYearService->getEffectiveAge(
            MemberYearService::extractBirthYear($profile->birthDate),
            $profile->scoutYearOffset,
            MemberYearService::referenceYearFromScoutYearLabel($profile->scoutYearLabel)
        );
    }
}
