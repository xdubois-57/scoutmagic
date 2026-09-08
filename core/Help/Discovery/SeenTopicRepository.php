<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Discovery;

use Core\Config\AppClock;
use Core\Service\DateInput;

/**
 * The only layer of the discovery feature that touches PDO
 * (ARCHITECTURE.md §8.95): what an account has already been shown
 * (`help_topics_seen`) and until when nothing more may be offered to it
 * (`user_accounts.help_discovery_snoozed_until`).
 *
 * Two places of state and not one more, which is what makes « Ne plus me
 * proposer d'astuces » a single mechanism: it marks the whole eligible
 * remainder as seen instead of raising a mute flag, so a promotion or a
 * newly enabled module makes new topics eligible and the dialog returns
 * on its own, with nothing to reconcile.
 *
 * Nothing here is a reading trace: §8.64 forbids journaling help
 * consultation and this is a preference, read only for the account it
 * belongs to.
 */
class SeenTopicRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Every topic id this account has already been shown, orphans
     * included — an id whose topic no longer exists simply matches
     * nothing when the caller subtracts this list.
     *
     * @return string[]
     */
    public function findSeenIds(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT topic_id FROM help_topics_seen WHERE user_account_id = ?');
        $stmt->execute([$accountId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * How many topics this account has been shown. Half of the ordering
     * seed (Core\Help\Discovery\DiscoveryService) and what decides
     * whether « Revoir les astuces » has anything to undo on /account.
     */
    public function countSeen(int $accountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM help_topics_seen WHERE user_account_id = ?');
        $stmt->execute([$accountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Records a batch as seen, in ONE statement.
     *
     * An upsert rather than a loop of INSERTs with a read in front: the
     * dialog is closed from a page that may be open in two tabs, and a
     * read-then-insert would turn the second close into a unique-index
     * error instead of a no-op. Re-marking an id already recorded keeps
     * the original `seen_at` — when somebody first saw a tip is the only
     * thing this row means.
     *
     * The two spellings are the portable pairing this repository already
     * uses elsewhere (Modules\Presences\Repository\PresenceRepository,
     * Modules\UsageStats\Repository\PageViewRepository): SQLite — the
     * in-memory test database — has no ON DUPLICATE KEY. Both clauses are
     * literals naming columns only; every value is bound.
     *
     * `seen_at` is written from PHP rather than left to the column
     * default because SQLite's CURRENT_TIMESTAMP is UTC while everything
     * else here runs on Europe/Brussels (Core\Config\AppClock).
     *
     * @param string[] $topicIds
     */
    public function markSeen(int $accountId, array $topicIds): void
    {
        $topicIds = array_values(array_unique($topicIds));
        if ($topicIds === []) {
            return;
        }

        $now = AppClock::now()->format('Y-m-d H:i:s');
        $values = [];
        $parameters = [];
        foreach ($topicIds as $topicId) {
            $values[] = '(?, ?, ?)';
            $parameters[] = $accountId;
            $parameters[] = $topicId;
            $parameters[] = $now;
        }

        $sql = 'INSERT INTO help_topics_seen (user_account_id, topic_id, seen_at) VALUES '
            . implode(', ', $values)
            . ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ' ON CONFLICT(user_account_id, topic_id) DO NOTHING'
                : ' ON DUPLICATE KEY UPDATE seen_at = seen_at');

        $this->pdo->prepare($sql)->execute($parameters);
    }

    /**
     * Forgets everything this account has been shown — the « Revoir les
     * astuces » half of the reset, whose other half is snooze(null).
     */
    public function clear(int $accountId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM help_topics_seen WHERE user_account_id = ?');
        $stmt->execute([$accountId]);
    }

    /**
     * When this account may be offered a tip again, or null when nothing
     * holds it back — which is also the answer for an account row that no
     * longer exists.
     */
    public function snoozedUntil(int $accountId): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare('SELECT help_discovery_snoozed_until FROM user_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $value = $stmt->fetchColumn();

        // A stored DATETIME is Belgian wall-clock time (AppClock), so the
        // zone is passed explicitly rather than inherited from whatever
        // the process default happens to be. DateInput rather than the
        // constructor: an empty or malformed column must read as "nothing
        // holds this account back", never as the current moment, which is
        // exactly what `new DateTimeImmutable('')` would answer.
        return DateInput::fromStorage(is_string($value) ? $value : null, AppClock::zone());
    }

    public function snooze(int $accountId, ?\DateTimeImmutable $until): void
    {
        $stmt = $this->pdo->prepare('UPDATE user_accounts SET help_discovery_snoozed_until = ? WHERE id = ?');
        $stmt->execute([$until?->format('Y-m-d H:i:s'), $accountId]);
    }
}
