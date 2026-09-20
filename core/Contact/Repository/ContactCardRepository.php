<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Repository;

use Core\Contact\ContactAffiliation;
use Core\Database\Connection;

/**
 * The two reads a contact card needs that no existing repository answers:
 * a member's WHOLE affiliation history, and the instant their card last
 * could have changed.
 *
 * **Why a history read exists at all.** `Core\Member\MemberProfile` is a
 * snapshot of ONE `member_years` row — it carries that year's functions
 * and nothing before them. Rebuilding a history by asking for a profile
 * per year would be one full hydration (addresses, functions, labels, and
 * an AES decryption of every personal column) per scout year, for data the
 * card needs three plain strings from. Both methods here read every year
 * of a member in a single query instead, and neither decrypts anything:
 * a function label, a section name and a year label are all in clear.
 */
class ContactCardRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Every scout year this member has a row for, most recent first, with
     * the functions held that year.
     *
     * @return list<ContactAffiliation>
     */
    public function findAffiliationsForMember(int $memberId): array
    {
        return $this->findAffiliationsForMembers([$memberId])[$memberId] ?? [];
    }

    /**
     * The same read for a whole address book at once — one query for every
     * member, never one per member.
     *
     * The section is resolved through the `sections` row, so a section
     * renamed in Configuration reads under its CURRENT name for every year
     * it appears in, past years included. `sections.name` is nullable and
     * `sections.desk_code` is deliberately NOT used as a fallback: the
     * card names a section or names none, it never prints a Desk code at
     * somebody's reader.
     *
     * **`member_functions` is a LEFT JOIN, and that is load-bearing.** A
     * member can perfectly well have a `member_years` row and no function
     * at all for that year — `Core\Attention\CoreAttentionRepository`
     * exists to flag exactly that, a Desk encoding somebody never
     * finished. An inner join would drop the whole year from the history
     * in silence, so a person's card would skip a season without saying
     * so. Such a year comes back as itself, with no function behind it
     * ({@see ContactAffiliation::format()}).
     *
     * @param int[] $memberIds
     * @return array<int, list<ContactAffiliation>> keyed by `members.id`; a member with no
     *         `member_years` row at all is simply absent
     */
    public function findAffiliationsForMembers(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->connection->getPdo()->prepare(
            "SELECT my.member_id AS member_id,
                    sy.label AS year_label,
                    sy.start_date AS year_start,
                    f.label AS function_label,
                    s.name AS section_name,
                    mf.is_main_function AS is_main
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             LEFT JOIN member_functions mf ON mf.member_year_id = my.id
             LEFT JOIN functions f ON f.id = mf.function_id
             LEFT JOIN sections s ON s.id = mf.section_id
             WHERE my.member_id IN ($placeholders)
             ORDER BY my.member_id, sy.start_date DESC, mf.is_main_function DESC, f.label, s.name"
        );
        $stmt->execute($memberIds);

        /** @var array<int, array<string, array{label: string, functions: list<array{function: string, section: ?string}>}>> $byMember */
        $byMember = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberId = (int) $row['member_id'];
            $yearKey = (string) $row['year_start'] . '|' . (string) $row['year_label'];

            // The year is registered whether or not a function came back
            // with it — see the LEFT JOIN above.
            if (!isset($byMember[$memberId][$yearKey])) {
                $byMember[$memberId][$yearKey] = ['label' => (string) $row['year_label'], 'functions' => []];
            }

            if ($row['function_label'] === null) {
                continue;
            }

            $sectionName = $row['section_name'] !== null ? (string) $row['section_name'] : null;
            $entry = [
                'function' => (string) $row['function_label'],
                'section' => $sectionName !== '' ? $sectionName : null,
            ];

            // The same function listed twice in one year is one function
            // said twice: a Desk export carries one row per
            // (function × address), the same trap
            // Core\Member\MemberFunctionInfo::deduplicate() exists for.
            if (!in_array($entry, $byMember[$memberId][$yearKey]['functions'], true)) {
                $byMember[$memberId][$yearKey]['functions'][] = $entry;
            }
        }

        $result = [];
        foreach ($byMember as $memberId => $years) {
            $result[$memberId] = array_values(array_map(
                fn(array $year): ContactAffiliation => new ContactAffiliation($year['label'], $year['functions']),
                $years
            ));
        }

        return $result;
    }

    /**
     * When each member's card last COULD have changed, as a UTC-naive
     * database timestamp string, or null for a member nothing is known
     * about.
     *
     * There is no `updated_at` on `member_years` to read: a Desk import
     * rewrites the row in place (`Core\Import\MemberYearRepository::
     * upsert()`), so its `created_at` answers when the member first
     * appeared, never when their phone number changed. What this reads
     * instead is every event that can move a card — the row's creation,
     * the imports covering the years it belongs to, and the member's
     * photo and configured addresses.
     *
     * It therefore **over-reports rather than under-reports**: an import
     * that changed nothing about a given member still bumps their
     * revision. That is the safe direction, and deliberately so — a
     * client re-fetches a card it already had, which costs one request a
     * few times a year, where the opposite error leaves a stale phone
     * number in somebody's phone forever.
     *
     * **It decrypts nothing**, which is what makes IT-03's `getctag` able
     * to answer "nothing changed" to a client that polls every few minutes
     * without touching a single encrypted column.
     *
     * @param int[] $memberIds
     * @return array<int, string> keyed by `members.id`
     */
    public function findRevisionsForMembers(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $sql = "SELECT member_id, MAX(ts) AS revision FROM (
                    SELECT my.member_id AS member_id, my.created_at AS ts
                        FROM member_years my WHERE my.member_id IN ($placeholders)
                    UNION ALL
                    SELECT my.member_id, ij.imported_at
                        FROM member_years my
                        JOIN import_journal ij ON ij.scout_year_id = my.scout_year_id
                        WHERE my.member_id IN ($placeholders)
                    UNION ALL
                    SELECT mp.member_id, mp.created_at
                        FROM member_photos mp WHERE mp.member_id IN ($placeholders)
                    UNION ALL
                    SELECT me.member_id, me.created_at
                        FROM member_emails me WHERE me.member_id IN ($placeholders)
                    UNION ALL
                    SELECT me.member_id, me.confirmed_at
                        FROM member_emails me WHERE me.member_id IN ($placeholders)
                    UNION ALL
                    SELECT me.member_id, me.deactivated_at
                        FROM member_emails me WHERE me.member_id IN ($placeholders)
                ) events
                WHERE ts IS NOT NULL
                GROUP BY member_id";

        $stmt = $this->connection->getPdo()->prepare($sql);
        $stmt->execute(array_merge(...array_fill(0, 6, $memberIds)));

        $revisions = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['revision'] === null) {
                continue;
            }
            $revisions[(int) $row['member_id']] = (string) $row['revision'];
        }

        return $revisions;
    }
}
