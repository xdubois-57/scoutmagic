<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

/**
 * Where the reports are kept, and what the screen asks of them
 * (roadmap IT-06).
 */
class DmarcReportRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Writes a report down, or does nothing if it is already here.
     *
     * **The same report arriving twice is routine**, not exceptional: a
     * UIDVALIDITY reset has a whole folder re-read, and one message can
     * sit in two watched folders at once. `MailboxSyncService` runs the
     * analysis pass BEFORE its Message-ID dedup, so a consumer sees the
     * re-read and has to be the one that shrugs — the same trap
     * `BounceConsumer` documents for itself.
     *
     * **The unique index is the guarantee; the lookup below is an
     * economy.** Removing the lookup changes nothing a caller can observe
     * — the insert then hits the index, the catch rolls back and answers
     * false just the same — which is why the test for this pins the
     * outcome rather than the mechanism. Said plainly here because the
     * opposite reading, that the lookup is what protects, would have
     * somebody remove the index one day and find the tests still green.
     *
     * @return bool whether anything was written
     */
    public function record(DmarcReport $report, \DateTimeImmutable $now): bool
    {
        if ($this->alreadyHave($report)) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO mail_dmarc_reports
                    (organisation, report_id, domain, period_begin, period_end, policy, received_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $report->organisation,
                $report->reportId,
                $report->domain,
                $report->begin->format('Y-m-d H:i:s'),
                $report->end->format('Y-m-d H:i:s'),
                $report->policy,
                $now->format('Y-m-d H:i:s'),
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $line = $this->pdo->prepare(
                'INSERT INTO mail_dmarc_sources
                    (dmarc_report_id, source_ip, message_count, authenticated_count, disposition)
                 VALUES (?, ?, ?, ?, ?)'
            );

            foreach ($report->records as $record) {
                $line->execute([
                    $id,
                    $record->sourceIp,
                    $record->count,
                    $record->authenticated() ? $record->count : 0,
                    $record->disposition,
                ]);
            }

            $this->pdo->commit();

            return true;
        } catch (\PDOException) {
            $this->pdo->rollBack();

            // **A losing race is not a failure.** Two syncs reading the
            // same folder at once both pass `alreadyHave()` and one loses
            // on the unique index; the report is here either way, which is
            // all the caller wanted.
            return false;
        }
    }

    private function alreadyHave(DmarcReport $report): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM mail_dmarc_reports WHERE organisation = ? AND report_id = ? LIMIT 1'
        );
        $statement->execute([$report->organisation, $report->reportId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Every source seen over the window, most messages first.
     *
     * **Grouped on the address rather than on the report**, because the
     * question the screen asks is « who sends in my name », and one sender
     * appears in as many reports as there are providers receiving from it.
     *
     * @return list<array{source_ip: string, messages: int, authenticated: int, reporters: int}>
     */
    public function sourcesSince(\DateTimeImmutable $since, int $limit = 200): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.source_ip AS source_ip,
                    SUM(s.message_count) AS messages,
                    SUM(s.authenticated_count) AS authenticated,
                    COUNT(DISTINCT r.organisation) AS reporters
               FROM mail_dmarc_sources s
               JOIN mail_dmarc_reports r ON r.id = s.dmarc_report_id
              WHERE r.period_end >= ?
              GROUP BY s.source_ip
              ORDER BY messages DESC, s.source_ip ASC
              LIMIT ' . max(1, $limit)
        );
        $statement->execute([$since->format('Y-m-d H:i:s')]);

        $sources = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sources[] = [
                'source_ip' => (string) $row['source_ip'],
                'messages' => (int) $row['messages'],
                'authenticated' => (int) $row['authenticated'],
                'reporters' => (int) $row['reporters'],
            ];
        }

        return $sources;
    }

    /**
     * The reports themselves over the window, most recent period first —
     * what the screen shows to say « these are the providers reporting,
     * and this is the policy they saw ».
     *
     * @return list<array{organisation: string, domain: string, policy: string, period_end: string, messages: int}>
     */
    public function reportsSince(\DateTimeImmutable $since, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.organisation, r.domain, r.policy, r.period_end,
                    COALESCE(SUM(s.message_count), 0) AS messages
               FROM mail_dmarc_reports r
               LEFT JOIN mail_dmarc_sources s ON s.dmarc_report_id = r.id
              WHERE r.period_end >= ?
              GROUP BY r.id, r.organisation, r.domain, r.policy, r.period_end
              ORDER BY r.period_end DESC, r.organisation ASC
              LIMIT ' . max(1, $limit)
        );
        $statement->execute([$since->format('Y-m-d H:i:s')]);

        $reports = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $reports[] = [
                'organisation' => (string) $row['organisation'],
                'domain' => (string) $row['domain'],
                'policy' => (string) $row['policy'],
                'period_end' => (string) $row['period_end'],
                'messages' => (int) $row['messages'],
            ];
        }

        return $reports;
    }

    /**
     * Drops everything whose period ended before the cut.
     *
     * **Operational data, so it purges** — and on the period's end rather
     * than on arrival, because a report can turn up days after the window
     * it describes and would otherwise be removed for being old the moment
     * it landed.
     *
     * @return int reports removed; their source lines follow by cascade
     */
    public function purgeBefore(\DateTimeImmutable $cut): int
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_dmarc_reports WHERE period_end < ?');
        $statement->execute([$cut->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }
}
