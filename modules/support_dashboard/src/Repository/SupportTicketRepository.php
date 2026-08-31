<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

use Core\Security\EncryptionService;
use Modules\SupportDashboard\TicketCategory;

/**
 * The receiver's own tickets (roadmap IT-23).
 *
 * **The only place a description or a contact address is ever in clear.**
 * Both are encrypted BLOBs (SECURITY.md §5) and nothing above this class
 * sees a ciphertext or holds a key; the blind index on the address is what
 * makes « every ticket from this person » answerable without decrypting
 * the table.
 */
class SupportTicketRepository
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Store one ticket. Returns its id.
     */
    public function create(
        int $installationId,
        TicketCategory $category,
        string $description,
        string $contactEmail,
        ?string $siteVersion,
        ?string $phpVersion
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_tickets
                (installation_id, category, description_encrypted, contact_email_encrypted,
                 contact_email_blind_index, site_version, php_version, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $installationId,
            $category->value,
            $this->encryption->encrypt($description, 'support_tickets.description'),
            $this->encryption->encrypt($contactEmail, 'support_tickets.contact_email'),
            $this->encryption->blindIndex(EncryptionService::normalizeEmailForIndex($contactEmail), 'email'),
            $siteVersion,
            $phpVersion,
            self::STATUS_OPEN,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * How many tickets this installation has sent since $sinceDatetime —
     * the per-installation half of the intake's rate limit.
     */
    public function countSince(int $installationId, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM support_tickets WHERE installation_id = ? AND created_at >= ?'
        );
        $stmt->execute([$installationId, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One ticket, decrypted.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_tickets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Every ticket of one installation, most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function findForInstallation(int $installationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_tickets WHERE installation_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$installationId]);

        return array_map(
            fn(array $row): array => $this->hydrate($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'installation_id' => (int) $row['installation_id'],
            'category' => TicketCategory::tryFromValue((string) $row['category']),
            'description' => $this->encryption->decrypt((string) $row['description_encrypted'], 'support_tickets.description'),
            'contact_email' => $this->encryption->decrypt((string) $row['contact_email_encrypted'], 'support_tickets.contact_email'),
            'site_version' => $row['site_version'] !== null ? (string) $row['site_version'] : null,
            'php_version' => $row['php_version'] !== null ? (string) $row['php_version'] : null,
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            'resolution_note' => ($row['resolution_note_encrypted'] ?? null) !== null
                ? $this->encryption->decrypt((string) $row['resolution_note_encrypted'], 'support_tickets.resolution_note')
                : null,
            'archive_file_id' => ($row['archive_file_id'] ?? null) !== null ? (int) $row['archive_file_id'] : null,
            'archive_received_at' => ($row['archive_received_at'] ?? null) !== null ? (string) $row['archive_received_at'] : null,
        ];
    }
}
