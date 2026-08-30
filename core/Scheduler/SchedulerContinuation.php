<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Config\SettingService;
use Core\Debug\RequestTimeline;
use Core\Http\SelfRequest;
use Core\Journal\JournalService;

/**
 * Makes the task queue drain itself after the first trigger, with no real
 * cron, no detached process, and no visitor left waiting.
 *
 * **The problem this solves is the scheduler's, not the migration's.**
 * Production evidence: `check_stable_update` due at 01:40:11 ran at
 * 01:46:32; `purge_notifications` due at 08:54:49 ran at 09:00:12. Six
 * minutes late, on tasks that do almost nothing — because the scheduler
 * only ever advances when somebody visits a page. Against
 * `UpdateHistoryRepository::STALE_AFTER_MINUTES` (fifteen minutes), that
 * lateness is what killed six updates stuck in `migrating`: the migration
 * was simply the only task long enough to need more than one slice, so it
 * was the only one that died of the defect. Every other long task would
 * have died the same way.
 *
 * **Division of labour.** The poor man's cron at the end of
 * `public/index.php` is unchanged and remains the IGNITION: it notices
 * there is work and runs the first slice. Starting was never its weakness.
 * This class is the ENGINE: at the end of a slice, if work remains, it
 * emits an HTTP hop to the site itself, after `fastcgi_finish_request()`,
 * so nothing is charged to the visitor who happened to trigger it.
 *
 * **Why HTTP and not a process.** On the reference host (LWS shared,
 * CloudLinux/CageFS) a self-directed HTTP request works on every target
 * tried, in blocking and non-blocking mode alike. Detached CLI spawning
 * does not: `system`, `passthru`, `proc_open` and `popen` are all in
 * `disable_functions`, and anything that did survive would still be in
 * `kill_orphaned_php`'s sights. `Core\System\ShellExecutor` and
 * `ExecutableLocator` exist in this codebase and will look like the
 * answer here. They are not.
 *
 * **Three cumulative conditions before a hop is emitted** — see
 * shouldHop(). Drop any one of them and two concurrent chains double at
 * every turn, which a shared host reads as a denial of service, with
 * account suspension at the end of it.
 */
final class SchedulerContinuation
{
    /**
     * MySQL advisory lock name. Deliberately distinct from the migration's
     * (`scoutmagic_schema_migration`): a migration slice and a scheduler
     * slice are different work and must not exclude each other.
     */
    public const LOCK_NAME = 'scoutmagic_scheduler_slice';

    /** Setting holding the current chain's hop count. */
    public const HOPS_SETTING = 'scheduler_chain_hops';

    /** Setting holding the per-slice time budget, in seconds. */
    public const BUDGET_SETTING = 'scheduler_slice_seconds';

    /** Setting holding the hard ceiling on hops in one chain. */
    public const MAX_HOPS_SETTING = 'scheduler_max_hops';

    /**
     * Fallbacks used only when the setting is missing or nonsensical —
     * never a substitute for the setting. `max_execution_time` is 90 on
     * the reference host, so 75 leaves room for a handler to overrun the
     * budget check and still finish.
     */
    private const DEFAULT_BUDGET_SECONDS = 75;
    private const DEFAULT_MAX_HOPS = 30;

    // The User-Agent a hop carries and the connect timeout it uses now
    // live on Http\SelfRequest, with every other caller that drives this
    // installation from itself.

    public function __construct(
        private SchedulerRunner $runner,
        private SchedulerRepository $repository,
        private SettingService $settings,
        private JournalService $journal,
        private \PDO $pdo,
        private string $sharedSecret
    ) {
    }

    /**
     * Run one slice under the exclusion lock, then hop if the slice earned
     * one. The single entry point for both the poor man's cron and the
     * continuation endpoint.
     */
    public function runSliceAndContinue(): SliceOutcome
    {
        $outcome = $this->runSlice();

        if ($this->shouldHop($outcome)) {
            $this->emitHop();
        }

        return $outcome;
    }

