<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use PDO;

/**
 * `mail_send_counters` — what each provider has sent today, per lane
 * (ARCHITECTURE.md §8.106).
 *
 * Two questions are asked of this table and only one of them is the
 * quota. The quota is one provider's whole day; the reserve (IT-02) is
 * the highest daily NON-BULK total of the last thirty days, which cannot
 * be derived from a single figure per provider — hence the lane column.
 */
final class SendCounterRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Count one message that actually left.
     *
     * Written after the transport returned, never before: a counter
     * incremented on an attempt would step a lane past a provider that
     * is working perfectly the moment anything else fails.
     *
     * The INSERT … ON DUPLICATE KEY UPDATE is what makes it safe under a
     * mailing running while a visitor asks for a sign-in link: two
     * concurrent rows for the same (provider, day, lane) are refused by
     * the unique index and folded into one increment.
     */
    public function increment(int $providerId, MailLane $lane, ?string $day = null): void
    {
        // Two spellings of one upsert, the portable pairing this codebase
        // already uses (Core\Help\Discovery\SeenTopicRepository,
        // Modules\UsageStats\Repository\PageViewRepository): SQLite — the
        // in-memory test database — has no ON DUPLICATE KEY. Both clauses
        // name columns only; every value is bound.
        $sql = 'INSERT INTO mail_send_counters (provider_id, count_date, lane, sent_count) VALUES (?, ?, ?, 1)'
            . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? ' ON CONFLICT(provider_id, count_date, lane) DO UPDATE SET sent_count = sent_count + 1'
                : ' ON DUPLICATE KEY UPDATE sent_count = sent_count + 1');

        $this->pdo->prepare($sql)->execute([$providerId, $day ?? date('Y-m-d'), $lane->value]);
    }

    /**
     * Everything one provider has sent on one day, all lanes together —
     * which is what a daily quota counts.
     */
    public function totalForProvider(int $providerId, ?string $day = null): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(sent_count), 0) FROM mail_send_counters WHERE provider_id = ? AND count_date = ?'
        );
        $statement->execute([$providerId, $day ?? date('Y-m-d')]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Today's total per provider, in one query.
     *
     * @return array<int, int> provider id => messages sent today
     */
    public function totalsForDay(?string $day = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT provider_id, COALESCE(SUM(sent_count), 0) AS total
             FROM mail_send_counters WHERE count_date = ? GROUP BY provider_id'
        );
        $statement->execute([$day ?? date('Y-m-d')]);

        $totals = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(int) $row['provider_id']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * How much non-bulk traffic the installation had, per day, over a
     * window — the history the reserve is computed from (D8).
     *
     * Summed across providers on purpose: what has to be protected is a
     * day's worth of authentication and transactional mail for this unit,
     * which does not become smaller because it was spread over two
     * relays.
     *
     * @return array<string, int> day (Y-m-d) => messages
     */
    public function dailyNonBulkTotals(int $days, ?string $today = null): array
    {
        $from = date('Y-m-d', strtotime(($today ?? date('Y-m-d')) . ' -' . max(0, $days - 1) . ' days'));

        $statement = $this->pdo->prepare(
            'SELECT count_date, COALESCE(SUM(sent_count), 0) AS total
             FROM mail_send_counters
             WHERE lane <> ? AND count_date >= ? AND count_date <= ?
             GROUP BY count_date ORDER BY count_date'
        );
        $statement->execute([MailLane::Bulk->value, $from, $today ?? date('Y-m-d')]);

        $totals = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(string) $row['count_date']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * Drop counters older than the window anything still reads.
     */
    public function purgeOlderThan(string $day): int
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_send_counters WHERE count_date < ?');
        $statement->execute([$day]);

        return $statement->rowCount();
    }

    public function forgetProvider(int $providerId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_send_counters WHERE provider_id = ?');
        $statement->execute([$providerId]);
    }
}
