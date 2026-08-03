<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Mail\MailException;
use Core\Member\MemberEmailException;
use Core\Member\MemberEmailService;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * Member page "Adresses email" management — add/delete/resend/reactivate,
 * plus the public confirmation-link target. Deliberately self-only, no
 * chief/admin bypass (module addendum: "a member manages ONLY their own
 * secondary emails") — every mutating action re-verifies the requesting
 * account is linked to the member's CURRENT year (same
 * canAccess(..., 'identified') idiom Core\Http\Controller\MemberController
 * already uses for its own $isSelf), never the route's role_min alone.
 */
class MemberEmailAddressController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberEmailService $memberEmailService,
        private MemberService $memberService
    ) {
    }

    /**
     * POST /members/{id}/emails — add a secondary address.
     *
     * @param array<string, string> $params
     */
    public function add(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $memberId = $this->requireOwnMemberId($request, $memberYearId);
        if ($memberId === null) {
            return new Response('Forbidden', 403);
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/members/' . $memberYearId);
        }

        try {
            $this->memberEmailService->addEmail($memberId, (string) $request->getBody('email', ''), AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Adresse ajoutée — un email de confirmation vient de vous être envoyé.');
        } catch (MemberEmailException $e) {
            FlashMessage::set('error', $e->getMessage());
        } catch (MailException) {
            FlashMessage::set('error', "L'adresse a été enregistrée, mais l'email de confirmation n'a pas pu être envoyé. Réessayez avec « Renvoyer ».");
        }

        return $this->redirect('/members/' . $memberYearId);
    }

    /**
     * POST /members/{id}/emails/{email_id}/resend
     *
     * @param array<string, string> $params
     */
    public function resend(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $memberId = $this->requireOwnMemberId($request, $memberYearId);
        if ($memberId === null) {
            return new Response('Forbidden', 403);
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/members/' . $memberYearId);
        }

        try {
            $this->memberEmailService->resendConfirmation($memberId, (int) $params['email_id'], AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Email de confirmation renvoyé.');
        } catch (MemberEmailException $e) {
            FlashMessage::set('error', $e->getMessage());
        } catch (MailException) {
            FlashMessage::set('error', "L'email de confirmation n'a pas pu être envoyé. Réessayez plus tard.");
        }

        return $this->redirect('/members/' . $memberYearId);
    }

    /**
     * POST /members/{id}/emails/{email_id}/delete
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $memberId = $this->requireOwnMemberId($request, $memberYearId);
        if ($memberId === null) {
            return new Response('Forbidden', 403);
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/members/' . $memberYearId);
        }

        try {
            $this->memberEmailService->deleteEmail($memberId, (int) $params['email_id'], AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Adresse supprimée.');
        } catch (MemberEmailException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/members/' . $memberYearId);
    }

    /**
     * POST /members/{id}/emails/{email_id}/reactivate
     *
     * @param array<string, string> $params
     */
    public function reactivate(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $memberId = $this->requireOwnMemberId($request, $memberYearId);
        if ($memberId === null) {
            return new Response('Forbidden', 403);
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/members/' . $memberYearId);
        }

        try {
            $this->memberEmailService->reactivateEmail($memberId, (int) $params['email_id'], AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Adresse réactivée.');
        } catch (MemberEmailException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/members/' . $memberYearId);
    }

    /**
     * GET /members/emails/confirm/{id}?token=... — the confirmation
     * link's target. Public, unauthenticated (the visitor may be on a
     * different device than the one they're logged in on, or not logged
     * in at all) — fails gracefully with a plain message, never a stack
     * trace or account-enumeration hint, same contract as
     * auth/password_reset.html.twig's own invalid-token state.
     *
     * @param array<string, string> $params
     */
    public function confirm(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $token = (string) $request->getQuery('token', '');

        $confirmed = $id > 0 && $token !== '' && $this->memberEmailService->confirmEmail($id, $token);

        return $this->render('members/email_confirmed.html.twig', ['confirmed' => $confirmed]);
    }

    /**
     * Re-verifies the requesting account is linked (blind-index email
     * match) to this exact member_year id, mirroring MemberController::
     * show()'s own $isSelf computation — deliberately never a chief/admin
     * bypass, per this feature's spec. Returns the persistent member id
     * on success, null when access must be denied.
     */
    private function requireOwnMemberId(Request $request, int $memberYearId): ?int
    {
        $userEmail = AuthSession::getEmail() ?? '';
        if (!$this->memberService->canAccess($userEmail, $memberYearId, 'identified')) {
            return null;
        }

        try {
            return $this->memberService->getMemberProfile($memberYearId)->memberId;
        } catch (MemberNotFoundException) {
            return null;
        }
    }
}
