<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Repository;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Core\Service\TextNormalizerService;
use Modules\Attestations\Service\MemberNameDirectory;
use Modules\Attestations\Value\MemberSummary;

/**
 * This module's read model over core's member data: the name → member table
 * the reader matches against, and the summary the verification screen shows
 * beside each line. The only place this module decrypts a member's name
 * (SECURITY.md §5).
 *
 * **Every scout year, deliberately.** The obvious query — the effective
 * year's roster — is the wrong one: a tax certificate covers the year just
 * gone and routinely names somebody who has since left. Those are precisely
 * the families the e-mail attachment exists for, since they no longer have
 * a page on the site. Restricting the table would leave their line
 * unmatched and undistributable.
 *
 * The read is one pass over `member_years`, ordered so that the most recent
 * row for a member comes last — which decides nothing (a member resolves to
 * one `members.id` whatever year the row came from) but keeps the SQL
 * stable to read. Names are decrypted in PHP because there is no blind
 * index on them and there cannot be: a blind index over member names would
 * be an exact-match oracle for the whole roster, bought for one feature.
 *
 * Cost is one AES round trip per member-year row. On a unit of a few
 * hundred members over five seasons that is a couple of thousand, paid once
 * per deposited file — never on a page load.
 */
class MemberNameRepository
{
    public function __construct(
        private Connection $connection,
        private EncryptionService $encryption
    ) {
    }

    /**
     * One entry per (member, spelling) across every year the site holds.
     *
     * A member who changed name between two seasons — a married name, a
     * correction — is indexed under both, which is right: a certificate
     * printed last year carries last year's spelling.
     */
    public function buildDirectory(): MemberNameDirectory
    {
        $directory = new MemberNameDirectory();

        $stmt = $this->connection->getPdo()->query(
            'SELECT my.member_id, my.first_name_encrypted, my.last_name_encrypted
             FROM member_years my
             ORDER BY my.member_id ASC, my.scout_year_id ASC'
        );
        if ($stmt === false) {
            return $directory;
        }

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $firstName = $this->decrypt($row['first_name_encrypted'], 'member_years.first_name');
            $lastName = $this->decrypt($row['last_name_encrypted'], 'member_years.last_name');

            // A row missing either half indexes nothing: half a name would
            // match half the unit, and an over-broad key here becomes a
            // wrong family's certificate rather than a missing match.
            if ($firstName === null || $lastName === null) {
                continue;
            }

            $directory->add((int) $row['member_id'], $firstName, $lastName);
        }

