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

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Remembers that an inbound attachment produced (or is) this receipt.
     *
     * First write wins: the row says where the receipt came from the first
     * time, and a later pass over the same attachment has nothing to add.
     */
    public function record(int $inboundAttachmentId, int $inboundMessageId, ?int $attachmentId, string $reason): void
    {
        if ($this->has($inboundAttachmentId)) {
            return;
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO finance_receipt_mail_sources
                    (inbound_attachment_id, inbound_message_id, attachment_id, reason)
                 VALUES (?, ?, ?, ?)'
            )->execute([$inboundAttachmentId, $inboundMessageId, $attachmentId, mb_substr($reason, 0, 20)]);
        } catch (\PDOException $e) {
            // Two passes over the same attachment at once: the other one
            // wrote the row, which is all this one wanted. Anything else is
            // a real failure.
            if (!$this->has($inboundAttachmentId)) {
                throw $e;
            }
        }
    }

    /**
     * Whether this inbound attachment has already been through finance.
     *
     * Impure: another pass can write the row between two calls, which is
     * exactly what record() re-asks after a failed insert.
     *
     * @phpstan-impure
     */
    public function has(int $inboundAttachmentId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM finance_receipt_mail_sources WHERE inbound_attachment_id = ? LIMIT 1'
        );
        $stmt->execute([$inboundAttachmentId]);

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
              WHERE attachment_id IN (' . $placeholders . ') AND reason NOT IN (?, ?)
           ORDER BY recorded_at ASC, inbound_attachment_id ASC'
        );
        $stmt->execute([...$attachmentIds, self::REASON_ALREADY_HELD, self::REASON_NOT_FILED]);

        $origins = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['attachment_id'];
            $origins[$id] ??= ['reason' => (string) $row['reason'], 'recorded_at' => (string) $row['recorded_at']];
        }

        return $origins;
    }
}
