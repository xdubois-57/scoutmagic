<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Security\EncryptionService;

/**
 * TEMPORARY — remove this class, its call site in public/index.php, and
 * the 'address_blind_index_backfilled' setting at iteration 3. Do not add
 * anything else to it in the meantime.
 *
 * schema/core.sql's migration is DDL-only (MigrationRunner never
 * backfills data), so every member_addresses row written before
 * address_normalized_blind_index existed has it NULL. Without this
 * one-time catch-up, Core\Member\FeeEstimationService would silently
 * report "one person" for every household on any already-installed unit
 * — a result that looks like normal behavior and would go unnoticed.
 *
 * Module spec: ~250 rows, one single pass, no batching (decrypting 250
 * addresses is negligible; a progressive/chunked mechanism would be
 * needless complexity for a one-off operation). Idempotent — only
 * touches rows where the index is still NULL.
 */
class AddressBlindIndexBackfill
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Returns the number of rows updated.
     */
    public function run(): int
    {
        $stmt = $this->pdo->query(
            'SELECT id, street_encrypted, number_encrypted, box_encrypted, postal_code_encrypted
             FROM member_addresses WHERE address_normalized_blind_index IS NULL'
        );
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        if ($rows === []) {
            return 0;
        }

        $update = $this->pdo->prepare('UPDATE member_addresses SET address_normalized_blind_index = ? WHERE id = ?');

        foreach ($rows as $row) {
            $street = $row['street_encrypted'] !== null ? $this->encryption->decrypt($row['street_encrypted']) : null;
            $number = $row['number_encrypted'] !== null ? $this->encryption->decrypt($row['number_encrypted']) : null;
            $box = $row['box_encrypted'] !== null ? $this->encryption->decrypt($row['box_encrypted']) : null;
            $postalCode = $row['postal_code_encrypted'] !== null ? $this->encryption->decrypt($row['postal_code_encrypted']) : null;

            $normalized = AddressNormalizer::normalize($street, $number, $box, $postalCode);
            $blindIndex = $normalized !== '' ? $this->encryption->blindIndex($normalized) : null;

            $update->execute([$blindIndex, $row['id']]);
        }

        return count($rows);
    }
}
