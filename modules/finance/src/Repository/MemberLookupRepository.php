<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

/**
 * The two exact lookups a payment campaign's import needs: does this
 * `members.id` exist, and which member does this Desk identifier
 * designate.
 *
 * Both read `members`, a core table, and both read nothing else from it
 * — no name, no address, nothing encrypted. That is deliberate: a
 * campaign resolves a spreadsheet line to a person by an identifier the
 * site itself produced, **never by a name**, so an identity lookup is
 * all this ever needs to be. The moment it grew a name comparison it
 * would be the approximate matching the whole design refuses.
 *
 * A merged identity resolves to the identity it was merged into
 * (`members.merged_into_member_id`, ARCHITECTURE.md §8.80). Without that
 * hop a treasurer working from an older export would be told the line
 * designates nobody, when it designates somebody the site has since
 * decided is the same person.
 */
class MemberLookupRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Which of these ids exist, mapped to the identity they resolve to —
     * themselves, or whatever they were merged into.
     *
     * @param int[] $memberIds
     * @return array<int, int> the id as given => the id to store
     */
    public function resolveIds(array $memberIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, merged_into_member_id FROM members WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        $resolved = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $resolved[(int) $row['id']] = $row['merged_into_member_id'] !== null
                ? (int) $row['merged_into_member_id']
                : (int) $row['id'];
        }

        return $resolved;
    }

    /**
     * @param string[] $deskIds
     * @return array<string, int> desk_id => the id to store
     */
    public function resolveDeskIds(array $deskIds): array
    {
        $values = array_values(array_unique(array_filter($deskIds, static fn(string $d): bool => $d !== '')));
        if ($values === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $this->pdo->prepare("SELECT desk_id, id, merged_into_member_id FROM members WHERE desk_id IN ($placeholders)");
        $stmt->execute($values);

        $resolved = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $resolved[(string) $row['desk_id']] = $row['merged_into_member_id'] !== null
                ? (int) $row['merged_into_member_id']
                : (int) $row['id'];
        }

        return $resolved;
    }
}
