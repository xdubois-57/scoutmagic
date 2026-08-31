<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;

/**
 * The diagnostic mail probes this receiver is expecting (roadmap IT-27).
 *
 * The mailbox address and the header findings are encrypted here and
 * decrypted nowhere else (SECURITY.md §5): the findings carry the relay
 * chain, which is IP addresses and server names.
 */
class SupportMailProbeRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * One row per address of one probe run — all sharing the run's key.
     *
     * @param list<string> $addresses
     */
    public function issue(
        int $installationId,
        string $correlationKey,
        array $addresses,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $expiresAt
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_mail_probes
                (installation_id, correlation_key, mailbox_address_encrypted, issued_at, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        // The caller's clock, not this method's: the delay a received
        // probe reports is `received_at - issued_at`, and two clocks in
        // that subtraction is how a fast delivery becomes a negative
        // number rounded up to zero.
        $now = $issuedAt->format('Y-m-d H:i:s');

        foreach ($addresses as $address) {
            $stmt->execute([
                $installationId,
                $correlationKey,
                $this->encryption->encrypt($address, 'support_mail_probes.mailbox_address'),
                $now,
                $expiresAt->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * When this installation last had a key issued to it — the whole
     * basis of the rate limit, since one press of the button writes one
     * row per mailbox and a caller looping on it would be an amplifier
     * pointed at this receiver's own boxes.
     */
    public function lastIssuedAt(int $installationId): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(issued_at) FROM support_mail_probes WHERE installation_id = ?'
        );
        $stmt->execute([$installationId]);
        $value = $stmt->fetchColumn();

        // MAX() over an empty set is NULL, and a corrupted column must
        // not 500 an authenticated API route — DateInput answers null for
        // both rather than throwing or inventing *now* (SECURITY.md § 35).
        return DateInput::fromStorage(is_string($value) ? $value : null);
    }

    /**
     * The probes of one still-valid key, undelivered ones included.
     *
     * @return list<array<string, mixed>>
     */
    public function findPending(string $correlationKey, \DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_mail_probes
             WHERE correlation_key = ? AND received_at IS NULL AND expires_at >= ?
             ORDER BY id ASC'
        );
        $stmt->execute([$correlationKey, $now->format('Y-m-d H:i:s')]);

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findForInstallation(int $installationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_mail_probes WHERE installation_id = ? ORDER BY issued_at DESC, id ASC'
        );
        $stmt->execute([$installationId]);

        return array_map(fn(array $row): array => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $authentication
     */
    public function markReceived(int $probeId, \DateTimeImmutable $receivedAt, int $delaySeconds, array $authentication): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE support_mail_probes
             SET received_at = ?, delay_seconds = ?, authentication_encrypted = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $receivedAt->format('Y-m-d H:i:s'),
            max(0, $delaySeconds),
            $this->encryption->encrypt((string) json_encode($authentication), 'support_mail_probes.authentication'),
            $probeId,
        ]);
    }

    /**
     * Drops the probes nobody ever claimed, past their expiry. A key that
     * is never coming is a row nothing will ever read.
     */
    public function deleteExpired(\DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM support_mail_probes WHERE received_at IS NULL AND expires_at < ?'
        );
        $stmt->execute([$now->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $authentication = null;
        if (($row['authentication_encrypted'] ?? null) !== null) {
            $decoded = json_decode(
                $this->encryption->decrypt((string) $row['authentication_encrypted'], 'support_mail_probes.authentication'),
                true
            );
            $authentication = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) $row['id'],
            'installation_id' => (int) $row['installation_id'],
            'correlation_key' => (string) $row['correlation_key'],
            'mailbox_address' => $this->encryption->decrypt(
                (string) $row['mailbox_address_encrypted'],
                'support_mail_probes.mailbox_address'
            ),
            'issued_at' => (string) $row['issued_at'],
            'expires_at' => (string) $row['expires_at'],
            'received_at' => $row['received_at'] !== null ? (string) $row['received_at'] : null,
            'delay_seconds' => $row['delay_seconds'] !== null ? (int) $row['delay_seconds'] : null,
            'authentication' => $authentication,
        ];
    }
}
