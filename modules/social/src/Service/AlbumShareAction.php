<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Modules\Gallery\Api\AlbumAction;
use Modules\Gallery\Api\AlbumActionProviderInterface;
use Modules\Gallery\Api\AlbumNote;
use Modules\Social\Api\SocialSharingInterface;

/**
 * « Partager » on an album's management page (ARCHITECTURE.md §7.6), and
 * the line that takes its place when there is nowhere to publish.
 *
 * It opens the one composer, prefilled — there is no album share page any
 * more (docs/chantiers/CHANTIER-medias-sociaux.md, IT-01).
 *
 * The question it asks is `canPublish()`, not `connectedDestinations()`:
 * a unit with no Meta account at all still shares its albums to its own
 * discussion groups, and the old question knew only about Facebook and
 * Instagram, so the button was missing for exactly those units.
 */
final class AlbumShareAction implements AlbumActionProviderInterface
{
    public const PATH = '/medias-sociaux/nouvelle/album/';

    public function __construct(
        private readonly SocialSharingInterface $sharing,
        private readonly ShareViewer $viewer
    ) {
    }

    public function actionsFor(int $albumId): array
    {
        return $this->canPublish()
            ? [new AlbumAction('Partager', self::PATH . $albumId, 'bi-share', true)]
            : [];
    }

    public function noteFor(int $albumId): ?AlbumNote
    {
        if ($this->canPublish()) {
            return null;
        }

        return new AlbumNote(
            ShareViewer::NO_DESTINATION,
            $this->viewer->mayConfigureMeta() ? 'Configurer les médias sociaux' : null,
            $this->viewer->mayConfigureMeta() ? ShareViewer::CONFIG_PATH : null
        );
    }

    private function canPublish(): bool
    {
        return $this->sharing->canPublish($this->viewer->email, $this->viewer->role, $this->viewer->userAccountId);
    }
}
