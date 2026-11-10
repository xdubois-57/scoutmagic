<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Member\MemberEmailRepository;

class AuthService
{
    private const TOKEN_EXPIRY_MINUTES = 15;
    private const MAX_REQUESTS_PER_HOUR = 5;

    /**
     * Wrong match codes a link survives before it is spent (issue #1099).
     * With a six-digit code, three guesses give one chance in 333 333.
     */
    public const MAX_MATCH_ATTEMPTS = 3;

    private UserAccountRepository $userRepo;
    private MagicLinkRepository $magicLinkRepo;
    private MemberEmailRepository $memberEmailRepo;
    private ?JournalService $journalService = null;

    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption,
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
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

        // And the code only the requesting window is shown (issue #1099).
        // The emailed link alone proves the mailbox was READ — by its owner,
        // or by a mail scanner following every URL —, not that its owner is
        // the one who asked. Confirming from any other browser requires
        // this code, which never leaves the window that typed the address.
        // Generated for every request, account or not, so the answer stays
        // the same whether the address exists (no enumeration).
        $matchCode = sprintf('%06d', random_int(0, 999999));
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
        $magicLinkId = $this->magicLinkRepo->create(
            $blindIndex,
            $tokenHash,
            $expiresAt,
            password_hash($matchCode, PASSWORD_DEFAULT)
        );

        if ($user !== null || $viaSecondaryEmail) {
            // Send the magic link email
            $magicLinkUrl = rtrim($this->baseUrl, '/') . "/auth/verify?token={$rawToken}&id={$magicLinkId}";
            try {
                $this->sendMagicLinkEmail($normalizedEmail, $magicLinkUrl);
            } catch (\Throwable $e) {
                $reason = str_replace($normalizedEmail, '[adresse]', $e->getMessage());
                $this->journalService?->log(
                    'core',
                    'magic_link_send_failed',
                    'info',
                    "Échec de l'envoi de l'email de lien magique",
                    ['error' => $reason, 'via_secondary_email' => $viaSecondaryEmail],
                    $user?->id
                );

                return new MagicLinkResult(
                    success: false,
                    magicLinkId: null,
                    error: 'Impossible d\'envoyer l\'email. Vérifiez la configuration SMTP.'
                );
            }

            $this->journalService?->log(
                'core',
                'magic_link_email_sent',
                'info',
                'Email de lien magique envoyé',
                ['via_secondary_email' => $viaSecondaryEmail],
                $user?->id
            );
        } else {
            // No enumeration in the response (still returns success below)
            // but worth a distinct journal entry — an admin trying to
            // diagnose "my colleague never got the email" needs to be able
            // to tell "no account matched at all" apart from "matched but
            // the send failed" apart from "sent fine, check spam".
            $this->journalService?->log(
                'core',
                'magic_link_no_account_found',
                'info',
                'Demande de lien magique pour une adresse sans compte correspondant',
                [],
                null
            );
        }

        $this->journalService?->log(
            'core',
            'magic_link_requested',
            'info',
            'Demande de lien magique',
            // No `ip` here: JournalService::log() already writes the
            // address into `event_log.ip_address`. A second copy in the
            // JSON is the same personal datum stored twice in one row
            // (ARCHITECTURE.md §8.49sexies).
            [],
            null
        );

        return new MagicLinkResult(
            success: true,
            magicLinkId: $magicLinkId,
            error: null,
            matchCode: $matchCode
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
            'core',
            'account_created_for_secondary_email',
            'security',
            'Compte créé pour une adresse email secondaire confirmée',
            [],
            $created->id
        );

