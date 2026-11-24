<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Database\Connection;
use Core\Database\TransactionScope;
use Core\Http\AfterResponse;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;

/**
 * "Mot de passe oublié" flow — same token generation/hashing convention as
 * Core\Security\AuthService's magic links (bin2hex(random_bytes(32)),
 * hashed with password_hash() before storage, single-use via a "used"
 * flag), but a longer validity window since a reset requires more of the
 * user (find the email, think of a new password) than a login link.
 *
 * @phpstan-import-type LiveAccesses from AccountAccessRevoker
 */
class PasswordResetService
{
    private const TOKEN_EXPIRY_MINUTES = 60;
    private const MAX_REQUESTS_PER_HOUR = 5;

    /** The journal event a failed send writes; what the attention points count (issue #1267). */
    public const EVENT_SEND_FAILED = 'password_reset_send_failed';

    private UserAccountRepository $userRepo;
    private PasswordResetRepository $resetRepo;
    private ?JournalService $journalService = null;
    private ?AccountAccessRevoker $accessRevoker = null;
    private ?AfterResponse $afterResponse = null;

    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption,
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private string $baseUrl,
        private string $siteName,
        ?PasswordResetRepository $resetRepo = null
    ) {
        $pdo = $this->connection->getPdo();
        $this->userRepo = new UserAccountRepository($pdo, $this->encryption);
        // The parameter is a seam for the test that plays the loser of a race
        // for the same token; production never passes one.
        $this->resetRepo = $resetRepo ?? new PasswordResetRepository($pdo);
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * Late, like the journal: with it the e-mail leaves after the answer
     * (issue #1267). Production always sets it; with none, the e-mail is sent
     * inside the request, which is what the tests and the CLI want.
     */
    public function setAfterResponse(AfterResponse $afterResponse): void
    {
        $this->afterResponse = $afterResponse;
    }

    /**
     * Late, like the journal: the device credentials it reaches are wired
     * long after this service in public/index.php. Production always sets
     * it; with none, a reset ends the sessions and nothing else.
     */
    public function setAccessRevoker(AccountAccessRevoker $accessRevoker): void
    {
        $this->accessRevoker = $accessRevoker;
    }

    /**
     * Always completes silently, whether or not the email matches an
     * account — the caller must show the exact same message either way
     * (no enumeration).
     */
    public function requestReset(string $email): void
    {
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');

        if ($this->resetRepo->countRecentByEmail($blindIndex) >= self::MAX_REQUESTS_PER_HOUR) {
            return;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = new \DateTimeImmutable('+' . self::TOKEN_EXPIRY_MINUTES . ' minutes');
        $tokenId = $this->resetRepo->create($blindIndex, $tokenHash, $expiresAt);

        $user = $this->userRepo->findByEmail($normalizedEmail);

        $this->journalService?->log(
            'core',
            'password_reset_requested',
            'info',
            'Demande de réinitialisation de mot de passe',
            // See AuthService: the address is already event_log.ip_address.
            [],
            null
        );

        // The e-mail is the only thing that differs between an address with an
        // account and one without, so it leaves AFTER the answer (issue #1267,
        // Core\Http\AfterResponse): sent inside the request, the SMTP
        // connection would make an address that has an account answer later.
        // An address with no account hands over nothing to do, and the wait a
        // host without early finish adds is the same for both.
        if ($user === null) {
            $this->afterTheAnswer(null);

            return;
        }

        // The token rides in the URL FRAGMENT, not the query string.
        // A fragment is never sent to the server, so it stays out of
        // access logs, proxy logs and any Referer header the reset page
        // emits — all places a query-string token would land in
        // cleartext. The page's own script lifts it out of
        // location.hash and posts it back (see
        // Core\Http\Controller\PasswordResetController::show()).
        $resetUrl = rtrim($this->baseUrl, '/') . "/password-reset/{$tokenId}#{$rawToken}";
        $userId = $user->id;
        $this->afterTheAnswer(function () use ($normalizedEmail, $resetUrl, $userId): void {
            try {
                $this->sendResetEmail($normalizedEmail, $resetUrl);
            } catch (\Throwable $e) {
                // Never told to the visitor — a send failure must not leak
                // whether the account exists either. Journaled instead, the
                // address taken out of the reason, and the administrators
                // see it on the attention points (Core\Security\
                // LoginMailAttentionProvider): a reset mail that never
                // leaves would otherwise be a silent failure for everybody.
                $reason = str_replace($normalizedEmail, '[adresse]', $e->getMessage());
                $this->journalService?->log(
                    'core',
                    self::EVENT_SEND_FAILED,
                    'info',
                    "Échec de l'envoi de l'email de réinitialisation de mot de passe",
                    ['error' => $reason],
                    $userId
                );
            }
        });
    }

    /** Run $work once the answer has been given — at once when nothing was wired. */
    private function afterTheAnswer(?\Closure $work): void
    {
        if ($this->afterResponse === null) {
            if ($work !== null) {
                $work();
            }

            return;
        }

        $this->afterResponse->run($work);
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
     * The passkeys and device credentials the reset page offers to take
     * back (issue #1115), or null when the token is not usable — the same
     * answer checkToken() gives, so a dead link never lists anything.
     *
     * Holding a valid reset link is proof of the account's mailbox, which
     * is what this page already trusts with a new password; the names
     * listed are the ones the holder typed, nothing more.
     *
     * Empty lists when no revoker is wired: the page then lists nothing,
     * and the reset ends the sessions only, as it did before.
     *
     * @return LiveAccesses|null
     */
    public function liveAccessesForToken(int $id, string $rawToken): ?array
    {
        $user = $this->userForToken($id, $rawToken);
        if ($user === null) {
            return null;
        }

        return $this->accessRevoker?->liveAccesses($user->id) ?? ['passkeys' => [], 'devices' => []];
    }

    /**
     * Re-validates the token, then atomically consumes it and sets the new
     * password. Returns false (nothing changed) when the token is invalid/
     * expired/already used — including when a parallel request consumed it
     * between this one's check and its claim —, the account no longer
     * exists, or the password fails Core\Security\PasswordPolicy.
     *
     * A reset is also how a holder takes their account back, so it
     * revokes every passkey and every device credential the account holds,
     * except the ones named in the two keep lists (issue #1115; see
     * Core\Security\AccountAccessRevoker for why the kept ones are named
     * rather than the revoked ones). Sessions end regardless, through
     * updatePasswordHash().
     *
     * @param list<int> $keepPasskeyIds
     * @param list<int> $keepDeviceIds
     */
    public function resetPassword(
        int $id,
        string $rawToken,
        string $newPassword,
        array $keepPasskeyIds = [],
        array $keepDeviceIds = []
    ): bool {
        $token = $this->loadValidToken($id, $rawToken);
        if ($token === null || !PasswordPolicy::isValid($newPassword)) {
            return false;
        }

        $user = $this->userRepo->findByBlindIndex($token->emailBlindIndex);
        if ($user === null) {
            return false;
        }

        // Claiming the token and writing the password are one unit of work
        // (issue #1257). The claim is a single conditional UPDATE, so of two
        // requests holding the same link only one gets past it — the other
        // changes nothing — and a password that cannot be written does not
        // leave the link spent.
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $claimed = TransactionScope::run($this->connection->getPdo(), function () use ($id, $user, $hash): bool {
            if (!$this->resetRepo->markUsed($id)) {
                return false;
            }
            $this->userRepo->updatePasswordHash($user->id, $hash);

            return true;
        });
        if (!$claimed) {
            return false;
        }

        $this->journalService?->log(
            'core',
            'password_reset_completed',
            'security',
            'Mot de passe réinitialisé via lien de réinitialisation',
            [],
            $user->id
        );

        $this->accessRevoker?->revoke(
            $user->id,
            $keepPasskeyIds,
            $keepDeviceIds,
            AccountAccessRevoker::REASON_PASSWORD_RESET,
            $user->id
        );

        return true;
    }

    private function userForToken(int $id, string $rawToken): ?UserAccount
    {
        $token = $this->loadValidToken($id, $rawToken);

        return $token === null ? null : $this->userRepo->findByBlindIndex($token->emailBlindIndex);
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

        // Through the e-mail register (Core\Mail\Template). Declared
        // `editable: false`, so this always renders the shipped template —
        // see AuthService::sendMagicLinkEmail() for why it goes through the
        // renderer regardless.
        $email = $this->emailTemplateRenderer->render('password_reset', $context);

        $this->mailService->send(
            to: $to,
            subject: $email->subject,
            bodyHtml: $email->bodyHtml,
            bodyText: $email->bodyText
        );
    }
}
