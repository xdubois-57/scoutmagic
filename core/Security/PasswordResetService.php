<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Twig\Environment;

/**
 * "Mot de passe oublié" flow — same token generation/hashing convention as
 * Core\Security\AuthService's magic links (bin2hex(random_bytes(32)),
 * hashed with password_hash() before storage, single-use via a "used"
 * flag), but a longer validity window since a reset requires more of the
 * user (find the email, think of a new password) than a login link.
 */
class PasswordResetService
{
    private const TOKEN_EXPIRY_MINUTES = 60;
    private const MAX_REQUESTS_PER_HOUR = 5;

    private UserAccountRepository $userRepo;
    private PasswordResetRepository $resetRepo;
    private ?JournalService $journalService = null;

    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption,
        private MailService $mailService,
        private Environment $twig,
        private string $baseUrl,
        private string $siteName
    ) {
        $pdo = $this->connection->getPdo();
        $this->userRepo = new UserAccountRepository($pdo, $this->encryption);
        $this->resetRepo = new PasswordResetRepository($pdo);
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * Always completes silently, whether or not the email matches an
     * account — the caller must show the exact same message either way
     * (no enumeration).
     */
    public function requestReset(string $email): void
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

        if ($this->resetRepo->countRecentByEmail($blindIndex) >= self::MAX_REQUESTS_PER_HOUR) {
            return;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = new \DateTimeImmutable('+' . self::TOKEN_EXPIRY_MINUTES . ' minutes');
        $tokenId = $this->resetRepo->create($blindIndex, $tokenHash, $expiresAt);

        $user = $this->userRepo->findByEmail($normalizedEmail);
        if ($user !== null) {
            $resetUrl = rtrim($this->baseUrl, '/') . "/password-reset/{$tokenId}?token={$rawToken}";
            try {
                $this->sendResetEmail($normalizedEmail, $resetUrl);
            } catch (\Throwable) {
                // Best-effort — a send failure must not leak whether the account exists either.
            }
        }

        $this->journalService?->log(
            'core', 'password_reset_requested', 'info', 'Demande de réinitialisation de mot de passe',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''], null
        );
    }

    /**
     * Whether $id/$rawToken is still valid — for rendering the reset form
     * (GET) without consuming the token, so reloading the page or the
     * browser prefetching the link doesn't burn it.
     */
    public function checkToken(int $id, string $rawToken): bool
    {
        return $this->loadValidToken($id, $rawToken) !== null;
    }

    /**
     * Re-validates the token, then atomically consumes it and sets the new
     * password. Returns false (nothing changed) when the token is invalid/
     * expired/already used, the account no longer exists, or the password
     * fails Core\Security\PasswordPolicy.
     */
    public function resetPassword(int $id, string $rawToken, string $newPassword): bool
    {
        $token = $this->loadValidToken($id, $rawToken);
        if ($token === null || !PasswordPolicy::isValid($newPassword)) {
            return false;
        }

        $user = $this->userRepo->findByBlindIndex($token->emailBlindIndex);
        if ($user === null) {
            return false;
        }

        $this->resetRepo->markUsed($id);
        $this->userRepo->updatePasswordHash($user->id, password_hash($newPassword, PASSWORD_DEFAULT));

        $this->journalService?->log(
            'core', 'password_reset_completed', 'security', 'Mot de passe réinitialisé via lien de réinitialisation',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''], $user->id
        );

        return true;
    }

    public function cleanupExpiredTokens(): int
    {
        return $this->resetRepo->deleteExpired();
    }

    private function loadValidToken(int $id, string $rawToken): ?PasswordResetToken
    {
        $token = $this->resetRepo->findById($id);
        if ($token === null || $token->used) {
            return null;
        }
        if ((new \DateTimeImmutable()) > $token->expiresAt) {
            return null;
        }
        if (!password_verify($rawToken, $token->tokenHash)) {
            return null;
        }

        return $token;
    }

    private function sendResetEmail(string $to, string $resetUrl): void
    {
        $context = [
            'site_name' => $this->siteName,
            'reset_url' => $resetUrl,
            'expiry_minutes' => self::TOKEN_EXPIRY_MINUTES,
        ];

        $bodyHtml = $this->twig->render('email/password_reset.html.twig', $context);
        $bodyText = $this->twig->render('email/password_reset.text.twig', $context);

        $this->mailService->send(
            to: $to,
            subject: 'Réinitialisation de votre mot de passe',
            bodyHtml: $bodyHtml,
            bodyText: $bodyText
        );
    }
}
