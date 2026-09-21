<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\CardDav;

use Core\Database\Connection;

/**
 * Who is in the synchronised address book, and when each of them last
 * changed — **without decrypting a single column**.
 *
 * That constraint is the whole reason this class exists beside
 * {@see \Core\Member\SectionService}, which answers the same question by
 * returning hydrated {@see \Core\Member\MemberProfile}s. A CardDAV client
 * polls every few minutes and almost always to be told « nothing has
 * changed »; answering that by decrypting every leader's name, address
 * and telephone number would make the cheapest request on the site the
 * most expensive one. Here the answer is built from identifiers and
 * timestamps, which are not personal data and are not encrypted.
 */
class AddressBookRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * The address book's membership for one scout year: the member id of
     * every leader, mapped to the member_year row their card is built
     * from.
     *
     * **The set is the trombinoscope's, exactly.** Functions whose role
     * is chef or chef d'unité, attached to a section that is active and
     * visible — which includes « Staff d'U », a real section like any
     * other (`Core\Member\UnitStaffSectionService`). That equality is
     * not a convenience: `SECURITY.md` §6 authorises this export because
     * the data leaving is data every identified member already sees on
     * `/trombinoscope`, and a set that were merely *similar* would make
     * that sentence false.
     *
     * Which is also why a function with no section at all is left out,
     * although it would be defensible to include one. It is not on the
     * trombinoscope, so it is not in here.
     *
     * A member holding two functions appears once: the DISTINCT is on
     * the member, not on the function.
     *
     * @return array<int, int> member_id => member_year_id, ordered by member id
     */
    public function findStaffMemberYears(int $scoutYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT DISTINCT my.member_id AS member_id, my.id AS member_year_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             JOIN sections s ON mf.section_id = s.id
             WHERE my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN (\'chief\', \'admin\')
               AND s.is_active = 1 AND s.is_visible = 1
             ORDER BY my.member_id'
        );
        $stmt->execute([$scoutYearId]);

        $members = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberId = (int) $row['member_id'];
            // A member with two member_year rows in one scout year should
            // not exist, and the first one wins if it ever does: the card
            // has one UID, so it must have one source row, chosen the
            // same way on every request rather than by whatever order the
            // engine felt like.
            $members[$memberId] ??= (int) $row['member_year_id'];
        }

        return $members;
    }

    /**
     * The member_year row one member's card is built from, or null when
     * that member is not in the address book for this year.
     *
     * Asked of the database rather than of
     * {@see findStaffMemberYears()}, so that fetching one card does not
     * first list the whole unit: a client that already holds the
     * collection asks for cards one at a time.
     */
    public function findStaffMemberYear(int $memberId, int $scoutYearId): ?int
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT my.id AS member_year_id
             FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN functions f ON mf.function_id = f.id
             JOIN sections s ON mf.section_id = s.id
             WHERE my.member_id = ? AND my.scout_year_id = ? AND my.is_active = 1
               AND f.role IN (\'chief\', \'admin\')
               AND s.is_active = 1 AND s.is_visible = 1
             ORDER BY my.id
             LIMIT 1'
        );
        $stmt->execute([$memberId, $scoutYearId]);

        $memberYearId = $stmt->fetchColumn();

        return $memberYearId === false ? null : (int) $memberYearId;
    }
}
