<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;

/**
 * The only place a rental asset's emergency phone number is ever encrypted
 * or decrypted (SECURITY.md §5) — no Service or Controller ever sees the
 * ciphertext, and no journal entry ever sees the plaintext.
 *
 * Every timestamp written here is computed in PHP and bound as a parameter,
 * never MySQL's `NOW()` — same convention as the rest of the codebase, so
 * this repository and its tests run unmodified against the SQLite test
 * database too.
 */
class RentalAssetRepository
{
    private const ENCRYPTION_CONTEXT = 'rental_assets.emergency_phone';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function create(
        string $assetType,
        string $name,
        string $slug,
        ?int $capacity,
        int $quantity,
        ?string $arrivalTime,
        ?string $departureTime,
        ?string $emergencyPhone,
        bool $isPublic,
        bool $showInMenu
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_assets (
                asset_type, name, slug, capacity, quantity, arrival_time, departure_time,
                emergency_phone_encrypted, is_archived, is_public, show_in_menu,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetType,
            $name,
            $slug,
            $capacity,
            $quantity,
            $arrivalTime,
            $departureTime,
            $this->encryptPhone($emergencyPhone),
            $isPublic ? 1 : 0,
            $showInMenu ? 1 : 0,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?RentalAsset
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_assets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Lookup by the stable public slug — the /locations/{slug} entry point.
     * Archived assets are deliberately still returned: the caller decides
     * whether to show them (a manager may open an archived asset; the
     * public may not), and hiding them here would make that impossible.
     */
    public function findBySlug(string $slug): ?RentalAsset
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_assets WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every asset, archived included — the configuration page's own list.
     *
     * @return RentalAsset[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM rental_assets ORDER BY is_archived ASC, name ASC');

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return RentalAsset[]
     */
    public function findAllActive(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM rental_assets WHERE is_archived = 0 ORDER BY name ASC');

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Everything the public index page lists. The archived filter is in the
     * SQL rather than in the caller: an archived asset must not reappear
     * publicly because one call site forgot a condition.
     *
     * @return RentalAsset[]
     */
    public function findAllPublic(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM rental_assets WHERE is_public = 1 AND is_archived = 0 ORDER BY name ASC'
        );

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The assets pinned to the "Notre unité" menu. Runs on every request
     * that builds a menu, so it is a single indexed query returning a
     * handful of rows (idx_rental_assets_menu), never a walk.
     *
     * @return RentalAsset[]
     */
    public function findAllPinnedToMenu(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM rental_assets
             WHERE show_in_menu = 1 AND is_public = 1 AND is_archived = 0
             ORDER BY name ASC'
        );

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param int[] $ids
     * @return RentalAsset[]
     */
    public function findAllByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM rental_assets WHERE id IN ({$placeholders}) ORDER BY name ASC");
        $stmt->execute($ids);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Whether $slug is already taken by an asset other than $exceptId —
     * the collision check behind Service\RentalSlugGenerator. Archived
     * assets count: their slug stays reserved so an old public link can
     * never be silently repointed at a different asset.
     */
    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM rental_assets WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM rental_assets WHERE slug = ? AND id != ? LIMIT 1');
            $stmt->execute([$slug, $exceptId]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Updates the "général" section only. Each configuration section saves
     * independently (roadmap §6.4), so this deliberately does NOT touch
     * managers, archiving, or anything another section owns — a partial
     * form post must never blank a field the operator could not see.
     */
    public function updateGeneral(
        int $id,
        string $assetType,
        string $name,
        string $slug,
        ?int $capacity,
        int $quantity,
        ?string $arrivalTime,
        ?string $departureTime,
        ?string $emergencyPhone,
        bool $isPublic,
        bool $showInMenu
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_assets
             SET asset_type = ?, name = ?, slug = ?, capacity = ?, quantity = ?,
                 arrival_time = ?, departure_time = ?, emergency_phone_encrypted = ?,
                 is_public = ?, show_in_menu = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $assetType,
            $name,
            $slug,
            $capacity,
            $quantity,
            $arrivalTime,
            $departureTime,
            $this->encryptPhone($emergencyPhone),
            $isPublic ? 1 : 0,
            $showInMenu ? 1 : 0,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function setArchived(int $id, bool $isArchived): void
    {
        $stmt = $this->pdo->prepare('UPDATE rental_assets SET is_archived = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$isArchived ? 1 : 0, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * Hard delete. Only ever reachable for an asset with no history at all
     * — Service\RentalAssetService is what enforces that, since only it
     * knows which other tables reference an asset.
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_assets WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function encryptPhone(?string $phone): ?string
    {
        $phone = $phone !== null ? trim($phone) : null;

        return $phone !== null && $phone !== ''
            ? $this->encryption->encrypt($phone, self::ENCRYPTION_CONTEXT)
            : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    /**
     * The asset's VAT-exemption sentence (§6.27).
     *
     * Its own tiny write rather than a field on the general form: it
     * belongs with the invoice configuration, and every section of the
     * configuration screen saves independently.
     */
    public function saveVatExemptionNote(int $assetId, ?string $note): void
    {
        $note = $note !== null ? trim($note) : null;

        $stmt = $this->pdo->prepare(
            'UPDATE rental_assets SET vat_exemption_note = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $assetId,
        ]);
    }

    /**
     * The asset's calendar publication settings (§6.30).
     *
     * Its own tiny write, like the VAT note: every section of the
     * configuration screen saves independently, and this one belongs with
     * the calendar rather than with the asset's general details.
     */
    public function saveCalendarPublication(
        int $assetId,
        bool $enabled,
        ?int $calendarId,
        \Modules\Rental\Calendar\PublishFrom $publishFrom
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_assets SET calendar_publication_enabled = ?, calendar_id = ?,
                                      calendar_publish_from = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $enabled && $calendarId !== null ? 1 : 0,
            $calendarId,
            $publishFrom->value,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $assetId,
        ]);
    }

    /**
     * Every asset publishing onto a calendar, in one query.
     *
     * The provider's entry point (§6.31): one call for a whole window,
     * never one per asset. Archived assets are included — a stay that
     * happened last year is still on the calendar of last year.
     *
     * @return RentalAsset[]
     */
    public function findPublishingToCalendar(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM rental_assets
             WHERE calendar_publication_enabled = 1 AND calendar_id IS NOT NULL
             ORDER BY name ASC'
        );

        return array_map(fn(array $row) => $this->hydrate($row), $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RentalAsset
    {
        $encrypted = $row['emergency_phone_encrypted'] ?? null;

        return new RentalAsset(
            id: (int) $row['id'],
            assetType: (string) $row['asset_type'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            capacity: $row['capacity'] !== null ? (int) $row['capacity'] : null,
            quantity: (int) $row['quantity'],
            arrivalTime: $row['arrival_time'] !== null ? (string) $row['arrival_time'] : null,
            departureTime: $row['departure_time'] !== null ? (string) $row['departure_time'] : null,
            emergencyPhone: $encrypted !== null && $encrypted !== ''
                ? $this->encryption->decrypt((string) $encrypted, self::ENCRYPTION_CONTEXT)
                : null,
            isArchived: (bool) $row['is_archived'],
            isPublic: (bool) $row['is_public'],
            showInMenu: (bool) $row['show_in_menu'],
            vatExemptionNote: isset($row['vat_exemption_note']) ? (string) $row['vat_exemption_note'] : null,
            calendarPublicationEnabled: (bool) ($row['calendar_publication_enabled'] ?? false),
            calendarId: isset($row['calendar_id']) ? (int) $row['calendar_id'] : null,
            calendarPublishFrom: \Modules\Rental\Calendar\PublishFrom::tryFrom(
                (string) ($row['calendar_publish_from'] ?? '')
            ) ?? \Modules\Rental\Calendar\PublishFrom::CONFIRMATION
        );
    }
}
