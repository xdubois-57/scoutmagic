<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Provider\ProviderModelCatalog;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Provider\ModelProbeCache;
use Modules\LlmConnector\Provider\ModelProbeStatus;
use Modules\LlmConnector\Repository\ProviderModelRepository;

/** Connection tests and scheduled refreshes apply exactly the same capability rules. */
final class ProviderCatalogRefresher
{
    private bool $retryNeeded = false;

    public function __construct(
        private ProviderModelRepository $models,
        private OcrModelSelector $selector,
        private JournalService $journal,
        private ?int $actingUserAccountId = null
    ) {
    }

    public function refresh(
        int $providerId,
        LlmProviderInterface $driver,
        string $configurationToken,
        bool $renewVision = false
    ): ProviderModelCatalog {
        $this->retryNeeded = false;
        $catalog = $driver->listModels();
        $discovered = $catalog;
        $evaluation = $driver instanceof ScalewayProvider
            ? (new ScalewayVisionVerifier($this->models))->verify($providerId, $driver, $catalog, $renewVision) : null;
        if ($driver instanceof MistralProvider) {
            $cache = new ModelProbeCache($this->models->modelProbeCache($providerId), $driver->capabilityScope());
            foreach ($catalog->chatModelIds() as $id) {
                $cache->record(
                    $id,
                    in_array($id, $catalog->visionModelIds(), true)
                        ? ModelProbeStatus::VISION_VERIFIED : ModelProbeStatus::VISION_NOT_VERIFIED
                );
            }
            $evaluation = ['catalog' => $catalog, 'cache' => $cache->toArray(), 'attempts' => 0, 'outcomes' => []];
        }
        $catalog = $evaluation['catalog'] ?? $catalog;
        $this->retryNeeded = $evaluation['retry_needed'] ?? false;
        // Network calls and classification never hold a database transaction.
        $tiers = $this->selector->selectTiers($driver, $catalog);
        if ($driver instanceof ScalewayProvider && $evaluation !== null) {
            $cache = new ModelProbeCache($evaluation['cache'], $driver->probeScope());
            foreach ($driver->rejectedChatModels() as $id) {
                $cache->record($id, ModelProbeStatus::CHAT_UNSUPPORTED);
            }
            $evaluation['cache'] = $cache->toArray();
        }
        $outcomes = $evaluation['outcomes'] ?? [];
        $this->models->transactional(function () use (
            $providerId, &$catalog, $discovered, $tiers, $evaluation, &$outcomes, $configurationToken
        ): void {
            $this->models->assertProviderConfiguration($providerId, $configurationToken);
            if ($evaluation !== null) {
                $merged = $this->models->storeModelProbeCache($providerId, $evaluation['cache']);
                $cache = new ModelProbeCache($merged, (string) $evaluation['cache']['scope']);
                $current = $discovered->withImageProbes($cache);
                if ($current->chatModelIds() !== $catalog->chatModelIds()
                    || $current->visionModelIds() !== $catalog->visionModelIds()) {
                    $tiers = $this->selector->fallbackTiers($current);
                }
                $catalog = $current;
                $outcomes = [];
                foreach ($discovered->chatModelIds() as $id) {
                    $status = $cache->resultFor($id);
                    if ($status !== null) {
                        $outcomes[$id] = $status->value;
                    }
                }
            }
            foreach ($catalog->chatModels() as $model) {
                $this->models->upsert($providerId, $model['id'], $model['display_name']);
            }
            $this->models->deleteModelsNotIn($providerId, $catalog->chatModelIds());
            $this->models->autoAssignTiers($providerId, $tiers);
        });

        $this->journal->log(
            'llm_connector',
            'model_capabilities_checked',
            'info',
            'Le catalogue IA a été vérifié avant l’affectation des tiers.',
            [
                'provider_id' => $providerId,
                'chat_model_count' => count($catalog->chatModelIds()),
                'vision_model_count' => count($catalog->visionModelIds()),
                'excluded_models' => $catalog->exclusions(),
                'image_probe_count' => $evaluation['attempts'] ?? 0,
                'image_probe_results' => $outcomes,
            ],
            $this->actingUserAccountId
        );
        return $catalog;
    }

    public function needsRetry(): bool
    {
        return $this->retryNeeded;
    }
}
