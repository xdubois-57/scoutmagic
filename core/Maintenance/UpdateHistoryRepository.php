<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Service\DateInput;

class UpdateHistoryRepository
{
    /**
     * Safety-net fallback for findInProgress() — normally a stuck row gets
     * cleaned up immediately when the NEXT update actually starts
     * (markOtherInProgressAsFailed(), called from Task\InstallUpdateHandler
     * right before it begins real work). This threshold only matters if
     * nothing new ever comes along to trigger that: a process that died
     * mid-'downloading'/'installing'/'migrating' with no successor would
     * otherwise leave Core\Maintenance\MaintenanceGate blocking every
     * visitor indefinitely. 15 minutes comfortably covers this app's real
     * scale — a full backup+download+install+migrate normally finishes in
     * well under a minute — while still being generous enough to never cut
     * off a genuinely still-progressing attempt (each MigrationRunner
     * invocation alone budgets only ~20s, see its own docblock).
     *
     * **It is counted from `progress_at`, not from `started_at`.** That
     * distinction is the whole difference between "nothing has moved this
     * for fifteen minutes" and "this has been going on for fifteen
     * minutes", and measuring the second one is what killed six updates on
     * scoutmagic.be in forty-eight hours. An update is several steps in
     * several processes by design (Task\InstallUpdateHandler never
     * migrates in the process that replaced the files), and a schema
     * change large enough to need many migration slices is a normal, fully
     * healthy update that simply takes longer than one threshold. Every
     * step stamps `progress_at`, and so does every migration slice
     * (public/index.php's migration-step endpoint calls
     * touchInProgress()), so what this measures now is silence.
     */
    private const STALE_AFTER_MINUTES = 15;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * started_at is stamped from PHP, not by the column's DEFAULT
     * CURRENT_TIMESTAMP: isStale() below subtracts it from PHP's own "now"
     * to decide whether a stuck update is still holding every visitor
     * behind Core\Maintenance\MaintenanceGate. Two clocks there either
     * declare a healthy update abandoned or leave the site gated.
     */
    public function create(string $versionFrom, string $versionTo, bool $dependenciesChanged, ?int $requestedBy): int
    {
        $now = self::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO update_history (version_from, version_to, dependencies_changed, requested_by, started_at, progress_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $versionFrom,
            $versionTo,
            $dependenciesChanged ? 1 : 0,
            $requestedBy,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?UpdateHistory
    {
        $stmt = $this->pdo->prepare('SELECT * FROM update_history WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return UpdateHistory[]
     */
    public function findRecent(int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM update_history ORDER BY started_at DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute();

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every row still merely queued, oldest first — for
     * AbandonedInstallSweeper, the only thing that ever closes a 'pending'
     * row that nothing is going to start. Deliberately not folded into
     * findInProgress(): that query must keep excluding 'pending', or a
     * queued install would gate every visitor behind the
     * update-in-progress page before it has touched anything.
     *
     * @return UpdateHistory[]
     */
    public function findPending(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM update_history WHERE status = 'pending' ORDER BY started_at ASC, id ASC"
        );
        $stmt->execute();

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The most recent update that actually finished and stuck — the health
     * signal behind Configuration > Maintenance's "dernière mise à jour
     * automatique".
     *
     * Only 'completed' counts. A 'failed' or 'rolled_back' row means the
     * site is still on the version it was on before, so reporting either as
     * "last updated" would say the opposite of the truth — which is the
     * whole failure this exists to make visible: an install channel can
     * stop working while every outward sign stays green (a push webhook
     * answers 200 whether it installed or ignored the push, so GitHub's
     * delivery log looks healthy either way).
     *
     * Ordered by completed_at, not started_at: a long update that began
     * before a short later one still finished after it.
     */
    public function findLastCompleted(): ?UpdateHistory
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM update_history
             WHERE status = 'completed'
             ORDER BY completed_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * The most recent update actively running (backing_up/downloading/
     * installing/migrating) — 'pending' is deliberately excluded, since a
     * scheduled-but-not-yet-started task hasn't touched the live site at
     * all yet, so there's nothing to gate. Used by Core\Maintenance\
     * MaintenanceGate to decide whether to show visitors the "update in
     * progress" page instead of the normal site.
     */
    public function findInProgress(): ?UpdateHistory
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM update_history
             WHERE status IN ('backing_up', 'downloading', 'installing', 'migrating')
             ORDER BY started_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $history = $this->hydrate($row);
        if ($this->isStale($history)) {
            $this->markFailed(
                $history->id,
                "Mise à jour abandonnée : bloquée à l'étape « {$history->status} » depuis plus de "
                    . self::STALE_AFTER_MINUTES . ' minutes sans qu\'une nouvelle tentative ne l\'ait supplantée.'
            );
            return null;
        }

        return $history;
    }

    private function isStale(UpdateHistory $history): bool
    {
        // progress_at when there is one, started_at otherwise — a row
        // written before the column existed has no heartbeat, and the
        // moment it began is the only thing left to measure from.
        $since = DateInput::fromStorage($history->progressAt ?? $history->startedAt);
        if ($since === null) {
            return false;
        }

        $elapsedSeconds = (new \DateTimeImmutable())->getTimestamp() - $since->getTimestamp();
        return $elapsedSeconds > self::STALE_AFTER_MINUTES * 60;
    }

    /**
     * "This update is still alive." Stamped by every step that moves an
     * update along, and by every migration slice run on its behalf, so the
     * watchdog above measures silence rather than duration.
     *
     * Deliberately not merged into setStatus(): the longest stretch of a
     * long update — a multi-slice schema migration — changes no status at
     * all, and it is precisely the stretch that needs a heartbeat.
     */
    public function touch(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET progress_at = ? WHERE id = ?');
        $stmt->execute([self::now(), $id]);
    }

    /**
     * The same heartbeat, for a caller that knows work is happening but
     * not which row it belongs to.
     *
     * Its one user is public/index.php's migration-step endpoint, which
     * runs before the application exists and has no update_history id in
     * hand — only the fact that it just executed schema statements. The
     * statuses match findInProgress()'s exactly: 'pending' is excluded
     * there and must be excluded here too, or a queued install waiting for
     * its weekly slot would have its start time quietly refreshed by an
     * unrelated migration.
     */
    public function touchInProgress(): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE update_history SET progress_at = ?
             WHERE status IN ('backing_up', 'downloading', 'installing', 'migrating')"
        );
        $stmt->execute([self::now()]);
    }

    /**
     * Marks every OTHER still-non-terminal row as failed. Called by
     * Task\InstallUpdateHandler right before a new update begins real
     * work — a new update actually starting is the clearest possible
     * signal that any other non-terminal row is abandoned, since this app
     * never intentionally runs two updates at once (Core\Maintenance\
     * GitHubWebhookService::handlePushEvent() refuses to schedule a new
     * automatic install while one is already active; only this — a new
     * attempt, automatic or a superadmin's manual "Installer maintenant",
     * actually beginning — supersedes a stuck one, rather than waiting on
     * findInProgress()'s own STALE_AFTER_MINUTES fallback above).
     */
    public function markOtherInProgressAsFailed(int $exceptId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE update_history
             SET status = 'failed',
                 error_message = 'Mise à jour abandonnée : une nouvelle mise à jour a démarré avant que celle-ci ne se termine.',
                 completed_at = ?
             WHERE id != ? AND status IN ('backing_up', 'downloading', 'installing', 'migrating')"
        );
        $stmt->execute([self::now(), $exceptId]);
    }

    /**
     * A status change is a sign of life, so it carries the heartbeat with
     * it — see touch() and isStale().
     */
    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET status = ?, progress_at = ? WHERE id = ?');
        $stmt->execute([$status, self::now(), $id]);
    }

