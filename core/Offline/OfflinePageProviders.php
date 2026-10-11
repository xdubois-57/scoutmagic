<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

/**
 * The providers named by the offline entries, by class name (#883).
 *
 * An entry names its provider in `module.json`, which only holds strings;
 * the instance, with whatever services it needs, is registered here from
 * the composition root. A provider an entry names but nobody registered —
 * a module whose wiring forgot it — contributes nothing rather than
 * failing the manifest, and tests/Architecture/OfflinePageProvidersAreWiredTest
 * is what keeps that from shipping.
 */
final class OfflinePageProviders
{
    /** @var array<class-string<OfflinePageProvider>, OfflinePageProvider> */
    private array $providers = [];

    public function register(OfflinePageProvider $provider): void
    {
        $this->providers[$provider::class] = $provider;
    }

    public function get(string $class): ?OfflinePageProvider
    {
        return $this->providers[$class] ?? null;
    }
}
