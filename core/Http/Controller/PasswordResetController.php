<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HumanCheck\HumanCheckService;
use Core\Security\PasswordPolicy;
use Core\Security\PasswordResetService;
use Twig\Environment;

class PasswordResetController extends AbstractController
{
    /**
     * Shared with AuthController, which renders this form's challenge on the
     * login page (the "Mot de passe oublié" mini-form lives there).
     */
    public const HUMAN_CHECK_FORM_KEY = 'password_reset_request';

    private ?HumanCheckService $humanCheck = null;

    public function __construct(
        protected Environment $twig,
        private PasswordResetService $passwordResetService
    ) {
    }

    public function setHumanCheck(HumanCheckService $humanCheck): void
    {
        $this->humanCheck = $humanCheck;
    }

    /**
     * POST /password-reset/request — "Mot de passe oublié" (AJAX, form-encoded).
     * Always responds with the same generic success message — never
     * reveals whether the email matches an account.
     *
     * @param array<string, string> $params
     */
    public function request(Request $request, array $params): Response
    {
        $csrfToken = (string) $request->getBody('_csrf_token', '');
        if (($guard = $this->guardCsrfJson($request, $csrfToken)) !== null) {
            return $guard;
        }

        $email = trim((string) $request->getBody('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Veuillez entrer une adresse email valide.']);
        }

        // Anti-bot barriers BEFORE the request ever reaches the service
        // (audit M3): unlike magic-link, this flow's only downstream throttle
        // is per-email, which a loop over random addresses never trips — and
        // each accepted request pays a bcrypt hash and writes a row. Honeypot
        // + minimum-delay + per-IP rate limit (enforceRateLimit: true) stop
        // that enumeration/CPU-burn loop at the front door. The generic
        // success response below is preserved so nothing here reveals whether
        // an address matches an account.
        $humanCheckResult = $this->humanCheck?->verify(
            self::HUMAN_CHECK_FORM_KEY,
            AuthSession::isAuthenticated(),
            $request->getBodyAll(),
            (string) $request->getServer('REMOTE_ADDR', ''),
            true
        );
        if ($humanCheckResult !== null && !$humanCheckResult->accepted) {
            return $this->json(['success' => true]);
        }

        $this->passwordResetService->requestReset($email);

        return $this->json(['success' => true]);
    }

    /**
     * GET /password-reset/{id} — the reset page reached via the emailed link.
     *
     * The token is in the URL fragment, which browsers never transmit, so
     * this request genuinely cannot see it — that is the point (it keeps
     * the token out of server logs and Referer headers). The page therefore
     * renders in a neutral "checking" state and its script posts the
     * fragment to check() below, which is what decides between the form and
     * the "no longer valid" message.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        return $this->render('auth/password_reset.html.twig', [
            'reset_id' => $id,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /password-reset/{id}/check — is this token still usable? (AJAX)
     *
     * Read-only: deliberately does NOT consume the token, so reloading the
     * page or a browser prefetching the link doesn't burn it.
     *
     * @param array<string, string> $params
     */
    public function check(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->json(['valid' => false], 403);
        }

        $id = (int) ($params['id'] ?? 0);
        $token = (string) $request->getBody('token', '');

        $valid = $id > 0 && $token !== '' && $this->passwordResetService->checkToken($id, $token);

        return $this->json(['valid' => $valid]);
    }

    /**
     * POST /password-reset/{id} — set the new password.
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (($guard = $this->guardCsrf($request, '/password-reset/' . $id . '#' . rawurlencode((string) $request->getBody('token', '')))) !== null) {
            return $guard;
        }

        $token = (string) $request->getBody('token', '');
        $newPassword = (string) $request->getBody('new_password', '');
        $confirmPassword = (string) $request->getBody('confirm_password', '');

        $backUrl = '/password-reset/' . $id . '#' . rawurlencode($token);

        if ($newPassword !== $confirmPassword) {
            FlashMessage::set('error', 'Les mots de passe ne correspondent pas.');
            return $this->redirect($backUrl);
        }

        if (!PasswordPolicy::isValid($newPassword)) {
            FlashMessage::set('error', 'Le mot de passe ne respecte pas les critères de sécurité requis.');
            return $this->redirect($backUrl);
        }

        if (!$this->passwordResetService->resetPassword($id, $token, $newPassword)) {
            FlashMessage::set('error', 'Ce lien de réinitialisation n\'est plus valide. Veuillez en demander un nouveau.');
            return $this->redirect('/login');
        }

        AuthSession::logout();
        FlashMessage::set('success', 'Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.');
        return $this->redirect('/login');
    }
}
