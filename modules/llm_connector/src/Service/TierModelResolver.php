<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Provider\ScalewayModelCapabilities;
use Modules\LlmConnector\Provider\ModelProbeCache;
use Modules\LlmConnector\Provider\ModelProbeStatus;
use Modules\LlmConnector\Repository\ProviderModelRepository;

/** One resolution rule for availability, calls and the configuration page. */
final class TierModelResolver
{
    public function __construct(private ProviderModelRepository $models)
    {
    }

    /** @return array{id: int, provider_id: int, model_id: string, display_name: string, supports_images?: bool}|null */
    public function resolve(int $providerId, string $driver, LlmTier $tier, ?string $scope = null): ?array
    {
        $model = $this->models->findByProviderAndTier($providerId, $tier);
        if ($model === null) {
            return null;
        }
        // Neutralize legacy Scaleway assignments before the first refresh too.
        // An absent OCR assignment is never evidence that CHEAP can read images.
        if ($driver === 'scaleway') {
            $capabilities = ScalewayModelCapabilities::forModel($model['model_id']);
            $cache = new ModelProbeCache($this->models->modelProbeCache($providerId), $scope ?? '');
            $status = $scope === null ? null : $cache->resultFor($model['model_id']);
            if ($capabilities['chat'] !== true || $status === ModelProbeStatus::CHAT_UNSUPPORTED
                || ($tier === LlmTier::OCR && $status !== ModelProbeStatus::VISION_VERIFIED)) {
                return null;
            }
            $model['supports_images'] = $status === ModelProbeStatus::VISION_VERIFIED;
        }
        if ($driver === 'mistral') {
            $cache = new ModelProbeCache($this->models->modelProbeCache($providerId), $scope ?? '');
            $verified = $scope !== null && $cache->resultFor($model['model_id']) === ModelProbeStatus::VISION_VERIFIED;
            if ($tier === LlmTier::OCR && !$verified) {
                return null;
            }
            $model['supports_images'] = $verified;
        }
        return $model;
    }
}
