<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberEmailRepository;
use Twig\Environment;

class AuthService
{
    private const TOKEN_EXPIRY_MINUTES = 15;
    private const MAX_REQUESTS_PER_HOUR = 5;

    private UserAccountRepository $userRepo;
    private MagicLinkRepository $magicLinkRepo;
    private MemberEmailRepository $memberEmailRepo;
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
        $this->memberEmailRepo = new MemberEmailRepository($pdo, $this->encryption);
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
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');

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

        // Can this address log in at all? Either it already has a
        // user_accounts row of its own (Desk-imported address, or an
        // address that has logged in here before), or it is currently a
        // 'valid' secondary address (Core\Member\MemberEmailService) of
        // some member — in which case verifyMagicLink() will give it its
        // OWN account row once the token is actually proven.
        //
        // The link is always stored against the SUBMITTED blind index,
        // never a resolved one: that index is the identity this link
        // confers, and it is also what countRecentByEmail() above counts,
        // so storing anything else would both hand out somebody else's
        // session and silently disable the rate limit.
        $user = $this->userRepo->findByBlindIndex($blindIndex);
        $viaSecondaryEmail = $user === null && $this->secondaryLoginAddress($blindIndex) !== null;

        // Store in database
        $magicLinkId = $this->magicLinkRepo->create($blindIndex, $tokenHash, $expiresAt);

        if ($user !== null || $viaSecondaryEmail) {
            // Send the magic link email
            $magicLinkUrl = rtrim($this->baseUrl, '/') . "/auth/verify?token={$rawToken}&id={$magicLinkId}";
            try {
                $this->sendMagicLinkEmail($normalizedEmail, $magicLinkUrl);
            } catch (\Throwable $e) {
                $reason = str_replace($normalizedEmail, '[adresse]', $e->getMessage());
                $this->journalService?->log(
                    'core', 'magic_link_send_failed', 'info', "Échec de l'envoi de l'email de lien magique",
                    ['error' => $reason, 'via_secondary_email' => $viaSecondaryEmail], $user?->id
                );

                return new MagicLinkResult(
                    success: false,
                    magicLinkId: null,
                    error: 'Impossible d\'envoyer l\'email. Vérifiez la configuration SMTP.'
                );
            }

            $this->journalService?->log(
                'core', 'magic_link_email_sent', 'info', 'Email de lien magique envoyé',
                ['via_secondary_email' => $viaSecondaryEmail], $user?->id
            );
        } else {
            // No enumeration in the response (still returns success below)
            // but worth a distinct journal entry — an admin trying to
            // diagnose "my colleague never got the email" needs to be able
            // to tell "no account matched at all" apart from "matched but
            // the send failed" apart from "sent fine, check spam".
            $this->journalService?->log(
                'core', 'magic_link_no_account_found', 'info',
                'Demande de lien magique pour une adresse sans compte correspondant',
                [], null
            );
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
     * The plaintext address behind $blindIndex when it is currently a
     * 'valid' secondary address of at least one member, null otherwise —
     * a 'pending'/'inactive' row never resolves a login (same rule as
     * Core\Security\RoleResolver). Every row sharing a blind index is the
     * same address, so the first one carries the plaintext we need to
     * create that address its own account.
     */
    private function secondaryLoginAddress(string $blindIndex): ?string
    {
        foreach ($this->memberEmailRepo->findValidByBlindIndex($blindIndex) as $row) {
            return $row->email;
        }

        return null;
    }

    /**
     * The account this blind index logs in as, created on the spot for a
     * secondary address that does not have one yet.
     *
     * A secondary address is an identity in its own right, NOT an alias
     * for the account of whichever member it happens to be attached to:
     * giving it the member's primary account would hand whoever controls
     * that address the member's whole account — every member linked to the
     * primary address, its role, its password and passkeys — from a single
     * confirmed address on one member. So it gets its own row (never a
     * super-admin one), and everything downstream that keys off the
     * session — Core\Security\RoleResolver, Core\Member\MemberService's
     * linked members, Core\Security\SessionRevalidator — then resolves
     * exactly the members that address is attached to, and nothing else.
     *
     * Only ever called from verifyMagicLink(), i.e. after possession of
     * the emailed token has been proven; requestMagicLink() deliberately
     * creates nothing, so typing an address at the login form can never
     * write a row.
     */
    private function resolveOrCreateAccountForBlindIndex(string $blindIndex): ?UserAccount
    {
        $user = $this->userRepo->findByBlindIndex($blindIndex);
        if ($user !== null) {
            return $user;
        }

        $address = $this->secondaryLoginAddress($blindIndex);
        if ($address === null) {
            return null;
        }

        try {
            $created = $this->userRepo->create($address, false);
        } catch (\PDOException $e) {
            // user_accounts.email_blind_index is UNIQUE — a concurrent
            // login for the same address won the race, so use its row.
            $existing = $this->userRepo->findByBlindIndex($blindIndex);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        $this->journalService?->log(
            'core', 'account_created_for_secondary_email', 'security',
            'Compte créé pour une adresse email secondaire confirmée',
            [], $created->id
        );

        return $created;
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

        // Find (or, for a confirmed secondary address logging in for the
        // first time, create) the account this link identifies.
        $user = $this->resolveOrCreateAccountForBlindIndex($record->emailBlindIndex);

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

        // verifyMagicLink() has necessarily run for a confirmed link, so
        // the account exists by now — a plain lookup, never a creation.
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
