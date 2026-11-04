<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Modules\LlmConnector\Provider\ModelProbeCache;
use Modules\LlmConnector\Provider\ModelProbeStatus;
use Modules\LlmConnector\Provider\ProviderModelCatalog;
use Modules\LlmConnector\Provider\ScalewayModelCapabilities;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Provider\VisionProbeChallenge;
use Modules\LlmConnector\Repository\ProviderModelRepository;

/** At most three short synthetic calls; a fresh successful probe avoids further calls. */
final class ScalewayVisionVerifier
{
    private const MAX_PROBES = 3;

    public function __construct(private ProviderModelRepository $models)
    {
    }

    /**
     * @return array{
     *     catalog: ProviderModelCatalog, cache: array<string, mixed>, attempts: int, outcomes: array<string, string>
     * }
     */
    public function verify(
        int $providerId, ScalewayProvider $driver, ProviderModelCatalog $catalog, bool $renewVision = false
    ): array {
        $cache = new ModelProbeCache($this->models->modelProbeCache($providerId), $driver->probeScope());
        $candidates = array_values(array_filter($catalog->chatModelIds(), OcrModelSelector::isCheap(...)));
        usort($candidates, static function (string $a, string $b) use ($cache): int {
            $aVerified = $cache->resultFor($a) === ModelProbeStatus::VISION_VERIFIED;
            $bVerified = $cache->resultFor($b) === ModelProbeStatus::VISION_VERIFIED;
            $aVision = ScalewayModelCapabilities::forModel($a)['vision'] === true;
            $bVision = ScalewayModelCapabilities::forModel($b)['vision'] === true;
            return ($bVerified <=> $aVerified) ?: ($bVision <=> $aVision) ?: strcmp($a, $b);
        });
        $hasVerified = !$renewVision && array_any($candidates,
            static fn (string $id): bool => $cache->resultFor($id) === ModelProbeStatus::VISION_VERIFIED);
        // Keep the shortlist stable: repeated clicks cannot walk the whole paid catalog.
        $candidates = array_slice($candidates, 0, self::MAX_PROBES);
        $attempts = 0;
        foreach ($hasVerified ? [] : $candidates as $id) {
            if (!$cache->needsProbe($id)
                && !($renewVision && $cache->resultFor($id) === ModelProbeStatus::VISION_VERIFIED)) {
                continue;
            }
            try {
                $challenge = VisionProbeChallenge::create();
                $attempts++;
                $status = $driver->probeVision($id, $challenge);
            } catch (\Throwable) {
                // A local generation/transport failure never establishes capability.
                $status = ModelProbeStatus::UNVERIFIED;
            }
            $cache->record($id, $status);
            if ($status === ModelProbeStatus::VISION_VERIFIED) {
                break;
            }
        }
        $outcomes = [];
        foreach ($catalog->chatModelIds() as $id) {
            $status = $cache->resultFor($id);
            if ($status !== null) {
                $outcomes[$id] = $status->value;
            }
        }
        return [
            'catalog' => $catalog->withImageProbes($cache), 'cache' => $cache->toArray(),
            'attempts' => $attempts, 'outcomes' => $outcomes,
        ];
    }
}
