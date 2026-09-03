<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Module\SubProcessorProvider;
use Core\Module\SubProcessorView;
use Modules\Gallery\Repository\StorageLocationRepository;

/**
 * The gallery's S3 storage as declared sub-processors (Core\Module\
 * SubProcessorProvider, §7.4) — the module's own reading of its own
 * tables, replacing the StorageLocationRepository import core's
 * RgpdContentService used to carry.
 *
 * Dynamic by contract: local storage keeps every byte on the unit's own
 * server and is therefore NO sub-processor at all — only the configured
 * S3 locations are, one view per distinct provider, in the exact wording
 * the RGPD prompt has always carried.
 */
final class GalleryStorageSubProcessorService implements SubProcessorProvider
{
    public function __construct(private StorageLocationRepository $storageLocations)
    {
    }

    public function getSubProcessors(): array
    {
        $names = [];
        foreach ($this->storageLocations->findAll() as $location) {
            if (!$location->isS3()) {
                continue;
            }
            $names[] = match ($location->s3Provider) {
                'hetzner' => 'Hetzner Object Storage (Allemagne/Finlande, UE)',
                'cloudflare_r2' => 'Cloudflare R2 (réseau mondial, région selon configuration du bucket : '
                    . ($location->s3Region !== null && $location->s3Region !== '' ? $location->s3Region : 'non précisée')
                    . ')',
                'scaleway' => 'Scaleway Object Storage (France/Pays-Bas, UE)',
                'ovhcloud' => 'OVHcloud Object Storage (France/Allemagne/Pologne, UE)',
                default => 'Fournisseur S3-compatible personnalisé (localisation selon configuration)',
            };
        }

        return array_map(
            static fn (string $name): SubProcessorView => new SubProcessorView(
                SubProcessorView::CATEGORY_MEDIA_STORAGE,
                $name,
                'Hébergement des photos et vidéos de la galerie'
            ),
            array_values(array_unique($names))
        );
    }
}
