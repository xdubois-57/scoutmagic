<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * mass_mail_suppressed_addresses — the unsubscribe list for external
 * (non-member) mail-merge recipients. Only ever a SHA-256 hash of the
 * normalized address, never the address itself: this table is checked by
 * exact match at freeze time and nothing ever needs the address back.
 * Never purged — an unsubscribe must outlive any retention window.
 */
class SuppressedAddressRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Idempotent (unique index on the hash) — a mailbox prefetch, the
     * confirm page's submit, and a manual resubmit may all land here for
     * the same address.
     */
    public function suppress(string $address): void
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO mass_mail_suppressed_addresses (email_hash) VALUES (?)');
            $stmt->execute([self::hash($address)]);
        } catch (\PDOException) {
            // Already suppressed — the exact state the caller wanted.
        }
    }

    public function isSuppressed(string $address): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mass_mail_suppressed_addresses WHERE email_hash = ? LIMIT 1');
        $stmt->execute([self::hash($address)]);
        return $stmt->fetchColumn() !== false;
    }

    private static function hash(string $address): string
    {
        return hash('sha256', mb_strtolower(trim($address)));
    }
}
