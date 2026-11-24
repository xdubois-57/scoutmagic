<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

/**
 * Which inbound attachment a receipt came from, and why it was filed where
 * it was (`finance_receipt_mail_sources`, #1342).
 *
 * The memory outlives the receipt on purpose. Deleting a receipt archives
 * it and moving one changes its account, but « this attachment of this
 * message was already turned into a receipt » stays true either way — and
 * it is what stops a re-read or a re-analysis from filing the same
 * document again (decision 3: only a NEW message brings back a receipt
 * somebody deleted).
 */
class ReceiptMailSourceRepository
{
    /** Filed from a document read off the general address. */
    public const REASON_CONTENT = 'content';

    /** A person associated the message — the receipt is theirs. */
    public const REASON_MANUAL = 'manual';

    /**
     * Recognised as one finance already held when the history was caught
     * up: nothing was filed, the attachment is only remembered.
     */
    public const REASON_ALREADY_HELD = 'already_held';

    /**
     * The catch-up tried and finance refused it (said in the journal as
     * `inbound_receipt_not_filed`): not tried again by nobody.
     */
    public const REASON_NOT_FILED = 'not_filed';

    /**
     * The catch-up could not read the file (said in the journal as
     * `inbound_receipt_unreadable`). Not « through finance »: nothing was
     * filed, and a filing that later succeeds replaces it. The catch-up
     * tries again, {@see self::MAX_UNREADABLE_TRIES} times in all, then
     * leaves it — a file gone for good must not hold the walk open for
     * ever, nor take the place of readable ones behind it.
     */
    public const REASON_UNREADABLE = 'unreadable';

    /** How many runs of the catch-up try an unreadable file before leaving it. */
    public const MAX_UNREADABLE_TRIES = 3;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Remembers that an inbound attachment produced (or is) this receipt.
     *
     * First receipt wins: the row says where the receipt came from the
     * first time, and a later pass over the same attachment has nothing to
     * add. Except to a row that names no receipt — a refusal, or one held
     * by a receipt it could not name — which a person's later filing of
     * the same attachment completes, so its receipt has a provenance too.
     */
    public function record(int $inboundAttachmentId, int $inboundMessageId, ?int $attachmentId, string $reason): void
    {
        $reason = mb_substr($reason, 0, 20);
        if ($this->exists($inboundAttachmentId)) {
            // An unreadable file that has since been read is whatever came
            // of it now; any other row is completed only by a receipt.
            $this->pdo->prepare(
                'UPDATE finance_receipt_mail_sources SET attachment_id = ?, reason = ?
                  WHERE inbound_attachment_id = ? AND (reason = ? OR (attachment_id IS NULL AND ? IS NOT NULL))'
            )->execute([$attachmentId, $reason, $inboundAttachmentId, self::REASON_UNREADABLE, $attachmentId]);

            return;
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO finance_receipt_mail_sources
                    (inbound_attachment_id, inbound_message_id, attachment_id, reason)
                 VALUES (?, ?, ?, ?)'
            )->execute([$inboundAttachmentId, $inboundMessageId, $attachmentId, $reason]);
        } catch (\PDOException $e) {
            // Two passes over the same attachment at once: the other one
            // wrote the row, which is all this one wanted. Anything else is
            // a real failure.
            if (!$this->exists($inboundAttachmentId)) {
                throw $e;
            }
        }
    }

    /**
     * One more failed try at reading this attachment's file — the first
     * writes the row, later ones count.
     */
    public function markUnreadable(int $inboundAttachmentId, int $inboundMessageId): void
    {
        if (!$this->exists($inboundAttachmentId)) {
            $this->record($inboundAttachmentId, $inboundMessageId, null, self::REASON_UNREADABLE);

            return;
        }

        $this->pdo->prepare(
            'UPDATE finance_receipt_mail_sources SET attempts = attempts + 1
              WHERE inbound_attachment_id = ? AND reason = ?'
        )->execute([$inboundAttachmentId, self::REASON_UNREADABLE]);
    }

    /**
     * Whether the catch-up is finished with this attachment: through
     * finance, or unreadable {@see self::MAX_UNREADABLE_TRIES} times.
     */
    public function settled(int $inboundAttachmentId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM finance_receipt_mail_sources
              WHERE inbound_attachment_id = ? AND (reason <> ? OR attempts >= ?) LIMIT 1'
        );
        $stmt->execute([$inboundAttachmentId, self::REASON_UNREADABLE, self::MAX_UNREADABLE_TRIES]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Any row at all, an unreadable one included.
     *
     * @phpstan-impure
     */
    private function exists(int $inboundAttachmentId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM finance_receipt_mail_sources WHERE inbound_attachment_id = ? LIMIT 1'
        );
        $stmt->execute([$inboundAttachmentId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Whether this inbound attachment has already been through finance — a
     * file that could not be read has not.
     *
     * Impure: another pass can write the row between two calls.
     *
     * @phpstan-impure
     */
    public function has(int $inboundAttachmentId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM finance_receipt_mail_sources WHERE inbound_attachment_id = ? AND reason <> ? LIMIT 1'
        );
        $stmt->execute([$inboundAttachmentId, self::REASON_UNREADABLE]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The inbound messages a receipt is known to have come from.
     *
     * @return list<int>
     */
    public function messageIdsFor(int $attachmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT inbound_message_id FROM finance_receipt_mail_sources WHERE attachment_id = ?'
        );
        $stmt->execute([$attachmentId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * For the receipts screen: the first recorded reason of each receipt
     * that arrived by e-mail, keyed by receipt id.
     *
     * @param int[] $attachmentIds
     * @return array<int, array{reason: string, recorded_at: string}>
     */
    public function originsFor(array $attachmentIds): array
    {
        $attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));
        if ($attachmentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($attachmentIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT attachment_id, reason, recorded_at FROM finance_receipt_mail_sources
              WHERE attachment_id IN (' . $placeholders . ') AND reason NOT IN (?, ?, ?)
           ORDER BY recorded_at ASC, inbound_attachment_id ASC'
        );
        $stmt->execute([...$attachmentIds, self::REASON_ALREADY_HELD, self::REASON_NOT_FILED, self::REASON_UNREADABLE]);

        $origins = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['attachment_id'];
            $origins[$id] ??= ['reason' => (string) $row['reason'], 'recorded_at' => (string) $row['recorded_at']];
        }

        return $origins;
    }
}
