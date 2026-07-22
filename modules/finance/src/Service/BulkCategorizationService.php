<?php

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
    private const RUNNING_SETTING_KEY = 'bulk_categorization_running';
    private const LAST_RESULT_SETTING_KEY = 'bulk_categorization_last_result';
    private const RUN_TASK_KEY = 'run_categorization_rules';

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
     * Whether Task\RunCategorizationRulesHandler is currently queued or
     * running — set the moment Controller\ConfigRuleController schedules
     * the task (before it has actually started, since the poor man's
     * cron may not pick it up for up to a minute — see public/index.php),
     * cleared once runInBackground() finishes, success or failure.
     */
    public function isRunning(): bool
    {
        return $this->settingService->get(self::RUNNING_SETTING_KEY, 'finance', '0') === '1';
    }

    public function markRunning(): void
    {
        $this->settingService->register(self::RUNNING_SETTING_KEY, '0', 'boolean', 'Exécution des règles en cours', 'Indicateur interne — ne pas modifier.', 'finance', null, null, false);
        $this->settingService->setInternal(self::RUNNING_SETTING_KEY, '1', 'finance');
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
        try {
            $result = $this->runOnUncategorized();
            $this->storeLastResult($result);
        } finally {
            $this->settingService->register(self::RUNNING_SETTING_KEY, '0', 'boolean', 'Exécution des règles en cours', 'Indicateur interne — ne pas modifier.', 'finance', null, null, false);
            $this->settingService->setInternal(self::RUNNING_SETTING_KEY, '0', 'finance');
        }
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
                $aiCategoryId = $this->aiCategorizationService->categorize($transaction);
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
