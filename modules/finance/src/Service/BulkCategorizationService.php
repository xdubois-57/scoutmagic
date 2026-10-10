<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Backs the config page's "Exécuter les règles sur les mouvements non
 * catégorisés" button, and — via scheduleBackgroundRun() — is also
 * triggered automatically right after a statement import (Service\
 * ImportService). Regular rules are cheap and already run inline on
 * every import (Service\CategoryRuleEngine::apply(), per line); the AI
 * rule makes a real LLM call per movement, which would make a routine
 * import slow and costly if it ran there too, so it — and any movement
 * a regular rule didn't already catch — always goes through this
 * background path instead, never inline.
 *
 * **A run is walked in short, resumable batches (issue #839).** It used to
 * be one scheduled task that categorized every movement in a single
 * process — up to one LLM call each — and a process the host killed
 * mid-way skipped every way out: the task row stayed `processing` for the
 * scheduler's six hours, the running marker for its own hour, and the
 * config page sat on « Exécution en arrière-plan… » with half the
 * movements done. Now:
 *
 * - **A run is explicit** ({@see self::RUN_SETTING_KEY}): an id, the
 *   highest uncategorized id at the moment it started (a statement
 *   imported mid-run cannot make the target endless), a cursor and the
 *   running counts. It is written after every movement, so a kill loses
 *   at most the one in flight.
 * - **Each batch arms its own continuation before doing any work.**
 *   Core\Scheduler\CronPassLock keeps that continuation from running
 *   beside the batch that armed it; if the batch dies, the next cron pass
 *   finds the continuation and carries on from the cursor.
 * - **A batch is short** — {@see self::BATCH_SIZE} movements or
 *   {@see self::BATCH_TIME_BUDGET_SECONDS}, whichever comes first.
 * - **A run nobody is carrying any more is noticed within minutes**, not
 *   an hour: {@see self::repairIfAbandoned()}.
 */
class BulkCategorizationService
{
    private const AI_ENABLED_SETTING_KEY = 'ai_categorization_enabled';

    /**
     * The current — or last — run, as JSON: see {@see self::startRun()}
     * for its fields. Its own key, typed `text`, because the two markers
     * it replaces are typed `number` and `boolean` on the installations
     * that already have them, and SettingService::setInternal() validates
     * a write against the stored type (SettingRepository::upsert() never
     * re-types a row).
     */
    private const RUN_SETTING_KEY = 'bulk_categorization_run';

    /**
     * The two markers a run used to be: a timestamp, and before that a
     * boolean. Only ever cleared now, so an installation upgraded while
     * one of them was set does not carry a stale value for ever.
     */
    private const LEGACY_STARTED_AT_SETTING_KEY = 'bulk_categorization_started_at';
    private const LEGACY_RUNNING_SETTING_KEY = 'bulk_categorization_running';

    private const LAST_RESULT_SETTING_KEY = 'bulk_categorization_last_result';
    private const RUN_TASK_KEY = 'run_categorization_rules';

    /**
     * Movements per batch. Small on purpose: with the AI rule on, each one
     * is an LLM call, and a batch is the unit a killed process loses.
     */
    public const BATCH_SIZE = 25;

    /**
     * How long one batch may keep starting new movements — checked
     * between movements, never in the middle of one. Well under any
     * plausible host limit: the run reported in #839 died after about
     * four and a half minutes. One LLM call is bounded by its own HTTP
     * timeout (30 s for Scaleway), so a batch overruns this by at most
     * that much.
     */
    public const BATCH_TIME_BUDGET_SECONDS = 60;

    /**
     * How long a run that has not moved its cursor, and has no queued
     * continuation, is still believed to be inside a batch. A batch writes
     * the run after every movement, and a movement is bounded by the LLM
     * call's HTTP timeout, so three minutes of silence means the process
     * that held the batch is gone.
     */
    private const LIVENESS_SECONDS = 180;

    /**
     * A run that has made no progress for this long is given up rather
     * than resumed once more — a batch that fails every time (the database
     * refusing it, say) would otherwise be re-armed for ever. The result so
     * far is kept, and the button is free again.
     */
    private const ABANDON_AFTER_SECONDS = 1800;

    /**
     * The same safety net for the path no human watches: the scheduler
     * carrying the run on its own. A batch counts itself before it starts
     * and the count drops back to zero the moment one movement is done, so
     * this many batches in a row that moved nothing — each claimed, each
     * failing before its first movement — end the run as abandoned. Counted
     * in batches rather than seconds because how far apart two batches run
     * depends on the host's cron, not on the run.
     */
    private const MAX_STALLED_BATCHES = 5;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock the current UNIX time, with
     *     microseconds — `microtime(true)` unless a test needs to move it
     */
    public function __construct(
        private TransactionRepository $transactionRepository,
        private CategoryRuleEngine $categoryRuleEngine,
        private AiCategorizationService $aiCategorizationService,
        private SettingService $settingService,
        private SchedulerService $schedulerService,
        private ?JournalService $journal = null,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    public function isAiRuleEnabled(): bool
    {
        return $this->settingService->get(self::AI_ENABLED_SETTING_KEY, 'finance', '0') === '1';
    }

    public function setAiRuleEnabled(bool $enabled): void
    {
        $this->settingService->register(
            self::AI_ENABLED_SETTING_KEY,
            '0',
            'boolean',
            'Règle de catégorisation IA activée',
            'Indicateur interne — ne pas modifier.',
            'finance',
            null,
            null,
            false
        );
        $this->settingService->setInternal(self::AI_ENABLED_SETTING_KEY, $enabled ? '1' : '0', 'finance');
    }

    /**
     * Whether a run is under way — queued, inside a batch, or between two.
     */
    public function isRunning(): bool
    {
        return ($this->loadRun()['status'] ?? null) === 'running';
    }

    /**
     * What the config page's poll shows — Controller\ConfigRuleController's
     * `run_status`. Asking is also what repairs a run nobody carries any
     * more ({@see self::repairIfAbandoned()}), so a page left open on a
     * dead run sees it resume, or end, within a few polls.
     *
     * @return array{running: bool, resumed: bool, processed: int, target: int,
     *     categorized_by_rules: int, categorized_by_ai: int, still_uncategorized: int}
     */
    public function status(): array
    {
        $resumed = $this->repairIfAbandoned();
        $run = $this->loadRun();

        return [
            'running' => ($run['status'] ?? null) === 'running',
            'resumed' => $resumed,
            'processed' => (int) ($run['processed'] ?? 0),
            'target' => (int) ($run['target'] ?? 0),
            'categorized_by_rules' => (int) ($run['by_rules'] ?? 0),
            'categorized_by_ai' => (int) ($run['by_ai'] ?? 0),
            'still_uncategorized' => (int) ($run['still'] ?? 0),
        ];
    }

    /**
     * Starts a run in the background (picked up by the next cron pass —
     * public/cron.php) — the single entry point both Controller\
     * ConfigRuleController's button and Service\ImportService (right after
     * a successful import) go through. Returns false when a run is already
     * under way: the button says so, an import says nothing (the run in
     * progress, or the next one, will reach its movements).
     *
     * Two clicks a moment apart cannot both start one: the run is claimed
     * with a compare-and-swap on its setting, so the second sees the first
     * one's run and stands down.
     */
    public function scheduleBackgroundRun(): bool
    {
        $this->repairIfAbandoned();

        return $this->startRun() !== null;
    }

    /**
     * The outcome of the last completed run, or null before the first
     * one ever finishes.
     *
     * `abandoned`, `processed` and `target` are absent from a result
     * stored before they existed.
     *
     * @return array{categorized_by_rules: int, categorized_by_ai: int, still_uncategorized: int,
     *     abandoned?: bool, processed?: int, target?: int}|null
     */
    public function getLastResult(): ?array
    {
        $json = $this->settingService->get(self::LAST_RESULT_SETTING_KEY, 'finance');
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Task\RunCategorizationRulesHandler's entry point: one batch of the
     * run the payload names.
     *
     * A row with no `run_id` was queued by a version from before runs
     * existed; it starts one, so an installation upgraded with that row
     * still queued loses nothing. A row whose run is no longer the current
     * one, or no longer running, is a leftover and does nothing.
     *
     * @param array<string, mixed> $payload
     */
    public function runBatch(array $payload): void
    {
        $runId = is_string($payload['run_id'] ?? null) ? $payload['run_id'] : null;
        if ($runId === null) {
            $run = $this->startRun();
            if ($run === null) {
                return;
            }
            $runId = $run['run_id'];
        }

        $run = $this->loadRun();
        if ($run === null || $run['run_id'] !== $runId || $run['status'] !== 'running') {
            return;
        }

        // A batch that keeps failing before its first movement is not
        // re-armed for ever: give up once enough of them moved nothing.
        $run['stalled_batches'] = (int) ($run['stalled_batches'] ?? 0) + 1;
        if ($run['stalled_batches'] > self::MAX_STALLED_BATCHES) {
            $this->finishRun($run, 'abandoned');

            return;
        }
        $this->saveRun($run);

        // FIRST, before anything that can take time: the continuation. If
        // this process is killed half-way, that row is what the next pass
        // finds. CronPassLock keeps it from running beside this batch.
        $this->armContinuation($runId);
        $this->log('categorization_batch_started', 'Lot de catégorisation commencé', $run);

        $aiEnabled = $this->isAiRuleEnabled() && $this->aiCategorizationService->isAvailable();
        $deadline = ($this->clock)() + self::BATCH_TIME_BUDGET_SECONDS;
        $batch = $this->transactionRepository->findUncategorizedAfter(
            (int) $run['cursor'],
            (int) $run['max_id'],
            self::BATCH_SIZE
        );

        $walkedAll = true;
        foreach ($batch as $index => $transaction) {
            if ($index > 0 && ($this->clock)() >= $deadline) {
                $walkedAll = false;
                break;
            }

            $outcome = $this->categorize($transaction, $aiEnabled);
            $run['cursor'] = $transaction->id;
            $run['stalled_batches'] = 0;
            $run['processed'] = (int) $run['processed'] + 1;
            $run[$outcome] = (int) $run[$outcome] + 1;
            $run['updated_at'] = (int) ($this->clock)();
            $this->saveRun($run);
        }

        $this->log('categorization_batch_done', 'Lot de catégorisation terminé', $run);

        if ($walkedAll && count($batch) < self::BATCH_SIZE) {
            $this->finishRun($run, 'done');
        }
    }

    /**
     * Runs every batch here and now, to the end, and returns the totals —
     * for a caller that is itself already off the request path (the
     * reference dataset's seeder) and for tests. The config page never
     * calls this; it goes through scheduleBackgroundRun().
     */
    public function runOnUncategorized(): BulkCategorizationResult
    {
        $this->repairIfAbandoned();
        $run = $this->startRun() ?? $this->loadRun();
        if ($run === null) {
            return new BulkCategorizationResult(0, 0, 0);
        }

        $payload = ['run_id' => $run['run_id']];
        while (($current = $this->loadRun()) !== null && $current['status'] === 'running') {
            $this->runBatch($payload);
            $after = $this->loadRun();
            // A batch that moved nothing and did not end the run would
            // be run again identically for ever — stop instead.
            if ($after !== null && $after['status'] === 'running' && $after['cursor'] === $current['cursor']) {
                break;
            }
        }

        $final = $this->loadRun() ?? $run;

        return new BulkCategorizationResult(
            (int) $final['by_rules'],
            (int) $final['by_ai'],
            (int) $final['still']
        );
    }

    /**
     * A run said to be running that nothing is carrying any more: no
     * continuation queued, and its cursor still for longer than one batch
     * can stay silent. That is a batch killed in the window before it armed
     * its continuation, or a continuation somebody cancelled. It is
     * resumed — or, when it has made no progress for
     * {@see self::ABANDON_AFTER_SECONDS}, given up, keeping what it did.
     *
     * Returns whether it resumed one.
     */
    public function repairIfAbandoned(): bool
    {
        $run = $this->loadRun();
        if ($run === null || $run['status'] !== 'running') {
            return false;
        }

        $now = (int) ($this->clock)();
        $silentFor = $now - (int) $run['updated_at'];
        if ($silentFor >= self::ABANDON_AFTER_SECONDS) {
            $this->finishRun($run, 'abandoned');

            return false;
        }

        if ($silentFor < self::LIVENESS_SECONDS
            || $this->schedulerService->find('finance', self::RUN_TASK_KEY, $this->reference($run['run_id'])) !== null
        ) {
            return false;
        }

        $this->armContinuation($run['run_id']);
        $this->log('categorization_run_resumed', 'Catégorisation interrompue reprise', $run, 'warning');

        return true;
    }

    /**
     * @return 'by_rules'|'by_ai'|'still'
     */
    private function categorize(Transaction $transaction, bool $aiEnabled): string
    {
        $categoryId = $this->categoryRuleEngine->applyToTransaction($transaction);
        if ($categoryId !== null) {
            $this->transactionRepository->setCategoryId($transaction->id, $categoryId);

            return 'by_rules';
        }

        if (!$aiEnabled) {
            return 'still';
        }

        // One unusable movement must never abort the batch.
        // AiCategorizationService::categorize() only catches LlmException,
        // and a movement can fail in other ways — a label carrying
        // non-UTF-8 bytes from a Latin-1 bank export, for one. The cursor
        // moves past it all the same.
        try {
            $aiCategoryId = $this->aiCategorizationService->categorize($transaction);
        } catch (\Throwable) {
            $aiCategoryId = null;
        }

        if ($aiCategoryId === null) {
            return 'still';
        }

        $this->transactionRepository->setCategoryId($transaction->id, $aiCategoryId);

        return 'by_ai';
    }

    /**
     * Claims a new run, or returns null when one is already under way —
     * the compare-and-swap is what makes two simultaneous starts yield
     * exactly one run.
     *
     * Fields: `run_id`; `status` (running, done, abandoned); `max_id`, the
     * bound frozen now; `target`, how many movements that bound covers;
     * `cursor`, the last id walked; `processed`, `by_rules`, `by_ai`,
     * `still`; `started_at` and `updated_at`, UNIX seconds.
     *
     * @return array<string, mixed>|null
     */
    private function startRun(): ?array
    {
        $this->registerRunSetting();
        $current = (string) ($this->settingService->get(self::RUN_SETTING_KEY, 'finance', '') ?? '');
        $decoded = $current !== '' ? json_decode($current, true) : null;
        if (is_array($decoded) && ($decoded['status'] ?? null) === 'running') {
            return null;
        }

        $now = (int) ($this->clock)();
        $maxId = $this->transactionRepository->findMaxUncategorizedId();
        $run = [
            'run_id' => bin2hex(random_bytes(8)),
            'status' => 'running',
            'max_id' => $maxId,
            'target' => $maxId > 0 ? $this->transactionRepository->countUncategorizedUpTo($maxId) : 0,
            'cursor' => 0,
            'processed' => 0,
            'by_rules' => 0,
            'by_ai' => 0,
            'still' => 0,
            'started_at' => $now,
            'updated_at' => $now,
            'stalled_batches' => 0,
        ];

        $claimed = $this->settingService->replaceIfUnchanged(
            self::RUN_SETTING_KEY,
            $current,
            $this->encode($run),
            'finance'
        );
        if (!$claimed) {
            return null;
        }

        $this->clearLegacyMarkers();
        $this->schedulerService->schedule(
            'finance',
            self::RUN_TASK_KEY,
            new \DateTimeImmutable(),
            ['run_id' => $run['run_id']],
            $this->reference($run['run_id'])
        );
        $this->log('categorization_run_started', 'Catégorisation en masse commencée', $run);

        return $run;
    }

    /**
     * @param array<string, mixed> $run
     */
    private function finishRun(array $run, string $status): void
    {
        $run['status'] = $status;
        $run['updated_at'] = (int) ($this->clock)();
        $this->saveRun($run);
        $this->schedulerService->cancelPending('finance', self::RUN_TASK_KEY, $this->reference($run['run_id']));
        $this->storeLastResult($run);
        $this->log(
            $status === 'done' ? 'categorization_run_done' : 'categorization_run_abandoned',
            $status === 'done'
                ? 'Catégorisation en masse terminée'
                : 'Catégorisation en masse abandonnée faute de progrès',
            $run,
            $status === 'done' ? 'info' : 'warning'
        );
    }

    private function armContinuation(string $runId): void
    {
        $this->schedulerService->rearmAfter(
            'finance',
            self::RUN_TASK_KEY,
            $this->reference($runId),
            0,
            ['run_id' => $runId]
        );
    }

    private function reference(string $runId): string
    {
        return 'run:' . $runId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRun(): ?array
    {
        $json = $this->settingService->get(self::RUN_SETTING_KEY, 'finance', '');
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) && is_string($decoded['run_id'] ?? null) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $run
     */
    private function saveRun(array $run): void
    {
        $this->registerRunSetting();
        $this->settingService->setInternal(self::RUN_SETTING_KEY, $this->encode($run), 'finance');
    }

    /**
     * @param array<string, mixed> $run
     */
    private function encode(array $run): string
    {
        return (string) json_encode($run);
    }

    private function registerRunSetting(): void
    {
        $this->settingService->register(
            self::RUN_SETTING_KEY,
            '',
            'text',
            'Catégorisation en masse en cours',
            'Indicateur interne — ne pas modifier.',
            'finance',
            null,
            null,
            false
        );
    }

    /**
     * '0' is valid whichever type the old row carries — `number` for the
     * timestamp, `boolean` for the flag before it.
     */
    private function clearLegacyMarkers(): void
    {
        foreach ([self::LEGACY_STARTED_AT_SETTING_KEY, self::LEGACY_RUNNING_SETTING_KEY] as $key) {
            if ($this->settingService->get($key, 'finance') !== null) {
                $this->settingService->setInternal($key, '0', 'finance');
            }
        }
    }

    /**
     * @param array<string, mixed> $run
     */
    private function storeLastResult(array $run): void
    {
        $this->settingService->register(
            self::LAST_RESULT_SETTING_KEY,
            '',
            'text',
            'Résultat de la dernière exécution '
                . 'des règles',
            'Indicateur interne — ne pas modifier.',
            'finance',
            null,
            null,
            false
        );
        $this->settingService->setInternal(
            self::LAST_RESULT_SETTING_KEY,
            (string) json_encode([
                'categorized_by_rules' => (int) $run['by_rules'],
                'categorized_by_ai' => (int) $run['by_ai'],
                'still_uncategorized' => (int) $run['still'],
                // An abandoned run's counters cover only what it walked
                // before giving up: the page has to say it stopped short,
                // not « terminée ».
                'abandoned' => ($run['status'] ?? null) === 'abandoned',
                'processed' => (int) $run['processed'],
                'target' => (int) $run['target'],
            ]),
            'finance'
        );
    }

    /**
     * Counters and the cursor only — never a label, a prompt or what the
     * model answered (issue #839).
     *
     * @param array<string, mixed> $run
     */
    private function log(string $type, string $description, array $run, string $level = 'info'): void
    {
        $this->journal?->log('finance', $type, $level, $description, [
            'run_id' => $run['run_id'],
            'cursor' => (int) $run['cursor'],
            'max_id' => (int) $run['max_id'],
            'processed' => (int) $run['processed'],
            'target' => (int) $run['target'],
            'by_rules' => (int) $run['by_rules'],
            'by_ai' => (int) $run['by_ai'],
            'still' => (int) $run['still'],
        ]);
    }
}
