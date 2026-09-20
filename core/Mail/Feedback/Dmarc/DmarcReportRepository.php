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

            try {
                $statement->execute([
                    $report->organisation,
                    $report->reportId,
                    $report->domain,
                    $report->begin->format('Y-m-d H:i:s'),
                    $report->end->format('Y-m-d H:i:s'),
                    $report->policy,
                    $now->format('Y-m-d H:i:s'),
                ]);
            } catch (\PDOException $duplicate) {
                if (!self::isDuplicateKey($duplicate)) {
                    throw $duplicate;
                }

                // **A losing race is not a failure.** Two syncs reading
                // the same folder at once both pass `alreadyHave()` and one
                // loses on the unique index; the report is here either way,
                // which is all the caller wanted.
                $this->pdo->rollBack();

                return false;
            }

            $reportRowId = (int) $this->pdo->lastInsertId();

            $line = $this->pdo->prepare(
                'INSERT INTO mail_dmarc_sources
                    (dmarc_report_id, source_ip, message_count, authenticated_count, disposition)
                 VALUES (?, ?, ?, ?, ?)'
            );

            foreach ($report->records as $record) {
                $line->execute([
                    $reportRowId,
                    $record->sourceIp,
                    $record->count,
                    $record->authenticated() ? $record->count : 0,
                    $record->disposition,
                ]);
            }

            $this->pdo->commit();

            return true;
        } catch (\PDOException $failure) {
            // **Everything that is not the race is rethrown**, and the
            // distinction cost a review to see. A source row that will not
            // write, or a commit the server refuses, used to come back as
            // `false` — indistinguishable from « we already had it ». The
            // consumer then skipped its journal entry and the registry saw
            // no exception to record, so a database refusing writes looked
            // exactly like a quiet, ordinary re-read. That is this
            // chantier's own recurring defect: a failure wearing the shape
            // of a success.
            $this->pdo->rollBack();

            throw $failure;
        }
    }

    /**
     * Whether this is the unique index refusing a report we already hold.
     *
     * **Not every `23000` is a duplicate** — that class covers a foreign
     * key and a NOT NULL just as well, and reading it as « already here »
     * would swallow the two failures this method exists to let through.
     * The driver's own code is what separates them: 1062 on MySQL and
     * MariaDB, 19 on SQLite, 7 on PostgreSQL. `errorInfo()[1]` is that
     * code; it is null for an error the driver did not number, which is
     * not a duplicate either.
     */
    private static function isDuplicateKey(\PDOException $exception): bool
    {
        if (($exception->errorInfo[0] ?? '') !== '23000') {
            return false;
        }

        return in_array($exception->errorInfo[1] ?? null, [1062, 19, 7], true);
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
     * **This one is capped, and every caller has to know it.** It feeds a
     * table somebody reads, and a table of ten thousand rows is not a
     * screen. What must NOT be built on it is a total or a verdict: the
     * counters come from {@see totalsSince()} and the « somebody unknown
     * is authenticating » scan from {@see authenticatingSourcesSince()},
     * both uncapped. Reading a capped list as the whole truth is how a
     * support archive undercounts and how the one warning on that page
     * misses the source it exists to name.
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
              WHERE r.period_end >= :since
              GROUP BY s.source_ip
              ORDER BY messages DESC, s.source_ip ASC
              LIMIT :limit'
        );
        // Bound rather than concatenated, `int` or not: « every SQL
        // statement is prepared » is a shape, and a shape that holds only
        // where somebody checked the type is not one (AGENTS.md).
        $statement->bindValue(':since', $since->format('Y-m-d H:i:s'));
        $statement->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

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
     * Capped like {@see sourcesSince()}, and with the same warning: it is
     * a list to show, never a count to report.
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
              WHERE r.period_end >= :since
              GROUP BY r.id, r.organisation, r.domain, r.policy, r.period_end
              ORDER BY r.period_end DESC, r.organisation ASC
              LIMIT :limit'
        );
        $statement->bindValue(':since', $since->format('Y-m-d H:i:s'));
        $statement->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

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
     * The window's figures, computed by the database over every row.
     *
     * **Separate from the two lists above because it must not be capped.**
     * A total summed in PHP from a capped list is a total of whatever
     * happened to fit, and the support archive that carried it would
     * undercount an installation's traffic without ever saying so — which
     * is worse than no figure, because a wrong figure is acted on.
     *
     * @return array{reports: int, sources: int, messages: int, authenticated: int, reporters: int}
     */
    public function totalsSince(\DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT r.id) AS reports,
                    COUNT(DISTINCT s.source_ip) AS sources,
                    COUNT(DISTINCT r.organisation) AS reporters,
                    COALESCE(SUM(s.message_count), 0) AS messages,
                    COALESCE(SUM(s.authenticated_count), 0) AS authenticated
               FROM mail_dmarc_reports r
               LEFT JOIN mail_dmarc_sources s ON s.dmarc_report_id = r.id
              WHERE r.period_end >= :since'
        );
        $statement->bindValue(':since', $since->format('Y-m-d H:i:s'));
        $statement->execute();

        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'reports' => (int) ($row['reports'] ?? 0),
            'sources' => (int) ($row['sources'] ?? 0),
            'messages' => (int) ($row['messages'] ?? 0),
            'authenticated' => (int) ($row['authenticated'] ?? 0),
            'reporters' => (int) ($row['reporters'] ?? 0),
        ];
    }

    /**
     * Every distinct policy the reporters saw published over the window.
     *
     * @return list<string>
     */
    public function policiesSince(\DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT policy FROM mail_dmarc_reports WHERE period_end >= :since ORDER BY policy ASC'
        );
        $statement->bindValue(':since', $since->format('Y-m-d H:i:s'));
        $statement->execute();

        return array_map(
            static fn(mixed $policy): string => (string) $policy,
            $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []
        );
    }

    /**
     * The addresses that got a message through, and nothing else.
     *
     * **This is what the page's one warning has to be computed from.** The
     * sentence it raises — « quelqu'un que nous ne reconnaissons pas envoie
     * en votre nom et y parvient » — is worth saying only if it cannot be
     * missed, and computing it from the two hundred rows the table happens
     * to show means missing exactly the quiet sender that matters: one
     * forgotten tool sending forty messages sorts below two hundred noisy
     * ones and disappears from the verdict along with the row.
     *
     * Only the authenticating addresses, because a source whose messages
     * all fail raises nothing — so the set is far smaller than « every
     * source », and the ceiling here is a guard against absurdity rather
     * than a page size.
     *
     * @return list<string>
     */
    public function authenticatingSourcesSince(\DateTimeImmutable $since, int $limit = 5000): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.source_ip AS source_ip
               FROM mail_dmarc_sources s
               JOIN mail_dmarc_reports r ON r.id = s.dmarc_report_id
              WHERE r.period_end >= :since
              GROUP BY s.source_ip
             HAVING SUM(s.authenticated_count) > 0
              ORDER BY s.source_ip ASC
              LIMIT :limit'
        );
        $statement->bindValue(':since', $since->format('Y-m-d H:i:s'));
        $statement->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn(mixed $address): string => (string) $address,
            $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []
        );
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
