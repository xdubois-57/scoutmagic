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
use Core\Http\SafeRedirect;
use Core\Member\TemporaryMemberSession;
use Core\Notification\NotificationService;
use Core\ScoutYear\AuthorizationYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HumanCheck\HumanCheckService;
use Core\Security\LastLoginMethodCookie;
use Core\Security\LoginReturn;
use Core\Security\LoginThrottler;
use Core\Security\PasswordAuthMethod;
use Core\Security\MagicLinkCodeMismatch;
use Core\Security\PendingMagicLink;
use Core\Security\RecentAuthentication;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\SessionGeneration;
use Core\Security\WebAuthnChallengeExpired;
use Core\Security\WebAuthnMalformedRequest;
use Core\Security\WebAuthnService;
use Twig\Environment;

class AuthController extends AbstractController
{
    /** Core\Security\HumanCheck form key for the magic-link request form. */
    private const HUMAN_CHECK_FORM_KEY = 'magic_link_request';

    private ?PasswordAuthMethod $passwordAuth = null;
    private ?WebAuthnService $webAuthnService = null;
    private ?HumanCheckService $humanCheck = null;
    private ?NotificationService $notificationService = null;
    private string $installationGeneration;

    public function __construct(
        protected Environment $twig,
        private AuthService $authService,
        SessionGeneration $sessionGeneration,
        private ?RoleResolver $roleResolver = null,
        private ?ScoutYearResolver $scoutYearResolver = null,
        private ?CookieConsentService $cookieConsentService = null,
        /**
         * The years an access decision may be taken in — the set the
         * login gate and every later request are both judged on. Null
         * only in the same degraded wiring the two dependencies above
         * are null in (no role/member system at all), where the three
         * private helpers below already answer without asking a year.
         */
        private ?AuthorizationYearService $authorizationYearService = null
    ) {
        // Snapshot before verifying credentials: a concurrent restoration
        // must not stamp an old authentication with the new database's epoch.
        $this->installationGeneration = $sessionGeneration->current();
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
     * What logout() forgets the device's push subscription through
     * (issue #1123). Unset, logout still works and forgets nothing.
     */
    public function setNotificationService(NotificationService $notificationService): void
    {
        $this->notificationService = $notificationService;
    }

    /**
     * GET /login — render the login page.
     *
     * `?return=<path>` is where the visitor goes once logged in
     * (Core\Security\LoginReturn, issue #833). A visitor already logged
     * in goes there at once — unless `?reauth=1` asks them to confirm
     * their identity (Core\Security\RecentAuthentication, issue #1115):
     * then the page shows as usual, any of its methods signs them in
     * afresh, and they land back on `return`.
     *
     * @param array<string, string> $params
     */
    public function login(Request $request, array $params): Response
    {
        $return = (string) $request->getQuery('return', '');
        $reauth = AuthSession::isAuthenticated()
            && (string) $request->getQuery(RecentAuthentication::QUERY_FLAG, '') === '1';
        if (AuthSession::isAuthenticated() && !$reauth) {
            LoginReturn::forget();
            return $this->redirect(SafeRedirect::loginReturnPath($return));
        }

        LoginReturn::remember($return);

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
            // « Confirmez votre identité »: the notice above the form, and
            // the address already typed — it is the account being confirmed.
            'reauth_notice' => $reauth ? RecentAuthentication::LOGIN_NOTICE : null,
            'prefill_email' => $reauth ? (string) AuthSession::getEmail() : '',
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
            return $this->json(['success' => false, 'error' => 'Vous devez accepter la politique de protection des '
                . 'données pour vous connecter.']);
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
        $pollToken = PendingMagicLink::remember((int) $result->magicLinkId, $this->installationGeneration);

        // The code this window shows, to be typed if the link is opened in
        // another browser (issue #1099), and the poll token only this page
        // holds (issue #1115). Sent to this window only.
        return $this->json([
            'success' => true,
            'poll_id' => $result->magicLinkId,
            'poll_token' => $pollToken,
            'match_code' => $result->matchCode,
        ]);
    }

    /**
     * GET /auth/verify — the link in the e-mail was OPENED.
     *
     * **Opening a link confirms nothing** (issue #1099, SECURITY.md §31).
     * A GET is what a mail scanner performs on every URL before the human
     * ever sees the message, and it used to be enough: the scanner
     * confirmed the link, and the window that ASKED for it — anybody's,
     * typing anybody's address — was logged in as the owner of the
     * mailbox. This request only checks the token, spends nothing, and
     * shows a page whose button POSTs to confirmLink() below.
     *
     * The page differs by who is looking. The session that asked for the
     * link (PendingMagicLink::matches()) has nothing to prove: one button.
     * Any other browser must type the code the asking window shows — the
     * one thing a person who did not ask, or a scanner, cannot know.
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

        $record = $this->authService->checkMagicLink($id, $token);
        if ($record === null) {
            return $this->render('auth/verify.html.twig', ['valid' => false]);
        }

        return $this->renderConfirmation($id, $token, $record->createdAt);
    }

    /**
     * POST /auth/verify — the visitor confirms the link (issue #1099).
     *
     * **Confirming a link is not logging in, except in the window that
     * asked.** The session is created there — by pollMagicLink(), which
     * that window is running — and nowhere else: the browser the link
     * happened to open in stays exactly as anonymous as it was, unless it
     * IS that session (the ordinary "asked on my phone, opened the mail
     * app on my phone" flow, where the polling tab may have been
     * discarded meanwhile).
     *
     * From any other browser the code the asking window shows is
     * required, and checked by AuthService::verifyMagicLink(): a wrong
     * one confirms nothing and counts against the link. Without it,
     * whoever typed somebody else's address at the login form was
     * identified as them the moment their mailbox — or its scanner —
     * opened the mail.
     *
     * @param array<string, string> $params
     */
    public function confirmLink(Request $request, array $params): Response
    {
        $token = (string) $request->getBody('token', '');
        $id = (int) $request->getBody('id', '0');

        if ($token === '' || $id === 0) {
            return $this->render('auth/verify.html.twig', ['valid' => false]);
        }

        $retry = '/auth/verify?' . http_build_query(['token' => $token, 'id' => $id]);
        if (($guard = $this->guardCsrf($request, $retry)) !== null) {
            return $guard;
        }

        $isRequestingSession = PendingMagicLink::matches($id, $this->installationGeneration);
        $code = (string) $request->getBody('match_code', '');

        if (!$isRequestingSession && trim($code) === '') {
            $record = $this->authService->checkMagicLink($id, $token);
            if ($record === null) {
                return $this->render('auth/verify.html.twig', ['valid' => false]);
            }

            return $this->renderConfirmation(
                $id,
                $token,
                $record->createdAt,
                "Saisissez le code affiché sur l'écran où vous avez demandé la connexion."
            );
        }

        try {
            $verified = $this->authService->verifyMagicLink($id, $token, $isRequestingSession ? null : $code);
        } catch (MagicLinkCodeMismatch $e) {
            if ($e->linkSpent) {
                return $this->render('auth/verify.html.twig', ['valid' => false, 'code_spent' => true]);
            }
            $record = $this->authService->checkMagicLink($id, $token);
            if ($record === null) {
                return $this->render('auth/verify.html.twig', ['valid' => false]);
            }

            return $this->renderConfirmation(
                $id,
                $token,
                $record->createdAt,
                'Ce code ne correspond pas. Vérifiez le code affiché sur '
                    . "l'écran où vous avez demandé la connexion."
            );
        }

        if ($verified === null) {
            return $this->render('auth/verify.html.twig', ['valid' => false]);
        }

        if (!$this->isMemberAuthorized($verified->email)) {
            return $this->render('auth/verify.html.twig', ['valid' => false, 'not_a_member' => true]);
        }

        if ($isRequestingSession) {
            $role = $this->resolveRole($verified->email, $verified->userAccountId);
            AuthSession::login($verified->userAccountId, $verified->email, $role, $this->installationGeneration);
            $this->storeLinkedMembers($verified->email);
            // AND THE PENDING LINK IS LEFT ALONE, deliberately. On a phone
            // the mail's link opens in a new tab of the same browser, which
            // carries the same session cookie as the tab still showing
            // "En attente de confirmation…". Forgetting it here spent the
            // one thing that tab has: its next poll got `{confirmed: false}`
            // for ever, over a session that was already authenticated
            // (issue #263). pollMagicLink() forgets it, once the window
            // that asked has been told.
        }

        return $this->render('auth/verify.html.twig', [
            'valid' => true,
            'confirmed' => true,
            'email' => $verified->email,
            // Drives what the page tells the visitor to do next: carry on
            // here, or go back to the window where they asked — which is
            // the one that is about to be identified.
            'signed_in_here' => $isRequestingSession,
            // Read, not spent: the tab polling for this link is in the
            // same session and is the one that consumes it.
            'continue_to' => $isRequestingSession ? LoginReturn::target() : '/',
        ]);
    }

    /**
     * The page that asks for the confirmation: one button for the session
     * that asked for the link, the code field for any other browser.
     */
    private function renderConfirmation(
        int $id,
        string $token,
        \DateTimeImmutable $requestedAt,
        ?string $error = null
    ): Response {
        return $this->render('auth/verify.html.twig', [
            'valid' => true,
            'confirmed' => false,
            'token' => $token,
            'link_id' => $id,
            'needs_code' => !PendingMagicLink::matches($id, $this->installationGeneration),
            'requested_at' => $requestedAt,
            'error' => $error,
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
        // Nor is the session cookie: a copy of it would race the page that
        // asked for the link. Only that page holds the poll token
        // (PendingMagicLink, issue #1115); any other poller gets the same
        // uniform answer.
        $pollToken = $request->getServer(PendingMagicLink::POLL_TOKEN_HEADER);
        if (
            !PendingMagicLink::matches($id)
            || !PendingMagicLink::pollTokenMatches(is_string($pollToken) ? $pollToken : null)
        ) {
            return $this->json(['confirmed' => false]);
        }
        if (!PendingMagicLink::matches($id, $this->installationGeneration)) {
            PendingMagicLink::forget();
            return $this->json(['confirmed' => false, 'expired' => true]);
        }

        $confirmed = $this->authService->isMagicLinkConfirmed($id);

        if (!$confirmed) {
            return $this->json(['confirmed' => false]);
        }

        // If this device (Device A) is not yet authenticated, create the
        // session. It already is in the same-browser case — verifyMagicLink()
        // signed this very session in when the mail's link opened in another
        // tab of it — and then there is nothing left to do but say so, which
        // is what stops the "En attente de confirmation…" screen (issue #263).
        if (!AuthSession::isAuthenticated()) {
            $user = $this->authService->getUserForConfirmedLink($id);
            if ($user !== null && $this->isMemberAuthorized($user->email)) {
                $role = $this->resolveRole($user->email, $user->id);
                AuthSession::login($user->id, $user->email, $role, $this->installationGeneration);
                $this->storeLinkedMembers($user->email);
            }
        } else {
            // A signed-in session that asked for a link for its OWN account
            // — « confirmez votre identité » (issue #1115) — has just proved
            // it again, from wherever the link was opened. A link for some
            // other account changes nothing here: the session stays who it
            // was, and no fresher than it was.
            $user = $this->authService->getUserForConfirmedLink($id);
            if ($user !== null && $user->id === AuthSession::getUserAccountId()) {
                AuthSession::markAuthenticatedNow();
            }
        }

        PendingMagicLink::forget();

        return $this->json([
            'confirmed' => true,
            'redirect' => AuthSession::isAuthenticated() ? LoginReturn::consume() : '/',
        ]);
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
            return $this->json(['success' => false, 'error' => 'Vous devez accepter la politique de protection des '
                . 'données pour vous connecter.']);
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
            return $this->json(['success' => false, 'error' => 'Aucun membre actif n\'est associé à cette adresse '
                . 'email. Contactez un(e) responsable de l\'unité.']);
        }

        // Login successful
        $role = $this->resolveRole($account->email, $account->id);
        AuthSession::login($account->id, $account->email, $role, $this->installationGeneration);
        $this->storeLinkedMembers($account->email);
        $this->rememberLoginMethod('password');

        return $this->json(['success' => true, 'redirect' => LoginReturn::consume()]);
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

        // A body that cannot be read is a 400, never a 500 (issue #1131).
        $body = json_decode((string) $request->getRawBody(), true);
        if (!is_array($body)) {
            return $this->json(['success' => false, 'error' => WebAuthnMalformedRequest::USER_MESSAGE], 400);
        }

        if (!$this->hasRgpdConsent($request, $body)) {
            return $this->json(['success' => false, 'error' => 'Vous devez accepter la politique de protection des '
                . 'données pour vous connecter.']);
        }

        try {
            $account = $this->webAuthnService->verifyAuthentication($body);
        } catch (WebAuthnMalformedRequest) {
            return $this->json(['success' => false, 'error' => WebAuthnMalformedRequest::USER_MESSAGE], 400);
        } catch (WebAuthnChallengeExpired) {
            return $this->json(['success' => false, 'error' => WebAuthnChallengeExpired::USER_MESSAGE]);
        }

        if ($account === null) {
            return $this->json(['success' => false, 'error' => 'L\'authentification a échoué.']);
        }

        if (!$this->isMemberAuthorized($account->email)) {
            return $this->json(['success' => false, 'error' => 'Aucun membre actif n\'est associé à cette adresse '
                . 'email. Contactez un(e) responsable de l\'unité.']);
        }

        $role = $this->resolveRole($account->email, $account->id);
        AuthSession::login($account->id, $account->email, $role, $this->installationGeneration);
        $this->storeLinkedMembers($account->email);
        $this->rememberLoginMethod('passkey');

        return $this->json(['success' => true, 'redirect' => LoginReturn::consume()]);
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

        $this->forgetPushDevice($request);
        AuthSession::logout();
        PendingMagicLink::forget();
        LoginReturn::forget();
        ScoutYearSession::clear();
        TemporaryMemberSession::clear();
        // The help assistant's conversation dies here too: it lives only
        // in the session (locked decision, ARCHITECTURE.md §8.87), and a
        // shared computer must not hand the next person the previous
        // one's questions.
        \Core\Help\Assistant\AssistantSession::clear();
        FlashMessage::set('success', 'Vous avez été déconnecté.');

        return $this->redirect('/');
    }

    /**
     * The device logging out stops receiving notifications (issue #1123).
     * public/assets/js/push-logout.js has just unsubscribed it in the
     * browser and put its endpoint in the form's `push_endpoint` field;
     * every row for that endpoint goes, whichever account it was
     * registered for. A missing or malformed field forgets nothing, and
     * nothing here can stand between the visitor and being logged out.
     */
    private function forgetPushDevice(Request $request): void
    {
        $endpoint = $request->getBody('push_endpoint', '');
        if ($this->notificationService === null || !is_string($endpoint) || $endpoint === '') {
            return;
        }

        try {
            $this->notificationService->forgetDevice($endpoint);
        } catch (\Throwable) {
            // A database hiccup costs a stale row the next send prunes
            // (404/410), never the logout itself.
        }
    }

    /**
     * Resolve role using RoleResolver if available, fallback to is_super_admin check.
     *
     * The ladder below is deliberate, and the order of its rungs is a
     * security property rather than a style — see isMemberAuthorized(),
     * which walks the same one for the same reason.
     */
    private function resolveRole(string $email, ?int $userAccountId = null): string
    {
        if ($this->roleResolver !== null && $this->authorizationYearService !== null) {
            return $this->roleResolver->resolveAcrossYears(
                $email,
                $this->authorizationYearService->resolve()
            );
        }

        // A role system with no year SET still resolves, in the one year it
        // has. Falling through to the crude super-admin check here would
        // quietly downgrade a real chief to `identified`.
        if ($this->roleResolver !== null && $this->scoutYearResolver !== null) {
            return $this->roleResolver->resolve(
                $email,
                (int) $this->scoutYearResolver->getCurrentPublicYear()['id']
            );
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
     *
     * **One year, never the set** (see RoleResolver::getLinkedMemberYears()):
     * this is the list of member records the address reaches, and merging
     * two years of it would show the same child twice and offer a section
     * that no longer exists. The year is the one this person is actually
     * SERVED — their effective year — which under the transition rules is
     * the single year where they really have members. Resolving it against
     * the public year instead left the animateur recruited for the year
     * being prepared with an empty list on a site that had just let them in.
     *
     * The role has already been written to the session by the caller, so
     * getEffectiveYear() is asked with the role this login just granted.
     * The preview is passed as null rather than read from the session: a
     * login is not the moment to honour one, and clearing it is what
     * logging out already does.
     */
    private function storeLinkedMembers(string $email): void
    {
        if ($this->roleResolver !== null && $this->scoutYearResolver !== null) {
            $effective = $this->scoutYearResolver->getEffectiveYear(
                null,
                Role::fromString(AuthSession::getRole())
            );
            $linked = $this->roleResolver->getLinkedMemberYears($email, $effective->id);
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
     *
     * **`$roleResolver === null` is the whole of that condition, and
     * widening it is how this gate gets switched off by accident.** It is
     * the one dependency whose absence really means "this installation has
     * no role/member system"; a missing YEAR source means only that the
     * question must be asked in one year instead of a set, and the gate
     * still has to be asked. Keying the fail-open on the year source
     * instead would hand a free pass to any composition that omits an
     * optional constructor argument — and this gate is also where a
     * DEACTIVATED account is refused (`RoleResolver::
     * isEmailAuthorizedToLogin*()` checks `is_active` before the
     * super-admin short-circuit), so the pass would be wider than it
     * looks.
     */
    private function isMemberAuthorized(string $email): bool
    {
        if ($this->roleResolver === null) {
            return true;
        }

        if ($this->authorizationYearService !== null) {
            return $this->roleResolver->isEmailAuthorizedToLoginAcrossYears(
                $email,
                $this->authorizationYearService->resolve()
            );
        }

        if ($this->scoutYearResolver !== null) {
            return $this->roleResolver->isEmailAuthorizedToLogin(
                $email,
                (int) $this->scoutYearResolver->getCurrentPublicYear()['id']
            );
        }

        return true;
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
