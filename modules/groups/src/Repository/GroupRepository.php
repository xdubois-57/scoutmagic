<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

use Modules\Groups\Support\Timestamps;

class GroupRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(string $name, ?int $scoutYearId, ?int $sectionId, ?int $createdByMemberId): int
    {
        $now = Timestamps::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_groups (name, scout_year_id, section_id, last_activity_at, created_by_member_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $scoutYearId, $sectionId, $now, $createdByMemberId, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?DiscussionGroup
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discussion_groups WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Every group, most recently active first. A unit has tens of groups,
     * not thousands, and readability has to be decided per group against
     * the caller's own membership (Service\GroupAccessService) — which is
     * not expressible as a WHERE clause, since derived membership lives in
     * the core member tables. Callers filter the result; see
     * Service\GroupListService, which batches the membership data it needs
     * so that filtering costs no query per group.
     *
     * @return DiscussionGroup[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM discussion_groups ORDER BY last_activity_at DESC, id DESC');

        return array_map([$this, 'hydrate'], $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Bumped whenever something happens in the group — see
     * Service\GroupActivityService, the single writer of this column.
     */
    public function touchActivity(int $id, string $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET last_activity_at = ? WHERE id = ?');
        $stmt->execute([$at, $id]);
    }

    public function setClosed(int $id, ?string $closedAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET closed_at = ? WHERE id = ?');
        $stmt->execute([$closedAt, $id]);
    }

    public function rename(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    /**
     * The group's optional one-liner. Separate from rename() rather than
     * folded into it: the two are edited together today, but a name is
     * required and a description is not, and collapsing them would make
     * "clear the description" indistinguishable from "do not touch it".
     */
    public function setDescription(int $id, ?string $description): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET description = ? WHERE id = ?');
        $stmt->execute([$description, $id]);
    }

    /**
     * Links or unlinks an invitation group to a scout year — never called
     * for a section group (Service\GroupService::edit() is the actual
     * enforcement of that; see its own docblock).
     */
    public function setScoutYearId(int $id, ?int $scoutYearId): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET scout_year_id = ? WHERE id = ?');
        $stmt->execute([$scoutYearId, $id]);
    }

    /**
     * Caches the group's delegated gallery album id, resolved by
     * Service\PostMediaService::ensureAlbumId(). Idempotent to call twice
     * with the same value — two concurrent first-media posts both resolve
     * the same album id from gallery's own concurrency-safe ensureAlbum()
     * and both land here harmlessly.
     */
    public function setGalleryAlbumId(int $id, int $galleryAlbumId): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET gallery_album_id = ? WHERE id = ?');
        $stmt->execute([$galleryAlbumId, $id]);
    }

    /**
     * The section group for exactly one (section, scout year) — what makes
     * Task\EnsureSectionGroupsHandler idempotent: it asks this before
     * creating anything, so a second run finds the group and does nothing.
     *
     * Matched on the section_id COLUMN (which records "this is section X's
     * group") rather than through discussion_group_sections, so an
     * invitation group that merely invited the same section is never
     * mistaken for it.
     */
    public function findSectionGroup(int $sectionId, int $scoutYearId): ?DiscussionGroup
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_groups WHERE section_id = ? AND scout_year_id = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$sectionId, $scoutYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Open groups whose last activity predates $cutoff — the candidates
     * for automatic closure.
     *
     * Bounded by $limit and ordered oldest-first so a first run on a large
     * install closes the most overdue groups and leaves the rest to the
     * next run, rather than attempting everything in one pass.
     *
     * @return DiscussionGroup[]
     */
    public function findOpenInactiveBefore(string $cutoff, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_groups
             WHERE closed_at IS NULL AND last_activity_at < ?
             ORDER BY last_activity_at, id
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$cutoff]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Groups closed before $cutoff and safe to purge — the candidates for
     * permanent deletion.
     *
     * $protectedScoutYearIds are excluded outright: a group of the
     * effective year or of a future one is never purged, however long it
     * has been closed. Without that rule, purging a closed section group
     * of the current year would have Task\EnsureSectionGroupsHandler
     * recreate it on its very next run — a delete/recreate loop, which is
     * also why this module needs no tombstone table to remember what it
     * deleted.
     *
     * @param int[] $protectedScoutYearIds
     * @return DiscussionGroup[]
     */
    public function findClosedBefore(string $cutoff, array $protectedScoutYearIds, int $limit): array
    {
        $sql = 'SELECT * FROM discussion_groups WHERE closed_at IS NOT NULL AND closed_at < ?';
        $params = [$cutoff];

        if ($protectedScoutYearIds !== []) {
            $placeholders = implode(',', array_fill(0, count($protectedScoutYearIds), '?'));
            // A year-less group (scout_year_id NULL) belongs to no year and
            // is therefore never protected by this rule — NOT IN would
            // discard it, since NULL NOT IN (…) is NULL, not true.
            $sql .= " AND (scout_year_id IS NULL OR scout_year_id NOT IN ({$placeholders}))";
            $params = array_merge($params, array_map('intval', array_values($protectedScoutYearIds)));
        }

        $sql .= ' ORDER BY closed_at, id LIMIT ' . max(1, $limit);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * How many OPEN, non-section groups this member has created — what the
     * creation quota counts.
     *
     * Two exclusions, both deliberate. A section group
     * (`section_id IS NOT NULL`) is created by
     * Task\EnsureSectionGroupsHandler and by nobody else, so counting it
     * against a person would be counting something they did not do. A
     * closed group (`closed_at IS NOT NULL`) is read-only and on its way
     * to the purge — the quota is about how much live clutter one person
     * can create, not about how much they ever created.
     *
     * Counted on created_by_member_id, the column prompt 3 already
     * introduced: there is exactly one notion of who created a group and
     * this does not add a second.
     */
    public function countOpenCreatedBy(int $memberId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discussion_groups
             WHERE created_by_member_id = ? AND section_id IS NULL AND closed_at IS NULL'
        );
        $stmt->execute([$memberId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_groups WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DiscussionGroup
    {
        return new DiscussionGroup(
            (int) $row['id'],
            (string) $row['name'],
            $row['scout_year_id'] !== null ? (int) $row['scout_year_id'] : null,
            $row['section_id'] !== null ? (int) $row['section_id'] : null,
            $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            (string) $row['last_activity_at'],
            $row['created_by_member_id'] !== null ? (int) $row['created_by_member_id'] : null,
            (string) $row['created_at'],
            $row['gallery_album_id'] !== null ? (int) $row['gallery_album_id'] : null,
            ($row['description'] ?? null) !== null ? (string) $row['description'] : null
        );
    }
}