    public function setBackupId(int $id, int $backupId): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET backup_id = ?, progress_at = ? WHERE id = ?');
        $stmt->execute([$backupId, self::now(), $id]);
    }

    public function markCompleted(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE update_history SET status = 'completed', completed_at = ? WHERE id = ?");
        $stmt->execute([self::now(), $id]);
    }

    public function markFailed(int $id, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare("UPDATE update_history SET status = 'failed', error_message = ?, completed_at = ? "
            . "WHERE id = ?");
        $stmt->execute([substr($errorMessage, 0, 500), self::now(), $id]);
    }

    public function markRolledBack(int $id, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare("UPDATE update_history SET status = 'rolled_back', error_message = ?, "
            . "completed_at = ? WHERE id = ?");
        $stmt->execute([substr($errorMessage, 0, 500), self::now(), $id]);
    }

    /**
     * The application clock — the one every timestamp in this table is on,
     * written here rather than by MySQL so `started_at` and `completed_at`
     * can never disagree with the PHP-side arithmetic in isStale() and in
     * Http\Controller\MaintenanceController::elapsedLabel().
     */
    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UpdateHistory
    {
        return new UpdateHistory(
            id: (int) $row['id'],
            versionFrom: (string) $row['version_from'],
            versionTo: (string) $row['version_to'],
            status: (string) $row['status'],
            dependenciesChanged: (bool) $row['dependencies_changed'],
            errorMessage: $row['error_message'] !== null ? (string) $row['error_message'] : null,
            backupId: $row['backup_id'] !== null ? (int) $row['backup_id'] : null,
            requestedBy: $row['requested_by'] !== null ? (int) $row['requested_by'] : null,
            startedAt: (string) $row['started_at'],
            completedAt: $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            // Coalesced rather than indexed: `SELECT *` on a database whose
            // schema migration has not landed yet has no such key at all,
            // and the watchdog's fallback to started_at is the right answer
            // there rather than a warning.
            progressAt: ($row['progress_at'] ?? null) !== null ? (string) $row['progress_at'] : null
        );
    }
}
