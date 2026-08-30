<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Repository;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Modules\Attestations\Service\MemberNameDirectory;

/**
 * Builds the name → member table the reader matches against. The only place
 * this module decrypts anything (SECURITY.md §5).
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
