<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

use Core\Service\DateInput;

/**
 * « M'envoyer un test » — at most one send per 30 seconds per account.
 *
 * The same shape as `Core\Security\LoginThrottler`: timestamped rows, a
 * fixed window, one COUNT, and a refusal that names how long is left. The
 * difference is where the rows live. The throttler counts the JOURNAL's
 * own `email_template_test_sent` entries rather than a table of its own,
 * because the roadmap already required those entries and a second store
 * would be a second truth: a send that journaled but did not count, or the
 * reverse, is exactly the drift a throttle cannot survive.
 *
 * Not a session counter, which is what the obvious implementation would
 * be. A session is per browser, so two tabs, a phone alongside a laptop,
 * or simply signing in again would each get their own allowance — and the
 * point of this limit is that the outgoing mail relay is not asked to send
 * fifty messages while somebody leans on a button.
 *
 * A refused send is not an error the administrator caused: it is answered
 * with the seconds remaining, not with a failure.
 */
class EmailTestSendThrottler
{
    /** Seconds an account must leave between two test sends. */
    public const WINDOW_SECONDS = 30;

    /** The journal entry a successful test send writes, and this counts. */
    public const JOURNAL_TYPE = 'email_template_test_sent';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Seconds this account must still wait, or 0 when it may send now.
     *
     * An account id of null — no session, which the RBAC guard makes
     * impossible on this route — is refused for the whole window rather
     * than allowed: an unattributable send is the one this limit exists
     * for.
     */
    public function secondsRemaining(?int $userAccountId): int
    {
        if ($userAccountId === null) {
            return self::WINDOW_SECONDS;
        }

        $stmt = $this->pdo->prepare(
            'SELECT logged_at FROM event_log
             WHERE event_type = ? AND user_account_id = ?
             ORDER BY logged_at DESC LIMIT 1'
        );
        $stmt->execute([self::JOURNAL_TYPE, $userAccountId]);
        $last = $stmt->fetchColumn();

        if (!is_string($last) || $last === '') {
            return 0;
        }

        // Through DateInput, like every other stored timestamp in this
        // codebase (Tests\Security\DateParsingConvergenceTest): a NUL byte
        // in the column makes PHP's own format parser raise rather than
        // return false, which the usual guard lets straight through.
        $lastAt = DateInput::fromStorage($last);
        if ($lastAt === null) {
            // An unreadable timestamp is not a reason to open the gate.
            return self::WINDOW_SECONDS;
        }

        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $lastAt->getTimestamp();

        return $elapsed >= self::WINDOW_SECONDS ? 0 : self::WINDOW_SECONDS - max(0, $elapsed);
    }

    public function allows(?int $userAccountId): bool
    {
        return $this->secondsRemaining($userAccountId) === 0;
    }
}
