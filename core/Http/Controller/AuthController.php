<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

class AuthController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private AuthService $authService
    ) {
    }

    /**
     * GET /login — render the login page.
     *
     * @param array<string, string> $params
     */
    public function login(Request $request, array $params): Response
    {
        if (AuthSession::isAuthenticated()) {
            return $this->redirect('/');
        }

        $csrfToken = CsrfGuard::generateToken();

        return $this->render('auth/login.html.twig', [
            'csrf_token' => $csrfToken,
        ]);
    }

    /**
     * POST /login/magic-link — request a magic link (AJAX, returns JSON).
     *
     * @param array<string, string> $params
     */
    public function requestMagicLink(Request $request, array $params): Response
    {
        // Validate CSRF
        $csrfToken = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Session expirée. Veuillez recharger la page.'], 403);
        }

        $email = trim((string) $request->getBody('email', ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Veuillez entrer une adresse email valide.']);
        }

        $result = $this->authService->requestMagicLink($email);

        if (!$result->success) {
            return $this->json(['success' => false, 'error' => $result->error]);
        }

        return $this->json(['success' => true, 'poll_id' => $result->magicLinkId]);
    }

    /**
     * GET /auth/verify — user clicked the magic link in their email.
     *
     * @param array<string, string> $params
     */
    public function verifyMagicLink(Request $request, array $params): Response
    {
        $token = (string) $request->getQuery('token', '');
        $id = (int) $request->getQuery('id', '0');

        if ($token === '' || $id === 0) {
            return $this->render('auth/verify.html.twig', ['valid' => false]);
        }

        $verified = $this->authService->verifyMagicLink($id, $token);

        if ($verified === null) {
            return $this->render('auth/verify.html.twig', ['valid' => false]);
        }

        // Create session on this device (Device B)
        $user = $this->authService->getUserById($verified->userAccountId);
        $role = ($user !== null && $user->isSuperAdmin) ? 'admin' : 'identified';
        AuthSession::login($verified->userAccountId, $verified->email, $role);

        return $this->render('auth/verify.html.twig', [
            'valid' => true,
            'email' => $verified->email,
        ]);
    }

    /**
     * GET /auth/poll/{id} — check if magic link has been confirmed (AJAX).
     *
     * @param array<string, string> $params
     */
    public function pollMagicLink(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id === 0) {
            return $this->json(['confirmed' => false]);
        }

        $confirmed = $this->authService->isMagicLinkConfirmed($id);

        if (!$confirmed) {
            return $this->json(['confirmed' => false]);
        }

        // If this device (Device A) is not yet authenticated, create session
        if (!AuthSession::isAuthenticated()) {
            $user = $this->authService->getUserForConfirmedLink($id);
            if ($user !== null) {
                $role = $user->isSuperAdmin ? 'admin' : 'identified';
                AuthSession::login($user->id, $user->email, $role);
            }
        }

        return $this->json(['confirmed' => true]);
    }

    /**
     * POST /logout — destroy session, redirect to home.
     *
     * @param array<string, string> $params
     */
    public function logout(Request $request, array $params): Response
    {
        // Validate CSRF
        $csrfToken = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->redirect('/');
        }

        AuthSession::logout();
        FlashMessage::set('success', 'Vous avez été déconnecté.');

        return $this->redirect('/');
    }
}
