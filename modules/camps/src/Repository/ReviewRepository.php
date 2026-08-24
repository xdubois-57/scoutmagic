<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/**
 * camp_reviews. A review is written about a field, not about a person —
 * nothing here is encrypted.
 */
class ReviewRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findByCamp(int $campId): ?Review
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_reviews WHERE camp_id = ?');
        $stmt->execute([$campId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @param int[] $campIds
     * @return array<int, Review> keyed by camp id
     */
    public function findByCamps(array $campIds): array
    {
        $campIds = array_values(array_unique(array_map('intval', $campIds)));
        if ($campIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($campIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM camp_reviews WHERE camp_id IN ({$placeholders})");
        $stmt->execute($campIds);

        $reviews = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $reviews[(int) $row['camp_id']] = $this->hydrate($row);
        }

        return $reviews;
    }

    /**
     * The rating a place SHOWS: the one from its most recent stay that
     * actually carries a number.
     *
     * Deliberately not an average. A place a unit went to in 2019 and
     * again in 2027 is not "3,5 étoiles" — it is what it was like the
     * last time, and the years in between are readable underneath. An
     * average would also let a bad 2019 quietly drag down a field that
     * has since changed hands.
     *
     * Cancelled stays are excluded: they carry a comment and never a
     * rating, and the exclusion here is a second line of defence behind
     * Service\ReviewService's own rule.
     *
     * @return array{rating: int, year: int}|null
     */
    public function latestRatingForPlace(int $placeId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.rating,
                    COALESCE(c.end_date, CONCAT(c.year_only, '-12-31')) AS stay_key
               FROM camp_reviews r
               JOIN camp_camps c ON c.id = r.camp_id
              WHERE c.place_id = ? AND r.rating IS NOT NULL AND c.status <> 'cancelled'
              ORDER BY stay_key DESC, c.id DESC
              LIMIT 1"
        );
        $stmt->execute([$placeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return ['rating' => (int) $row['rating'], 'year' => (int) substr((string) $row['stay_key'], 0, 4)];
    }

    /**
     * The persistent members.id behind a session's linked member-year
     * ids — what camp_reviews.author_member_id needs.
     *
     * The session carries member_years ids (one per scout year), the FK
     * wants the member who outlives them. The lookup lives here rather
     * than in the controller because it is a database read, and here
     * rather than in Core\Member because one column of one table is not
     * a reason to widen a shared service's surface.
     *
     * @param int[] $memberYearIds
     */
    public function memberIdForMemberYears(array $memberYearIds): ?int
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if ($memberYearIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id FROM member_years WHERE id IN ({$placeholders}) AND member_id IS NOT NULL
              ORDER BY scout_year_id DESC LIMIT 1"
        );
        $stmt->execute($memberYearIds);
        $id = $stmt->fetchColumn();

        return $id !== false && $id !== null ? (int) $id : null;
    }

    public function save(int $campId, ?int $rating, ?string $comment, ?int $authorMemberId): void
    {
        $existing = $this->findByCamp($campId);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE camp_reviews SET rating = ?, comment = ?, author_member_id = ?, updated_at = ? WHERE camp_id = ?'
            );
            $stmt->execute([$rating, $comment, $authorMemberId, date('Y-m-d H:i:s'), $campId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO camp_reviews (camp_id, rating, comment, author_member_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$campId, $rating, $comment, $authorMemberId, $now, $now]);
    }

    public function delete(int $campId): void
    {
        $this->pdo->prepare('DELETE FROM camp_reviews WHERE camp_id = ?')->execute([$campId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Review
    {
        return new Review(
            id: (int) $row['id'],
            campId: (int) $row['camp_id'],
            rating: $row['rating'] !== null ? (int) $row['rating'] : null,
            comment: $row['comment'] !== null && $row['comment'] !== '' ? (string) $row['comment'] : null,
            authorMemberId: $row['author_member_id'] !== null ? (int) $row['author_member_id'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
