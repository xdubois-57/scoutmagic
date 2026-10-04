<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;

/**
 * The signature a manager countersigns contracts with (#708, IT-16).
 *
 * **Keyed by the account, and that key is the whole access rule.** Every
 * method takes the id of the account asking, and there is no method that
 * lists, nor one that reads somebody else's: a signature is the one thing
 * that would let anyone forge a signed contract, so it is readable by its
 * owner alone — never by another manager of the same asset, never by an
 * administrator through this class.
 *
 * Encrypted at rest under its own context, so a ciphertext copied from one
 * row into another table decrypts to nothing.
 */
class RentalManagerSignatureRepository
{
    private const CONTEXT = 'rental_manager_signature';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /** The PNG bytes of this account's own signature, or null when it has none. */
    public function findPng(int $userAccountId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT image_encrypted FROM rental_manager_signatures WHERE user_account_id = ?'
        );
        $stmt->execute([$userAccountId]);
        $encrypted = $stmt->fetchColumn();

        return is_string($encrypted) && $encrypted !== ''
            ? $this->encryption->decrypt($encrypted, self::CONTEXT)
            : null;
    }

    public function has(int $userAccountId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM rental_manager_signatures WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Records this account's signature, replacing the one it had — the old
     * row deleted rather than updated, so nothing of the previous image
     * survives in it.
     *
     * Encrypted first, then deleted and inserted as one transaction: a
     * replacement that fails anywhere leaves the signature it was replacing,
     * never none at all.
     */
    public function save(int $userAccountId, string $png, \DateTimeImmutable $at): void
    {
        $encrypted = $this->encryption->encrypt($png, self::CONTEXT);

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->delete($userAccountId);
            $this->pdo->prepare(
                'INSERT INTO rental_manager_signatures (user_account_id, image_encrypted, updated_at) VALUES (?, ?, ?)'
            )->execute([$userAccountId, $encrypted, $at->format('Y-m-d H:i:s')]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Deletes this account's signature, whenever they ask. Returns whether there was one. */
    public function delete(int $userAccountId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_manager_signatures WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);

        return $stmt->rowCount() > 0;
    }
}
