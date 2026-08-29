<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Core\Module\SubProcessorProvider;
use Core\Module\SubProcessorView;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;

/**
 * The AI provider as a declared sub-processor (Core\Module\
 * SubProcessorProvider, §7.4) — the module's own reading of its own
 * tables, replacing the two Repository imports core's RgpdContentService
 * used to carry.
 *
 * Dynamic by contract: no active provider means NO sub-processor — an AI
 * integration nothing is configured to call processes nobody's data. The
 * wording per driver is the exact wording the RGPD prompt has always
 * carried; an unknown driver falls back to the provider's own configured
 * name, location unstated, which the generated document then has to say.
 */
final class LlmSubProcessorService implements SubProcessorProvider
{
    public function __construct(
        private ProviderRepository $providerRepository,
        private ProviderModelRepository $modelRepository,
    ) {
    }

    public function getSubProcessors(): array
    {
        $provider = $this->providerRepository->findFirstActive();
        if ($provider === null) {
            return [];
        }

        $name = match ($provider['driver']) {
            'anthropic' => 'Anthropic (États-Unis, hors UE)',
            'mistral' => 'Mistral AI (France, UE)',
            'scaleway' => 'Scaleway (France/Pays-Bas, UE)',
            default => (string) $provider['name'],
        };

        return [new SubProcessorView(
            SubProcessorView::CATEGORY_AI,
            $name,
            'Traitement par intelligence artificielle (génération de texte, lecture de reçus et de photos)',
            $this->assignedModels((int) $provider['id']),
        )];
    }

    /**
     * The assigned models with their tiers, in the exact wording the
     * RGPD prompt has always carried — « Aucun modèle assigné » when the
     * provider has none, so the document can say the integration is
     * configured but idle.
     */
    private function assignedModels(int $providerId): string
    {
        $assigned = [];
        foreach ($this->modelRepository->findByProvider($providerId) as $model) {
            $tiers = [];
            if ($model['is_tier_cheap']) {
                $tiers[] = 'économique';
            }
            if ($model['is_tier_capable']) {
                $tiers[] = 'performant';
            }
            if ($model['is_tier_ocr']) {
                $tiers[] = 'OCR';
            }
            if ($tiers !== []) {
                $assigned[] = $model['display_name'] . ' (' . implode(', ', $tiers) . ')';
            }
        }

        return $assigned === [] ? 'Aucun modèle assigné' : implode('; ', $assigned);
    }
}
