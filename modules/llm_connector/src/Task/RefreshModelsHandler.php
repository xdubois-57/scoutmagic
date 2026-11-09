<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Scheduler\SchedulerService;
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
    private const CHAIN_PAYLOAD = ['capability_checks_version' => 1];
    private const RETRY_DELAYS = [900, 3600, 21600, 86400];

    /** Seed from either entry point; upgrade a legacy weekly delay only once. */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        $now = \Core\Config\AppClock::now();
        if ($scheduler->seed('llm_connector', 'refresh_models', 'weekly', $now, self::CHAIN_PAYLOAD)) {
            return;
        }
        $existing = $scheduler->findLive('llm_connector', 'refresh_models', 'weekly');
        $payload = is_string($existing['payload'] ?? null) ? json_decode($existing['payload'], true) : [];
        if ($existing !== null && $existing['status'] === 'pending'
            && (!is_array($payload) || ($payload['capability_checks_version'] ?? null) !== 1)) {
            $scheduler->cancel((int) $existing['id']);
            $scheduler->seed('llm_connector', 'refresh_models', 'weekly', $now, self::CHAIN_PAYLOAD);
        }
    }

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

        $providers = $providerRepo->findAll();
        $retryProviderId = (int) ($payload['provider_id'] ?? 0);

        foreach ($providers as $provider) {
            if (!$provider['is_active'] || ($retryProviderId > 0 && $provider['id'] !== $retryProviderId)) {
                continue;
            }
            $needsRetry = true;
            try {
                // Decrypt only this provider, inside its bounded recovery path.
                $configured = $providerRepo->findById($provider['id']);
                if ($configured === null || !$configured['is_active']) {
                    continue;
                }
                $driver = $this->createDriver(
                    $configured['driver'],
                    $configured['api_endpoint'],
                    $configured['api_key']
                );
                $refresher = new ProviderCatalogRefresher(
                    $modelRepo,
                    new OcrModelSelector($context->journal),
                    $context->journal
                );
                $catalog = $refresher->refresh($provider['id'], $driver, $configured['configuration_token'], true);
                $needsRetry = $refresher->needsRetry();
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
            $scheduler = SchedulerService::forPdo($pdo);
            if ($needsRetry) {
                self::scheduleRetry(
                    $scheduler,
                    $provider['id'],
                    $startedAt,
                    (int) ($payload['retry_attempt'] ?? 0)
                );
            } else {
                $scheduler->cancelPending('llm_connector', 'refresh_models', 'retry-' . $provider['id']);
            }
        }

        // A provider's retry must not move the other providers' weekly renewal.
        if ($retryProviderId <= 0) {
            $this->scheduleNextWeeklyRefresh($context, $startedAt);
        }
    }

    /** Share bounded recovery between scheduled discovery and the connection test. */
    public static function scheduleRetry(
        SchedulerService $scheduler,
        int $providerId,
        \DateTimeImmutable $startedAt,
        int $attempt
    ): void {
        $attempt = max(0, min($attempt, count(self::RETRY_DELAYS) - 1));
        $nextRun = $startedAt->setTimestamp($startedAt->getTimestamp() + self::RETRY_DELAYS[$attempt]);
        $reference = 'retry-' . $providerId;
        $existing = $scheduler->find('llm_connector', 'refresh_models', $reference);
        if ($existing !== null && strtotime($existing['run_at']) <= $nextRun->getTimestamp()) {
            return;
        }
        if ($existing !== null) {
            $scheduler->cancel((int) $existing['id']);
        }
        $scheduler->rearm('llm_connector', 'refresh_models', $reference, $nextRun, [
            'provider_id' => $providerId,
            'retry_attempt' => min($attempt + 1, count(self::RETRY_DELAYS) - 1),
        ]);
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

        $schedulerService->rearm('llm_connector', 'refresh_models', 'weekly', $nextRun, self::CHAIN_PAYLOAD);
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
