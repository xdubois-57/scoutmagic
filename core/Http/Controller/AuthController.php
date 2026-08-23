<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Cookie\CookieConsentService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\TemporaryMemberSession;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HumanCheck\HumanCheckService;
use Core\Security\LastLoginMethodCookie;
use Core\Security\LoginThrottler;
use Core\Security\PasswordAuthMethod;
use Core\Security\PendingMagicLink;
use Core\Security\RoleResolver;
use Core\Security\WebAuthnService;
use Twig\Environment;

class AuthController extends AbstractController
{
    /** Core\Security\HumanCheck form key for the magic-link request form. */
    private const HUMAN_CHECK_FORM_KEY = 'magic_link_request';

    private ?PasswordAuthMethod $passwordAuth = null;
    private ?WebAuthnService $webAuthnService = null;
    private ?HumanCheckService $humanCheck = null;

    public function __construct(
        protected Environment $twig,
        private AuthService $authService,
        private ?RoleResolver $roleResolver = null,
        private ?ScoutYearResolver $scoutYearResolver = null,
        private ?CookieConsentService $cookieConsentService = null
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

    public function setHumanCheck(HumanCheckService $humanCheck): void
    {
        $this->humanCheck = $humanCheck;
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
            'default_login_method' => LastLoginMethodCookie::read() ?? 'magic-link',
            'human_check' => $this->humanCheck?->generateChallenge(self::HUMAN_CHECK_FORM_KEY),
            // The "Mot de passe oublié" mini-form on this page posts to
            // PasswordResetController::request(), which now applies its own
            // HumanCheck (audit M3) — render its challenge here (a distinct
            // form key, so the two challenges are not interchangeable).
            'human_check_reset' => $this->humanCheck?->generateChallenge(PasswordResetController::HUMAN_CHECK_FORM_KEY),
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
        if (($guard = $this->guardCsrfJson($request, $csrfToken)) !== null) {
            return $guard;
        }

        if (!$this->hasRgpdConsent($request)) {
            return $this->json(['success' => false, 'error' => 'Vous devez accepter la politique de protection des données pour vous connecter.']);
        }

        // Core\Security\HumanCheck: honeypot + minimum-delay barriers only
        // (enforceRateLimit: false). AuthService::requestMagicLink()
        // already rate-limits by email blind index (SECURITY.md §8,
        // MAX_REQUESTS_PER_HOUR) — a second, differently-scoped (per-IP)
        // counter here would produce inconsistent thresholds and
        // unexplained lockouts for the same abuse pattern (ARCHITECTURE.md
        // §8's iteration-1 spec calls this out explicitly). The honeypot
        // and delay checks are still worth applying: they catch a naive
        // bot before it ever touches AuthService, at zero cost to a
        // legitimate slow human.
        $humanCheckResult = $this->humanCheck?->verify(
            self::HUMAN_CHECK_FORM_KEY,
            AuthSession::isAuthenticated(),
            $request->getBodyAll(),
            (string) $request->getServer('REMOTE_ADDR', ''),
            false
        );
        if ($humanCheckResult !== null && !$humanCheckResult->accepted) {
            return $this->json(['success' => false, 'error' => 'Une erreur est survenue. Veuillez réessayer.']);
        }

        $email = trim((string) $request->getBody('email', ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Veuillez entrer une adresse email valide.']);
        }

        $result = $this->authService->requestMagicLink($email);

        if (!$result->success) {
            return $this->json(['success' => false, 'error' => $result->error]);
        }

        $this->rememberLoginMethod('magic-link');

        // Only this session may later collect the session created by
        // confirming that link (see Core\Security\PendingMagicLink).
        PendingMagicLink::remember((int) $result->magicLinkId);

        return $this->json(['success' => true, 'poll_id' => $result->magicLinkId]);
    }

    /**
     * GET /auth/verify — user clicked the magic link in their email.
     *
     * **Confirming a link is not logging in.** The session is created in
     * the window that ASKED for the link (pollMagicLink() below), and
     * only there: this request confirms the address, marks the link used,
     * and says so. The browser the link happened to open in is left
     * exactly as anonymous as it was.
     *
     * That is a real boundary, not a nicety. A magic link travels by
     * email, and an email is opened wherever the mail happens to be read
     * — a shared family tablet, a work laptop, a webmail on somebody
     * else's machine, a corporate scanner that follows links before the
     * human ever sees them. Every one of those used to end up holding a
     * live session for the address, indefinitely and silently, while the
     * person who actually asked to log in saw their own window log in
     * too. Now the only thing that ever becomes identified is the window
     * that started the flow.
     *
     * The ONE case where this request still creates a session is when
     * this very session is the one that asked for the link
     * (`PendingMagicLink::matches()`) — the ordinary "request it on my
     * phone, open the mail app on my phone" flow, where the tab doing the
     * polling may have been discarded by the OS in the meantime. Nothing
     * is widened by it: that session was going to collect the session
     * anyway through its own poll, and it has now additionally proven
     * possession of the emailed token.
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

        // Read BEFORE verifying: verifying marks the link used, and this
        // answer is about the session, not about the link.
        $isRequestingSession = PendingMagicLink::matches($id);

        $verified = $this->authService->verifyMagicLink($id, $token);

        if ($verified === null) {
            return $this->render('auth/verify.html.twig', ['valid' => false]);
        }

        if (!$this->isMemberAuthorized($verified->email)) {
            return $this->render('auth/verify.html.twig', ['valid' => false, 'not_a_member' => true]);
        }

        if ($isRequestingSession) {
            $role = $this->resolveRole($verified->email, $verified->userAccountId);
            AuthSession::login($verified->userAccountId, $verified->email, $role);
            $this->storeLinkedMembers($verified->email);
            PendingMagicLink::forget();
        }

        return $this->render('auth/verify.html.twig', [
            'valid' => true,
            'email' => $verified->email,
            // Drives what the page tells the visitor to do next: carry on
            // here, or go back to the window where they asked — which is
            // the one that is about to be identified.
            'signed_in_here' => $isRequestingSession,
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

        // The id is a sequential AUTO_INCREMENT integer, not a secret, so it
        // is never on its own enough to collect a session — only the session
        // that actually requested this link may poll it. Answering the
        // uniform "not confirmed yet" for every other id also keeps this
        // endpoint from confirming that some id exists or was clicked.
        if (!PendingMagicLink::matches($id)) {
            return $this->json(['confirmed' => false]);
        }

        $confirmed = $this->authService->isMagicLinkConfirmed($id);

        if (!$confirmed) {
            return $this->json(['confirmed' => false]);
        }

        // If this device (Device A) is not yet authenticated, create session
        if (!AuthSession::isAuthenticated()) {
            $user = $this->authService->getUserForConfirmedLink($id);
            if ($user !== null && $this->isMemberAuthorized($user->email)) {
                $role = $this->resolveRole($user->email, $user->id);
                AuthSession::login($user->id, $user->email, $role);
                $this->storeLinkedMembers($user->email);
            }
        }

        PendingMagicLink::forget();

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
        if (($guard = $this->guardCsrfJson($request, $csrfToken)) !== null) {
            return $guard;
        }

        if (!$this->hasRgpdConsent($request, $body)) {
            return $this->json(['success' => false, 'error' => 'Vous devez accepter la politique de protection des données pour vous connecter.']);
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

        $account = $result['account'];

        if (!$this->isMemberAuthorized($account->email)) {
            return $this->json(['success' => false, 'error' => 'Aucun membre actif n\'est associé à cette adresse email. Contactez un(e) responsable de l\'unité.']);
        }

        // Login successful
        $role = $this->resolveRole($account->email, $account->id);
        AuthSession::login($account->id, $account->email, $role);
        $this->storeLinkedMembers($account->email);
        $this->rememberLoginMethod('password');

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

        if (!$this->hasRgpdConsent($request, $body)) {
            return $this->json(['success' => false, 'error' => 'Vous devez accepter la politique de protection des données pour vous connecter.']);
        }

        $account = $this->webAuthnService->verifyAuthentication($body);

        if ($account === null) {
            return $this->json(['success' => false, 'error' => 'L\'authentification a échoué.']);
        }

        if (!$this->isMemberAuthorized($account->email)) {
            return $this->json(['success' => false, 'error' => 'Aucun membre actif n\'est associé à cette adresse email. Contactez un(e) responsable de l\'unité.']);
        }

        $role = $this->resolveRole($account->email, $account->id);
        AuthSession::login($account->id, $account->email, $role);
        $this->storeLinkedMembers($account->email);
        $this->rememberLoginMethod('passkey');

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
        if (($guard = $this->guardCsrf($request, '/', $csrfToken)) !== null) {
            return $guard;
        }

        AuthSession::logout();
        PendingMagicLink::forget();
        ScoutYearSession::clear();
        TemporaryMemberSession::clear();
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

    /**
     * Module addendum: the RGPD policy checkbox is mandatory on every
     * login submission, every method. $jsonBody is passed for the two
     * methods whose payload is raw JSON rather than a form body.
     *
     * @param array<string, mixed>|null $jsonBody
     */
    private function hasRgpdConsent(Request $request, ?array $jsonBody = null): bool
    {
        $value = $jsonBody !== null ? ($jsonBody['rgpd_consent'] ?? null) : $request->getBody('rgpd_consent');

        return $value === true || $value === '1' || $value === 1 || $value === 'true';
    }

    /**
     * Module addendum: a user_accounts row alone (password/passkey/magic
     * link) is not sufficient — the email must also match a real member,
     * unless the account is a super-admin. Degrades to "allow" when no
     * role/member system is configured (mirrors resolveRole()'s own
     * fallback), since there's nothing to check against.
     */
    private function isMemberAuthorized(string $email): bool
    {
        if ($this->roleResolver === null || $this->scoutYearResolver === null) {
            return true;
        }

        $currentYear = $this->scoutYearResolver->getCurrentPublicYear();
        return $this->roleResolver->isEmailAuthorizedToLogin($email, $currentYear['id']);
    }

    /**
     * Best-effort — never blocks a login over this (see
     * Core\Security\LastLoginMethodCookie).
     */
    private function rememberLoginMethod(string $method): void
    {
        if ($this->cookieConsentService !== null) {
            LastLoginMethodCookie::remember($method, $this->cookieConsentService);
        }
    }
}
