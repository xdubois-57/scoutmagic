<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Config\SettingService;
use Modules\Finance\Repository\TransactionRepository;

/**
 * The config page's "Exécuter les règles sur les mouvements non
 * catégorisés" button — a manual, on-demand backfill, never run
 * automatically at import time (module spec follow-up): regular rules
 * are cheap and already run on every import, but the AI rule makes a
 * real LLM call per movement, which would make a routine statement
 * import slow and costly if it ran there too. Regular rules are tried
 * first for every uncategorized movement (fast, no I/O); only a
 * movement none of them matched, and only while the AI rule setting is
 * on, reaches AiCategorizationService.
 */
class BulkCategorizationService
{
    private const AI_ENABLED_SETTING_KEY = 'ai_categorization_enabled';

    public function __construct(
        private TransactionRepository $transactionRepository,
        private CategoryRuleEngine $categoryRuleEngine,
        private AiCategorizationService $aiCategorizationService,
        private SettingService $settingService
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
