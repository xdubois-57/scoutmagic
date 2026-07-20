<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;

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

                // Auto-assign tiers based on driver logic
                $tierMap = $driver->resolveTiers($modelIds);
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
    }

    private function createDriver(string $driver, string $apiEndpoint, string $apiKey): LlmProviderInterface
    {
        return match ($driver) {
            'anthropic' => new AnthropicProvider($apiEndpoint, $apiKey),
            'mistral' => new MistralProvider($apiEndpoint, $apiKey),
            default => throw new \RuntimeException("Unknown driver: {$driver}"),
        };
    }
}
