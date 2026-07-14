<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Twig\Environment;

class AuthService
{
    private const TOKEN_EXPIRY_MINUTES = 15;
    private const MAX_REQUESTS_PER_HOUR = 5;

    private UserAccountRepository $userRepo;
    private MagicLinkRepository $magicLinkRepo;
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
        $this->magicLinkRepo = new MagicLinkRepository($pdo);
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * Step 1: User submits their email on the login page.
     *
     * Generates a magic link token and sends it to the user's email if the account exists.
     * Returns success regardless of whether the email exists (no enumeration).
     */
    public function requestMagicLink(string $email): MagicLinkResult
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

        // Rate limiting
        $recentCount = $this->magicLinkRepo->countRecentByEmail($blindIndex);
        if ($recentCount >= self::MAX_REQUESTS_PER_HOUR) {
            return new MagicLinkResult(
                success: false,
                magicLinkId: null,
                error: 'Trop de demandes. Veuillez réessayer dans une heure.'
            );
        }

        // Generate token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = new \DateTimeImmutable('+' . self::TOKEN_EXPIRY_MINUTES . ' minutes');

        // Store in database
        $magicLinkId = $this->magicLinkRepo->create($blindIndex, $tokenHash, $expiresAt);

        // Check if email exists in user_accounts
        $user = $this->userRepo->findByEmail($normalizedEmail);

        if ($user !== null) {
            // Send the magic link email
            $magicLinkUrl = rtrim($this->baseUrl, '/') . "/auth/verify?token={$rawToken}&id={$magicLinkId}";
            try {
                $this->sendMagicLinkEmail($normalizedEmail, $magicLinkUrl);
            } catch (\Throwable $e) {
                return new MagicLinkResult(
                    success: false,
                    magicLinkId: null,
                    error: 'Impossible d\'envoyer l\'email. Vérifiez la configuration SMTP.'
                );
            }
        }

        $this->journalService?->log(
            'core', 'magic_link_requested', 'info', 'Demande de lien magique',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
            null
        );

        return new MagicLinkResult(
            success: true,
            magicLinkId: $magicLinkId,
            error: null
        );
    }

    /**
     * Step 2: User clicks the link in their email.
     *
     * Verifies the token and marks the link as used.
     * Returns the verified magic link data, or null if invalid.
     */
    public function verifyMagicLink(int $id, string $rawToken): ?VerifiedMagicLink
    {
        $record = $this->magicLinkRepo->findById($id);

        if ($record === null) {
            return null;
        }

        // Check not used
        if ($record->used) {
            return null;
        }

        // Check not expired
        $now = new \DateTimeImmutable();
        if ($now > $record->expiresAt) {
            return null;
        }

        // Verify token hash
        if (!password_verify($rawToken, $record->tokenHash)) {
            return null;
        }

        // Mark as used
        $this->magicLinkRepo->markUsed($id);

        // Find the user account via blind index
        $user = $this->userRepo->findByBlindIndex($record->emailBlindIndex);

        if ($user === null) {
            return null;
        }

        // Update last login
        $this->userRepo->updateLastLogin($user->id);

        $this->journalService?->log(
            'core', 'login_success', 'security', 'Connexion par lien magique',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
            $user->id
        );

        return new VerifiedMagicLink(
            email: $user->email,
            userAccountId: $user->id
        );
    }

    /**
     * Step 3: Check if a specific magic link has been confirmed.
     * Used by the polling endpoint.
     */
    public function isMagicLinkConfirmed(int $id): bool
    {
        $record = $this->magicLinkRepo->findById($id);

        if ($record === null) {
            return false;
        }

        return $record->used && $record->confirmedAt !== null;
    }

    /**
     * Get the user account associated with a confirmed magic link.
     * Used by the polling endpoint to create a session on Device A.
     */
    public function getUserForConfirmedLink(int $id): ?UserAccount
    {
        $record = $this->magicLinkRepo->findById($id);

        if ($record === null || !$record->used || $record->confirmedAt === null) {
            return null;
        }

        return $this->userRepo->findByBlindIndex($record->emailBlindIndex);
    }

    /**
     * Get a user account by ID.
     */
    public function getUserById(int $id): ?UserAccount
    {
        return $this->userRepo->findById($id);
    }

    /**
     * Clean up expired magic links.
     */
    public function cleanupExpiredLinks(): int
    {
        return $this->magicLinkRepo->deleteExpired();
    }

    /**
     * Send the magic link email via MailService.
     */
    private function sendMagicLinkEmail(string $to, string $magicLinkUrl): void
    {
        $context = [
            'site_name' => $this->siteName,
            'magic_link_url' => $magicLinkUrl,
            'expiry_minutes' => self::TOKEN_EXPIRY_MINUTES,
        ];

        $bodyHtml = $this->twig->render('email/magic_link.html.twig', $context);
        $bodyText = $this->twig->render('email/magic_link.text.twig', $context);

        $this->mailService->send(
            to: $to,
            subject: 'Votre lien de connexion',
            bodyHtml: $bodyHtml,
            bodyText: $bodyText
        );
    }
}
