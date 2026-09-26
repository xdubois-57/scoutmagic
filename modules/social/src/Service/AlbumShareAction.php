<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Modules\Gallery\Api\AlbumAction;
use Modules\Gallery\Api\AlbumActionProviderInterface;
use Modules\Social\Api\SocialSharingInterface;

/**
 * « Partager » on an album's management page (ARCHITECTURE.md §7.6) —
 * offered only while there is somewhere to publish.
 */
final class AlbumShareAction implements AlbumActionProviderInterface
{
    public const PATH = '/partage/album/';

    public function __construct(private readonly SocialSharingInterface $sharing)
    {
    }

    public function actionsFor(int $albumId): array
    {
        return $this->sharing->connectedDestinations() === []
            ? []
            : [new AlbumAction('Partager', self::PATH . $albumId, 'bi-share')];
    }
}
