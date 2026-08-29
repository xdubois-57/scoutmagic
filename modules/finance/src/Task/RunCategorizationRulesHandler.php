<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AiCategorizationService;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\LlmConnector\Api\LlmConnectorInterface;

/**
 * Runs Controller\ConfigCategoryController's "Exécuter les règles sur les
 * mouvements non catégorisés" in the background (module spec follow-up)
 * — scheduled with a 0-second delay right when the config page's button
 * is clicked (Controller\ConfigRuleController), then actually picked up
 * by the "poor man's cron" the next time any page load is more than a
 * minute after the last one (public/index.php) — same async pattern as
 * Task\ExtractReceiptDataHandler, just for a potentially much longer-
 * running batch (one LLM call per uncategorized movement when the AI
 * rule is on), which is exactly why this must never run inline within
 * the request that clicked the button.
 */
class RunCategorizationRulesHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $transactionRepository = new TransactionRepository($pdo, $context->encryption);
        $categoryRuleRepository = new CategoryRuleRepository($pdo);
        $categoryRepository = new CategoryRepository($pdo);
        $ruleEngine = new CategoryRuleEngine($transactionRepository, $categoryRuleRepository);

        // The connector is a capability (ARCHITECTURE.md §7.5 on the
        // scheduled path): null — llm_connector absent or disabled —
        // means AiCategorizationService reports unavailable, keyword
        // rules still apply, and nothing fails.
        $llmConnector = $context->getOptional(LlmConnectorInterface::class);

        $aiCategorizationService = new AiCategorizationService(
            $llmConnector,
            $categoryRepository,
            new AiCategorySuggestionRepository($pdo),
            $context->journal,
            new AccountRepository($pdo, $context->encryption),
            new TransactionAttachmentRepository($pdo),
            new AttachmentRepository($pdo, $context->encryption),
            // The calendar's published read Api, as a capability — null
            // when that module is disabled, and the prompt then carries
            // no activity-date hints (ARCHITECTURE.md §7.5).
            $context->getOptional(CalendarEventLookupInterface::class)
        );

        $bulkCategorizationService = new BulkCategorizationService(
            $transactionRepository, $ruleEngine, $aiCategorizationService, new SettingService(new SettingRepository($pdo)),
            new SchedulerService(new SchedulerRepository($pdo))
        );

        $bulkCategorizationService->runInBackground();
    }
}
