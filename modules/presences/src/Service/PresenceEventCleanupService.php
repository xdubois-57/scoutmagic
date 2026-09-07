<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Modules\Presences\Api\PresenceEventCleanupInterface;
use Modules\Presences\Repository\PresenceEventLinkRepository;
use Modules\Presences\Repository\PresenceRepository;

/**
 * The module's answer to « that evening is being deleted » — see the
 * interface for why the database cannot answer it on its own.
 *
 * Both tables in one place because both are keyed by the event and
 * neither means anything without it: the states and comments, and the
 * short code the sheet was reached by.
 *
 * The `short_urls` row that code points at is deliberately left where it
 * is. Nothing here owns it — `Core\Url\ShortUrlService` mints codes and
 * has never deleted one — and it is not a leak: the code now redirects to
 * a sheet whose event is gone, which the page refuses like any other
 * event it cannot resolve.
 */
class PresenceEventCleanupService implements PresenceEventCleanupInterface
{
    public function __construct(
        private PresenceRepository $repository,
        private PresenceEventLinkRepository $linkRepository
    ) {
    }

    public function forgetEvent(int $eventId): void
    {
        $this->repository->deleteByEvent($eventId);
        $this->linkRepository->forgetEvent($eventId);
    }
}
