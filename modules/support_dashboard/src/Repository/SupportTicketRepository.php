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
     * The alphabet a reference is drawn from: upper case, no O/0 and no
     * I/1, because the whole point of a reference is being read out on the
     * phone and typed back into an e-mail.
     */
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const REFERENCE_LENGTH = 6;

    /**
     * Store one ticket. Returns its reference — never its row id, which
     * would tell the reporting instance how many tickets this receiver
     * has ever had.
     */
    public function create(
        int $installationId,
        TicketCategory $category,
        string $description,
        string $contactEmail,
        ?string $siteVersion,
        ?string $phpVersion
    ): string {
        $reference = $this->uniqueReference();

        $stmt = $this->pdo->prepare(
            'INSERT INTO support_tickets
                (reference, installation_id, category, description_encrypted, contact_email_encrypted,
                 contact_email_blind_index, site_version, php_version, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $reference,
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

        return $reference;
    }

    /**
     * A reference nothing else carries.
     *
     * The UNIQUE index is what makes it true; this loop only keeps the
     * insert from failing on the collision the index would catch. Six
     * characters of a 32-symbol alphabet is a billion values, so the
     * retry is theory rather than practice — and bounded, because a loop
     * that cannot end is worse than a collision.
     */
    private function uniqueReference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'SUP-';
            for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
                $reference .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }

            $stmt = $this->pdo->prepare('SELECT 1 FROM support_tickets WHERE reference = ?');
            $stmt->execute([$reference]);
            if ($stmt->fetchColumn() === false) {
                return $reference;
            }
        }

        // Five collisions in a row is not a database this can reason
        // about; the unique index refuses the insert and the caller sees a
        // refusal rather than a ticket filed under somebody else's
        // reference.
        throw new \RuntimeException('Could not allocate a unique support ticket reference.');
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
            'reference' => (string) $row['reference'],
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
