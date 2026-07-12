<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\UserAccountRepository;
use Core\Security\WebAuthnCredentialRepository;
use Core\Security\WebAuthnService;
use Twig\Environment;

class AccountController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private UserAccountRepository $userAccountRepo,
        private WebAuthnCredentialRepository $webAuthnCredentialRepo,
        private WebAuthnService $webAuthnService
    ) {
    }

    /**
     * GET /account — render the Mon compte page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->redirect('/login');
        }

        $account = $this->userAccountRepo->findById($userId);
        if ($account === null) {
            return $this->redirect('/login');
        }

        $passkeys = $this->webAuthnCredentialRepo->findByUserAccountId($userId);
        $hasPassword = $this->userAccountRepo->hasPassword($userId);

        return $this->render('account/index.html.twig', [
            'account' => $account,
            'has_password' => $hasPassword,
            'passkeys' => $passkeys,
        ]);
    }

    /**
     * POST /account/profile — update name and surname.
     *
     * @param array<string, string> $params
     */
    public function updateProfile(Request $request, array $params): Response
    {
        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->redirect('/login');
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Session expirée. Veuillez réessayer.');
            return $this->redirect('/account');
        }

        $firstName = trim((string) $request->getBody('first_name', ''));
        $lastName = trim((string) $request->getBody('last_name', ''));

        $this->userAccountRepo->updateProfile(
            $userId,
            $firstName !== '' ? $firstName : null,
            $lastName !== '' ? $lastName : null
        );

        FlashMessage::set('success', 'Profil mis à jour.');
        return $this->redirect('/account');
    }

    /**
     * POST /account/password — set or change password.
     *
     * @param array<string, string> $params
     */
    public function updatePassword(Request $request, array $params): Response
    {
        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->redirect('/login');
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Session expirée. Veuillez réessayer.');
            return $this->redirect('/account');
        }

        $hasPassword = $this->userAccountRepo->hasPassword($userId);
        $currentPassword = (string) $request->getBody('current_password', '');
        $newPassword = (string) $request->getBody('new_password', '');
        $confirmPassword = (string) $request->getBody('confirm_password', '');

        // Validate current password if user already has one
        if ($hasPassword) {
            $account = $this->userAccountRepo->findById($userId);
            if ($account === null || $account->passwordHash === null || !password_verify($currentPassword, $account->passwordHash)) {
                FlashMessage::set('error', 'Le mot de passe actuel est incorrect.');
                return $this->redirect('/account');
            }
        }

        // Validate new password
        if (strlen($newPassword) < 8) {
            FlashMessage::set('error', 'Le mot de passe doit contenir au moins 8 caractères.');
            return $this->redirect('/account');
        }

        if ($newPassword !== $confirmPassword) {
            FlashMessage::set('error', 'Les mots de passe ne correspondent pas.');
            return $this->redirect('/account');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->userAccountRepo->updatePasswordHash($userId, $hash);

        FlashMessage::set('success', $hasPassword ? 'Mot de passe mis à jour.' : 'Mot de passe défini.');
        return $this->redirect('/account');
    }

    /**
     * GET /account/passkey/register-options — get WebAuthn registration options (AJAX).
     *
     * @param array<string, string> $params
     */
    public function passkeyRegisterOptions(Request $request, array $params): Response
    {
        $userId = AuthSession::getUserAccountId();
        $email = AuthSession::getEmail();

        if ($userId === null || $email === null) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }

        $options = $this->webAuthnService->generateRegistrationOptions($userId, $email);
        return $this->json($options);
    }

    /**
     * POST /account/passkey/register — complete passkey registration (AJAX).
     *
     * @param array<string, string> $params
     */
    public function passkeyRegister(Request $request, array $params): Response
    {
        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }

        $body = json_decode((string) $request->getRawBody(), true);
        if (!is_array($body)) {
            return $this->json(['success' => false, 'error' => 'Données invalides.']);
        }

        $deviceLabel = trim((string) ($body['device_label'] ?? 'Clé sans nom'));
        if ($deviceLabel === '') {
            $deviceLabel = 'Clé sans nom';
        }

        try {
            $this->webAuthnService->verifyRegistration($userId, $body, $deviceLabel);
            return $this->json(['success' => true]);
        } catch (\RuntimeException $e) {
            return $this->json(['success' => false, 'error' => 'L\'enregistrement a échoué.']);
        }
    }

    /**
     * POST /account/passkey/delete — remove a passkey (AJAX).
     *
     * @param array<string, string> $params
     */
    public function passkeyDelete(Request $request, array $params): Response
    {
        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }

        $body = json_decode((string) $request->getRawBody(), true);
        $id = (int) ($body['id'] ?? 0);

        if ($id === 0) {
            return $this->json(['success' => false, 'error' => 'ID invalide.']);
        }

        // Verify the credential belongs to this user
        $credentials = $this->webAuthnCredentialRepo->findByUserAccountId($userId);
        $found = false;
        foreach ($credentials as $cred) {
            if ((int) $cred['id'] === $id) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->json(['success' => false, 'error' => 'Clé introuvable.']);
        }

        $this->webAuthnCredentialRepo->delete($id);
        return $this->json(['success' => true]);
    }
}
