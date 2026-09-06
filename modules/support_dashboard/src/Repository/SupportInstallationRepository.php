<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

use Core\Security\DecryptionException;
use Core\Security\EncryptionService;

/**
 * The receiver's record of every installation that reports to it
 * (ARCHITECTURE.md §8.49).
 *
 * One row per installation, never a history: each accepted report
 * overwrites the previous one. The shared secret is stored **only** as a
 * `password_hash()`, never in clear, in any column.
 */
class SupportInstallationRepository
{
    public function __construct(
        private \PDO $pdo,
        /**
         * Needed only by the WHOIS half, and optional so that the dozen
         * callers that never touch it — the purge task, the dashboard's
         * own reads — keep constructing this the way they always have.
         *
         * Null means the raw response is neither written nor read: the
         * response may carry a registrant's name and address, and a
         * repository without a key writes plaintext or nothing. Nothing is
         * the only acceptable answer (SECURITY.md §5), and the parsed
         * registration beside it is organisational and survives either way.
         */
        private ?EncryptionService $encryption = null
    )
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByInstallationId(string $installationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_installations WHERE installation_id = ?');
        $stmt->execute([$installationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_installations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Every retained installation, newest report first.
     *
     * Deliberately unpaginated and unfiltered: filtering, searching,
     * sorting and paging all happen in Modules\SupportDashboard\Service\
     * SupportDashboardService. See its docblock for why — the short version
     * is that one of the filters reads the stored JSON payload, which no
     * portable SQL can express, and splitting the work would make the
     * table, the counters and the KPI cards disagree with each other.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM support_installations ORDER BY last_received_at DESC, id DESC');

        return $stmt !== false ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * First registration: an installation id nobody has seen before.
     *
     * @param array<string, mixed> $denormalized
     */
    public function register(
        string $installationId,
        string $secretHash,
        string $rawPayload,
        array $denormalized,
        bool $telemetryEnabled = true
    ): int {
        // Both timestamps are written from PHP rather than left to the
        // column's DEFAULT CURRENT_TIMESTAMP. recordReport() has always
        // stamped last_received_at from PHP, so leaving the first one to
        // the database made a fresh registration and its next report use
        // two different clocks — and on a host where PHP and MySQL sit in
        // different timezones that difference is hours, applied directly to
        // the value the dashboard compares against its active/stale
        // threshold and against which retention deletes.
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $columns = array_merge(
            ['installation_id', 'secret_hash', 'payload', 'first_seen_at', 'last_received_at', 'telemetry_enabled'],
            array_keys($denormalized)
        );
        $values = self::bindable(array_merge(
            [$installationId, $secretHash, $rawPayload, $now, $now, $telemetryEnabled],
            array_values($denormalized)
        ));

        $stmt = $this->pdo->prepare(
            'INSERT INTO support_installations (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A subsequent report from a known installation. Deliberately replaces
     * every denormalised column, including with NULL: a sender that stops
     * being able to measure something must stop reporting it, not keep the
     * last value it happened to know.
     *
     * @param array<string, mixed> $denormalized
     */
    public function recordReport(int $id, string $rawPayload, array $denormalized): void
    {
        // A report arriving on a row that was created for a ticket clears
        // the « sans télémétrie » mark: the reason for it has gone, and a
        // row that keeps saying so would be lying from the second report
        // onwards (roadmap IT-24).
        $assignments = ['payload = ?', 'last_received_at = ?', 'telemetry_enabled = 1'];
        $values = [$rawPayload, (new \DateTimeImmutable())->format('Y-m-d H:i:s')];

        foreach ($denormalized as $column => $value) {
            $assignments[] = $column . ' = ?';
            $values[] = $value;
        }

        $values[] = $id;

        $stmt = $this->pdo->prepare(
            'UPDATE support_installations SET ' . implode(', ', $assignments) . ' WHERE id = ?'
        );
        $stmt->execute(self::bindable($values));
    }

    /**
     * Removes one installation **in its entirety** — identifier, URL, last
     * payload, reception metadata and the credential hash — whether the
     * caller is the retention task or a superadmin acting by hand.
     *
     * Nothing here touches `support_monthly_aggregates`: a finalised
     * aggregate is a count of distinct installations for a month that has
     * ended, and it must survive the disappearance of any installation that
     * fed it. See ARCHITECTURE.md §8.51.
     */
    /**
     * What the registry said about this installation's domain.
     *
     * Written by the statistics intake when a daily report arrives and the
     * registration this receiver holds has gone stale — never on every
     * report. A WHOIS query per installation per day is a few hundred
     * queries a day at a registry that rate-limits by address, and being
     * refused is how a diagnostic stops working exactly when somebody
     * needs it.
     *
     * @param string $status one of found / not_found / unavailable
     * @param array<string, mixed>|null $registration what was read out of
     *   the response — organisational fields only, see the schema
     * @param string|null $raw the response verbatim, encrypted here
     */
    public function recordWhois(
        int $id,
        ?string $domain,
        ?string $server,
        string $status,
        ?array $registration,
        ?string $raw,
        \DateTimeImmutable $at
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE support_installations
                SET whois_domain = ?, whois_server = ?, whois_status = ?,
                    whois_registration = ?, whois_raw_encrypted = ?, whois_checked_at = ?
              WHERE id = ?'
        );

        $stmt->execute([
            $domain,
            $server,
            $status,
            $registration === null ? null : json_encode($registration, JSON_UNESCAPED_UNICODE),
            $raw === null || $this->encryption === null
                ? null
                : $this->encryption->encrypt($raw, 'support_installations.whois_raw'),
            $at->format('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * The verbatim response, read on its own rather than with the row.
     *
     * The dashboard lists every installation on every render and has no use
     * for thirty kilobytes of registry prose apiece; the two places that do
     * — the detail dialog and the ticket's support dossier — ask for one.
     */
    public function findWhoisRaw(int $id): ?string
    {
        if ($this->encryption === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT whois_raw_encrypted FROM support_installations WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return $this->encryption->decrypt($raw, 'support_installations.whois_raw');
        } catch (DecryptionException) {
            // Ciphertext this key cannot read — a restored database, a
            // rotated key, a truncated column. The only caller is the
            // ticket dossier, where the WHOIS is one optional file among
            // twenty: an unreadable one must read as absent, not abort
            // the download of everything else somebody asked for.
            return null;
        }
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM support_installations WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Ids whose last accepted report predates $cutoff — the retention
     * task's work list. Returned as ids rather than deleted in one
     * statement so the caller can journal what it removed.
     *
     * @return array<int, int>
     */
    public function findIdsLastReceivedBefore(string $cutoff): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM support_installations WHERE last_received_at < ?');
        $stmt->execute([$cutoff]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * PDOStatement::execute() binds every value of its array as a string,
     * and PHP casts `false` to `''` — which MySQL refuses for a BOOLEAN
     * (TINYINT) column under strict mode, and which SQLite silently stores
     * as an empty string that then reads back as "not reported". Both are
     * wrong for the same reason: `false` is a reported value, not a missing
     * one. NULL is left untouched — that one really is "not reported".
     *
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private static function bindable(array $values): array
    {
        return array_map(
            static fn(mixed $value): mixed => is_bool($value) ? (int) $value : $value,
            $values
        );
    }
}
