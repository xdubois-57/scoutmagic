<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\LoginThrottler;
use Core\Security\PasswordAuthMethod;
use Core\Security\RoleResolver;
use Core\Security\WebAuthnService;
use Twig\Environment;

class AuthController extends AbstractController
{
    private ?PasswordAuthMethod $passwordAuth = null;
    private ?WebAuthnService $webAuthnService = null;

    public function __construct(
        protected Environment $twig,
        private AuthService $authService,
        private ?RoleResolver $roleResolver = null,
        private ?ScoutYearResolver $scoutYearResolver = null
    ) {
    }

    public function setPasswordAuth(PasswordAuthMethod $passwordAuth): void
    {
        $this->passwordAuth = $passwordAuth;
    }

    public function setWebAuthnService(WebAuthnService $webAuthnService): void
    {
        $this->webAuthnService = $webAuthnService;
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
        $role = $this->resolveRole($verified->email, $verified->userAccountId);
        AuthSession::login($verified->userAccountId, $verified->email, $role);
        $this->storeLinkedMembers($verified->email);

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
                $role = $this->resolveRole($user->email, $user->id);
                AuthSession::login($user->id, $user->email, $role);
                $this->storeLinkedMembers($user->email);
            }
        }

        return $this->json(['confirmed' => true]);
    }

    /**
     * POST /login/password — authenticate with email + password (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function loginWithPassword(Request $request, array $params): Response
    {
        if ($this->passwordAuth === null) {
            return $this->json(['success' => false, 'error' => 'Méthode non disponible.']);
        }

        $body = json_decode((string) $request->getRawBody(), true);
        if (!is_array($body)) {
            return $this->json(['success' => false, 'error' => 'Données invalides.']);
        }

        // Validate CSRF
        $csrfToken = (string) ($body['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Session expirée. Veuillez recharger la page.'], 403);
        }

        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Identifiants invalides.']);
        }

        if ($password === '') {
            return $this->json(['success' => false, 'error' => 'Identifiants invalides.']);
        }

        $result = $this->passwordAuth->attempt(['email' => $email, 'password' => $password]);

        if ($result['locked_seconds'] > 0) {
            return $this->json([
                'success' => false,
                'error' => 'Trop de tentatives.',
                'locked_seconds' => $result['locked_seconds'],
            ]);
        }

        if ($result['account'] === null) {
            return $this->json(['success' => false, 'error' => 'Identifiants invalides.']);
        }

        // Login successful
        $account = $result['account'];
        $role = $this->resolveRole($account->email, $account->id);
        AuthSession::login($account->id, $account->email, $role);
        $this->storeLinkedMembers($account->email);

        return $this->json(['success' => true]);
    }

    /**
     * GET /login/passkey/options — get WebAuthn authentication options (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function passkeyOptions(Request $request, array $params): Response
    {
        if ($this->webAuthnService === null) {
            return $this->json(['error' => 'Méthode non disponible.'], 500);
        }

        $options = $this->webAuthnService->generateAuthenticationOptions();
        return $this->json($options);
    }

    /**
     * POST /login/passkey/verify — verify WebAuthn authentication (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function passkeyVerify(Request $request, array $params): Response
    {
        if ($this->webAuthnService === null) {
            return $this->json(['success' => false, 'error' => 'Méthode non disponible.']);
        }

        $body = json_decode((string) $request->getRawBody(), true);
        if (!is_array($body)) {
            return $this->json(['success' => false, 'error' => 'Données invalides.']);
        }

        $account = $this->webAuthnService->verifyAuthentication($body);

        if ($account === null) {
            return $this->json(['success' => false, 'error' => 'L\'authentification a échoué.']);
        }

        $role = $this->resolveRole($account->email, $account->id);
        AuthSession::login($account->id, $account->email, $role);
        $this->storeLinkedMembers($account->email);

        return $this->json(['success' => true]);
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
        ScoutYearSession::clear();
        FlashMessage::set('success', 'Vous avez été déconnecté.');

        return $this->redirect('/');
    }

    /**
     * Resolve role using RoleResolver if available, fallback to is_super_admin check.
     */
    private function resolveRole(string $email, ?int $userAccountId = null): string
    {
        if ($this->roleResolver !== null && $this->scoutYearResolver !== null) {
            $currentYear = $this->scoutYearResolver->getCurrentPublicYear();
            return $this->roleResolver->resolve($email, $currentYear['id']);
        }

        // Fallback for cases without role resolver
        if ($userAccountId !== null) {
            $user = $this->authService->getUserById($userAccountId);
            return ($user !== null && $user->isSuperAdmin) ? 'superadmin' : 'identified';
        }

        return 'identified';
    }

    /**
     * Store linked member years in session.
     */
    private function storeLinkedMembers(string $email): void
    {
        if ($this->roleResolver !== null && $this->scoutYearResolver !== null) {
            $currentYear = $this->scoutYearResolver->getCurrentPublicYear();
            $linked = $this->roleResolver->getLinkedMemberYears($email, $currentYear['id']);
            AuthSession::setLinkedMembers($linked);
        }
    }
}
