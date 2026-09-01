<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

use Core\Security\EncryptionService;
use Modules\SupportDashboard\Service\ReportedFacts;
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

    /**
     * The archive goes ninety days after the ticket is closed — long
     * enough to reopen a question, short enough that a receiver is not a
     * warehouse of other people's server logs.
     */
    public const ARCHIVE_RETENTION_DAYS_AFTER_CLOSURE = 90;

    /**
     * And at the latest a year after the ticket was created, closed or
     * not. A ticket nobody ever closed is the normal fate of one nobody
     * could reproduce, and « gardée parce que personne n'a cliqué » is
     * not a retention policy.
     */
    public const ARCHIVE_MAX_AGE_DAYS = 365;

    /**
     * The ticket itself, its metadata and its resolution note: two years.
     * That is what makes the corpus worth having — « ce problème, on l'a
     * déjà vu » is only answerable from tickets that are still there.
     */
    public const TICKET_RETENTION_DAYS = 730;

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
        ?string $phpVersion,
        ?string $statisticsSnapshot = null
    ): string {
        $reference = $this->uniqueReference();

        $stmt = $this->pdo->prepare(
            'INSERT INTO support_tickets
                (reference, installation_id, category, description_encrypted, contact_email_encrypted,
                 contact_email_blind_index, site_version, php_version, status, created_at,
                 statistics_snapshot_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            // Frozen on purpose: the installation row beside it keeps only
            // the latest report, and a ticket read three weeks later must
            // still say what was running when it was written.
            $statisticsSnapshot !== null && trim($statisticsSnapshot) !== ''
                ? $this->encryption->encrypt($statisticsSnapshot, 'support_tickets.statistics_snapshot')
                : null,
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
     * One ticket by the reference its instance was given.
     *
     * @return array<string, mixed>|null
     */
    public function findByReference(string $reference): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_tickets WHERE reference = ?');
        $stmt->execute([$reference]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Record that this ticket's diagnostic archive arrived (roadmap
     * IT-26). Written only once — the caller checks first, and a second
     * copy of the same archive would be storage nobody asked for.
     */
    public function attachArchive(int $ticketId, int $fileId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE support_tickets SET archive_file_id = ?, archive_received_at = ? WHERE id = ?'
        );
        $stmt->execute([$fileId, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $ticketId]);
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
     * Every ticket, most recent first, joined to what the receiver knows
     * about the installation that sent it.
     *
     * **The join is the point of the whole design** (roadmap IT-28): a
     * ticket carries a category and a sentence, and « quelle version, quel
     * hébergement, combien de membres » is the other half of any answer.
     * Reusing the statistics identity is what makes that join possible at
     * all, and it is why there is no second credential.
     *
     * Everything is read and decrypted here; filtering and searching
     * happen above, in PHP. A `WHERE` on `description_encrypted` cannot
     * work — the column is a ciphertext — and a blind index would be a
     * searchable copy of what somebody wrote about their own installation,
     * for a search that is a substring match rather than a lookup
     * (SECURITY.md §5).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllWithInstallation(): array
    {
        $stmt = $this->pdo->query(
            'SELECT t.*, i.installation_id AS installation_public_id, i.instance_url, i.payload
             FROM support_tickets t
             LEFT JOIN support_installations i ON i.id = t.installation_id
             ORDER BY t.created_at DESC, t.id DESC'
        );
        if ($stmt === false) {
            return [];
        }

        return array_map(
            fn(array $row): array => $this->hydrateWithInstallation($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * One ticket with its installation, for the detail page.
     *
     * @return array<string, mixed>|null
     */
    public function findWithInstallation(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, i.installation_id AS installation_public_id, i.instance_url, i.payload
             FROM support_tickets t
             LEFT JOIN support_installations i ON i.id = t.installation_id
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrateWithInstallation($row) : null;
    }

    /**
     * Close a ticket, with the note that makes the corpus worth keeping.
     *
     * The ticket is one-way — there is no thread and the instance is never
     * polled — so the resolution note is the only place what actually
     * happened is written down. An empty note is stored as NULL rather
     * than as an empty ciphertext: « clos sans note » and « clos avec une
     * note vide » are the same fact, and only one of them should be
     * representable.
     *
     * @return bool false when the ticket does not exist or is already closed
     */
    public function close(int $id, ?string $resolutionNote, \DateTimeImmutable $closedAt): bool
    {
        $note = $resolutionNote !== null && trim($resolutionNote) !== ''
            ? $this->encryption->encrypt(trim($resolutionNote), 'support_tickets.resolution_note')
            : null;

        $stmt = $this->pdo->prepare(
            'UPDATE support_tickets
             SET status = ?, closed_at = ?, resolution_note_encrypted = ?
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            self::STATUS_CLOSED,
            $closedAt->format('Y-m-d H:i:s'),
            $note,
            $id,
            self::STATUS_OPEN,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Forget the archive, keeping the ticket.
     *
     * Called by the purge once the archive's own retention is up. The
     * ticket, its metadata and its resolution note stay — they are what
     * makes the corpus readable a year later, and they weigh nothing next
     * to the megabytes this releases.
     */
    public function detachArchive(int $ticketId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE support_tickets SET archive_file_id = NULL, archive_received_at = NULL WHERE id = ?'
        );
        $stmt->execute([$ticketId]);
    }

    /**
     * The tickets whose archive has outlived its retention: ninety days
     * past closure, or one year past creation for a ticket nobody ever
     * closed.
     *
     * The second bound is not a detail. Without it a ticket left open —
     * which is the normal fate of one nobody could reproduce — would keep
     * its archive for ever, and « on l'a gardée parce que personne n'a
     * cliqué » is not a retention policy.
     *
     * @return list<array{id: int, archive_file_id: int}>
     */
    public function findArchivesToPurge(\DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, archive_file_id FROM support_tickets
             WHERE archive_file_id IS NOT NULL
               AND ((closed_at IS NOT NULL AND closed_at < ?) OR created_at < ?)'
        );
        $stmt->execute([
            $now->modify('-' . self::ARCHIVE_RETENTION_DAYS_AFTER_CLOSURE . ' days')->format('Y-m-d H:i:s'),
            $now->modify('-' . self::ARCHIVE_MAX_AGE_DAYS . ' days')->format('Y-m-d H:i:s'),
        ]);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['id' => (int) $row['id'], 'archive_file_id' => (int) $row['archive_file_id']];
        }

        return $rows;
    }

    /**
     * The tickets past the two-year retention, archive or no archive.
     *
     * @return list<int>
     */
    public function findIdsCreatedBefore(\DateTimeImmutable $cutoff): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM support_tickets WHERE created_at < ?');
        $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);

        return array_map(static fn($id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM support_tickets WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * The snapshot as an array, or null when there is none or it is not
     * readable as one.
     *
     * A ticket predating this column, or one from an instance too old to
     * send a snapshot, is an ordinary case rather than a broken row: the
     * detail page simply says the snapshot is absent.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeSnapshot(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * A ticket plus the last thing its installation reported.
     *
     * The payload is the raw JSON the statistics intake stored; only the
     * handful of fields a maintainer reads while answering are lifted out
     * of it, and a ticket from an installation that never reported (or
     * whose record retention has since removed it) simply carries nulls.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateWithInstallation(array $row): array
    {
        $ticket = $this->hydrate($row);
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];

        // Read through Service\ReportedFacts, the ONE reader of a usage
        // payload — the fields are nested (`scoutmagic.version`,
        // `runtime.php_version`, …) and picking them off the top level,
        // as this did until it was noticed on a real ticket, renders
        // « Non renseigné » for values the document plainly carries.
        $ticket['installation'] = array_merge(
            [
                'public_id' => ($row['installation_public_id'] ?? null) !== null
                    ? (string) $row['installation_public_id']
                    : null,
                'instance_url' => ($row['instance_url'] ?? null) !== null ? (string) $row['instance_url'] : null,
            ],
            ReportedFacts::fromPayload($payload)
        );

        return $ticket;
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
            'statistics_snapshot' => self::decodeSnapshot(
                ($row['statistics_snapshot_encrypted'] ?? null) !== null
                    ? $this->encryption->decrypt(
                        (string) $row['statistics_snapshot_encrypted'],
                        'support_tickets.statistics_snapshot'
                    )
                    : null
            ),
        ];
    }
}
