<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Config\SettingService;
use Core\Scheduler\SchedulerService;
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
 */
class BulkCategorizationService
{
    private const AI_ENABLED_SETTING_KEY = 'ai_categorization_enabled';
    /**
     * Unix timestamp of the currently-claimed run, or '0'. A distinct key
     * from the boolean 'bulk_categorization_running' this replaces: an
     * existing install already carries that row typed as `boolean`, and
     * SettingRepository::upsert() never changes an existing row's type while
     * SettingService::setInternal() validates against it — writing a
     * timestamp there would throw on every upgraded site. The old row simply
     * falls out of use.
     */
    private const RUNNING_SETTING_KEY = 'bulk_categorization_started_at';

    /**
     * The key the one above replaces. Only ever read, never written: an
     * install from before this change already carries that row typed as
     * `boolean`, SettingRepository::upsert() never re-types an existing row,
     * and SettingService::setInternal() validates against the stored type —
     * so writing a timestamp there throws on exactly the sites that have one.
     */
    private const LEGACY_RUNNING_SETTING_KEY = 'bulk_categorization_running';
    private const LAST_RESULT_SETTING_KEY = 'bulk_categorization_last_result';
    private const RUN_TASK_KEY = 'run_categorization_rules';

    /**
     * Ceiling for a background run. The poor man's cron executes this inside
     * a normal web request, whose default max_execution_time (often 30s) is
     * far below what one LLM call per uncategorized movement needs — and
     * exceeding it is a fatal error that skips the finally block below.
     */
    private const RUN_TIME_LIMIT_SECONDS = 1800;

    public function __construct(
        private TransactionRepository $transactionRepository,
        private CategoryRuleEngine $categoryRuleEngine,
        private AiCategorizationService $aiCategorizationService,
        private SettingService $settingService,
        private SchedulerService $schedulerService
    ) {
    }

    public function isAiRuleEnabled(): bool
    {
        return $this->settingService->get(self::AI_ENABLED_SETTING_KEY, 'finance', '0') === '1';
    }

    public function setAiRuleEnabled(bool $enabled): void
    {
        $this->settingService->register(self::AI_ENABLED_SETTING_KEY, '0', 'boolean', 'Règle de catégorisation IA activée', 'Indicateur interne — ne pas modifier.', 'finance', null, null, false);
        $this->settingService->setInternal(self::AI_ENABLED_SETTING_KEY, $enabled ? '1' : '0', 'finance');
    }

    /**
     * How long a "running" marker is trusted before it is treated as
     * abandoned. The flag is cleared by runInBackground()'s finally block,
     * but nothing clears it if the scheduled task is never picked up at all
     * (the poor man's cron depends on a page load — see public/index.php)
     * or if the process dies before that block runs. Without an expiry the
     * config page's "Exécuter les règles" button then stayed disabled for
     * ever, with no way to reset it from the UI. An hour is far longer than
     * any real run: the batch makes one LLM call per uncategorized movement.
     */
    private const RUNNING_STALE_AFTER_SECONDS = 3600;

    /**
     * Whether Task\RunCategorizationRulesHandler is currently queued or
     * running — set the moment Controller\ConfigRuleController schedules
     * the task (before it has actually started, since the poor man's
     * cron may not pick it up for up to a minute — see public/index.php),
     * cleared once runInBackground() finishes, success or failure.
     *
     * A marker older than RUNNING_STALE_AFTER_SECONDS is reported as not
     * running: see that constant for why one can be left behind. The most
     * likely way that happens is not an exception at all — the poor man's
     * cron runs the batch inside an ordinary web request, and exceeding
     * max_execution_time is a fatal error, which skips runInBackground()'s
     * finally block entirely.
     */
    public function isRunning(): bool
    {
        $startedAt = (int) ($this->settingService->get(self::RUNNING_SETTING_KEY, 'finance', '0') ?: '0');
        if ($startedAt > 0) {
            return (time() - $startedAt) < self::RUNNING_STALE_AFTER_SECONDS;
        }

        return $this->hasLegacyRunningMarker();
    }

    /**
     * A bare '1' written under the old key by a version that recorded no
     * timestamp. Still honoured, so upgrading mid-run does not immediately
     * start a second one — but only while the scheduled task it refers to is
     * actually still queued, since the marker carries no expiry of its own.
     */
    private function hasLegacyRunningMarker(): bool
    {
        if ($this->settingService->get(self::LEGACY_RUNNING_SETTING_KEY, 'finance', '0') !== '1') {
            return false;
        }

        return $this->schedulerService->find('finance', self::RUN_TASK_KEY) !== null;
    }

    /**
     * Stores the marker as the UNIX timestamp the run was queued at, so
     * isRunning() can tell a live run from one that never finished.
     */
    public function markRunning(): void
    {
        $this->registerRunningSetting();
        $this->settingService->setInternal(self::RUNNING_SETTING_KEY, (string) time(), 'finance');
    }

