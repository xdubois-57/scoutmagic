<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\File\AttachedFileRepository;
use Core\Service\DateInput;
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
class RentalDocumentRepository implements AttachedFileRepository
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

    /**
     * Whether a document of this type has already gone out to the renter.
     *
     * What « en lecture seule » is decided from (§22.6): the text a
     * contract was made from stops being editable once that contract has
     * been sent, because the renter holds a copy and silently changing what
     * it was made from is precisely the confusion versioning exists to
     * prevent.
     *
     * Any version counts, not just the latest: v1 sent and v2 regenerated
     * still means the renter has something.
     */
    public function hasSentDocumentOfType(int $bookingId, DocumentType $type): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM rental_documents
             WHERE booking_id = ? AND document_type = ? AND sent_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([$bookingId, $type->value]);

        return $stmt->fetchColumn() !== false;
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

    /**
     * Write a document's source text, unless it has already gone out.
     *
     * The lock is carried BY THE WRITE, and that is the whole point. The
     * service checks `textIsLocked()` first — for the banner, and for a
     * refusal in French rather than a silent no-op — but a check standing
     * apart from its write is a TOCTOU: between the two, a second manager
     * pressing « Envoyer » writes `sent_at`, and both succeed. The tenant
     * then holds a PDF whose source says something else, with nothing on
     * screen to say so (#405).
     *
     * `NOT EXISTS` inside the UPDATE closes that window without storing
     * anything: InnoDB evaluates it as a locking read at write time, and
     * SQLite serialises writers outright. The lock therefore stays
     * DERIVED from the sent documents, which is what this module does
     * everywhere else (ARCHITECTURE.md §8.53) — issue #405 proposed a
     * `locked_at` column and regretted both of its costs, a schema change
     * and that lost derivation. Neither is needed.
     *
     * **Zero changed rows is not zero matched rows**, and that distinction
     * is what the first version of this got wrong. Without
     * `PDO::MYSQL_ATTR_FOUND_ROWS`, which this application does not set,
     * MySQL's `rowCount()` after an UPDATE counts rows it CHANGED. So a
     * manager double-clicking « Enregistrer » on unedited text — twice
     * inside the same second, so that even `updated_at` is identical —
     * matched the row, changed nothing, and was told « ce document a été
     * envoyé au locataire » about a document nobody had sent, with the
     * write dropped. The same trap is already written down for
     * `SettingRepository::replaceIfUnchanged()` and
     * `BounceStateRepository` (ARCHITECTURE.md §8.29).
     *
     * **A second query disambiguates, and the two run as one.** When the
     * UPDATE reports nothing changed, the row is asked whether it already
     * holds exactly this text with nothing sent — the no-op — or not,
     * which is the refusal. Nothing is re-checked on the ordinary path,
     * so the window the `NOT EXISTS` closes stays closed.
     *
     * **They share a transaction, and that is not belt-and-braces.** The
     * first version left them as two independent statements, reasoning
     * only about a SEND landing in between (where refusing is truthful:
     * the text on file is already the text asked for, and it has gone
     * out). A concurrent SAVE breaks that reasoning — a second manager
     * writing different text between the UPDATE and the question makes
     * the question find a row that no longer holds what was asked for,
     * so it answers « refused » and the first manager is told the
     * document went to the tenant. Nothing went anywhere. The UPDATE
     * takes the row's write lock, so holding both inside one transaction
     * makes that second save wait rather than slip in: a false refusal
     * introduced by the very disambiguation that removed another one.
     *
     * **A unit test cannot catch this**, which is why it survived one:
     * `DatabaseTestHelper::createTestDatabase()` builds an in-memory
     * SQLite, whose `changes()` counts matched rows whatever the values
     * were. The divergence appears only against MySQL — the `test` job of
     * CI, the `database` group of a remote session, and production.
     *
     * @return bool false when the text was already sent and nothing was written
     */
    public function saveText(int $bookingId, DocumentType $type, string $bodyHtml): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Written as select-then-write rather than an upsert so it behaves
        // identically on MySQL and on the SQLite test database, whose
        // conflict syntaxes differ.
        if ($this->findText($bookingId, $type) === null) {
            // A text that does not exist yet cannot have been sent: a
            // document is generated FROM this row, so there is no window
            // to close here.
            $stmt = $this->pdo->prepare(
                'INSERT INTO rental_booking_document_texts
                    (booking_id, document_type, body_html, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$bookingId, $type->value, $bodyHtml, $now, $now]);

            return true;
        }

        // Only when nobody above us owns one: this repository is called
        // from a service that may already have opened its own, and a
        // second `beginTransaction()` on the same connection throws.
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE rental_booking_document_texts SET body_html = ?, updated_at = ?
                 WHERE booking_id = ? AND document_type = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM rental_documents d
                       WHERE d.booking_id = rental_booking_document_texts.booking_id
                         AND d.document_type = rental_booking_document_texts.document_type
                         AND d.sent_at IS NOT NULL
                   )'
            );
            $stmt->execute([$bodyHtml, $now, $bookingId, $type->value]);

            $written = $stmt->rowCount() > 0
                || $this->alreadyHoldsUnsent($bookingId, $type, $bodyHtml);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                try {
                    $this->pdo->rollBack();
                } catch (\Throwable) {
                    // Nothing left to roll back — a commit that threw
                    // after doing its work leaves no transaction open.
                    // Swallowed on purpose: the caller is about to
                    // receive the original failure, which is the one
                    // sentence they can act on.
                }
            }

            throw $e;
        }

        return $written;
    }

    /**
     * Did the UPDATE change nothing because there was nothing to change?
     *
     * Asked only when `rowCount()` is zero, where MySQL cannot tell « the
     * `NOT EXISTS` refused this » from « the row already said exactly
     * that ». One row, one answer: the text is identical AND no document
     * of this type has gone out, so the caller's intent is already the
     * state on disk.
     *
     * Runs inside {@see saveText()}'s transaction, after its UPDATE has
     * taken the row's write lock — which is what stops a concurrent save
     * from changing the answer between the two. Never called from
     * anywhere else, for that reason.
     */
    private function alreadyHoldsUnsent(int $bookingId, DocumentType $type, string $bodyHtml): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM rental_booking_document_texts t
              WHERE t.booking_id = ? AND t.document_type = ? AND t.body_html = ?
                AND NOT EXISTS (
                    SELECT 1 FROM rental_documents d
                    WHERE d.booking_id = t.booking_id
                      AND d.document_type = t.document_type
                      AND d.sent_at IS NOT NULL
                )'
        );
        $stmt->execute([$bookingId, $type->value, $bodyHtml]);

        return $stmt->fetchColumn() !== false;
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
            sentAt: DateInput::fromStorage($row['sent_at'] === null ? null : (string) $row['sent_at']),
            createdByMemberId: $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at'),
            source: isset($row['source']) && (string) $row['source'] !== ''
                ? (string) $row['source']
                : RentalDocument::SOURCE_MANUAL
        );
    }
}
