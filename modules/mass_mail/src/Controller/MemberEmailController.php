<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Modules\MassMail\Api\MassMailQueryInterface;
use Twig\Environment;

/**
 * The member page's "view as sent" detail page for one received email
 * (member page §4, ARCHITECTURE.md §8.22) — lives in this module rather
 * than core\Http\Controller\MemberController because the content itself
 * (subject/body) is entirely mass_mail's own data; core\Member\
 * MemberPageService only ever links to it (via the recipient id it
 * already returns from getRecentEmailsForMember()) and never renders it
 * directly. Only registered when mass_mail is enabled — the "Communications
 * récentes" list that links here is likewise hidden when it's disabled.
 */
class MemberEmailController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberService $memberService,
        private MassMailQueryInterface $massMailQuery
    ) {
    }

    /**
     * GET /members/{id}/emails/{recipient_id} — role_min: identified,
     * additionally scoped server-side (role_min alone is not the real
     * boundary, same principle as MemberController::show()) to chief/admin
     * or the member themselves via MemberService::canAccess(). On top of
     * that, findEmailDetailForMember() re-verifies server-side that
     * $recipientId really belongs to this member, so a recipient id from
     * one member's page can never be reused to read another member's mail.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $recipientId = (int) $params['recipient_id'];
        $userEmail = AuthSession::getEmail() ?? '';
        $userRole = AuthSession::getRole();

        if (!$this->memberService->canAccess($userEmail, $memberYearId, $userRole)) {
            return new Response('Forbidden', 403);
        }

        try {
            $profile = $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException $e) {
            return new Response('Member not found', 404);
        }

        $detail = $this->massMailQuery->findEmailDetailForMember($profile->memberId, $recipientId);
        if ($detail === null) {
            return new Response('Not found', 404);
        }

        return $this->render('members/email_detail.html.twig', [
            'member' => $profile,
            'email' => $detail,
            // Real ancestor page: the member's own page this email was
            // listed on — a breadcrumb_trail, not a `parents` menu label
            // (design.md §7.3).
            'breadcrumb_trail' => [
                ['label' => $profile->getDisplayName(), 'url' => '/members/' . $profile->memberYearId],
            ],
        ]);
    }
}
