<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

use Core\Config\SettingService;
use Core\Debug\RequestTimeline;
use Core\Http\SelfRequest;

/**
 * Makes a pending schema migration finish itself, with nobody watching.
 *
 * **The hole this closes.** `public/index.php` short-circuits every
 * request while a migration is pending: anything that is not
 * `POST /api/system/migration-step` gets the progress page and exits,
 * before routing, on purpose — visitors must not reach a half-migrated
 * site. The consequence went unnoticed until production showed it: that
 * short-circuit also swallows the scheduler. `Scheduler\SchedulerKick::
 * now()`, added precisely so the migration would not wait for a visitor,
 * emits an HTTP hop into this same site — and that hop gets the progress
 * page like everything else. On an installation with no real crontab (the
 * reference host has never run `public/cron.php` once), the only engine
 * left was a human's browser sitting on the progress page.
 *
 * Measured on 2026-08-30, update `dev-3fdd425 → dev-00804d3`: the install
 * task finished in 7.3 s at 18:57:49, having queued the migration resume
 * task for 18:57:48. That task ran at **19:28:04** — thirty minutes and
 * sixteen seconds late, and by then `UpdateHistoryRepository::
 * STALE_AFTER_MINUTES` had already marked the update failed. The whole
 * queue was frozen for those thirty minutes; fifty tasks drained in the
 * same second once a visitor finally got through.
 *
 * **The fix, and why it is shaped like this.** Not a detached CLI process
 * (`disable_functions` covers every spawn primitive on the reference host,
 * and `kill_orphaned_php` covers the rest), not a resident worker, and not
 * a dependency on a real crontab — the three things this project has ruled
 * out. What is left is the mechanism already proven here: an HTTP request
 * from the installation to itself. The blocked request that would have
 * shown the progress page to nobody now also starts a chain of hops onto
 * the one endpoint that *is* reachable during the window, and each slice
 * that leaves work behind emits the next hop.
 *
 * Everything degrades to exactly the previous behaviour: no `base_url`, a
 * refused socket, a ceiling of zero — the migration still advances on the
 * progress page's own polling, as it always did.
 */
final class MigrationChain
{
    /** The one path reachable while a migration is pending. */
    public const STEP_PATH = '/api/system/migration-step';

    /**
     * The header `public/index.php` requires on that endpoint. A custom
     * header cannot be set cross-origin on a simple request without a
     * preflight the endpoint never grants, which is what stands in for a
     * CSRF token there — see the endpoint's own comment.
     */
    public const STEP_HEADER = 'X-ScoutMagic-Migration';

    /** Setting holding the current chain's hop count. */
    public const HOPS_SETTING = 'migration_chain_hops';

    /** Setting holding the unix timestamp of the last hop emitted. */
    public const LAST_HOP_SETTING = 'migration_chain_last_hop_at';

    /** Setting holding the hard ceiling on hops in one chain. */
    public const MAX_HOPS_SETTING = 'migration_chain_max_hops';

    /**
     * Slices are five seconds, so this is a little over an hour of
     * migration work in one chain — far beyond the largest migration ever
     * measured here (831 s in total, and that one included the download
     * and the backup), while still being finite. A chain that hits the
     * ceiling stops; the next blocked request starts a fresh one once the
     * staleness window below has passed.
     */
    private const DEFAULT_MAX_HOPS = 800;

    /**
     * How long without a hop before the chain counts as dead and a blocked
     * request may start a new one.
     *
     * This is what keeps the mechanism self-healing. Without it, a chain
     * killed mid-flight — the worker recycled, the host restarted, a slice
     * that threw — would leave the hop counter above zero forever and no
     * later request would ever start another. Comfortably longer than a
     * slice (5 s) plus the time to reach the next request.
     */
    private const CHAIN_STALE_AFTER_SECONDS = 120;

    public function __construct(private SettingService $settings)
    {
    }

    /**
     * Called from the request that is about to be shown the progress page.
     *
     * Starts a chain only when none is running, so a burst of blocked
     * requests produces one chain rather than one per request. Two racing
     * requests can still both start one; that costs a duplicate slice,
     * which `MigrationRunner`'s own `GET_LOCK(..., 0)` turns into a no-op,
     * and both chains remain individually bounded by the ceiling.
     */
    public function ensureRunning(): bool
    {
        if (!$this->chainLooksDead()) {
            return false;
        }

        $this->write(self::HOPS_SETTING, '0');

        return $this->emitHop();
    }

    /**
     * Called from the migration-step endpoint once its slice has run and
     * left work behind. This is what makes the chain a chain: the ignition
     * above only ever emits one hop.
     */
    public function continueChain(): bool
    {
        return $this->emitHop();
    }

    /**
     * Called once the migration is done, so the next one starts from a
     * clean counter instead of inheriting this one's.
     */
    public function finished(): void
    {
        if ($this->hopCount() !== 0) {
            $this->write(self::HOPS_SETTING, '0');
        }
    }

    public function hopCount(): int
    {
        return max(0, (int) ($this->settings->get(self::HOPS_SETTING) ?? 0));
    }

    /**
     * Zero is a real answer, not a missing one: it turns the chain off
     * entirely, for an environment that cannot host it — a `php -S` server
     * serving one request at a time is the case that matters, exactly as
     * for `Scheduler\SchedulerContinuation`.
     */
    public function maxHops(): int
    {
        $configured = $this->settings->get(self::MAX_HOPS_SETTING);

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return self::DEFAULT_MAX_HOPS;
    }

    private function chainLooksDead(): bool
    {
        if ($this->hopCount() === 0) {
            return true;
        }

        $last = (int) ($this->settings->get(self::LAST_HOP_SETTING) ?? 0);

        return $last <= 0 || (time() - $last) > self::CHAIN_STALE_AFTER_SECONDS;
    }

    private function emitHop(): bool
    {
        if ($this->hopCount() >= $this->maxHops()) {
            RequestTimeline::mark('migration_hop_ceiling_reached', ['hops' => $this->hopCount()]);

            return false;
        }

        // Counted before the attempt, not after: a hop that is emitted but
        // whose slice then dies must still have consumed its budget, or a
        // chain failing halfway through every slice never approaches the
        // ceiling. Same rule as the scheduler's.
        $this->write(self::HOPS_SETTING, (string) ($this->hopCount() + 1));
        $this->write(self::LAST_HOP_SETTING, (string) time());

        $emitted = SelfRequest::post($this->settings, self::STEP_PATH, [self::STEP_HEADER => '1']);
        RequestTimeline::mark($emitted ? 'migration_hop_emitted' : 'migration_hop_failed', [
            'hops' => $this->hopCount(),
        ]);

        return $emitted;
    }

    private function write(string $key, string $value): void
    {
        try {
            $this->settings->setInternal($key, $value);
        } catch (\Throwable) {
            // Best-effort, exactly like the scheduler's hop counter: a
            // counter that cannot be written makes the ceiling
            // unenforceable, and the progress page's own polling remains
            // the fallback it has always been.
        }
    }
}
