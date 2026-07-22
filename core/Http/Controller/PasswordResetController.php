<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\PasswordPolicy;
use Core\Security\PasswordResetService;
use Twig\Environment;

class PasswordResetController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private PasswordResetService $passwordResetService
    ) {
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
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Session expirée. Veuillez recharger la page.'], 403);
        }

        $email = trim((string) $request->getBody('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Veuillez entrer une adresse email valide.']);
        }

        $this->passwordResetService->requestReset($email);

        return $this->json(['success' => true]);
    }

    /**
     * GET /password-reset/{id} — the reset page reached via the emailed link.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $token = (string) $request->getQuery('token', '');

        $valid = $id > 0 && $token !== '' && $this->passwordResetService->checkToken($id, $token);

        return $this->render('auth/password_reset.html.twig', [
            'valid' => $valid,
            'reset_id' => $id,
            'reset_token' => $token,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /password-reset/{id} — set the new password.
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Session expirée. Veuillez réessayer.');
            return $this->redirect('/password-reset/' . $id . '?token=' . urlencode((string) $request->getBody('token', '')));
        }

        $token = (string) $request->getBody('token', '');
        $newPassword = (string) $request->getBody('new_password', '');
        $confirmPassword = (string) $request->getBody('confirm_password', '');

        $backUrl = '/password-reset/' . $id . '?token=' . urlencode($token);

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
