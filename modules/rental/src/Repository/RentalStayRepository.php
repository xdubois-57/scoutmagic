<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\InventoryState;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RentalMeter;
use Modules\Rental\Stay\Settlement;
use Modules\Rental\Stay\SettlementLine;

/**
 * Everything the stay itself produces (§6.21–§6.23): meters and their
 * readings, the inventory checklist and its per-booking snapshot, incidents,
 * and the versioned final settlement.
 *
 * One repository rather than five because these tables are always read
 * together — a booking's "stay" panel wants all of them — and splitting
 * them would mean five constructor arguments everywhere for no boundary
 * anybody draws.
 *
 * **Only incident descriptions are encrypted.** They are written by a
 * manager about what a renter's group did, so they carry the same
 * protection as the renter's own fields. A meter comment and an inventory
 * note are about a device and a piece of furniture, and stay plain — the
 * same rule, and the same responsibility on the interface, as
 * `rental_blocks.reason`.
 *
 * Every timestamp is computed in PHP, never MySQL's `NOW()`, so this runs
 * unmodified against the SQLite test database.
 */
class RentalStayRepository
{
    private const CTX_INCIDENT = 'rental_incidents.description';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    // ── Meters (§6.22) ──────────────────────────────────────────────────

    public function createMeter(
        int $assetId,
        string $label,
        MeterKind $kind,
        string $unit,
        ?int $feeId,
        int $sortOrder = 0
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_meters (asset_id, label, meter_kind, unit, fee_id, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $label,
            $kind->value,
            $unit,
            $feeId,
            $sortOrder,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return RentalMeter[]
     */
    public function findMeters(int $assetId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM rental_meters WHERE asset_id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$assetId]);

        return array_map(
            static fn(array $row) => new RentalMeter(
                id: (int) $row['id'],
                assetId: (int) $row['asset_id'],
                label: (string) $row['label'],
                kind: MeterKind::tryFrom((string) $row['meter_kind']) ?? MeterKind::OTHER,
                unit: (string) $row['unit'],
                feeId: $row['fee_id'] !== null ? (int) $row['fee_id'] : null,
                sortOrder: (int) $row['sort_order'],
                isActive: (bool) $row['is_active']
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function findMeter(int $meterId): ?RentalMeter
    {
        $stmt = $this->pdo->prepare('SELECT asset_id FROM rental_meters WHERE id = ?');
        $stmt->execute([$meterId]);
        $assetId = $stmt->fetchColumn();

        if ($assetId === false) {
            return null;
        }

        foreach ($this->findMeters((int) $assetId, false) as $meter) {
            if ($meter->id === $meterId) {
                return $meter;
            }
        }

        return null;
    }

    /**
     * Retiring a meter rather than deleting it: readings already taken
     * against it are evidence for a settlement, and a foreign key that
     * cascaded would erase them along with the meter.
     */
    public function deactivateMeter(int $meterId): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_meters SET is_active = 0 WHERE id = ?');
        $stmt->execute([$meterId]);
    }

    // ── Meter readings (§6.22) ──────────────────────────────────────────

    /**
     * Records a reading, replacing any earlier one for the same meter and
     * phase.
     *
     * Replacing rather than accumulating is the point: a second arrival
     * reading is a **correction**, and keeping both would make consumption
     * a guess about which pair to use.
     */
    public function saveReading(
        int $bookingId,
        int $meterId,
        ReadingPhase $phase,
        int $valueMilli,
        \DateTimeImmutable $readAt,
        ?int $fileId,
        ?string $comment,
        ?int $recordedByMemberId
    ): void {
        $existing = $this->findReading($bookingId, $meterId, $phase);
        $timestamp = $readAt->format('Y-m-d H:i:s');
        $comment = $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 255) : null;

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE rental_meter_readings
                 SET value_milli = ?, read_at = ?, file_id = ?, comment = ?, recorded_by_member_id = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $valueMilli,
                $timestamp,
                // An existing photo survives a value correction: the photo
                // is the evidence for the reading, and re-uploading it just
                // to fix a digit is not something to demand.
                $fileId ?? $existing->fileId,
                $comment,
                $recordedByMemberId,
                $existing->id,
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_meter_readings
                (booking_id, meter_id, phase, value_milli, read_at, file_id, comment, recorded_by_member_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $bookingId,
            $meterId,
            $phase->value,
            $valueMilli,
            $timestamp,
            $fileId,
            $comment,
            $recordedByMemberId,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function findReading(int $bookingId, int $meterId, ReadingPhase $phase): ?MeterReading
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_meter_readings WHERE booking_id = ? AND meter_id = ? AND phase = ?'
        );
        $stmt->execute([$bookingId, $meterId, $phase->value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateReading($row);
    }

    /**
     * Every reading of a booking, keyed `meterId.phase` so a caller can pair
     * them up without a query each.
     *
     * @return array<string, MeterReading>
     */
    public function findReadings(int $bookingId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_meter_readings WHERE booking_id = ?');
        $stmt->execute([$bookingId]);

        $readings = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $reading = $this->hydrateReading($row);
            $readings[$reading->meterId . '.' . $reading->phase->value] = $reading;
        }

        return $readings;
    }

    // ── Inventory template (§6.23) ──────────────────────────────────────

    public function createInventoryItem(int $assetId, string $label, int $sortOrder = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_inventory_items (asset_id, label, sort_order, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$assetId, $label, $sortOrder, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array{id: int, label: string, sort_order: int}>
     */
    public function findInventoryItems(int $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, label, sort_order FROM rental_inventory_items
             WHERE asset_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$assetId]);

        return array_map(
            static fn(array $row) => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'sort_order' => (int) $row['sort_order'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function deactivateInventoryItem(int $itemId): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_inventory_items SET is_active = 0 WHERE id = ?');
        $stmt->execute([$itemId]);
    }

    // ── Inventory snapshot (§6.23) ──────────────────────────────────────

    /**
     * Copies the asset's checklist into the booking, **once**.
     *
     * The flag rather than "are there rows?": an asset with an empty
     * checklist snapshots legitimately into zero rows, and re-snapshotting
     * later would overwrite a completed inventory with blanks.
     *
     * @return bool Whether the snapshot was taken now.
     */
    public function snapshotInventory(int $bookingId, int $assetId): bool
    {
        $stmt = $this->pdo->prepare('SELECT inventory_snapshotted FROM rental_bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $already = $stmt->fetchColumn();

        if ($already === false || (bool) $already) {
            return false;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $insert = $this->pdo->prepare(
            'INSERT INTO rental_booking_inventory (booking_id, label, sort_order, updated_at) VALUES (?, ?, ?, ?)'
        );

        foreach ($this->findInventoryItems($assetId) as $item) {
            // The LABEL is copied, not referenced: an item renamed in June
            // must not rewrite what was checked in March, and one deleted
            // must not erase a finding.
            $insert->execute([$bookingId, $item['label'], $item['sort_order'], $now]);
        }

        $flag = $this->pdo->prepare(
            'UPDATE rental_bookings SET inventory_snapshotted = 1, updated_at = ? WHERE id = ?'
        );
        $flag->execute([$now, $bookingId]);

        return true;
    }

    /**
     * @return array<int, array{id: int, label: string, sort_order: int, arrival_state: InventoryState, departure_state: InventoryState, arrival_note: ?string, departure_note: ?string}>
     */
    public function findBookingInventory(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_booking_inventory WHERE booking_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$bookingId]);

        return array_map(
            static fn(array $row) => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'sort_order' => (int) $row['sort_order'],
                'arrival_state' => InventoryState::tryFrom((string) $row['arrival_state'])
                    ?? InventoryState::NOT_CHECKED,
                'departure_state' => InventoryState::tryFrom((string) $row['departure_state'])
                    ?? InventoryState::NOT_CHECKED,
                'arrival_note' => $row['arrival_note'] !== null ? (string) $row['arrival_note'] : null,
                'departure_note' => $row['departure_note'] !== null ? (string) $row['departure_note'] : null,
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function setInventoryState(
        int $inventoryId,
        ReadingPhase $phase,
        InventoryState $state,
        ?string $note
    ): void {
        $stateColumn = $phase === ReadingPhase::ARRIVAL ? 'arrival_state' : 'departure_state';
        $noteColumn = $phase === ReadingPhase::ARRIVAL ? 'arrival_note' : 'departure_note';

        // The column names come from the enum above, never from a request:
        // a phase that is not one of the two cases cannot reach this line.
        $stmt = $this->pdo->prepare(
            "UPDATE rental_booking_inventory SET {$stateColumn} = ?, {$noteColumn} = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([
            $state->value,
            $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $inventoryId,
        ]);
    }

    public function findInventoryBookingId(int $inventoryId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT booking_id FROM rental_booking_inventory WHERE id = ?');
        $stmt->execute([$inventoryId]);
        $bookingId = $stmt->fetchColumn();

        return $bookingId === false ? null : (int) $bookingId;
    }

    // ── Incidents (§6.23) ───────────────────────────────────────────────

    public function createIncident(
        int $bookingId,
        string $description,
        ?int $proposedAmountCents,
        ?int $fileId,
        ?int $createdByMemberId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_incidents
                (booking_id, description_encrypted, proposed_amount_cents, decision, file_id, created_by_member_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $bookingId,
            $this->encryption->encrypt(trim($description), self::CTX_INCIDENT),
            $proposedAmountCents,
            IncidentDecision::PENDING->value,
            $fileId,
            $createdByMemberId,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function decideIncident(
        int $incidentId,
        IncidentDecision $decision,
        ?int $decidedAmountCents,
        ?int $decidedByMemberId
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_incidents
             SET decision = ?, decided_amount_cents = ?, decided_at = ?, decided_by_member_id = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $decision->value,
            $decidedAmountCents,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $decidedByMemberId,
            $incidentId,
        ]);
    }

    public function findIncident(int $incidentId): ?Incident
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_incidents WHERE id = ?');
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateIncident($row);
    }

    /**
     * @return Incident[]
     */
    public function findIncidents(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_incidents WHERE booking_id = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$bookingId]);

        return array_map(fn(array $row) => $this->hydrateIncident($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function deleteIncident(int $incidentId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_incidents WHERE id = ?');
        $stmt->execute([$incidentId]);
    }

    // ── Settlements (§6.21) ─────────────────────────────────────────────

    /**
     * Claims the next settlement version, moving the counter forward.
     *
     * **Never `MAX(version)` over the surviving rows.** A deleted v2 must
     * not make the next settlement v2 again: v2 may already have been sent.
     * Same reasoning, and the same shape, as document versions and booking
     * references.
     */
    public function claimNextSettlementVersion(int $bookingId): int
    {
        $stmt = $this->pdo->prepare('SELECT settlement_last_version FROM rental_bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $current = $stmt->fetchColumn();

        $next = $current === false || $current === null ? 1 : ((int) $current) + 1;

        $update = $this->pdo->prepare(
            'UPDATE rental_bookings SET settlement_last_version = ?, updated_at = ? WHERE id = ?'
        );
        $update->execute([$next, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $bookingId]);

        return $next;
    }

    /**
     * @param SettlementLine[] $lines
     */
    public function createSettlement(
        int $bookingId,
        int $version,
        ?int $finalPersons,
        array $lines,
        int $totalCents,
        int $alreadyPaidCents,
        int $balanceCents,
        ?int $withheldCents,
        ?int $returnCents,
        ?int $createdByMemberId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_settlements
                (booking_id, version, final_persons, lines_snapshot, total_cents, already_paid_cents,
                 balance_cents, security_deposit_withheld_cents, security_deposit_return_cents,
                 created_by_member_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $bookingId,
            $version,
            $finalPersons,
            (string) json_encode(
                array_map(static fn(SettlementLine $line) => $line->toArray(), $lines),
                JSON_UNESCAPED_UNICODE
            ),
            $totalCents,
            $alreadyPaidCents,
            $balanceCents,
            $withheldCents,
            $returnCents,
            $createdByMemberId,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Marks a settlement validated, **only if it is not already**.
     *
     * The `is_validated = 0` in the WHERE clause is what makes a validated
     * settlement immutable: a second validation matches no row and the
     * caller is told so, rather than silently re-stamping a document
     * somebody may already have acted on.
     */
    public function validateSettlement(int $settlementId, ?int $validatedByMemberId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_settlements SET is_validated = 1, validated_at = ?, validated_by_member_id = ?
             WHERE id = ? AND is_validated = 0'
        );
        $stmt->execute([
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $validatedByMemberId,
            $settlementId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function findSettlement(int $settlementId): ?Settlement
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_settlements WHERE id = ?');
        $stmt->execute([$settlementId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateSettlement($row);
    }

    /**
     * @return Settlement[] Newest version first.
     */
    public function findSettlements(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_settlements WHERE booking_id = ? ORDER BY version DESC'
        );
        $stmt->execute([$bookingId]);

        return array_map(fn(array $row) => $this->hydrateSettlement($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findLatestSettlement(int $bookingId): ?Settlement
    {
        $settlements = $this->findSettlements($bookingId);

        return $settlements === [] ? null : $settlements[0];
    }

    public function deleteSettlement(int $settlementId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_settlements WHERE id = ? AND is_validated = 0');
        $stmt->execute([$settlementId]);
    }

    // ── Hydration ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateReading(array $row): MeterReading
    {
        return new MeterReading(
            id: (int) $row['id'],
            bookingId: (int) $row['booking_id'],
            meterId: (int) $row['meter_id'],
            phase: ReadingPhase::tryFrom((string) $row['phase']) ?? ReadingPhase::ARRIVAL,
            valueMilli: (int) $row['value_milli'],
            readAt: new \DateTimeImmutable((string) $row['read_at']),
            fileId: $row['file_id'] !== null ? (int) $row['file_id'] : null,
            comment: $row['comment'] !== null ? (string) $row['comment'] : null,
            recordedByMemberId: $row['recorded_by_member_id'] !== null ? (int) $row['recorded_by_member_id'] : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateIncident(array $row): Incident
    {
        return new Incident(
            id: (int) $row['id'],
            bookingId: (int) $row['booking_id'],
            description: $this->encryption->decrypt((string) $row['description_encrypted'], self::CTX_INCIDENT),
            proposedAmountCents: $row['proposed_amount_cents'] !== null
                ? (int) $row['proposed_amount_cents']
                : null,
            decision: IncidentDecision::tryFrom((string) $row['decision']) ?? IncidentDecision::PENDING,
            decidedAmountCents: $row['decided_amount_cents'] !== null ? (int) $row['decided_amount_cents'] : null,
            decidedAt: $row['decided_at'] !== null ? new \DateTimeImmutable((string) $row['decided_at']) : null,
            decidedByMemberId: $row['decided_by_member_id'] !== null ? (int) $row['decided_by_member_id'] : null,
            fileId: $row['file_id'] !== null ? (int) $row['file_id'] : null,
            createdByMemberId: $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at'])
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSettlement(array $row): Settlement
    {
        $decoded = json_decode((string) ($row['lines_snapshot'] ?? '[]'), true);

        return new Settlement(
            id: (int) $row['id'],
            bookingId: (int) $row['booking_id'],
            version: (int) $row['version'],
            finalPersons: $row['final_persons'] !== null ? (int) $row['final_persons'] : null,
            lines: array_map(
                static fn(array $line) => SettlementLine::fromArray($line),
                is_array($decoded) ? $decoded : []
            ),
            totalCents: (int) $row['total_cents'],
            alreadyPaidCents: (int) $row['already_paid_cents'],
            balanceCents: (int) $row['balance_cents'],
            securityDepositWithheldCents: $row['security_deposit_withheld_cents'] !== null
                ? (int) $row['security_deposit_withheld_cents']
                : null,
            securityDepositReturnCents: $row['security_deposit_return_cents'] !== null
                ? (int) $row['security_deposit_return_cents']
                : null,
            isValidated: (bool) $row['is_validated'],
            validatedAt: $row['validated_at'] !== null ? new \DateTimeImmutable((string) $row['validated_at']) : null,
            validatedByMemberId: $row['validated_by_member_id'] !== null
                ? (int) $row['validated_by_member_id']
                : null,
            createdByMemberId: $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at'])
        );
    }
}
