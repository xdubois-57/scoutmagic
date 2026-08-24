<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;

/**
 * Taking a place off every normal screen — and putting it back.
 *
 * Archiving, never deleting: a deleted place would take its stays'
 * history with it, and that history is the whole module. An archived
 * place keeps everything and is reachable from the Archives view.
 */
class PlaceArchiveService
{
    public function __construct(
        private PlaceRepository $places,
        private CampRepository $camps,
        private AuditService $audit
    ) {
    }

    /**
     * Refuses while a CONFIRMED upcoming stay exists.
     *
     * Not a warning: a place hidden from search while a unit is booked to
     * leave for it in July is how a staff loses the address of the field
     * they are going to. Cancel the stay first — which is a decision
     * somebody has to actually make, and is exactly the point.
     *
     * A stay still "à confirmer" only warns, because an unconfirmed
     * booking is often precisely what somebody is archiving the place to
     * get away from.
     */
    public function archive(Place $place, ?int $actorUserAccountId, \DateTimeImmutable $today): void
    {
        $upcoming = $this->camps->countUpcomingByStatusForPlace($place->id, $today);
        $confirmed = $upcoming[Camp::STATUS_CONFIRMED] ?? 0;
        if ($confirmed > 0) {
            throw new CampsException(sprintf(
                'Ce lieu a %d séjour%s confirmé%s à venir. Annulez-le%s d\'abord — archiver un lieu '
                . 'le retire de la recherche, et un staff qui part en juillet en aurait besoin.',
                $confirmed,
                $confirmed > 1 ? 's' : '',
                $confirmed > 1 ? 's' : '',
                $confirmed > 1 ? 's' : ''
            ));
        }

        $this->places->archive($place->id, true);
        $this->audit->record(
            PlaceService::ENTITY_TYPE, $place->id, 'archived', null, 'Archivé', AuditSource::Human,
            'Lieu retiré des écrans courants — rien n\'est supprimé',
            null, $actorUserAccountId
        );
    }

    public function restore(Place $place, ?int $actorUserAccountId): void
    {
        $this->places->archive($place->id, false);
        $this->audit->record(
            PlaceService::ENTITY_TYPE, $place->id, 'archived', 'Archivé', null, AuditSource::Human,
            'Lieu restauré', null, $actorUserAccountId
        );
    }

    /**
     * The warning the confirmation screen shows — an unconfirmed upcoming
     * stay does not block, but the chief should know it is there.
     */
    public function pendingWarning(Place $place, \DateTimeImmutable $today): ?string
    {
        $upcoming = $this->camps->countUpcomingByStatusForPlace($place->id, $today);
        $toConfirm = $upcoming[Camp::STATUS_TO_CONFIRM] ?? 0;
        if ($toConfirm === 0) {
            return null;
        }

        return sprintf(
            '%d séjour%s à venir %s encore « à confirmer » ici.',
            $toConfirm,
            $toConfirm > 1 ? 's' : '',
            $toConfirm > 1 ? 'sont' : 'est'
        );
    }
}