        return $directory;
    }

    /**
     * The name and main function of each of the given members, read from
     * their MOST RECENT year.
     *
     * Most recent rather than the effective year, for the same reason the
     * directory covers every year: a certificate names people who have
     * left, and a screen that could not name them would leave their line
     * unreadable. `AdminMemberPageService` makes the same choice and states
     * the year it is showing; so does this screen.
     *
     * **Ordered on the scout year's start_date, never on its id.**
     * `ScoutYearService::ensureYear()` can create a past year after a later
     * one, so the ids are not chronological and sorting on them shows the
     * wrong year's data under the right person's name (ARCHITECTURE.md
     * §8.62bis, where exactly that was caught by a test).
     *
     * @param list<int> $memberIds
     * @return array<int, MemberSummary> keyed by members.id
     */
    public function findSummaries(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($memberIds), '?'));
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT my.member_id,
                    my.first_name_encrypted,
                    my.last_name_encrypted,
                    sy.label AS scout_year_label,
                    f.label  AS function_label
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             LEFT JOIN member_functions mf ON mf.member_year_id = my.id AND mf.is_main_function = 1
             LEFT JOIN functions f ON f.id = mf.function_id
             WHERE my.member_id IN (' . $placeholders . ')
             ORDER BY my.member_id ASC, sy.start_date ASC'
        );
        $stmt->execute($memberIds);

        $summaries = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $firstName = $this->decrypt($row['first_name_encrypted'], 'member_years.first_name');
            $lastName = $this->decrypt($row['last_name_encrypted'], 'member_years.last_name');

            // Later rows overwrite earlier ones, so the last one standing
            // is the most recent season — which is what the ORDER BY is for.
            $summaries[(int) $row['member_id']] = new MemberSummary(
                memberId: (int) $row['member_id'],
                fullName: trim(($firstName ?? '') . ' ' . ($lastName ?? '')),
                functionLabel: $row['function_label'] !== null ? (string) $row['function_label'] : null,
                scoutYearLabel: (string) $row['scout_year_label']
            );
        }

        return $summaries;
    }

    /**
     * The roster of one scout year: who the unit had that season, named.
     *
     * This is the population the coverage screen measures against, and the
     * year matters rather than "today": a certificate covers the season it
     * covers, and a member who has since left was there when it was earned.
     * Reading today's roster instead would drop exactly the families who
     * most need somebody to notice they received nothing.
     *
     * Keyed on `members.id` for the same reason the whole module is: the
     * coverage question is about a person, not about an annual row.
     *
     * @return array<int, MemberSummary> keyed by members.id, by name
     */
    public function findRoster(int $scoutYearId): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT my.member_id,
                    my.first_name_encrypted,
                    my.last_name_encrypted,
                    sy.label AS scout_year_label,
                    f.label  AS function_label
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             LEFT JOIN member_functions mf ON mf.member_year_id = my.id AND mf.is_main_function = 1
             LEFT JOIN functions f ON f.id = mf.function_id
             WHERE my.scout_year_id = ?
             ORDER BY my.member_id ASC'
        );
        $stmt->execute([$scoutYearId]);

        $roster = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $firstName = $this->decrypt($row['first_name_encrypted'], 'member_years.first_name');
            $lastName = $this->decrypt($row['last_name_encrypted'], 'member_years.last_name');

            $roster[(int) $row['member_id']] = new MemberSummary(
                memberId: (int) $row['member_id'],
                fullName: trim(($firstName ?? '') . ' ' . ($lastName ?? '')),
                functionLabel: $row['function_label'] !== null ? (string) $row['function_label'] : null,
                scoutYearLabel: (string) $row['scout_year_label']
            );
        }

        // Sorted here, which SQL cannot do: the column is a ciphertext
        // blob, so ORDER BY on it would sort by encryption. On the FOLDED
        // name (this project's single case- and accent-insensitive form,
        // §8.0) rather than through strcoll(), whose answer depends on the
        // process locale — « Émile » would sort after « Zoé » under the C
        // locale, which is exactly the kind of thing nobody notices until a
        // reader cannot find a name in the list.
        uasort(
            $roster,
            static fn(MemberSummary $a, MemberSummary $b): int => TextNormalizerService::fold($a->fullName)
                <=> TextNormalizerService::fold($b->fullName)
        );

        return $roster;
    }

    /**
     * Each member's most recent Desk e-mail address, across every year.
     *
     * **Across every year, for the same reason as everything else here.** A
     * certificate names people who have left, and their last known address
     * is the only one the site has — it is also the one that matters most,
     * since they have no page to fetch the document from.
     *
     * **The Desk address, and only it.** A member's confirmed secondary
     * addresses are login identities of their own (SECURITY.md §2), and
     * `MemberAccountResolver` resolves both for a NOTIFICATION — a line in
     * somebody's centre. This carries the document itself, so it goes to
     * the one address the unit holds for the family and no other: a
     * teenager who added their own address should not thereby receive a
     * copy of their parents' tax paperwork. A family the Desk address no
     * longer reaches is what the member sheet's « Renvoyer par e-mail » is
     * for.
     *
     * @param list<int> $memberIds
     * @return array<int, string> members.id => address
     */
    public function findMostRecentEmails(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($memberIds), '?'));
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT my.member_id, my.email_encrypted
             FROM member_years my
             JOIN scout_years sy ON sy.id = my.scout_year_id
             WHERE my.member_id IN (' . $placeholders . ')
               AND my.email_encrypted IS NOT NULL
             ORDER BY my.member_id ASC, sy.start_date ASC'
        );
        $stmt->execute($memberIds);

        $emails = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $address = $this->decrypt($row['email_encrypted'], 'member_years.email');
            if ($address === null) {
                continue;
            }

            $address = trim($address);
            if ($address !== '') {
                // Later rows overwrite earlier ones, so the last one
                // standing is the most recent season.
                $emails[(int) $row['member_id']] = $address;
            }
        }

        return $emails;
    }

    private function decrypt(mixed $value, string $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $plain = $this->encryption->decrypt((string) $value, $context);
        } catch (\Throwable) {
            return null;
        }

        return $plain === '' ? null : $plain;
    }
}
