<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

use Core\Security\EncryptionService;

/**
 * finance_campaign_rows.
 *
 * Two encrypted columns, both for the same reason and both decrypted
 * only here: `merge_data` carries whatever other columns the treasurer's
 * spreadsheet had (a name, an address, a section — personal data by any
 * reading), and `note` is what the treasurers tell each other about a
 * receivable and the family must never read.
 */
class CampaignRowRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param array<string, string> $mergeData
     */
    public function create(int $campaignId, int $memberId, int $amountCents, int $sourceLine, array $mergeData): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_campaign_rows (campaign_id, member_id, amount_cents, source_line, merge_data, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $campaignId,
            $memberId,
            $amountCents,
            $sourceLine,
            $mergeData === [] ? null : $this->encryption->encrypt(
                json_encode($mergeData, JSON_UNESCAPED_UNICODE) ?: '{}',
                'finance_campaign_rows.merge_data'
            ),
            date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?CampaignRow
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_campaign_rows WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return CampaignRow[]
     */
    public function findByCampaignId(int $campaignId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_campaign_rows WHERE campaign_id = ? ORDER BY id ASC');
        $stmt->execute([$campaignId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Rows naming one member, across every campaign — what the member's
     * own page and the home banner read.
     *
     * @param int[] $memberIds
     * @return CampaignRow[]
     */
    public function findByMemberIds(array $memberIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $memberIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM finance_campaign_rows WHERE member_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($ids);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countByCampaignId(int $campaignId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM finance_campaign_rows WHERE campaign_id = ?');
        $stmt->execute([$campaignId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Writes — or clears, with a null $note — the treasurers' own note.
     *
     * The author and the moment travel with the text, because "who said
     * this and when" is most of what makes a note usable six weeks later.
     */
    public function setNote(int $id, ?string $note, ?int $authorId): void
    {
        $trimmed = $note !== null ? trim($note) : null;
        $stmt = $this->pdo->prepare(
            'UPDATE finance_campaign_rows SET note = ?, note_author_id = ?, note_updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $trimmed === null || $trimmed === '' ? null : $this->encryption->encrypt($trimmed, 'finance_campaign_rows.note'),
            $trimmed === null || $trimmed === '' ? null : $authorId,
            $trimmed === null || $trimmed === '' ? null : date('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * Drops the spreadsheet's columns for a whole campaign, when the
     * source file itself is purged. Keeping a copy of data we have
     * promised to delete would make the promise decorative.
     */
    public function forgetMergeDataForCampaign(int $campaignId): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_campaign_rows SET merge_data = NULL WHERE campaign_id = ?');
        $stmt->execute([$campaignId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CampaignRow
    {
        $mergeData = [];
        if (($row['merge_data'] ?? null) !== null) {
            $decoded = json_decode($this->encryption->decrypt($row['merge_data'], 'finance_campaign_rows.merge_data'), true);
            if (is_array($decoded)) {
                foreach ($decoded as $header => $value) {
                    $mergeData[(string) $header] = (string) $value;
                }
            }
        }

        return new CampaignRow(
            id: (int) $row['id'],
            campaignId: (int) $row['campaign_id'],
            memberId: (int) $row['member_id'],
            amountCents: (int) $row['amount_cents'],
            sourceLine: (int) ($row['source_line'] ?? 0),
            mergeData: $mergeData,
            note: ($row['note'] ?? null) !== null ? $this->encryption->decrypt($row['note'], 'finance_campaign_rows.note') : null,
            noteAuthorId: isset($row['note_author_id']) ? (int) $row['note_author_id'] : null,
            noteUpdatedAt: isset($row['note_updated_at']) ? (string) $row['note_updated_at'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
