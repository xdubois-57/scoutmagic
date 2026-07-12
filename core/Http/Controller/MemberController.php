<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Twig\Environment;

class MemberController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberService $memberService
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
        ]);
    }
}
