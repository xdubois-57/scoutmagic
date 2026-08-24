<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

use Core\Security\EncryptionService;

/**
 * camp_contacts. Encrypts and decrypts every personal field, and is the
 * only layer allowed to (SECURITY.md §5).
 */
class ContactRepository
{
    /**
     * What every personal field of an anonymised contact becomes. Stored
     * encrypted like any other value, so the column never holds a mix of
     * ciphertext and plaintext.
     */
    public const ANONYMISED_MARKER = '(anonymisé)';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function findById(int $id): ?Contact
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_contacts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Contact[]
     */
    public function findByCamp(int $campId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_contacts WHERE camp_id = ? ORDER BY id ASC');
        $stmt->execute([$campId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function create(
        int $campId,
        ?string $name,
        ?string $roleLabel,
        ?string $email,
        ?string $phone,
        ?string $otherDetails
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO camp_contacts (camp_id, name, role_label, email, email_blind_index, phone,
                                        other_details, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            $campId,
            $this->encryptNullable($name),
            $roleLabel,
            $this->encryptNullable($email),
            $this->blindIndex($email),
            $this->encryptNullable($phone),
            $this->encryptNullable($otherDetails),
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        ?string $name,
        ?string $roleLabel,
        ?string $email,
        ?string $phone,
        ?string $otherDetails
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE camp_contacts SET name = ?, role_label = ?, email = ?, email_blind_index = ?,
                    phone = ?, other_details = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $this->encryptNullable($name),
            $roleLabel,
            $this->encryptNullable($email),
            $this->blindIndex($email),
            $this->encryptNullable($phone),
            $this->encryptNullable($otherDetails),
            date('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM camp_contacts WHERE id = ?')->execute([$id]);
    }

    public function moveCamp(int $fromCampId, int $toCampId): int
    {
        $stmt = $this->pdo->prepare('UPDATE camp_contacts SET camp_id = ? WHERE camp_id = ?');
        $stmt->execute([$toCampId, $fromCampId]);

        return $stmt->rowCount();
    }

    /**
     * Every contact row that is the SAME PERSON as this one, anywhere in
     * the module — the whole point of the blind index.
     *
     * A contact with no e-mail can only ever be itself: there is nothing
     * to match on, and guessing by name would be worse than not matching
     * (two "M. Martin" at two different farms are two different people).
     *
     * @return Contact[]
     */
    public function findSamePerson(Contact $contact): array
    {
        $index = $this->blindIndex($contact->email);
        if ($index === null) {
            return [$contact];
        }

        $stmt = $this->pdo->prepare('SELECT * FROM camp_contacts WHERE email_blind_index = ? ORDER BY id ASC');
        $stmt->execute([$index]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Replaces every personal field of the given rows with the marker and
     * CLEARS the blind index, so the erased person is no longer findable
     * by the very key that grouped them — leaving it would keep a
     * queryable fingerprint of the address that was asked to be removed.
     *
     * @param int[] $contactIds
     */
    public function anonymise(array $contactIds): int
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
        if ($contactIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $marker = $this->encryption->encrypt(self::ANONYMISED_MARKER, 'camp_contacts.value');
        $stmt = $this->pdo->prepare(
            "UPDATE camp_contacts
                SET name = ?, email = ?, phone = ?, other_details = ?, email_blind_index = NULL, updated_at = ?
              WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$marker, $marker, $marker, $marker, date('Y-m-d H:i:s')], $contactIds));

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Contact
    {
        return new Contact(
            id: (int) $row['id'],
            campId: (int) $row['camp_id'],
            name: $this->decryptNullable($row['name']),
            roleLabel: $row['role_label'] !== null && $row['role_label'] !== '' ? (string) $row['role_label'] : null,
            email: $this->decryptNullable($row['email']),
            phone: $this->decryptNullable($row['phone']),
            otherDetails: $this->decryptNullable($row['other_details']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private function blindIndex(?string $email): ?string
    {
        $email = $email !== null ? mb_strtolower(trim($email)) : null;

        return $email !== null && $email !== ''
            ? $this->encryption->blindIndex($email, 'camp_contacts.email')
            : null;
    }

    private function encryptNullable(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== ''
            ? $this->encryption->encrypt($value, 'camp_contacts.value')
            : null;
    }

    private function decryptNullable(mixed $value): ?string
    {
        return $value !== null && $value !== ''
            ? $this->encryption->decrypt((string) $value, 'camp_contacts.value')
            : null;
    }
}
