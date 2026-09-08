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
        } catch (\PDOException $e) {
            // ONLY the duplicate. « Already suppressed » is the exact
            // state the caller wanted and is not an error; everything else
            // — the table missing after a half-applied schema, a
            // read-only or full database, a connection that dropped — is,
            // and swallowing it lost an unsubscribe in silence. This class
            // says of itself that an unsubscribe « must outlive any
            // retention window »; a consent decision that is not written
            // down has not been honoured, and the next campaign writes to
            // somebody who asked not to be written to.
            //
            // SQLSTATE 23000 is the integrity-constraint class in both
            // engines this project supports and in SQLite, which is what
            // the unique index on the hash raises.
            if (($e->getCode() !== '23000') && ($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
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
