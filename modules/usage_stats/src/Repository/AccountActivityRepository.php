<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Repository;

/**
 * Adoption, read off `user_accounts` and stored nowhere.
 *
 * **This module records nothing to answer « le site sert-il ? ».**
 * `last_login_at` already exists, so « 62 comptes se sont connectés ce
 * mois-ci » costs one `COUNT(*)` and no new column, no new table and no
 * new personal datum. Two counts leave this class and nothing else — never
 * a row, never an address, never an id.
 *
 * **And it is why the figure is about THIS month and no other.**
 * `last_login_at` holds the LAST login, not every login: for a past month
 * it would answer « comptes dont la dernière connexion remonte à ce
 * mois-là », which is the count of people who stopped coming — the
 * opposite of what a reader would take it for. So the question is asked
 * of the current month only, and the screen says so. Answering it for
 * every month would mean recording each visit's account, which is exactly
 * the nominative tracking this whole module refuses (ARCHITECTURE.md
 * §8.93).
 */
class AccountActivityRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Accounts that logged in during $month.
     *
     * Compared against the two ends of the month rather than with a
     * `LIKE 'YYYY-MM%'`, so an index on the column can be used and so the
     * two supported engines and the test SQLite agree.
     */
    public function activeAccountsIn(string $month): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_accounts
             WHERE is_active = 1 AND last_login_at >= ? AND last_login_at < ?'
        );
        $stmt->execute([$month . '-01 00:00:00', \Modules\UsageStats\Month::shift($month, 1) . '-01 00:00:00']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Accounts that could log in at all. A deactivated account is left out
     * of both figures: counting it in the denominator would make every
     * unit's adoption fall a little every year for no reason anybody could
     * act on.
     */
    public function activeAccountCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM user_accounts WHERE is_active = 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
