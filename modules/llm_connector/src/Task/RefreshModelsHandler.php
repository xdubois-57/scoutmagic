<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Provider\ModelProbeCache;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\OcrModelSelector;
use Modules\LlmConnector\Service\ProviderCatalogRefresher;

/**
 * Refresh the compatible catalog and tiers atomically for each active provider.
 * Removed or incompatible models are pruned, including legacy assignments.
 */
class RefreshModelsHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $startedAt = \Core\Config\AppClock::now();
        $pdo = $context->connection->getPdo();
        $encryption = $context->encryption;

        $providerRepo = new ProviderRepository($pdo, $encryption);
        $modelRepo = new ProviderModelRepository($pdo);

        $activeProviders = $providerRepo->findAllActive();

        foreach ($activeProviders as $provider) {
            try {
                $driver = $this->createDriver($provider['driver'], $provider['api_endpoint'], $provider['api_key']);
                $refresher = new ProviderCatalogRefresher(
                    $modelRepo,
                    new OcrModelSelector($context->journal),
                    $context->journal
                );
                $catalog = $refresher->refresh($provider['id'], $driver, $provider['configuration_token'], true);
                $modelCount = count($catalog->chatModelIds());

                $context->journal->log(
                    'llm_connector',
                    'models_refreshed',
                    'info',
                    "Rafraîchissement planifié : {$provider['name']} — {$modelCount} modèle(s) compatible(s).",
                    ['provider_id' => $provider['id'], 'model_count' => $modelCount],
                    null
                );
            } catch (\Throwable $e) {
                $context->journal->log(
                    'llm_connector',
                    'models_refresh_failed',
                    'info',
                    "Échec du rafraîchissement planifié pour {$provider['name']}.",
                    ['provider_id' => $provider['id'], 'error_type' => $e::class, 'error_code' => $e->getCode()],
                    null
                );
            }
        }

        // Schedule next weekly refresh
        $this->scheduleNextWeeklyRefresh($context, $startedAt);
    }

    /**
     * Anchor renewal before discovery, with an hour of margin before expiry.
     */
    private function scheduleNextWeeklyRefresh(TaskContext $context, \DateTimeImmutable $startedAt): void
    {
        $schedulerRepo = new \Core\Scheduler\SchedulerRepository($context->connection->getPdo());
        $schedulerService = new \Core\Scheduler\SchedulerService($schedulerRepo);

        // Check if a future refresh is already scheduled
        $existing = $schedulerService->find('llm_connector', 'refresh_models', 'weekly');
        $nextRun = $startedAt->setTimestamp($startedAt->getTimestamp() + ModelProbeCache::RENEW_AFTER_SECONDS);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()
            && strtotime($existing['run_at']) <= $nextRun->getTimestamp()) {
            // A future task already exists, don't duplicate
            return;
        }

        if ($existing !== null) {
            $schedulerService->cancel((int) $existing['id']);
        }

        $schedulerService->rearm('llm_connector', 'refresh_models', 'weekly', $nextRun);
    }

    protected function createDriver(string $driver, string $apiEndpoint, string $apiKey): LlmProviderInterface
    {
        return match ($driver) {
            'anthropic' => new AnthropicProvider($apiEndpoint, $apiKey),
            'mistral' => new MistralProvider($apiEndpoint, $apiKey),
            'scaleway' => new ScalewayProvider($apiEndpoint, $apiKey),
            default => throw new \RuntimeException("Unknown driver: {$driver}"),
        };
    }
}
