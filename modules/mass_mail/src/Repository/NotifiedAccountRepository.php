<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * mass_mail_notified_accounts — « this account has had its Nouvel email
 * notification for this communication » (a classic list: one per email) or
 * « for this file row » (a mail merge: one per row). What keeps the
 * notification to at most one per account and per communication or row,
 * whatever the number of addresses of that account the communication
 * reached, and through any « Renvoyer » (issue #999, §2.6).
 *
 * audience_row_key is written here, and only here, as
 * COALESCE(audience_row_id, 0): the unique key cannot be built on a NULL
 * (schema.sql says why it is not a generated column).
 */
class NotifiedAccountRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Records the account as notified for this communication or row, and
     * answers whether it was NOT already — true is the caller's licence to
     * dispatch, false means somebody already did.
     *
     * Meant to run inside the transaction that writes the notification, so
     * the two stand or fall together. The read first answers the ordinary
     * case without an error; the unique key answers the race the read
     * cannot see, and its violation is the only exception swallowed.
     */
    public function claim(int $emailId, ?int $audienceRowId, int $userAccountId): bool
    {
        $key = $audienceRowId ?? 0;

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM mass_mail_notified_accounts
             WHERE email_id = ? AND audience_row_key = ? AND user_account_id = ? LIMIT 1'
        );
        $stmt->execute([$emailId, $key, $userAccountId]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO mass_mail_notified_accounts (email_id, audience_row_id, audience_row_key, user_account_id)
                 VALUES (?, ?, ?, ?)'
            )->execute([$emailId, $audienceRowId, $key, $userAccountId]);
        } catch (\PDOException $e) {
            // ONLY the duplicate: SQLSTATE 23000 in both engines and in
            // SQLite, and 1062 (ER_DUP_ENTRY) to tell it from a foreign key
            // refusal on MySQL and MariaDB, which shares the class and must
            // not pass for « already notified ».
            $driverCode = $e->errorInfo[1] ?? null;
            if ($e->getCode() === '23000' && ($driverCode === 1062 || $driverCode === 19 || $driverCode === null)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * How many accounts were notified for this communication — every row,
     * whatever the file row (a test, and a count the journal can carry).
     */
    public function countForEmail(int $emailId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mass_mail_notified_accounts WHERE email_id = ?');
        $stmt->execute([$emailId]);

        return (int) $stmt->fetchColumn();
    }
}
