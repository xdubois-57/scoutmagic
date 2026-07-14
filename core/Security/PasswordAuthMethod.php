<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Journal\JournalService;

class PasswordAuthMethod
{
    private ?JournalService $journalService = null;

    public function __construct(
        private UserAccountRepository $userAccountRepo,
        private EncryptionService $encryption,
        private LoginThrottler $throttler
    ) {
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * Attempt login with email + password.
     *
     * @param array{email: string, password: string} $input
     * @return array{account: UserAccount|null, locked_seconds: int}
     */
    public function attempt(array $input): array
    {
        $email = strtolower(trim($input['email']));
        $password = $input['password'];
        $blindIndex = $this->encryption->blindIndex($email);

        // Check lockout
        $lockout = $this->throttler->getLockoutRemaining($blindIndex);
        if ($lockout > 0) {
            $this->journalService?->log(
                'core', 'login_lockout', 'security', 'Compte temporairement verrouillé (trop de tentatives)',
                ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'locked_seconds' => $lockout]
            );
            return ['account' => null, 'locked_seconds' => $lockout];
        }

        // Find user by email
        $account = $this->userAccountRepo->findByEmail($email);

        // No account found — same behavior as wrong password (no enumeration)
        if ($account === null) {
            $this->throttler->recordFailure($blindIndex);
            return ['account' => null, 'locked_seconds' => 0];
        }

        // Account has no password set
        if ($account->passwordHash === null) {
            $this->throttler->recordFailure($blindIndex);
            return ['account' => null, 'locked_seconds' => 0];
        }

        // Verify password
        if (!password_verify($password, $account->passwordHash)) {
            $this->throttler->recordFailure($blindIndex);
            return ['account' => null, 'locked_seconds' => 0];
        }

        // Success — clear failures
        $this->throttler->clearFailures($blindIndex);

        $this->journalService?->log(
            'core', 'login_success', 'security', 'Connexion par mot de passe',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
            $account->id
        );

        return ['account' => $account, 'locked_seconds' => 0];
    }
}
