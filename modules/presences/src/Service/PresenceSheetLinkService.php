<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Core\Security\Role;
use Core\Url\ShortUrlService;
use Modules\Presences\Api\PresenceSheetLink;
use Modules\Presences\Api\PresenceSheetLinkLookupInterface;
use Modules\Presences\Repository\PresenceEventLinkRepository;

/**
 * `Api\PresenceSheetLinkLookupInterface`'s implementation: « voici le lien
 * vers la feuille, si vous animez cette section ».
 *
 * Three things it is careful about, and each of them was written down as a
 * trap before it was written as code.
 *
 * **The rights are the same ones as the page's, asked again.** It goes
 * through `PresenceSheetService::resolveEvent()` rather than re-deriving
 * anything, so an animateur who leaves the section stops seeing the link
 * at their next agenda refresh — there is nothing to revoke, because
 * there is nothing remembered.
 *
 * **`ShortUrlService` returns the CODE, not the URL.** Its own comment
 * says it has « aucune notion de schéma ni d'hôte »: composing the
 * address is the caller's job, from the site's own `base_url`. With no
 * base URL configured there is no address to compose, and the link is
 * simply not offered rather than emitted relative — a relative URL in an
 * ICS description resolves against nothing.
 *
 * **The code is minted once per event and reused.** An agenda refreshes
 * every few hours; a code per read would fill the table and change the
 * link in somebody's calendar on every sync.
 */
class PresenceSheetLinkService implements PresenceSheetLinkLookupInterface
{
    public function __construct(
        private PresenceSheetService $sheetService,
        private PresenceEventLinkRepository $linkRepository,
        private ShortUrlService $shortUrlService,
        private string $baseUrl
    ) {
    }

    public function findSheetLink(
        int $eventId,
        Role $viewerRole,
        ?string $viewerEmail,
        ?int $scoutYearId
    ): ?PresenceSheetLink {
        // An anonymous feed has no reader to qualify — the unit feed and
        // a calendar's own feed therefore never carry the link.
        if ($viewerEmail === null || $scoutYearId === null) {
            return null;
        }

        $base = rtrim(trim($this->baseUrl), '/');
        if ($base === '') {
            return null;
        }

        if ($this->sheetService->resolveEvent($eventId, $viewerEmail, $viewerRole->value, $scoutYearId) === null) {
            return null;
        }

        return new PresenceSheetLink($base . '/s/' . $this->codeFor($eventId));
    }

    private function codeFor(int $eventId): string
    {
        $existing = $this->linkRepository->findCode($eventId);
        if ($existing !== null) {
            return $existing;
        }

        // createShortUrl() writes the short_urls row; rememberCode() is
        // what ties it to this event, and settles the race when two
        // agendas refresh at the same second.
        $code = $this->shortUrlService->createShortUrl('/chefs/presences/feuille/' . $eventId, null);

        return $this->linkRepository->rememberCode($eventId, $code);
    }
}
