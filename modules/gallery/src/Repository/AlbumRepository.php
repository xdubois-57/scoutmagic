<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Repository;

class AlbumRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?Album
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gallery_albums WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * All albums, newest activity date first — the chief "manage" list
     * (unfiltered; Service\GalleryAccessService applies section scoping).
     * Delegated albums (owner_type IS NOT NULL) are always excluded: they
     * are reachable only through their owning module, never through
     * gallery's own management pages.
     *
     * @return Album[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM gallery_albums WHERE owner_type IS NULL ORDER BY album_date DESC, id DESC');
        return $stmt !== false ? array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)) : [];
    }

    /**
     * findAll() narrowed to a set of scout years, in SQL — the chief
     * list and the management page both default to the recent years, and
     * an installation's whole album history has no business being read
     * to show them (idx_gallery_albums_scout_year backs the filter).
     *
     * @param int[] $scoutYearIds
     * @return Album[]
     */
    public function findByScoutYearIds(array $scoutYearIds): array
    {
        if ($scoutYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($scoutYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM gallery_albums WHERE owner_type IS NULL AND scout_year_id IN ({$placeholders}) ORDER BY album_date DESC, id DESC"
        );
        $stmt->execute(array_values($scoutYearIds));

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every delegated album (owner_type IS NOT NULL) — the complement of
     * findAll() above.
     *
     * Deliberately NOT for browsing or editing: what a delegated album
     * holds and who may see it belong to the module that owns it. It exists
     * for the one thing an administrator legitimately does with such an
     * album from gallery's side — see where it lives and move it — which
     * was impossible while gallery's own configuration page could not so
     * much as list it. An album nobody can see is an album whose storage
     * bill nobody can explain.
     *
     * @return Album[]
     */
    public function findDelegated(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM gallery_albums WHERE owner_type IS NOT NULL ORDER BY owner_type ASC, owner_id ASC'
        );

        return $stmt !== false ? array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)) : [];
    }

    /**
     * Albums visible to an identified member: matching one of the given
     * section ids, or unit-wide (section_id IS NULL), for one of the
     * given scout years — the public gallery list (module spec: "current
     * or previous year"). $sectionIds may be empty (member linked to no
     * section) — unit-wide albums still show.
     *
     * Delegated albums (owner_type IS NOT NULL) are always excluded — same
     * reasoning as findAll() above; a delegated album is never section/
     * scout-year visible in gallery's own sense, since a member's access
     * to it is decided entirely by its owning module's own rule.
     *
     * @param int[] $sectionIds
     * @param int[] $scoutYearIds
     * @return Album[]
     */
    public function findVisible(array $sectionIds, array $scoutYearIds): array
    {
        if ($scoutYearIds === []) {
            return [];
        }

        $yearPlaceholders = implode(',', array_fill(0, count($scoutYearIds), '?'));
        $params = $scoutYearIds;

        $sectionClause = 'section_id IS NULL';
        if ($sectionIds !== []) {
            $sectionPlaceholders = implode(',', array_fill(0, count($sectionIds), '?'));
            $sectionClause = "(section_id IS NULL OR section_id IN ({$sectionPlaceholders}))";
            $params = array_merge($params, $sectionIds);
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM gallery_albums WHERE owner_type IS NULL AND scout_year_id IN ({$yearPlaceholders}) AND {$sectionClause} ORDER BY album_date DESC, id DESC"
        );
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The delegated album already owned by (ownerType, ownerId), or null
     * when none exists yet — Service\DelegatedAlbumService::ensureAlbum()'s
     * "find" half, called both before attempting create() (the common
     * case) and after a UNIQUE constraint violation from it (the losing
     * side of a race — see gallery_albums.owner_type's schema.sql
     * comment). LIMIT 1 is defensive, not load-bearing: the index this
     * queries is UNIQUE, so at most one row can ever match.
     */
    public function findByOwner(string $ownerType, int $ownerId): ?Album
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM gallery_albums WHERE owner_type = ? AND owner_id = ? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$ownerType, $ownerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(
        string $type,
        string $title,
        ?string $subtitle,
        string $albumDate,
        ?int $sectionId,
        int $scoutYearId,
        ?string $externalUrl,
        ?int $storageLocationId,
        int $createdBy,
        // Last, both null: an ordinary album never sets these — only
        // Service\DelegatedAlbumService::ensureAlbum() ever passes a
        // non-null pair.
        ?string $ownerType = null,
        ?int $ownerId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO gallery_albums (type, title, subtitle, album_date, section_id, scout_year_id, external_url, storage_location_id, created_by, created_at, owner_type, owner_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $title, $subtitle, $albumDate, $sectionId, $scoutYearId, $externalUrl, $storageLocationId, $createdBy, date('Y-m-d H:i:s'), $ownerType, $ownerId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setStorageLocationId(int $id, int $storageLocationId): void
    {
        $stmt = $this->pdo->prepare('UPDATE gallery_albums SET storage_location_id = ? WHERE id = ?');
        $stmt->execute([$storageLocationId, $id]);
    }

    /**
     * Starts a background storage migration — set together so a reader
     * never observes 'in_progress' without a target.
     */
    public function startMigration(int $id, int $targetLocationId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE gallery_albums SET migration_status = 'in_progress', migration_target_location_id = ?, migration_error = NULL WHERE id = ?"
        );
        $stmt->execute([$targetLocationId, $id]);
    }

    /**
     * Every file for every media row was copied to and verified at the
     * target — flips the album onto it and clears the migration state in
     * one statement (Task\MigrateAlbumStorageHandler calls this only after
     * every single file has succeeded).
     */
    public function completeMigration(int $id, int $newStorageLocationId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE gallery_albums SET storage_location_id = ?, migration_status = 'none', migration_target_location_id = NULL, migration_error = NULL WHERE id = ?"
        );
        $stmt->execute([$newStorageLocationId, $id]);
    }

    /**
     * Aborts a migration on any failure — storage_location_id is
     * deliberately left untouched by this statement, still pointing at the
     * (fully intact) source.
     */
    public function failMigration(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare("UPDATE gallery_albums SET migration_status = 'failed', migration_error = ? WHERE id = ?");
        $stmt->execute([$error, $id]);
    }

    public function update(
        int $id,
        string $title,
        ?string $subtitle,
        string $albumDate,
        ?int $sectionId,
        ?string $externalUrl
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE gallery_albums SET title = ?, subtitle = ?, album_date = ?, section_id = ?, external_url = ? WHERE id = ?'
        );
        $stmt->execute([$title, $subtitle, $albumDate, $sectionId, $externalUrl, $id]);
    }

    public function updateOgMetadata(int $id, ?string $ogTitle, ?string $ogDescription, ?string $ogImageUrl, ?int $ogImageFileId): void
    {
        $stmt = $this->pdo->prepare('UPDATE gallery_albums SET og_title = ?, og_description = ?, og_image_url = ?, og_image_file_id = ? WHERE id = ?');
        $stmt->execute([$ogTitle, $ogDescription, $ogImageUrl, $ogImageFileId, $id]);
    }

    public function setCoverMediaId(int $id, ?int $coverMediaId): void
    {
        $stmt = $this->pdo->prepare('UPDATE gallery_albums SET cover_media_id = ? WHERE id = ?');
        $stmt->execute([$coverMediaId, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM gallery_albums WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Album
    {
        return new Album(
            id: (int) $row['id'],
            type: (string) $row['type'],
            title: (string) $row['title'],
            subtitle: $row['subtitle'] !== null ? (string) $row['subtitle'] : null,
            albumDate: (string) $row['album_date'],
            sectionId: $row['section_id'] !== null ? (int) $row['section_id'] : null,
            scoutYearId: (int) $row['scout_year_id'],
            coverMediaId: $row['cover_media_id'] !== null ? (int) $row['cover_media_id'] : null,
            externalUrl: $row['external_url'] !== null ? (string) $row['external_url'] : null,
            ogTitle: $row['og_title'] !== null ? (string) $row['og_title'] : null,
            ogDescription: $row['og_description'] !== null ? (string) $row['og_description'] : null,
            ogImageUrl: $row['og_image_url'] !== null ? (string) $row['og_image_url'] : null,
            ogImageFileId: $row['og_image_file_id'] !== null ? (int) $row['og_image_file_id'] : null,
            storageLocationId: $row['storage_location_id'] !== null ? (int) $row['storage_location_id'] : null,
            migrationStatus: (string) $row['migration_status'],
            migrationTargetLocationId: $row['migration_target_location_id'] !== null ? (int) $row['migration_target_location_id'] : null,
            migrationError: $row['migration_error'] !== null ? (string) $row['migration_error'] : null,
            createdBy: (int) $row['created_by'],
            createdAt: (string) $row['created_at'],
            ownerType: $row['owner_type'] !== null ? (string) $row['owner_type'] : null,
            ownerId: $row['owner_id'] !== null ? (int) $row['owner_id'] : null
        );
    }
}
