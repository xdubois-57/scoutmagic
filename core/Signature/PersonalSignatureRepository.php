<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

use Core\Security\EncryptionService;
use Core\Service\DateInput;

/**
 * The signature an account keeps for signing documents (issue #926),
 * `user_account_signatures`.
 *
 * **Keyed by the account, and that key is the whole access rule.** Every
 * method takes the id of the account asking, and there is no method that
 * lists, nor one that reads several: a signature is the one thing that
 * would let anyone forge a signed document, so it is readable by its owner
 * alone — never by another account, never by an administrator through this
 * class. Which account is asking is the caller's to establish, from the
 * session and never from a request parameter.
 *
 * Encrypted at rest under its own context, so a ciphertext copied from one
 * row into another table decrypts to nothing.
 */
class PersonalSignatureRepository
{
    private const CONTEXT = 'user_account_signature';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /** This account's own signature, or null when it keeps none. */
    public function find(int $userAccountId): ?SignaturePng
    {
        $stmt = $this->pdo->prepare('SELECT image_encrypted FROM user_account_signatures WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);
        $encrypted = $stmt->fetchColumn();

        return is_string($encrypted) && $encrypted !== ''
            ? new SignaturePng($this->encryption->decrypt($encrypted, self::CONTEXT))
            : null;
    }

    /** When this account last saved its signature, or null when it keeps none. */
    public function savedAt(int $userAccountId): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare('SELECT updated_at FROM user_account_signatures WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);
        $value = $stmt->fetchColumn();

        return is_string($value)
            ? DateInput::requireFromStorage($value, 'user_account_signatures.updated_at')
            : null;
    }

    /**
     * Records this account's signature, replacing the one it had.
     *
     * One upsert on the primary key rather than a delete and an insert: the
     * account's row is created or overwritten whole in a single statement.
     * A delete-then-insert let two submissions of the same person (two tabs,
     * a double click — the session lock is released early, see
     * public/index.php) both delete and then meet on the insert, the loser a
     * duplicate key or a deadlock, i.e. a 500. A write that fails leaves the
     * signature it was replacing, never none at all. The race is the bug, so
     * the two dialects are written out.
     *
     * It opens no transaction of its own: a caller that signs a document and
     * remembers the signature in the same act runs this inside its own, so
     * that a signing that fails leaves nothing remembered.
     */
    public function save(int $userAccountId, SignaturePng $png, \DateTimeImmutable $at): void
    {
        $insert = 'INSERT INTO user_account_signatures (user_account_id, image_encrypted, updated_at)
                   VALUES (?, ?, ?)';
        $sql = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? $insert . ' ON CONFLICT(user_account_id) DO UPDATE SET
                   image_encrypted = excluded.image_encrypted,
                   updated_at = excluded.updated_at'
            : $insert . ' ON DUPLICATE KEY UPDATE
                   image_encrypted = VALUES(image_encrypted),
                   updated_at = VALUES(updated_at)';

        $this->pdo->prepare($sql)->execute([
            $userAccountId,
            $this->encryption->encrypt($png->bytes(), self::CONTEXT),
            $at->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Deletes this account's signature. Returns whether there was one.
     *
     * A `DELETE` changes the rows it matches, so `rowCount()` reads the same
     * on SQLite and on MySQL/MariaDB here — unlike after an `UPDATE`.
     */
    public function delete(int $userAccountId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_account_signatures WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);

        return $stmt->rowCount() > 0;
    }
}
