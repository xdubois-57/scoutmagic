<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

use Core\Security\EncryptionService;
use Modules\Camps\Support;

/**
 * camp_camps and its camp_camp_sections join. The only layer that sees
 * booked_by_name's ciphertext.
 */
class CampRepository
{
    /**
     * "Upcoming" as the database sees it. The SAME rule as
     * Repository\Camp::isUpcoming(), and Tests\Modules\Camps\Repository\
     * UpcomingDefinitionTest pins the two together — a camp that the list
     * calls upcoming while its own place calls it past is the bug this
     * duplication would otherwise produce, quietly, on one day of the
     * year.
     *
     * Takes two bound parameters, in this order: today (Y-m-d) and the
     * current calendar year.
     */
    public const UPCOMING_SQL = "(c.status <> 'cancelled' AND ("
        . '(c.end_date IS NOT NULL AND c.end_date >= ?) OR '
        . '(c.end_date IS NULL AND c.year_only IS NOT NULL AND c.year_only >= ?)'
        . '))';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function findById(int $id): ?Camp
    {
        $stmt = $this->pdo->prepare('SELECT c.* FROM camp_camps c WHERE c.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->hydrate($row, $this->sectionIdsFor([(int) $row['id']]));
    }

    /**
     * Every stay at one place, newest first.
     *
     * @return Camp[]
     */
    public function findByPlace(int $placeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM camp_camps c WHERE c.place_id = ?
              ORDER BY COALESCE(c.end_date, CONCAT(c.year_only, \'-12-31\')) DESC, c.id DESC'
        );
        $stmt->execute([$placeId]);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every stay of every visible place, with the name of that place,
     * newest first — in ONE query.
     *
     * What « Rattacher à » on the camps mail screen needs, and what it
     * used to build by calling `findByPlace()` once per place: one query
     * per place, every page view, to render a `<select>` holding the whole
     * history of the unit. This returns the same set in one round trip and
     * carries the place name so the caller does not have to look it up
     * again either.
     *
     * Archived places are left out, as they are on every other screen: a
     * place a chief archived is one they said they were done with, and
     * proposing its stays first is proposing the answer they retired.
     *
     * Sections are not read. This feeds a picker, which names a stay by
     * its place and its dates; loading the sections of every stay of every
     * year to render a label that never shows them would be the same
     * excess in a different place.
     *
     * @return list<array{camp: Camp, place_name: string}>
     */
    public function findAllWithPlaceName(): array
    {
        $stmt = $this->pdo->query(
            'SELECT c.*, p.name AS place_name
               FROM camp_camps c
               JOIN camp_places p ON p.id = c.place_id
              WHERE p.is_archived = 0
              ORDER BY COALESCE(c.end_date, CONCAT(c.year_only, \'-12-31\')) DESC, c.id DESC'
        );
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'camp' => $this->hydrate($row, []),
                'place_name' => (string) $row['place_name'],
            ];
        }

        return $rows;
    }

    /**
     * Every stay running exactly from one day to another, newest first.
     *
     * The other axis again, and the one a message asks about: a booking
     * states a period, not a place id. Deliberately EXACT on both ends —
     * an overlap test would put a week-long camp and the weekend inside it
     * on the same footing, and « du 18 au 20 » is a claim about two days,
     * not about a neighbourhood of them.
     *
     * A stay missing either end can never match: `Mail\ExistingStayMatcher`
     * only ever asks with two dates it read, and a year-only stay states
     * no day for them to equal.
     *
     * @return Camp[]
     */
    public function findByDateRange(string $startDate, string $endDate): array
    {
        // Never a cancelled stay: a message stating the days of a stay the
        // unit called off is about whatever replaced it, and a cancelled
        // stay must not compete with — or win over — the live one.
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM camp_camps c
              WHERE c.start_date = ? AND c.end_date = ? AND c.status <> ?
              ORDER BY c.id DESC'
        );
        $stmt->execute([$startDate, $endDate, Camp::STATUS_CANCELLED]);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The id of every stay, whatever its place or status — what a screen
     * scoping `inbound_mail` reads needs, and nothing more.
     *
     * One query, where the callers used to ask for every visible place and
     * then for every stay of each: a query per place on every camps list
     * view. Archived places are included on purpose here — a message filed
     * under a stay at a place a chief has since archived must stay
     * reachable, or its propositions can never be answered.
     *
     * @return int[]
     */
    public function findAllIds(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM camp_camps ORDER BY id ASC');

        return $stmt === false ? [] : array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every stay one of these sections went on, newest first.
     *
     * The other axis of findByPlace(): "which stays involved these
     * sections" rather than "which stays happened here". Feeds
     * Service\MemberCampStayService, and the DISTINCT matters — a stay
     * that two of a member's sections both went on is one stay.
     *
     * @param int[] $sectionIds
     * @return Camp[]
     */
    public function findBySectionIds(array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT c.* FROM camp_camps c
               JOIN camp_camp_sections cs ON cs.camp_id = c.id
              WHERE cs.section_id IN ({$placeholders})
              ORDER BY COALESCE(c.end_date, CONCAT(c.year_only, '-12-31')) DESC, c.id DESC"
        );
        $stmt->execute(array_map('intval', array_values($sectionIds)));

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every upcoming stay, soonest first — the "À venir" list.
     *
     * @return Camp[]
     */
    public function findUpcoming(\DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM camp_camps c
               JOIN camp_places p ON p.id = c.place_id
              WHERE p.is_archived = 0 AND ' . self::UPCOMING_SQL . '
              ORDER BY COALESCE(c.start_date, c.end_date, CONCAT(c.year_only, \'-01-01\')) ASC, c.id ASC'
        );
        $stmt->execute([$today->format('Y-m-d'), (int) $today->format('Y')]);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Whether a place still has an upcoming stay, and of which status —
     * what archiving needs to know before it hides a place someone is
     * about to leave for.
     *
     * @return array<string, int> status => count
     */
    public function countUpcomingByStatusForPlace(int $placeId, \DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.status, COUNT(*) AS n FROM camp_camps c
              WHERE c.place_id = ? AND ' . self::UPCOMING_SQL . '
              GROUP BY c.status'
        );
        $stmt->execute([$placeId, $today->format('Y-m-d'), (int) $today->format('Y')]);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Stays whose review notification is due and has never been sent.
     *
     * "The day after the end date" is expressed as "the end date is
     * strictly past" rather than "the end date was yesterday": the second
     * form silently loses the notification on any day the scheduler did
     * not run, and this module assumes installations without a real cron.
     * A stay that ended a week ago on such a site is notified late, which
     * is the right failure.
     *
     * Never a year-only stay — there is no day after a year — and never a
     * cancelled one: there was no camp to have an opinion about.
     *
     * @return Camp[]
     */
    public function findAwaitingReviewNotification(\DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.* FROM camp_camps c
              WHERE c.review_notified_at IS NULL
                AND c.status <> 'cancelled'
                AND c.end_date IS NOT NULL
                AND c.end_date < ?
              ORDER BY c.end_date ASC, c.id ASC"
        );
        $stmt->execute([$today->format('Y-m-d')]);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function markReviewNotified(int $campId, \DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE camp_camps SET review_notified_at = ? WHERE id = ?');
        $stmt->execute([$at->format('Y-m-d H:i:s'), $campId]);
    }

    /**
     * Removes a stay. The ONLY caller is Service\MergeService: a stay is
     * never deleted through the interface — one that did not happen is
     * cancelled, which is itself worth recording. Here the row disappears
     * because its contents have just been moved into another stay, and
     * its losing values written into that stay's note.
     */
    public function delete(int $campId): void
    {
        $this->pdo->prepare('DELETE FROM camp_camp_sections WHERE camp_id = ?')->execute([$campId]);
        $this->pdo->prepare('DELETE FROM camp_camps WHERE id = ?')->execute([$campId]);
    }

    /**
     * Which of $sectionIds are real, active sections.
     *
     * `camp_camp_sections.section_id` is a foreign key, so an id nobody
     * offers turns a forged POST into a PDOException — a 500 on a chief's
     * form. Checked here rather than by trusting the `<select>`: the
     * `<select>` is the client's copy of the list, not the list.
     *
     * Deliberately not filtered on `is_visible`: visibility is a display
     * choice that changes, and refusing to save an unrelated correction on
     * a 2019 camp because one of its sections was hidden last month would
     * be a worse rule than the one it enforces.
     *
     * @param int[] $sectionIds
     * @return int[]
     */
    public function existingSectionIds(array $sectionIds): array
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        if ($sectionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id FROM sections WHERE id IN ({$placeholders}) AND is_active = 1"
        );
        $stmt->execute($sectionIds);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Whether `members` really holds this id — same reason as above. */
    public function memberExists(int $memberId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ?');
        $stmt->execute([$memberId]);

        return $stmt->fetchColumn() !== false;
    }

    public function countByPlace(int $placeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM camp_camps WHERE place_id = ?');
        $stmt->execute([$placeId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param int[] $sectionIds
     */
    public function create(
        int $placeId,
        string $stayType,
        ?string $startDate,
        ?string $endDate,
        ?int $yearOnly,
        string $status,
        ?int $priceCents,
        ?int $participantCount,
        ?int $bookedByMemberId,
        ?string $bookedByName,
        array $sectionIds
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO camp_camps (place_id, stay_type, start_date, end_date, year_only, status,
                                     price_cents, participant_count, booked_by_member_id, booked_by_name,
                                     created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            $placeId, $stayType, $startDate, $endDate, $yearOnly, $status,
            $priceCents, $participantCount, $bookedByMemberId, $this->encryptNullable($bookedByName),
            $now, $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->replaceSections($id, $sectionIds);

        return $id;
    }

    /**
     * @param int[] $sectionIds
     */
    public function update(
        int $id,
        string $stayType,
        ?string $startDate,
        ?string $endDate,
        ?int $yearOnly,
        string $status,
        ?int $priceCents,
        ?int $participantCount,
        ?int $bookedByMemberId,
        ?string $bookedByName,
        array $sectionIds
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE camp_camps SET stay_type = ?, start_date = ?, end_date = ?, year_only = ?, status = ?,
                    price_cents = ?, participant_count = ?, booked_by_member_id = ?, booked_by_name = ?,
                    updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $stayType, $startDate, $endDate, $yearOnly, $status,
            $priceCents, $participantCount, $bookedByMemberId, $this->encryptNullable($bookedByName),
            date('Y-m-d H:i:s'), $id,
        ]);
        $this->replaceSections($id, $sectionIds);
    }

    /**
     * Moves every stay of one place onto another — what a place merge is,
     * once the fields have been resolved. The stays keep their own ids, so
     * their history, documents and albums follow them untouched.
     */
    public function movePlace(int $fromPlaceId, int $toPlaceId): int
    {
        $stmt = $this->pdo->prepare('UPDATE camp_camps SET place_id = ?, updated_at = ? WHERE place_id = ?');
        $stmt->execute([$toPlaceId, date('Y-m-d H:i:s'), $fromPlaceId]);

        return $stmt->rowCount();
    }

    /**
     * @param int[] $sectionIds
     */
    public function replaceSections(int $campId, array $sectionIds): void
    {
        $this->pdo->prepare('DELETE FROM camp_camp_sections WHERE camp_id = ?')->execute([$campId]);

        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        if ($sectionIds === []) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO camp_camp_sections (camp_id, section_id) VALUES (?, ?)');
        foreach ($sectionIds as $sectionId) {
            $stmt->execute([$campId, $sectionId]);
        }
    }

    /**
     * @param int[] $campIds
     * @return array<int, int[]> camp id => section ids
     */
    public function sectionIdsFor(array $campIds): array
    {
        $campIds = array_values(array_unique(array_map('intval', $campIds)));
        if ($campIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($campIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT camp_id, section_id FROM camp_camp_sections WHERE camp_id IN ({$placeholders}) ORDER BY section_id"
        );
        $stmt->execute($campIds);

        $map = array_fill_keys($campIds, []);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['camp_id']][] = (int) $row['section_id'];
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return Camp[]
     */
    private function hydrateAll(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        // One query for every camp's sections rather than one per camp:
        // the place sheet of a well-used site lists twenty stays.
        $sections = $this->sectionIdsFor(array_map(static fn(array $r): int => (int) $r['id'], $rows));

        return array_map(fn(array $row): Camp => $this->hydrate($row, $sections), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, int[]>    $sections
     */
    private function hydrate(array $row, array $sections): Camp
    {
        $id = (int) $row['id'];

        return new Camp(
            id: $id,
            placeId: (int) $row['place_id'],
            stayType: (string) $row['stay_type'],
            startDate: $row['start_date'] !== null ? (string) $row['start_date'] : null,
            endDate: $row['end_date'] !== null ? (string) $row['end_date'] : null,
            yearOnly: $row['year_only'] !== null ? (int) $row['year_only'] : null,
            status: (string) $row['status'],
            priceCents: $row['price_cents'] !== null ? (int) $row['price_cents'] : null,
            participantCount: $row['participant_count'] !== null ? (int) $row['participant_count'] : null,
            bookedByMemberId: $row['booked_by_member_id'] !== null ? (int) $row['booked_by_member_id'] : null,
            bookedByName: $this->decryptNullable($row['booked_by_name']),
            sectionIds: $sections[$id] ?? [],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private function encryptNullable(?string $value): ?string
    {
        $value = Support::clean($value);

        return $value !== null ? $this->encryption->encrypt($value, 'camp_camps.booked_by_name') : null;
    }

    private function decryptNullable(mixed $value): ?string
    {
        return $value !== null && $value !== ''
            ? $this->encryption->decrypt((string) $value, 'camp_camps.booked_by_name')
            : null;
    }
}
