<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Module\SubProcessorProvider;
use Core\Module\SubProcessorView;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Config\WebDavLocationConfig;
use Core\Storage\Location\StorageLocationRepository;

/**
 * The gallery's external storage as declared sub-processors
 * (Core\Module\SubProcessorProvider, §7.4) — the module's own reading of
 * its own tables, replacing the StorageLocationRepository import core's
 * RgpdContentService used to carry.
 *
 * Dynamic by contract: local storage keeps every byte on the unit's own
 * server and is therefore NO sub-processor at all. Everything else is,
 * one view per distinct destination, in the exact wording the RGPD prompt
 * has always carried.
 *
 * **Every kind of external destination, not only S3.** This read one
 * `instanceof` and skipped the rest, which was right while S3 was the
 * only one there was — and turned into a silent omission the moment a
 * second kind arrived. What flows to these hosts is photographs and films
 * of children, so a destination missing from this list is a disclosure
 * that is wrong rather than merely incomplete, and AGENTS.md § RGPD is
 * explicit that a change adding one without saying so is unfinished.
 *
 * **What guards the next kind is a test, not the language.** PHP has no
 * exhaustiveness to offer over a set of classes — a `match` on the config
 * class without a default arm would raise at RUNTIME, on the « Sous-
 * traitants » page itself, which trades a silent omission for a broken
 * page. So `GalleryStorageSubProcessorServiceTest` walks
 * `StorageLocationType::cases()`, builds each one's config through the
 * enum's own `configFromArray()`, and fails if any type but the local
 * disk comes back unnamed. A case added to the enum arrives in that test
 * on its own.
 */
final class GalleryStorageSubProcessorService implements SubProcessorProvider
{
    public function __construct(private StorageLocationRepository $storageLocations)
    {
    }

    /**
     * How one destination is named on the « Sous-traitants » page, or null
     * when it is not a sub-processor at all.
     *
     * The name has to say WHERE the photographs are, because that is the
     * question the page answers: a provider's usual regions for the ones
     * that have them, and the configured host for a share, which can be
     * anywhere at all.
     */
    private static function nameOf(LocationConfig $config): ?string
    {
        if ($config instanceof ObjectStorageLocationConfig) {
            return match ($config->provider) {
                'hetzner' => 'Hetzner Object Storage (Allemagne/Finlande, UE)',
                'cloudflare_r2' => 'Cloudflare R2 (réseau mondial, région selon configuration du bucket : '
                    . ($config->region !== '' ? $config->region : 'non précisée')
                    . ')',
                'scaleway' => 'Scaleway Object Storage (France/Pays-Bas, UE)',
                'ovhcloud' => 'OVHcloud Object Storage (France/Allemagne/Pologne, UE)',
                default => 'Fournisseur S3-compatible personnalisé (localisation selon configuration)',
            };
        }

        if ($config instanceof WebDavLocationConfig) {
            $host = parse_url($config->baseUrl, PHP_URL_HOST);

            // The host and not the whole address: what follows it names
            // the account on the share, which is very often somebody, and
            // this page is read by anybody who asks for it.
            return is_string($host) && $host !== ''
                ? 'Partage WebDAV hébergé par ' . $host . ' (localisation selon cet hébergeur)'
                : 'Partage WebDAV externe (localisation selon cet hébergeur)';
        }

        if ($config instanceof GoogleDriveLocationConfig) {
            return 'Google Drive — Google Ireland Limited (transferts hors UE possibles)';
        }

        // A local folder is the unit's own server: no third party, so
        // nothing to declare.
        return null;
    }

    public function getSubProcessors(): array
    {
        $names = [];
        foreach ($this->storageLocations->findAll() as $location) {
            $name = self::nameOf($location->config);
            if ($name !== null) {
                $names[] = $name;
            }
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
