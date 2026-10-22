<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\IncidentPhoto;
use Modules\Rental\Stay\InventoryKind;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RentalMeter;
use Modules\Rental\Stay\RenterSigning;
use Modules\Rental\Stay\RenterIncident;
use Modules\Rental\Stay\RenterIncidents;
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
 * **Only incident texts are encrypted**, with the reason a balance was
 * not recovered (#980). They are written by a manager about what a
 * renter's group did or owes, so they carry the same protection as the
 * renter's own fields. A meter comment and an inventory
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
    private const CTX_INCIDENT_AWAITING = 'rental_incidents.awaiting';
    private const CTX_INCIDENT_REFERENCE = 'rental_incidents.reference';
    private const CTX_INCIDENT_WAIVE_REASON = 'rental_incidents.waive_reason';
    private const CTX_UNRECOVERED_REASON = 'rental_settlements.unrecovered_reason';

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
     *
     * Never onto a validated phase, checked by the write itself — see
     * setInventoryValue(). False when no row changed: the phase was
     * validated in between, or the correction changed nothing.
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
    ): bool {
        $existing = $this->findReading($bookingId, $meterId, $phase);
        $timestamp = $readAt->format('Y-m-d H:i:s');
        $comment = $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 255) : null;

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE rental_meter_readings
                 SET value_milli = ?, read_at = ?, file_id = ?, comment = ?, recorded_by_member_id = ?
                 WHERE id = ?
                   AND NOT EXISTS (SELECT 1 FROM rental_inventory_validations v
                                    WHERE v.booking_id = ? AND v.phase IN (' . self::placeholders($phase) . '))'
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
                $bookingId,
                ...self::frozenByValues($phase),
            ]);

            return $stmt->rowCount() > 0;
        }

        // `FROM (SELECT 1)` rather than MySQL's `FROM DUAL`, which SQLite
        // does not know: the one-row source the NOT EXISTS filters.
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_meter_readings
                (booking_id, meter_id, phase, value_milli, read_at, file_id, comment, recorded_by_member_id, '
                . 'created_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?, ? FROM (SELECT 1 AS one) AS single_row
              WHERE NOT EXISTS (SELECT 1 FROM rental_inventory_validations v
                                 WHERE v.booking_id = ? AND v.phase IN (' . self::placeholders($phase) . '))'
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
            $bookingId,
            ...self::frozenByValues($phase),
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Takes the photo off a reading (#989) — never on a validated phase,
     * read by the UPDATE itself, like saveReading(). False when nothing
     * changed: the phase was validated in between, or the photo is no
     * longer this one. The file is the caller's to delete.
     */
    public function clearReadingPhoto(int $readingId, int $bookingId, int $fileId, ReadingPhase $phase): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_meter_readings SET file_id = NULL
              WHERE id = ? AND booking_id = ? AND file_id = ?
                AND NOT EXISTS (SELECT 1 FROM rental_inventory_validations v
                                 WHERE v.booking_id = ? AND v.phase IN (' . self::placeholders($phase) . '))'
        );
        $stmt->execute([$readingId, $bookingId, $fileId, $bookingId, ...self::frozenByValues($phase)]);

        // A non-null file_id becomes NULL: the row CHANGES whenever it
        // matches, so MySQL's changed-rows count and SQLite's matched-rows
        // count agree here.
        return $stmt->rowCount() > 0;
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

    /**
     * A new item goes to the END of the list (#708, IT-10): there is no
     * position field any more, the order is the manager's drag.
     */
    public function createInventoryItem(int $assetId, string $label, InventoryKind $kind, ?int $expectedCount): int
    {
        $next = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM rental_inventory_items WHERE asset_id = ? AND is_active = 1'
        );
        $next->execute([$assetId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_inventory_items (asset_id, label, kind, expected_count, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $label,
            $kind->value,
            $expectedCount,
            (int) $next->fetchColumn(),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array{id: int, label: string, kind: InventoryKind, expected_count: ?int, sort_order: int}>
     */
    public function findInventoryItems(int $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, label, kind, expected_count, sort_order FROM rental_inventory_items
             WHERE asset_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$assetId]);

        return array_map(
            static fn(array $row) => self::hydrateInventoryItem($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * One active item of THIS asset's template, or null — the guard every
     * write on an item goes through, so an id alone cannot reach another
     * asset's list.
     *
     * @return array{id: int, label: string, kind: InventoryKind, expected_count: ?int, sort_order: int}|null
     */
    public function findInventoryItem(int $assetId, int $itemId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, label, kind, expected_count, sort_order FROM rental_inventory_items
             WHERE id = ? AND asset_id = ? AND is_active = 1'
        );
        $stmt->execute([$itemId, $assetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrateInventoryItem($row) : null;
    }

    public function updateInventoryItemKind(int $itemId, InventoryKind $kind, ?int $expectedCount): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_inventory_items SET kind = ?, expected_count = ? WHERE id = ?');
        $stmt->execute([$kind->value, $expectedCount, $itemId]);
    }

    /**
     * Rewrites the positions of an asset's items in the order given. Ids
     * that are not this asset's are ignored by the WHERE, never moved.
     *
     * @param int[] $itemIds
     */
    public function reorderInventoryItems(int $assetId, array $itemIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_inventory_items SET sort_order = ? WHERE id = ? AND asset_id = ?');
        foreach (array_values($itemIds) as $position => $itemId) {
            $stmt->execute([$position, $itemId, $assetId]);
        }
    }

    public function deactivateInventoryItem(int $itemId): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_inventory_items SET is_active = 0 WHERE id = ?');
        $stmt->execute([$itemId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, label: string, kind: InventoryKind, expected_count: ?int, sort_order: int}
     */
    private static function hydrateInventoryItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'kind' => InventoryKind::tryFrom((string) $row['kind']) ?? InventoryKind::QUANTITY,
            'expected_count' => $row['expected_count'] !== null ? (int) $row['expected_count'] : null,
            'sort_order' => (int) $row['sort_order'],
        ];
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
            'INSERT INTO rental_booking_inventory (booking_id, label, kind, expected_count, sort_order, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($this->findInventoryItems($assetId) as $item) {
            // The LABEL is copied, not referenced: an item renamed in June
            // must not rewrite what was checked in March, and one deleted
            // must not erase a finding. The sort and the count travel with
            // it for the same reason (#708, IT-10).
            $insert->execute([
                $bookingId,
                $item['label'],
                $item['kind']->value,
                $item['expected_count'],
                $item['sort_order'],
                $now,
            ]);
        }

        $flag = $this->pdo->prepare(
            'UPDATE rental_bookings SET inventory_snapshotted = 1, updated_at = ? WHERE id = ?'
        );
        $flag->execute([$now, $bookingId]);

        return true;
    }

    /** Whether this booking's checklist was copied (at its confirmation). */
    public function isInventorySnapshotted(int $bookingId): bool
    {
        $stmt = $this->pdo->prepare('SELECT inventory_snapshotted FROM rental_bookings WHERE id = ?');
        $stmt->execute([$bookingId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array<
     *     int,
     *     array{
     *         id: int,
     *         label: string,
     *         kind: InventoryKind,
     *         expected_count: ?int,
     *         sort_order: int,
     *         arrival_value: ?string,
     *         departure_value: ?string,
     *         arrival_note: ?string,
     *         departure_note: ?string
     *     }
     * >
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
                'kind' => InventoryKind::tryFrom((string) $row['kind']) ?? InventoryKind::QUANTITY,
                'expected_count' => $row['expected_count'] !== null ? (int) $row['expected_count'] : null,
                'sort_order' => (int) $row['sort_order'],
                'arrival_value' => isset($row['arrival_value']) ? (string) $row['arrival_value'] : null,
                'departure_value' => isset($row['departure_value']) ? (string) $row['departure_value'] : null,
                'arrival_note' => $row['arrival_note'] !== null ? (string) $row['arrival_note'] : null,
                'departure_note' => $row['departure_note'] !== null ? (string) $row['departure_note'] : null,
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * What was found on one line, for one phase (#708, IT-17): the value —
     * already parsed by its sort — and the note beside it.
     *
     * Never onto a validated phase, checked by the write itself and not
     * only beforehand: a save that passed the check while another manager
     * was validating would otherwise land after the PDF was made, and the
     * frozen value would differ from the one the renter holds. "Validated"
     * includes a later phase (`ReadingPhase::frozenBy()`): the departure's
     * PDF is read against the arrival, even one only ticked by hand.
     *
     * False when no row changed — the phase was validated in between, or
     * (MySQL counts changed rows, not matched ones) nothing was different.
     */
    public function setInventoryValue(
        int $inventoryId,
        ReadingPhase $phase,
        ?string $value,
        ?string $note
    ): bool {
        $valueColumn = $phase === ReadingPhase::ARRIVAL ? 'arrival_value' : 'departure_value';
        $noteColumn = $phase === ReadingPhase::ARRIVAL ? 'arrival_note' : 'departure_note';

        // The column names come from the enum above, never from a request:
        // a phase that is not one of the two cases cannot reach this line.
        $stmt = $this->pdo->prepare(
            "UPDATE rental_booking_inventory SET {$valueColumn} = ?, {$noteColumn} = ?, updated_at = ?
              WHERE id = ?
                AND NOT EXISTS (SELECT 1 FROM rental_inventory_validations v
                                 WHERE v.booking_id = rental_booking_inventory.booking_id
                                   AND v.phase IN (" . self::placeholders($phase) . '))'
        );
        $stmt->execute([
            $value,
            $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $inventoryId,
            ...self::frozenByValues($phase),
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The phases validated on this booking (#708, IT-17), keyed by phase.
     *
     * @return array<string, array{
     *     validated_at: \DateTimeImmutable,
     *     validated_by_member_id: ?int,
     *     validated_by_account_id: ?int,
     *     renter_signing: ?RenterSigning,
     *     document_id: ?int,
     *     document_sha256: ?string,
     *     paper_sheet_document_id: ?int
     * }>
     */
    public function findInventoryValidations(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT phase, validated_at, validated_by_member_id, validated_by_account_id, renter_signing,
                    document_id, document_sha256, paper_sheet_document_id
               FROM rental_inventory_validations WHERE booking_id = ?'
        );
        $stmt->execute([$bookingId]);

        $validations = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $validations[(string) $row['phase']] = [
                'validated_at' => DateInput::requireFromStorage(
                    (string) $row['validated_at'],
                    'rental_inventory_validations.validated_at'
                ),
                'validated_by_member_id' => $row['validated_by_member_id'] !== null
                    ? (int) $row['validated_by_member_id']
                    : null,
                'validated_by_account_id' => $row['validated_by_account_id'] !== null
                    ? (int) $row['validated_by_account_id']
                    : null,
                // Null for a value this version does not know (a later
                // lot's « paper » read by an older one), never a guess.
                'renter_signing' => RenterSigning::tryFrom((string) $row['renter_signing']),
                'document_id' => $row['document_id'] !== null ? (int) $row['document_id'] : null,
                'document_sha256' => $row['document_sha256'] !== null ? (string) $row['document_sha256'] : null,
                'paper_sheet_document_id' => $row['paper_sheet_document_id'] !== null
                    ? (int) $row['paper_sheet_document_id']
                    : null,
            ];
        }

        return $validations;
    }

    /**
     * Freezes a phase. False when it already was: the unique key is the
     * lock, so two managers validating at once produce one validation.
     *
     * `$accountId` is the account that signed (the session's) and
     * `$renterSigning` what the renter did (#989, lot E) — the only trace
     * of the renter's part, never their typed name. The default is what
     * the many fixtures freezing a phase need; the signing service always
     * says.
     */
    public function recordInventoryValidation(
        int $bookingId,
        ReadingPhase $phase,
        \DateTimeImmutable $at,
        ?int $memberId,
        ?int $accountId = null,
        RenterSigning $renterSigning = RenterSigning::ON_SITE
    ): bool {
        try {
            $this->pdo->prepare(
                'INSERT INTO rental_inventory_validations
                    (booking_id, phase, validated_at, validated_by_member_id, validated_by_account_id, renter_signing)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $bookingId,
                $phase->value,
                $at->format('Y-m-d H:i:s'),
                $memberId,
                $accountId,
                $renterSigning->value,
            ]);
        } catch (\PDOException $e) {
            // The unique key only: an FK failure shares SQLSTATE 23000 and
            // must not be read as « already validated ».
            if (\Core\Database\ConstraintViolation::isDuplicateKey($e)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * The PDF a validation produced, and the SHA-256 of its file as filed
     * (#989, lot E) — 64 lowercase hexadecimal characters, or null — and,
     * for an inventory signed on paper, the document holding the photo of
     * the signed sheet (lot F).
     */
    public function setInventoryValidationDocument(
        int $bookingId,
        ReadingPhase $phase,
        int $documentId,
        ?string $documentSha256 = null,
        ?int $paperSheetDocumentId = null
    ): void {
        $this->pdo->prepare(
            'UPDATE rental_inventory_validations SET document_id = ?, document_sha256 = ?, paper_sheet_document_id = ?
              WHERE booking_id = ? AND phase = ?'
        )->execute([$documentId, $documentSha256, $paperSheetDocumentId, $bookingId, $phase->value]);
    }

    public function findInventoryBookingId(int $inventoryId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT booking_id FROM rental_booking_inventory WHERE id = ?');
        $stmt->execute([$inventoryId]);
        $bookingId = $stmt->fetchColumn();

        return $bookingId === false ? null : (int) $bookingId;
    }

    // ── Incidents (§6.23) ───────────────────────────────────────────────

    /**
     * Never once the departure inventory is validated, checked by the
     * write itself — see setInventoryValue(): the departure PDF lists the
     * incidents, and one landing after it was made would be billable
     * while missing from what the renter received.
     *
     * Null when the departure was validated in between: nothing inserted.
     *
     * Its photos (#989) are written with it, in one transaction: an
     * incident never exists without the photos it was reported with.
     *
     * @param list<int> $fileIds
     */
    public function createIncident(
        int $bookingId,
        string $description,
        ?int $proposedAmountCents,
        array $fileIds,
        ?int $createdByMemberId
    ): ?int {
        return $this->inTransaction(function () use (
            $bookingId,
            $description,
            $proposedAmountCents,
            $fileIds,
            $createdByMemberId
        ): ?int {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'INSERT INTO rental_incidents
                    (booking_id, description_encrypted, proposed_amount_cents, decision, created_by_member_id, '
                    . 'created_at)
                 SELECT ?, ?, ?, ?, ?, ? FROM (SELECT 1 AS one) AS single_row
                  WHERE NOT EXISTS (SELECT 1 FROM rental_inventory_validations v
                                     WHERE v.booking_id = ? AND v.phase = ?)'
            );
            $stmt->execute([
                $bookingId,
                $this->encryption->encrypt(trim($description), self::CTX_INCIDENT),
                $proposedAmountCents,
                IncidentDecision::PENDING->value,
                $createdByMemberId,
                $now,
                $bookingId,
                ReadingPhase::DEPARTURE->value,
            ]);

            // An INSERT reports the rows it inserted on every engine alike.
            if ($stmt->rowCount() === 0) {
                return null;
            }
            $id = (int) $this->pdo->lastInsertId();

            $photo = $this->pdo->prepare(
                'INSERT INTO rental_incident_photos
                    (incident_id, booking_id, file_id, position, created_by_member_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($fileIds as $index => $fileId) {
                $photo->execute([$id, $bookingId, $fileId, $index + 1, $createdByMemberId, $now]);
            }

            return $id;
        });
    }

    // ── Incident photos (#989) ──────────────────────────────────────────

    /**
     * Adds photos to an incident of this booking, all or none.
     *
     * Refused — false, nothing written — when the incident is not this
     * booking's, when the departure inventory is validated (its PDF lists
     * the incidents, frozen with it), or when the photos would go past
     * `$max`. Each condition is read by the INSERT itself, so a validation
     * or another addition landing in between cannot slip past it; two
     * simultaneous additions that computed the same position collide on
     * the unique key and the second is refused the same way.
     *
     * @param list<int> $fileIds
     */
    public function addIncidentPhotos(
        int $incidentId,
        int $bookingId,
        array $fileIds,
        ?int $createdByMemberId,
        int $max
    ): bool {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'INSERT INTO rental_incident_photos
                    (incident_id, booking_id, file_id, position, created_by_member_id, created_at)
                 SELECT ?, ?, ?, stats.next_position, ?, ?
                   FROM (SELECT COALESCE(MAX(p.position), 0) + 1 AS next_position, COUNT(*) AS photo_count
                           FROM rental_incident_photos p
                          WHERE p.incident_id = ?) AS stats
                  WHERE stats.photo_count < ?
                    AND EXISTS (SELECT 1 FROM rental_incidents i WHERE i.id = ? AND i.booking_id = ?)
                    AND NOT EXISTS (SELECT 1 FROM rental_inventory_validations v
                                     WHERE v.booking_id = ? AND v.phase = ?)'
            );
            foreach ($fileIds as $fileId) {
                $values = [
                    $incidentId,
                    $bookingId,
                    $fileId,
                    $createdByMemberId,
                    $now,
                    $incidentId,
                    $max,
                    $incidentId,
                    $bookingId,
                    $bookingId,
                    ReadingPhase::DEPARTURE->value,
                ];
                // Bound with their types: SQLite compares a COUNT(*) with a
                // value bound as text by type, not by number, and every
                // integer sorts below any text — the limit never held.
                foreach ($values as $index => $value) {
                    $type = match (true) {
                        is_int($value) => \PDO::PARAM_INT,
                        $value === null => \PDO::PARAM_NULL,
                        default => \PDO::PARAM_STR,
                    };
                    $stmt->bindValue($index + 1, $value, $type);
                }
                $stmt->execute();
                // An INSERT reports the rows it inserted on every engine.
                if ($stmt->rowCount() === 0) {
                    if ($ownTransaction) {
                        $this->pdo->rollBack();
                    }

                    return false;
                }
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return true;
        } catch (\PDOException $e) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            // Two additions that read the same next position: the unique
            // key refused the second, which is a refusal, not a crash.
            if ((string) $e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public function findIncidentPhoto(int $photoId): ?IncidentPhoto
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_incident_photos WHERE id = ?');
        $stmt->execute([$photoId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrateIncidentPhoto($row);
    }

    public function countIncidentPhotos(int $incidentId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rental_incident_photos WHERE incident_id = ?');
        $stmt->execute([$incidentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Removes one photo of this booking's incidents — never once the
     * departure inventory is validated, read by the DELETE itself. False
     * when nothing was removed. The file is the caller's to delete.
     */
    public function deleteIncidentPhoto(int $photoId, int $bookingId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rental_incident_photos
              WHERE id = ? AND booking_id = ?
                AND NOT EXISTS (SELECT 1 FROM rental_inventory_validations v
                                 WHERE v.booking_id = ? AND v.phase = ?)'
        );
        $stmt->execute([$photoId, $bookingId, $bookingId, ReadingPhase::DEPARTURE->value]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Every file this booking's stay holds outside `rental_documents`:
     * its incidents' photos and its readings' photos (#989) — what the
     * retention purge deletes with the booking.
     *
     * @return list<int>
     */
    public function photoFileIds(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT file_id FROM rental_incident_photos WHERE booking_id = ?
             UNION
             SELECT file_id FROM rental_meter_readings WHERE booking_id = ? AND file_id IS NOT NULL'
        );
        $stmt->execute([$bookingId, $bookingId]);

        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        sort($ids);

        return $ids;
    }

    /**
     * The three texts are written as given — null clears one — and each
     * under its own encryption context, like the description (#980).
     */
    public function decideIncident(
        int $incidentId,
        IncidentDecision $decision,
        ?int $decidedAmountCents,
        ?int $decidedByMemberId,
        ?string $awaiting = null,
        ?string $reference = null,
        ?string $waiveReason = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_incidents
             SET decision = ?, decided_amount_cents = ?, decided_at = ?, decided_by_member_id = ?,
                 awaiting_encrypted = ?, reference_encrypted = ?, waive_reason_encrypted = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $decision->value,
            $decidedAmountCents,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $decidedByMemberId,
            $this->encryptOptional($awaiting, self::CTX_INCIDENT_AWAITING),
            $this->encryptOptional($reference, self::CTX_INCIDENT_REFERENCE),
            $this->encryptOptional($waiveReason, self::CTX_INCIDENT_WAIVE_REASON),
            $incidentId,
        ]);
    }

    /**
     * Turns every incident still « withheld from the security deposit »
     * into a charge of the same amount — what was decided, falling back to
     * what was proposed, as `Incident::effectiveAmountCents()` read it (#980).
     * Settlements are not touched: a validated one is immutable.
     *
     * Idempotent: a second run matches nothing. Every matched row changes
     * its decision, so the count is the same on SQLite (rows matched) and
     * on MySQL and MariaDB (rows changed).
     *
     * @return int incidents converted
     */
    public function convertWithheldIncidentsToCharges(): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_incidents
             SET decision = ?, decided_amount_cents = COALESCE(decided_amount_cents, proposed_amount_cents)
             WHERE decision = ?'
        );
        $stmt->execute([IncidentDecision::CHARGE->value, IncidentDecision::LEGACY_WITHHOLD]);

        return $stmt->rowCount();
    }

    public function findIncident(int $incidentId): ?Incident
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_incidents WHERE id = ?');
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->hydrateIncident($row, $this->incidentPhotos((int) $row['booking_id'])[$incidentId] ?? []);
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
        $photos = $this->incidentPhotos($bookingId);

        return array_map(
            fn(array $row) => $this->hydrateIncident($row, $photos[(int) $row['id']] ?? []),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * A booking's incident photos, one query, grouped by incident and in
     * the order they were added.
     *
     * @return array<int, list<IncidentPhoto>>
     */
    private function incidentPhotos(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_incident_photos WHERE booking_id = ? ORDER BY incident_id ASC, position ASC'
        );
        $stmt->execute([$bookingId]);

        $grouped = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $grouped[(int) $row['incident_id']][] = self::hydrateIncidentPhoto($row);
        }

        return $grouped;
    }

    /**
     * The incidents as the renter's tracking page may show them (#980).
     *
     * **An assessment in progress never leaves the database for this
     * page**: the query hands back its decision and nothing else — no
     * ciphertext, no amount — so its description is never decrypted and
     * there is nothing a template could print (ARCHITECTURE.md §7.6, rule
     * 3). Only the values that read as decided carry their columns, the
     * retired `withhold` among them; an unknown one reads as pending, as
     * `IncidentDecision::fromStored()` reads it. The internal texts
     * (awaiting, reference, waive reason) are not selected at all.
     */
    public function findRenterIncidents(int $bookingId): RenterIncidents
    {
        $decided = [IncidentDecision::LEGACY_WITHHOLD];
        foreach (IncidentDecision::cases() as $case) {
            if ($case->isVisibleToRenter()) {
                $decided[] = $case->value;
            }
        }
        $in = implode(', ', array_fill(0, count($decided), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT decision,
                    CASE WHEN decision IN ($in) THEN description_encrypted END AS description_encrypted,
                    CASE WHEN decision IN ($in)
                         THEN COALESCE(decided_amount_cents, proposed_amount_cents) END AS amount_cents,
                    CASE WHEN decision IN ($in) THEN created_at END AS reported_at
             FROM rental_incidents WHERE booking_id = ? ORDER BY created_at ASC, id ASC"
        );
        $stmt->execute([...$decided, ...$decided, ...$decided, $bookingId]);

        $visible = [];
        $assessmentInProgress = false;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $decision = IncidentDecision::fromStored((string) $row['decision']);
            if (!$decision->isVisibleToRenter()) {
                $assessmentInProgress = true;
                continue;
            }

            $visible[] = new RenterIncident(
                description: $this->encryption->decrypt((string) $row['description_encrypted'], self::CTX_INCIDENT),
                reportedAt: DateInput::requireFromStorage((string) $row['reported_at'], 'created_at'),
                chargedCents: $decision->entersSettlement() ? (int) ($row['amount_cents'] ?? 0) : null
            );
        }

        return new RenterIncidents($visible, $assessmentInProgress);
    }

    /**
     * The booking's incidents still being assessed, oldest first — ids
     * only, so asking whether a settlement may validate or a deposit be
     * given back decrypts nothing (#980). Read again after a refused write,
     * to say why it was refused: never a value to remember.
     *
     * @phpstan-impure
     * @return list<int>
     */
    public function pendingIncidentIds(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM rental_incidents WHERE booking_id = ? AND decision = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$bookingId, IncidentDecision::PENDING->value]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The incidents still being assessed on a booking that left on or
     * before $departedBy and was not abandoned (#980, chantier §2.4) — the
     * attention page's bounded read: one row per incident, with only what
     * the point shows. No renter name is read.
     *
     * $departedBy is worked out by the caller, so the date arithmetic is
     * PHP's on every engine.
     *
     * @param list<string> $abandonedStatuses
     * @return list<array{
     *     incident_id: int,
     *     booking_id: int,
     *     asset_id: int,
     *     reference: string,
     *     departure_date: string,
     *     awaiting: ?string,
     *     file_reference: ?string
     * }>
     */
    public function findPendingIncidentsDepartedBy(string $departedBy, array $abandonedStatuses): array
    {
        $params = [IncidentDecision::PENDING->value, $departedBy];
        $notAbandoned = '';
        if ($abandonedStatuses !== []) {
            $notAbandoned = ' AND b.status NOT IN (' . implode(', ', array_fill(0, count($abandonedStatuses), '?'))
                . ')';
            $params = array_merge($params, $abandonedStatuses);
        }

        $stmt = $this->pdo->prepare(
            'SELECT i.id AS incident_id, i.booking_id, i.awaiting_encrypted, i.reference_encrypted,
                    b.asset_id, b.reference, b.departure_date
               FROM rental_incidents i
               JOIN rental_bookings b ON b.id = i.booking_id
              WHERE i.decision = ? AND b.departure_date <= ?' . $notAbandoned . '
              ORDER BY b.departure_date ASC, i.id ASC'
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'incident_id' => (int) $row['incident_id'],
                'booking_id' => (int) $row['booking_id'],
                'asset_id' => (int) $row['asset_id'],
                'reference' => (string) $row['reference'],
                'departure_date' => substr((string) $row['departure_date'], 0, 10),
                'awaiting' => $this->decryptOptional($row['awaiting_encrypted'] ?? null, self::CTX_INCIDENT_AWAITING),
                'file_reference' => $this->decryptOptional(
                    $row['reference_encrypted'] ?? null,
                    self::CTX_INCIDENT_REFERENCE
                ),
            ];
        }

        return $rows;
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
        ?int $createdByMemberId,
        ?int $keptCents = null,
        ?string $incidentBasisHash = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_settlements
                (booking_id, version, final_persons, lines_snapshot, total_cents, already_paid_cents,
                 balance_cents, security_deposit_withheld_cents, security_deposit_return_cents,
                 created_by_member_id, created_at, kept_cents, incident_basis_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $keptCents,
            $incidentBasisHash,
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
     *
     * $securityDeposit, when given, is the deposit's share derived from the
     * money at this moment (#980, chantier §2.3) and replaces the figures
     * the version was recorded with, in the same statement — so a version
     * already validated is never rewritten.
     *
     * Never while one of the booking's incidents is still being assessed
     * (#980, chantier §2.4), checked by the write itself like
     * createIncident(): one sent back to its assessment between the
     * service's check and this statement leaves the version unvalidated.
     *
     * @param array{withheld_cents: int, return_cents: int}|null $securityDeposit
     * @param int|null $keptCents The kept amount read before validation, stored when the version has none
     */
    public function validateSettlement(
        int $settlementId,
        ?int $validatedByMemberId,
        ?array $securityDeposit = null,
        ?int $keptCents = null
    ): bool {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        // kept_cents is written in the same compare-and-set, and only when
        // the version has none: a version recorded before #980 reads its
        // kept amount as « total + withheld », and validation rewrites the
        // withheld figure from the money — without this, its kept amount
        // would silently change the moment it was validated.
        $noAssessment = 'NOT EXISTS (SELECT 1 FROM rental_incidents i
                                      WHERE i.booking_id = rental_settlements.booking_id AND i.decision = ?)';
        if ($securityDeposit === null) {
            $stmt = $this->pdo->prepare(
                'UPDATE rental_settlements SET is_validated = 1, validated_at = ?, validated_by_member_id = ?,
                        kept_cents = COALESCE(kept_cents, ?)
                 WHERE id = ? AND is_validated = 0 AND ' . $noAssessment
            );
            $stmt->execute([$now, $validatedByMemberId, $keptCents, $settlementId, IncidentDecision::PENDING->value]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE rental_settlements SET is_validated = 1, validated_at = ?, validated_by_member_id = ?,
                        security_deposit_withheld_cents = ?, security_deposit_return_cents = ?,
                        kept_cents = COALESCE(kept_cents, ?)
                 WHERE id = ? AND is_validated = 0 AND ' . $noAssessment
            );
            $stmt->execute([
                $now,
                $validatedByMemberId,
                max(0, $securityDeposit['withheld_cents']),
                max(0, $securityDeposit['return_cents']),
                $keptCents,
                $settlementId,
                IncidentDecision::PENDING->value,
            ]);
        }

        // is_validated goes from 0 to 1 on every matched row, so rows
        // changed (MySQL, MariaDB) and rows matched (SQLite) agree.
        return $stmt->rowCount() > 0;
    }

    /**
     * Writes what an unrecovered closure gave up on onto the version it has
     * just recorded (#980, CHANTIER-locations-incidents.md §2.9) — before that version is
     * validated, in the same transaction: a validated version is never
     * written again. The reason is encrypted; blank, it is refused by the
     * caller, never stored as the ciphertext of nothing.
     */
    public function markUnrecovered(
        int $settlementId,
        int $unrecoveredCents,
        string $reason,
        \DateTimeImmutable $at
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_settlements
                SET unrecovered_cents = ?, unrecovered_reason_encrypted = ?, unrecovered_at = ?
              WHERE id = ? AND is_validated = 0'
        );
        $stmt->execute([
            max(0, $unrecoveredCents),
            $this->encryptOptional($reason, self::CTX_UNRECOVERED_REASON),
            $at->format('Y-m-d H:i:s'),
            $settlementId,
        ]);
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

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $work();
            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
            readAt: DateInput::requireFromStorage((string) $row['read_at'], 'read_at'),
            fileId: $row['file_id'] !== null ? (int) $row['file_id'] : null,
            comment: $row['comment'] !== null ? (string) $row['comment'] : null,
            recordedByMemberId: $row['recorded_by_member_id'] !== null ? (int) $row['recorded_by_member_id'] : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateIncidentPhoto(array $row): IncidentPhoto
    {
        return new IncidentPhoto(
            id: (int) $row['id'],
            incidentId: (int) $row['incident_id'],
            bookingId: (int) $row['booking_id'],
            fileId: (int) $row['file_id'],
            position: (int) $row['position'],
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at')
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<IncidentPhoto> $photos
     */
    private function hydrateIncident(array $row, array $photos = []): Incident
    {
        return new Incident(
            id: (int) $row['id'],
            bookingId: (int) $row['booking_id'],
            description: $this->encryption->decrypt((string) $row['description_encrypted'], self::CTX_INCIDENT),
            proposedAmountCents: $row['proposed_amount_cents'] !== null
                ? (int) $row['proposed_amount_cents']
                : null,
            decision: IncidentDecision::fromStored((string) $row['decision']),
            decidedAmountCents: $row['decided_amount_cents'] !== null ? (int) $row['decided_amount_cents'] : null,
            decidedAt: DateInput::fromStorage($row['decided_at'] === null ? null : (string) $row['decided_at']),
            decidedByMemberId: $row['decided_by_member_id'] !== null ? (int) $row['decided_by_member_id'] : null,
            createdByMemberId: $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at'),
            awaiting: $this->decryptOptional($row['awaiting_encrypted'] ?? null, self::CTX_INCIDENT_AWAITING),
            reference: $this->decryptOptional($row['reference_encrypted'] ?? null, self::CTX_INCIDENT_REFERENCE),
            waiveReason: $this->decryptOptional(
                $row['waive_reason_encrypted'] ?? null,
                self::CTX_INCIDENT_WAIVE_REASON
            ),
            photos: $photos
        );
    }

    /** Null, or a blank text, stores NULL rather than the ciphertext of nothing. */
    private function encryptOptional(?string $text, string $context): ?string
    {
        $text = $text !== null ? trim($text) : '';

        return $text === '' ? null : $this->encryption->encrypt($text, $context);
    }

    private function decryptOptional(mixed $cipher, string $context): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }

        return $this->encryption->decrypt((string) $cipher, $context);
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
            validatedAt: DateInput::fromStorage($row['validated_at'] === null ? null : (string) $row['validated_at']),
            validatedByMemberId: $row['validated_by_member_id'] !== null
                ? (int) $row['validated_by_member_id']
                : null,
            createdByMemberId: $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at'),
            recordedKeptCents: isset($row['kept_cents']) ? (int) $row['kept_cents'] : null,
            incidentBasisHash: isset($row['incident_basis_hash']) ? (string) $row['incident_basis_hash'] : null,
            unrecoveredCents: isset($row['unrecovered_cents']) ? (int) $row['unrecovered_cents'] : null,
            unrecoveredReason: $this->decryptOptional(
                $row['unrecovered_reason_encrypted'] ?? null,
                self::CTX_UNRECOVERED_REASON
            ),
            unrecoveredAt: DateInput::fromStorage(
                isset($row['unrecovered_at']) ? (string) $row['unrecovered_at'] : null
            )
        );
    }

    /** One `?` per phase whose validation freezes `$phase`. */
    private static function placeholders(ReadingPhase $phase): string
    {
        return implode(', ', array_fill(0, count($phase->frozenBy()), '?'));
    }

    /** @return list<string> */
    private static function frozenByValues(ReadingPhase $phase): array
    {
        return array_map(static fn(ReadingPhase $p): string => $p->value, $phase->frozenBy());
    }
}
