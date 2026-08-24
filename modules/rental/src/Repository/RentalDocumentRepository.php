<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;

/**
 * Documents attached to bookings (§6.24), and each booking's own copy of a
 * template (§6.25, level 2).
 *
 * Joins `files` for the name and size a listing needs, rather than making
 * every caller pair a document row with a separate lookup — a booking with
 * six documents would otherwise be seven queries for one panel.
 *
 * Every timestamp is computed in PHP, never MySQL's `NOW()`, so this runs
 * unmodified against the SQLite test database.
 */
class RentalDocumentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    // ── Documents ───────────────────────────────────────────────────────

    public function create(
        int $bookingId,
        int $fileId,
        DocumentType $type,
        int $version,
        bool $isForRenter,
        ?string $snapshot,
        ?int $createdByMemberId,
        string $source = RentalDocument::SOURCE_MANUAL
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_documents
                (booking_id, file_id, document_type, version, is_for_renter,
                 generated_snapshot, created_by_member_id, created_at, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $bookingId,
            $fileId,
            $type->value,
            $version,
            $isForRenter ? 1 : 0,
            $snapshot,
            $createdByMemberId,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $source === RentalDocument::SOURCE_EMAIL
                ? RentalDocument::SOURCE_EMAIL
                : RentalDocument::SOURCE_MANUAL,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Whether another document row points at the same file.
     *
     * Two rows can legitimately share one file: an email's attachment
     * reclassified on one booking and moved to another, a document
     * re-filed by `moveToBooking()`. Deleting the bytes while a second row
     * still points at them turns that row into a broken download.
     */
    public function isFileReferencedElsewhere(int $fileId, int $exceptDocumentId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rental_documents WHERE file_id = ? AND id <> ?');
        $stmt->execute([$fileId, $exceptDocumentId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function findById(int $id): ?RentalDocument
    {
        $stmt = $this->pdo->prepare($this->selectWithFile() . ' WHERE d.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return RentalDocument[] Newest first, so the current version of a
     *   generated document is the one a manager sees at the top.
     */
    public function findForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            $this->selectWithFile() . ' WHERE d.booking_id = ? ORDER BY d.created_at DESC, d.id DESC'
        );
        $stmt->execute([$bookingId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The most recent version of one generated type, or null.
     */
    public function findLatest(int $bookingId, DocumentType $type): ?RentalDocument
    {
        $stmt = $this->pdo->prepare(
            $this->selectWithFile()
            . ' WHERE d.booking_id = ? AND d.document_type = ? ORDER BY d.version DESC LIMIT 1'
        );
        $stmt->execute([$bookingId, $type->value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Claims the next version number, moving the counter forward.
     *
     * **Never `MAX(version)` over the surviving documents.** Deleting v2
     * must not make the next generation v2 again: v2 may already have been
     * emailed, and two different PDFs under one version number is exactly
     * the confusion versioning exists to prevent. Same reasoning — and the
     * same shape — as `rental_reference_sequences` for booking references.
     *
     * The counter lives on the booking's own template copy, which is the
     * row that already exists once per (booking, type) and is created at
     * the first generation.
     */
    public function claimNextVersion(int $bookingId, DocumentType $type): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT last_version FROM rental_booking_document_texts WHERE booking_id = ? AND document_type = ?'
        );
        $stmt->execute([$bookingId, $type->value]);
        $current = $stmt->fetchColumn();

        $next = $current === false || $current === null ? 1 : ((int) $current) + 1;

        $update = $this->pdo->prepare(
            'UPDATE rental_booking_document_texts SET last_version = ?, updated_at = ?
             WHERE booking_id = ? AND document_type = ?'
        );
        $update->execute([
            $next,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $bookingId,
            $type->value,
        ]);

        return $next;
    }

    public function markSent(int $id, \DateTimeImmutable $sentAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_documents SET sent_at = ? WHERE id = ?');
        $stmt->execute([$sentAt->format('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_documents WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Reclassify a document (§6.24, §7.8).
     *
     * Only the type and the renter flag move — never the file, never the
     * version. A `Non classé` attachment that turns out to be the signed
     * contract becomes one in place, keeping the very bytes that arrived
     * rather than a copy somebody made of them.
     */
    public function updateType(int $id, DocumentType $type, ?bool $isForRenter = null): void
    {
        if ($isForRenter === null) {
            $stmt = $this->pdo->prepare('UPDATE rental_documents SET document_type = ? WHERE id = ?');
            $stmt->execute([$type->value, $id]);

            return;
        }

        $stmt = $this->pdo->prepare('UPDATE rental_documents SET document_type = ?, is_for_renter = ? WHERE id = ?');
        $stmt->execute([$type->value, $isForRenter ? 1 : 0, $id]);
    }

    /**
     * Re-file a document under another booking — what happens to an email's
     * attachment when the email itself is moved (§7.7).
     *
     * The file on disk does not move: `FileAccessGuard` resolves a file's
     * booking through this row, so changing the row is exactly what changes
     * who may read it.
     */
    public function moveToBooking(int $id, int $bookingId): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_documents SET booking_id = ? WHERE id = ?');
        $stmt->execute([$bookingId, $id]);
    }

    /**
     * Which booking a file belongs to, for the ownership checker — the one
     * question `Core\File\FileAccessGuard` has to answer before serving it.
     */
    public function findBookingIdForFile(int $fileId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT booking_id FROM rental_documents WHERE file_id = ? LIMIT 1');
        $stmt->execute([$fileId]);
        $bookingId = $stmt->fetchColumn();

        return $bookingId === false || $bookingId === null ? null : (int) $bookingId;
    }

    // ── The booking's own template copy (§6.25, level 2) ────────────────

    public function findText(int $bookingId, DocumentType $type): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT body_html FROM rental_booking_document_texts WHERE booking_id = ? AND document_type = ?'
        );
        $stmt->execute([$bookingId, $type->value]);
        $body = $stmt->fetchColumn();

        return $body === false ? null : (string) $body;
    }

    public function saveText(int $bookingId, DocumentType $type, string $bodyHtml): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Written as select-then-write rather than an upsert so it behaves
        // identically on MySQL and on the SQLite test database, whose
        // conflict syntaxes differ.
        if ($this->findText($bookingId, $type) === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rental_booking_document_texts
                    (booking_id, document_type, body_html, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$bookingId, $type->value, $bodyHtml, $now, $now]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE rental_booking_document_texts SET body_html = ?, updated_at = ?
             WHERE booking_id = ? AND document_type = ?'
        );
        $stmt->execute([$bodyHtml, $now, $bookingId, $type->value]);
    }

    private function selectWithFile(): string
    {
        return 'SELECT d.*, f.original_name, f.size_bytes
                FROM rental_documents d
                LEFT JOIN files f ON f.id = d.file_id';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RentalDocument
    {
        return new RentalDocument(
            id: (int) $row['id'],
            bookingId: (int) $row['booking_id'],
            fileId: (int) $row['file_id'],
            type: DocumentType::tryFrom((string) $row['document_type']) ?? DocumentType::OTHER,
            version: (int) $row['version'],
            isForRenter: (bool) $row['is_for_renter'],
            originalName: isset($row['original_name']) ? (string) $row['original_name'] : null,
            sizeBytes: isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
            sentAt: $row['sent_at'] !== null ? new \DateTimeImmutable((string) $row['sent_at']) : null,
            createdByMemberId: $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            source: isset($row['source']) && (string) $row['source'] !== ''
                ? (string) $row['source']
                : RentalDocument::SOURCE_MANUAL
        );
    }
}
