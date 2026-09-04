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
 * Single global row holding the bearer token for the "unité complète"
 * aggregate ICS feed (all configured calendars combined).
 *
 * Encrypted at rest + blind-indexed — the token must stay re-displayable
 * (the feed URL lives in subscribers' calendar apps), so it can't be
 * hashed-only, and plaintext would hand a working credential to any DB
 * read. Same pattern as CalendarPersonalTokenRepository.
 */
class CalendarUnitFeedTokenRepository
{
    private const ENCRYPTION_CONTEXT = 'calendar_unit_feed_token.token';
    private const BLIND_INDEX_PURPOSE = 'calendar_unit_feed_token';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function findToken(): ?string
    {
        $stmt = $this->pdo->query('SELECT token_encrypted FROM calendar_unit_feed_token LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $encrypted = $stmt->fetchColumn();

        return $encrypted !== false
            ? $this->encryption->decrypt((string) $encrypted, self::ENCRYPTION_CONTEXT)
            : null;
    }

    public function tokenExists(string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT token_encrypted FROM calendar_unit_feed_token WHERE token_blind_index = ?');
        $stmt->execute([$this->encryption->blindIndex($token, self::BLIND_INDEX_PURPOSE)]);
        $encrypted = $stmt->fetchColumn();
        if ($encrypted === false) {
            return false;
        }

        // Verify exact match (blind index collisions are theoretically possible).
        try {
            $stored = $this->encryption->decrypt((string) $encrypted, self::ENCRYPTION_CONTEXT);
        } catch (DecryptionException) {
            return false;
        }

        return CapabilityToken::equalsConstantTime($stored, $token);
    }

    /**
     * Insert-or-replace the single global token row.
     */
    public function setToken(string $token): void
    {
        $encrypted = $this->encryption->encrypt($token, self::ENCRYPTION_CONTEXT);
        $blindIndex = $this->encryption->blindIndex($token, self::BLIND_INDEX_PURPOSE);

        $stmt = $this->pdo->query('SELECT id FROM calendar_unit_feed_token LIMIT 1');
        $existingId = $stmt !== false ? $stmt->fetchColumn() : false;

        if ($existingId === false) {
            $stmt = $this->pdo->prepare('INSERT INTO calendar_unit_feed_token (token_encrypted, token_blind_index) '
                . 'VALUES (?, ?)');
            $stmt->execute([$encrypted, $blindIndex]);
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE calendar_unit_feed_token SET token_encrypted = ?, token_blind_index = ? '
            . 'WHERE id = ?');
        $stmt->execute([$encrypted, $blindIndex, $existingId]);
    }
}
