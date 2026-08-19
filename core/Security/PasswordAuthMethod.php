<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Journal\JournalService;

class PasswordAuthMethod
{
    /**
     * Burned through password_verify() whenever there is no real hash to
     * check against, so "no such account" and "account with no password
     * set" cost the same wall-clock time as a wrong password instead of
     * returning early. The uniform error message on its own doesn't buy
     * no-enumeration if the response time still separates the two cases.
     * Any valid bcrypt hash does — this is the hash of a value nothing can
     * submit, and the comparison result is discarded.
     */
    private const DUMMY_HASH = '$2y$12$gfsuXxms7C92/nLKTGwn9eOOv/Md7CmuXncMSuN9vL/JWbru3V6yi';

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
        // Counted alongside the email so a spray of one password across many
        // accounts from a single source is slowed too — per-email counting
        // alone never accumulates enough failures on any one address to see it.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // Check lockout
        $lockout = $this->throttler->getLockoutRemaining($blindIndex, $ip);
        if ($lockout > 0) {
            $this->journalService?->log(
                'core', 'login_lockout', 'security', 'Compte temporairement verrouillé (trop de tentatives)',
                ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'locked_seconds' => $lockout]
            );
            return ['account' => null, 'locked_seconds' => $lockout];
        }

        // Find user by email
        $account = $this->userAccountRepo->findByEmail($email);

        // No account found, or an account with no password set — same
        // behavior AND the same cost as a wrong password (no enumeration,
        // by response content and by timing alike).
        if ($account === null || $account->passwordHash === null) {
            password_verify($password, self::DUMMY_HASH);
            $this->throttler->recordFailure($blindIndex, $ip);
            return ['account' => null, 'locked_seconds' => 0];
        }

        // Verify password
        if (!password_verify($password, $account->passwordHash)) {
            $this->throttler->recordFailure($blindIndex, $ip);
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