    private function registerRunningSetting(): void
    {
        $this->settingService->register(self::RUNNING_SETTING_KEY, '0', 'number', 'Exécution des règles en cours depuis', 'Indicateur interne — ne pas modifier.', 'finance', null, null, false);
    }

    /**
     * Schedules Task\RunCategorizationRulesHandler to run in the
     * background (picked up by the poor man's cron — public/index.php) —
     * the single entry point both Controller\ConfigRuleController's
     * button and Service\ImportService (right after a successful import)
     * go through, so the "already running" guard only has to live in one
     * place. A no-op, returning false, when a run is already queued or in
     * progress — never stacks duplicate runs; the caller decides whether
     * that's worth surfacing as an error (the button does) or silently
     * ignoring (an import does — the next run, whenever it happens, will
     * still pick up its newly-imported movements).
     */
    public function scheduleBackgroundRun(): bool
    {
        if ($this->isRunning()) {
            return false;
        }
        $this->markRunning();
        $this->schedulerService->scheduleAfter('finance', self::RUN_TASK_KEY, 0);
        return true;
    }

    /**
     * The outcome of the last completed run, or null before the first
     * one ever finishes — Controller\ConfigRuleController's polling
     * endpoint for the config page's "en cours" indicator.
     *
     * @return array{categorized_by_rules: int, categorized_by_ai: int, still_uncategorized: int}|null
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
     * Task\RunCategorizationRulesHandler's entry point — runs the same
     * logic as runOnUncategorized(), then records the result and clears
     * the running flag, whatever the outcome (a mid-run crash must never
     * leave the config page's button stuck disabled forever).
     */
    public function runInBackground(): void
    {
        // Same reasoning as Core\View\RgpdContentService::generateWithAi():
        // max_execution_time is a hard script timeout, not a catchable
        // exception, so it must be raised before the work rather than
        // handled after it.
        $previousLimit = (int) ini_get('max_execution_time');
        @set_time_limit(self::RUN_TIME_LIMIT_SECONDS);

        try {
            $result = $this->runOnUncategorized();
            $this->storeLastResult($result);
        } finally {
            $this->clearRunning();
            @set_time_limit($previousLimit);
        }
    }

    /**
     * Public so a stuck lock can also be released deliberately — see
     * Controller\ConfigRuleController's "run_status" branch, which reports
     * the lock as free once it has expired.
     */
    public function clearRunning(): void
    {
        $this->registerRunningSetting();
        $this->settingService->setInternal(self::RUNNING_SETTING_KEY, '0', 'finance');

        // Retire the legacy marker too, so a site upgraded mid-run stops
        // falling back to it once this run has finished. '0' is a valid
        // value whichever type that row happens to carry — only a timestamp
        // would fail validation on a `boolean` row, which is precisely why
        // the timestamp lives under its own key.
        $this->settingService->register(self::LEGACY_RUNNING_SETTING_KEY, '0', 'boolean', 'Exécution des règles en cours (obsolète)', 'Indicateur interne — ne pas modifier.', 'finance', null, null, false);
        $this->settingService->setInternal(self::LEGACY_RUNNING_SETTING_KEY, '0', 'finance');
    }

    private function storeLastResult(BulkCategorizationResult $result): void
    {
        $this->settingService->register(self::LAST_RESULT_SETTING_KEY, '', 'text', 'Résultat de la dernière exécution des règles', 'Indicateur interne — ne pas modifier.', 'finance', null, null, false);
        $this->settingService->setInternal(self::LAST_RESULT_SETTING_KEY, json_encode([
            'categorized_by_rules' => $result->categorizedByRules,
            'categorized_by_ai' => $result->categorizedByAi,
            'still_uncategorized' => $result->stillUncategorized,
        ]), 'finance');
    }

    public function runOnUncategorized(): BulkCategorizationResult
    {
        $aiEnabled = $this->isAiRuleEnabled() && $this->aiCategorizationService->isAvailable();

        $byRules = 0;
        $byAi = 0;
        $stillUncategorized = 0;

        foreach ($this->transactionRepository->findAllUncategorized() as $transaction) {
            $categoryId = $this->categoryRuleEngine->applyToTransaction($transaction);
            if ($categoryId !== null) {
                $this->transactionRepository->setCategoryId($transaction->id, $categoryId);
                $byRules++;
                continue;
            }

            if ($aiEnabled) {
                // One unusable movement must never abort the whole batch.
                // AiCategorizationService::categorize() only catches
                // LlmException, and a movement can fail in other ways — a
                // label carrying non-UTF-8 bytes from a Latin-1 bank export,
                // for one. Everything before this point has already been
                // committed, so the batch continues with the next movement.
                try {
                    $aiCategoryId = $this->aiCategorizationService->categorize($transaction);
                } catch (\Throwable) {
                    $aiCategoryId = null;
                }

                if ($aiCategoryId !== null) {
                    $this->transactionRepository->setCategoryId($transaction->id, $aiCategoryId);
                    $byAi++;
                    continue;
                }
            }

            $stillUncategorized++;
        }

        return new BulkCategorizationResult($byRules, $byAi, $stillUncategorized);
    }
}
