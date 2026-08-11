<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Security\EncryptionService;

/**
 * Every timestamp comparison/write is computed in PHP and bound as a
 * parameter, never MySQL's NOW() — same convention as Core\Member\
 * MemberEmailRepository (see its own docblock), so this repository (and
 * its tests) work unmodified against the SQLite test database too.
 */
class RegistrationSecondaryEmailRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function create(
        int $registrationRequestId,
        string $email,
        string $confirmationTokenHash,
        \DateTimeImmutable $confirmationExpiresAt
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_secondary_emails (
                registration_request_id, email_encrypted, email_blind_index, status,
                confirmation_token_hash, confirmation_expires_at, last_confirmation_sent_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $registrationRequestId,
            $this->encryption->encrypt($email),
            $this->encryption->blindIndex(RegistrationRequestRepository::normalizeEmail($email)),
            RegistrationSecondaryEmail::STATUS_PENDING,
            $confirmationTokenHash,
            $confirmationExpiresAt->format('Y-m-d H:i:s'),
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?RegistrationSecondaryEmail
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_secondary_emails WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return array<RegistrationSecondaryEmail>
     */
    public function findByRequest(int $registrationRequestId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_secondary_emails WHERE registration_request_id = ? ORDER BY created_at'
        );
        $stmt->execute([$registrationRequestId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findByRequestAndEmail(int $registrationRequestId, string $email): ?RegistrationSecondaryEmail
    {
        $blindIndex = $this->encryption->blindIndex(RegistrationRequestRepository::normalizeEmail($email));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_secondary_emails WHERE registration_request_id = ? AND email_blind_index = ?'
        );
        $stmt->execute([$registrationRequestId, $blindIndex]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every request whose PRIMARY email is not this one but that has a
     * VALID secondary email blind-indexing to $email — Service\
     * TrackingService's linkage lookup.
     *
     * @return array<int> registration_request_id values
     */
    public function findRequestIdsByValidBlindIndex(string $blindIndex): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT registration_request_id FROM registration_secondary_emails WHERE email_blind_index = ? AND status = 'valid'"
        );
        $stmt->execute([$blindIndex]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function refreshConfirmation(int $id, string $confirmationTokenHash, \DateTimeImmutable $confirmationExpiresAt): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE registration_secondary_emails
             SET confirmation_token_hash = ?, confirmation_expires_at = ?, last_confirmation_sent_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$confirmationTokenHash, $confirmationExpiresAt->format('Y-m-d H:i:s'), $now, $id]);
    }

    public function markValid(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "UPDATE registration_secondary_emails
             SET status = 'valid', confirmed_at = ?, confirmation_token_hash = NULL, confirmation_expires_at = NULL
             WHERE id = ?"
        );
        $stmt->execute([$now, $id]);
    }

    public function reactivate(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE registration_secondary_emails SET status = 'valid', deactivated_at = NULL WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function markInactive(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "UPDATE registration_secondary_emails SET status = 'inactive', deactivated_at = ? WHERE id = ?"
        );
        $stmt->execute([$now, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM registration_secondary_emails WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RegistrationSecondaryEmail
    {
        return new RegistrationSecondaryEmail(
            id: (int) $row['id'],
            registrationRequestId: (int) $row['registration_request_id'],
            email: $this->encryption->decrypt($row['email_encrypted']),
            status: (string) $row['status'],
            confirmationTokenHash: $row['confirmation_token_hash'] !== null ? (string) $row['confirmation_token_hash'] : null,
            confirmationExpiresAt: $row['confirmation_expires_at'] !== null ? new \DateTimeImmutable((string) $row['confirmation_expires_at']) : null,
            lastConfirmationSentAt: $row['last_confirmation_sent_at'] !== null ? new \DateTimeImmutable((string) $row['last_confirmation_sent_at']) : null,
            confirmedAt: $row['confirmed_at'] !== null ? new \DateTimeImmutable((string) $row['confirmed_at']) : null,
            deactivatedAt: $row['deactivated_at'] !== null ? new \DateTimeImmutable((string) $row['deactivated_at']) : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at'])
        );
    }
}
