<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Modules\Gallery\Api\AlbumAction;
use Modules\Gallery\Api\AlbumActionProviderInterface;

/**
 * The actions other modules contribute to an album's management page
 * (ARCHITECTURE.md §7.6): built in the gallery's block of the composition
 * root, appended to by the contributing modules' blocks that run after it.
 *
 * A provider that throws is skipped: one contributing module in trouble
 * must not take the album page down.
 */
final class AlbumActionRegistry
{
    /** @var list<AlbumActionProviderInterface> */
    private array $providers = [];

    public function register(AlbumActionProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @return list<AlbumAction>
     */
    public function collect(int $albumId): array
    {
        $actions = [];
        foreach ($this->providers as $provider) {
            try {
                foreach ($provider->actionsFor($albumId) as $action) {
                    $actions[] = $action;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $actions;
    }
}