        return $created;
    }

    /**
     * Step 2a: the link from the e-mail is OPENED — a GET, which a mail
     * scanner performs as readily as a person (issue #1099). Checks the
     * token and says whether the link can still be confirmed, without
     * consuming or confirming anything: only verifyMagicLink(), behind a
     * POST, does that.
     */
    public function checkMagicLink(int $id, string $rawToken): ?MagicLinkRecord
    {
        $record = $this->magicLinkRepo->findById($id);

        if ($record === null || $record->used || new \DateTimeImmutable() > $record->expiresAt) {
            return null;
        }

        return password_verify($rawToken, $record->tokenHash) ? $record : null;
    }

    /**
     * Step 2b: the visitor CONFIRMS — a POST from the page step 2a showed.
     *
     * Verifies the token again and marks the link as used. $matchCode is
     * the code the requesting window shows, required whenever the
     * confirmation does not come from that very session (the caller knows,
     * through PendingMagicLink); null means it does. A wrong code confirms
     * nothing, counts against the link, and spends it at
     * MAX_MATCH_ATTEMPTS.
     *
     * Returns the verified magic link data, or null if invalid.
     *
     * @throws MagicLinkCodeMismatch when $matchCode is given and wrong
     */
    public function verifyMagicLink(int $id, string $rawToken, ?string $matchCode = null): ?VerifiedMagicLink
    {
        $record = $this->checkMagicLink($id, $rawToken);

        if ($record === null) {
            return null;
        }

        if ($matchCode !== null) {
            // The attempt is counted before the code is compared, so that
            // parallel requests cannot each get a guess in while the
            // counter still reads low (one atomic UPDATE gates them).
            $attempts = $this->magicLinkRepo->reserveMatchAttempt($id, self::MAX_MATCH_ATTEMPTS);
            if ($attempts === null) {
                throw new MagicLinkCodeMismatch(true);
            }
            $typed = preg_replace('/\D/', '', $matchCode) ?? '';
            if ($record->matchCodeHash === null || $typed === '' || !password_verify($typed, $record->matchCodeHash)) {
                $spent = $attempts >= self::MAX_MATCH_ATTEMPTS;
                if ($spent) {
                    $this->magicLinkRepo->invalidate($id);
                }
                $this->journalService?->log(
                    'core',
                    'magic_link_code_mismatch',
                    'security',
                    $spent
                        ? 'Lien magique annulé : trop de codes de confirmation erronés'
                        : 'Code de confirmation de lien magique erroné',
                    ['magic_link_id' => $id, 'attempts' => $attempts],
                    null
                );

                throw new MagicLinkCodeMismatch($spent);
            }
        }

        // Mark as used — and only the request that actually claims the
        // link goes on: a concurrent confirmation of the same link, or one
        // that a third wrong code spent meanwhile, stops here.
        if (!$this->magicLinkRepo->markUsed($id)) {
            return null;
        }

        // Find (or, for a confirmed secondary address logging in for the
        // first time, create) the account this link identifies.
        $user = $this->resolveOrCreateAccountForBlindIndex($record->emailBlindIndex);

        if ($user === null) {
            return null;
        }

        // Update last login
        $this->userRepo->updateLastLogin($user->id);

        $this->journalService?->log(
            'core',
            'login_success',
            'security',
            'Connexion par lien magique',
            // No `ip` here: JournalService::log() already writes the
            // address into `event_log.ip_address`. A second copy in the
            // JSON is the same personal datum stored twice in one row
            // (ARCHITECTURE.md §8.49sexies).
            [],
            $user->id
        );
        if ($matchCode !== null) {
            // Confirmed from a browser other than the one that asked,
            // proven by the code: the asking window is about to be
            // identified. A line of its own, distinct from a same-browser
            // login (issue #1099).
            $this->journalService?->log(
                'core',
                'magic_link_confirmed_elsewhere',
                'security',
                'Lien magique confirmé depuis un autre navigateur, avec le code de la fenêtre demandeuse',
                ['magic_link_id' => $id],
                $user->id
            );
        }

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

        // Expired is expired, confirmed or not (issue #1099): a link
        // confirmed in time is collected within its fifteen minutes, never
        // for the rest of the requesting session's life.
        return $record->used && $record->confirmedAt !== null && new \DateTimeImmutable() <= $record->expiresAt;
    }

    /**
     * Get the user account associated with a confirmed magic link.
     * Used by the polling endpoint to create a session on Device A.
     */
    public function getUserForConfirmedLink(int $id): ?UserAccount
    {
        $record = $this->magicLinkRepo->findById($id);

        if (
            $record === null || !$record->used || $record->confirmedAt === null
            || new \DateTimeImmutable() > $record->expiresAt
        ) {
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
     * Clean up expired magic links — those past the rate-limit hour too
     * (MagicLinkRepository::deleteExpired()). Run by
     * Task\PurgeExpiredMagicLinksHandler.
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

        // Through the e-mail register (Core\Mail\Template), which for this
        // one can only ever answer with the shipped template: the magic
        // link is declared `editable: false`, so no customisation of it can
        // exist to be found. Routing it here anyway keeps one rendering
        // path rather than two.
        $email = $this->emailTemplateRenderer->render('magic_link', $context);

        // The one send() call in the whole codebase that states a
        // purpose. It is what lets the mail sandbox let sign-in links
        // through while capturing everything else (ARCHITECTURE.md
        // §8.63); everywhere else the default `Ordinary` applies and
        // nothing knows this enum exists.
        $this->mailService->send(
            to: $to,
            subject: $email->subject,
            bodyHtml: $email->bodyHtml,
            bodyText: $email->bodyText,
            purpose: MailPurpose::MagicLink
        );
    }
}
