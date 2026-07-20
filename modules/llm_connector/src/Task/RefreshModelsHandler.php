<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\OcrModelSelector;

/**
 * Scheduled task: for each active provider, calls listModels() and upserts
 * into llm_provider_models. Never deletes — only inserts new and updates
 * last_seen_at for existing ones.
 */
class RefreshModelsHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $encryption = $context->encryption;

        $providerRepo = new ProviderRepository($pdo, $encryption);
        $modelRepo = new ProviderModelRepository($pdo);

        $activeProviders = $providerRepo->findAllActive();

        foreach ($activeProviders as $provider) {
            try {
                $driver = $this->createDriver($provider['driver'], $provider['api_endpoint'], $provider['api_key']);
                $models = $driver->listModels();

                $modelIds = [];
                foreach ($models as $model) {
                    $modelRepo->upsert($provider['id'], $model['id'], $model['display_name']);
                    $modelIds[] = $model['id'];
                }

                $ocrSelector = new OcrModelSelector();
                $ocrSelector->setJournalService($context->journal);
                $tierMap = $ocrSelector->selectTiers($driver, $modelIds);
                $modelRepo->autoAssignTiers($provider['id'], $tierMap);

                $context->journal->log(
                    'llm_connector',
                    'models_refreshed',
                    'info',
                    "Scheduled refresh: {$provider['name']} — " . count($models) . ' model(s)',
                    ['provider_id' => $provider['id'], 'model_count' => count($models)],
                    null
                );
            } catch (\Throwable $e) {
                $context->journal->log(
                    'llm_connector',
                    'models_refresh_failed',
                    'info',
                    "Scheduled refresh failed for {$provider['name']}",
                    ['provider_id' => $provider['id'], 'error' => $e->getMessage()],
                    null
                );
            }
        }

        // Schedule next weekly refresh
        $this->scheduleNextWeeklyRefresh($context);
    }

    /**
     * Schedule the next weekly model refresh (7 days from now).
     */
    private function scheduleNextWeeklyRefresh(TaskContext $context): void
    {
        $schedulerRepo = new \Core\Scheduler\SchedulerRepository($context->connection->getPdo());
        $schedulerService = new \Core\Scheduler\SchedulerService($schedulerRepo);

        // Check if a future refresh is already scheduled
        $existing = $schedulerService->find('llm_connector', 'refresh_models', 'weekly');
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            // A future task already exists, don't duplicate
            return;
        }

        // Schedule next run in 7 days
        $nextRun = new \DateTimeImmutable('+7 days');
        $schedulerService->schedule('llm_connector', 'refresh_models', $nextRun, [], 'weekly');
    }

    private function createDriver(string $driver, string $apiEndpoint, string $apiKey): LlmProviderInterface
    {
        return match ($driver) {
            'anthropic' => new AnthropicProvider($apiEndpoint, $apiKey),
            'mistral' => new MistralProvider($apiEndpoint, $apiKey),
            'scaleway' => new ScalewayProvider($apiEndpoint, $apiKey),
            default => throw new \RuntimeException("Unknown driver: {$driver}"),
        };
    }
}