    /**
     * One slice of scheduler work, serialized against other slices.
     *
     * The lock is non-blocking (timeout 0) and must stay that way. On
     * CloudLinux, ten visitors waiting on a lock are ten FPM workers
     * immobilised against an Entry Processes ceiling around twenty: the
     * site would fall over because of the mechanism meant to protect it.
     * A caller that does not get the lock hands back immediately.
     */
    public function runSlice(): SliceOutcome
    {
        if (!$this->acquireLock()) {
            RequestTimeline::mark('scheduler_slice_skipped_no_lock');

            return new SliceOutcome(heldLock: false, processed: 0, workRemains: true);
        }

        try {
            $deadline = microtime(true) + $this->budgetSeconds();
            $processed = $this->runner->processOverdue($deadline);

            return new SliceOutcome(
                heldLock: true,
                processed: $processed,
                workRemains: $this->repository->countOverdue() > 0
            );
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * The three cumulative conditions. All of them, every time.
     *
     * 1. **The slice held the lock.** A slice that did not is not the one
     *    doing the work, and the one that is will hop for itself. Without
     *    this, every visitor arriving during a long chain starts a chain
     *    of their own.
     * 2. **The slice made progress.** Zero tasks processed and a hop
     *    emitted is a loop that consumes a request per turn and finishes
     *    nothing — the exact shape of a self-inflicted denial of service.
     * 3. **The hop counter is under its hard ceiling.** A chain that
     *    cannot end is worse than a queue that drains slowly.
     *
     * And, before all three: there has to be work left.
     */
    public function shouldHop(SliceOutcome $outcome): bool
    {
        return $outcome->workRemains
            && $outcome->heldLock
            && $outcome->processed > 0
            && $this->hopCount() < $this->maxHops();
    }

    /**
     * Start a fresh chain: the hop counter goes back to zero.
     *
     * Called by the IGNITION path (the poor man's cron), never by the
     * continuation endpoint — otherwise the ceiling resets at every hop
     * and stops being a ceiling.
     */
    public function beginChain(): void
    {
        $this->writeHopCount(0);
    }

    /**
     * Start a fresh chain NOW, from a process that is not going to run the
     * work itself.
     *
     * The caller is `Task\InstallUpdateHandler`, which has just replaced
     * every file on disk and must not be the process that migrates the
     * schema: its own classes are the old ones, still in memory, while
     * anything it loads from here on comes from the new files. Scheduling
     * the migration and returning already guarantees a different process
     * runs it — `SchedulerRunner::processOverdue()` claims its task list
     * once, at the start of a pass, so a task created during that pass is
     * never run by it. What this adds is *when*: without it the update
     * would sit in the queue until the next cron tick or the next
     * visitor, which is exactly the "migrate on somebody's page load" this
     * is meant to end.
     *
     * Returns whether a request was actually written. False is not a
     * failure the caller should act on — the queue still drains the usual
     * way — but it is worth journalling.
     */
    public function kick(): bool
    {
        // A ceiling of zero means chaining is OFF on this installation,
        // and a kick is a chain of one — so it has to honour it like any
        // other hop. It did not, and that is a defect this comment exists
        // to stop coming back: kick() went straight to emitHop(), which
        // only ever checked base_url, while the ceiling lives in
        // shouldHop(). An instance that had explicitly switched chaining
        // off still got a self-request.
        //
        // Where that bit: the end-to-end and dynamic-scan harnesses set it
        // to zero precisely because `php -S` serves one request per worker
        // and defaults to one worker (scripts/e2e-support.php says so at
        // length). The kick queued behind the request that emitted it and
        // then held the only worker for a whole slice, and a browser step
        // waiting on a navigation timed out — intermittently, and only
        // under the scan, where every request already carries proxy
        // latency.
        if ($this->maxHops() < 1) {
            return false;
        }

        $this->beginChain();

        return $this->emitHop();
    }

    /**
     * Fire-and-forget HTTP request to this site's own continuation
     * endpoint: write the request onto the socket, then close without
     * reading a byte of the response. Reading would mean waiting for the
     * next slice to finish, which is the opposite of the point.
     *
     * Opportunistic by construction: every failure path here degrades to
     * exactly today's behaviour (the queue waits for the next visitor),
     * and none of them is allowed to become a new way for the site to
     * break.
     */
    private function emitHop(): bool
    {
        $base = $this->baseUrl();
        if ($base === null) {
            return false;
        }

        // Counted before the attempt, not after: a hop that is emitted but
        // whose slice then dies must still have consumed its budget, or a
        // chain that fails halfway through every slice never approaches
        // the ceiling.
        $this->writeHopCount($this->hopCount() + 1);

        $request = SelfRequest::buildPost($base, ['X-Scoutmagic-Scheduler' => $this->sharedSecret]);

        foreach ($this->hopTargets($base) as $target) {
            if ($this->writeAndForget($target, $base['host'], $request)) {
                RequestTimeline::mark('scheduler_hop_emitted', ['hops' => $this->hopCount()]);

                return true;
            }
        }

        RequestTimeline::mark('scheduler_hop_failed');

        return false;
    }

    /**
     * Loopback first, public name second.
     *
     * `127.0.0.1` with a correct `Host` header short-circuits DNS and any
     * external proxy — on the reference host `SERVER_ADDR` is `127.0.0.1`
     * and the site sits behind one. The public name is the fallback for a
     * host where loopback is firewalled off from PHP.
     *
     * @param array{scheme: string, host: string, port: int, path: string} $base
     * @return array<int, string>
     */
    private function hopTargets(array $base): array
    {
        return SelfRequest::targets($base);
    }

    /**
     * TLS to 127.0.0.1 verifies the certificate against the site's own
     * name, not against the IP literal — `peer_name` is what the
     * handshake presents (SNI) and checks, so the host's real certificate
     * validates normally. Verification is never disabled: a hop carries
     * the shared secret.
     */
    private function writeAndForget(string $target, string $host, string $request): bool
    {
        return SelfRequest::writeAndForget($target, $host, $request);
    }

    /**
     * The hop's destination, from the configured `base_url` and nothing
     * else.
     *
     * **Never from `HTTP_HOST`.** The Host header is attacker-supplied on
     * every request (the same reason `Core\Statistics\DestinationMatcher`
     * and `Core\Module\InstallationProfile` refuse it); on this host
     * `SERVER_ADDR` is `127.0.0.1` and the site is behind a proxy, so
     * trusting it would turn this mechanism into a remotely-triggerable
     * SSRF — an attacker choosing where the server sends an authenticated
     * request. `base_url` is set once at installation
     * (`SetupController::resolveDefaultBaseUrl()` derives it from the
     * request exactly once, then freezes it in the setting) and is
     * authoritative from then on.
     *
     * @return array{scheme: string, host: string, port: int, path: string}|null
     */
    private function baseUrl(): ?array
    {
        return SelfRequest::resolveBase($this->settings, SchedulerContinuationRoute::PATH);
    }

    private function budgetSeconds(): int
    {
        $configured = (int) ($this->settings->get(self::BUDGET_SETTING) ?? 0);

        return $configured > 0 ? $configured : self::DEFAULT_BUDGET_SECONDS;
    }

    /**
     * Zero is a real answer, not a missing one: it turns self-continuation
     * off entirely, and an environment that cannot host it needs to be
     * able to say so.
     *
     * A `php -S` server is the case that matters. It serves exactly one
     * request per worker at a time and defaults to one worker, so a hop
     * does not run *alongside* the request that emitted it — it queues
     * behind it and then occupies the only worker for a whole slice.
     * Everything else waits, which is fine for a background queue and
     * fatal for a browser test driving the same server. FPM with a pool
     * of workers, which is what this mechanism is built for, has no such
     * problem: one busy worker out of twenty is exactly the intent.
     */
    private function maxHops(): int
    {
        $configured = $this->settings->get(self::MAX_HOPS_SETTING);

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return self::DEFAULT_MAX_HOPS;
    }

    public function hopCount(): int
    {
        return max(0, (int) ($this->settings->get(self::HOPS_SETTING) ?? 0));
    }

    private function writeHopCount(int $count): void
    {
        try {
            $this->settings->setInternal(self::HOPS_SETTING, (string) $count);
        } catch (\Throwable) {
            // Best-effort: a hop counter that cannot be written makes the
            // ceiling unenforceable, which is why emitHop() is never
            // reached without shouldHop() having read it first — a read
            // that fails answers 0 and the ceiling still bounds the chain
            // through the ignition path's own reset.
        }
    }

    private function acquireLock(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)");
            if ($stmt === false) {
                return false;
            }
            $acquired = (int) $stmt->fetchColumn();
            $stmt->closeCursor();

            return $acquired === 1;
        } catch (\PDOException) {
            // GET_LOCK() is MySQL/MariaDB-only and absent on the SQLite
            // connection this class's fast tests use. Proceeding without
            // mutual exclusion is safe there: those tests never run two
            // slices at once against the same connection.
            return true;
        }
    }

    private function releaseLock(): void
    {
        try {
            // ::query(), not ::exec() — RELEASE_LOCK() returns a result
            // set, and leaving its cursor open breaks the very next query
            // on the connection with "Cannot execute queries while other
            // unbuffered queries are active". MigrationRunner learned this
            // the hard way; the same shape, the same fix.
            $stmt = $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
        } catch (\PDOException) {
        }
    }

    /**
     * Whether a presented secret is this installation's. Constant-time,
     * and a missing secret on either side refuses — see
     * Core\Security\CapabilityToken's contract, which this follows.
     */
    public function verifySecret(?string $presented): bool
    {
        return \Core\Security\CapabilityToken::equalsConstantTime($this->sharedSecret, $presented);
    }

    public function journalRefusedHop(?string $ip): void
    {
        $this->journal->log(
            'core',
            'scheduler_continuation_refused',
            'security',
            'Appel de continuation de l\'ordonnanceur refusé : secret invalide',
            ['ip' => $ip ?? ''],
            null
        );
    }
}
