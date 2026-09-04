<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Repository;

use Core\Security\CapabilityToken;
use Core\Security\DecryptionException;
use Core\Security\EncryptionService;

/**
 * One row per user_account, holding the bearer token for their personal ICS
 * feed. Regeneration replaces the token in place — the old link stops
 * matching immediately (real revocation, no history kept).
 *
 * The token is a long-lived bearer credential that must stay re-displayable
 * (the feed URL lives in the user's calendar app), so it is encrypted at
 * rest with a blind index for lookup — same pattern as user_accounts.email,
 * and unlike single-use credentials (magic links, resets) which are hashed.
 */
class CalendarPersonalTokenRepository
{
    private const ENCRYPTION_CONTEXT = 'calendar_personal_tokens.token';
    private const BLIND_INDEX_PURPOSE = 'calendar_personal_token';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function findTokenByUserAccountId(int $userAccountId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT token_encrypted FROM calendar_personal_tokens WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);
        $encrypted = $stmt->fetchColumn();

        return $encrypted !== false
            ? $this->encryption->decrypt((string) $encrypted, self::ENCRYPTION_CONTEXT)
            : null;
    }

    public function findUserAccountIdByToken(string $token): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_account_id, token_encrypted FROM calendar_personal_tokens WHERE token_blind_index = ?'
        );
        $stmt->execute([$this->encryption->blindIndex($token, self::BLIND_INDEX_PURPOSE)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Verify exact match (blind index collisions are theoretically possible).
        try {
            $stored = $this->encryption->decrypt((string) $row['token_encrypted'], self::ENCRYPTION_CONTEXT);
        } catch (DecryptionException) {
            return null;
        }

        return CapabilityToken::equalsConstantTime($stored, $token) ? (int) $row['user_account_id'] : null;
    }

    /**
     * Insert-or-replace the token for a user account.
     */
    public function setToken(int $userAccountId, string $token): void
    {
        $encrypted = $this->encryption->encrypt($token, self::ENCRYPTION_CONTEXT);
        $blindIndex = $this->encryption->blindIndex($token, self::BLIND_INDEX_PURPOSE);

        $existing = $this->findTokenByUserAccountId($userAccountId);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO calendar_personal_tokens (user_account_id, token_encrypted, token_blind_index) VALUES '
                    . '(?, ?, ?)'
            );
            $stmt->execute([$userAccountId, $encrypted, $blindIndex]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE calendar_personal_tokens SET token_encrypted = ?, token_blind_index = ? WHERE user_account_id = ?'
        );
        $stmt->execute([$encrypted, $blindIndex, $userAccountId]);
    }
}
