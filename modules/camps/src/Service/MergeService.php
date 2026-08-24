<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\View\EditableContentService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;

/**
 * Bringing two rows that are one thing back together.
 *
 * Never automatic and never AI-driven. A merge is irreversible in
 * practice — the losing row is gone and nobody remembers what it held —
 * so both kinds start on a screen that lists what will be regrouped, and
 * both are triggered by a human pressing a button.
 *
 * The two merges are deliberately NOT symmetric:
 *
 * - Merging PLACES is admin-only. It moves whole stays between places and
 *   affects every screen the place appears on.
 * - Merging CAMPS is open to every chief, and is safe to be, because
 *   nothing is silently lost: every losing value is appended to the
 *   surviving stay's note, timestamped. A chief who merges the wrong pair
 *   still has the numbers, in prose, on the page.
 */
class MergeService
{
    public function __construct(
        private PlaceRepository $places,
        private CampRepository $camps,
        private ContactRepository $contacts,
        private LinkRepository $links,
        private DocumentRepository $documents,
        private ReviewRepository $reviews,
        private EditableContentService $editableContent,
        private AuditService $audit,
        private CampAlbumService $albums
    ) {
    }

    /**
     * What merging two places would regroup — computed BEFORE anything
     * happens, so the confirmation screen can show it.
     *
     * @return array{stays: int, contacts: int, documents: int, links: int, fields: array<int, string>}
     */
    public function placeMergePreview(Place $from, Place $to): array
    {
        $stays = $this->camps->findByPlace($from->id);
        $contacts = 0;
        $documents = 0;
        $links = 0;
        foreach ($stays as $stay) {
            $contacts += count($this->contacts->findByCamp($stay->id));
            $documents += $this->documents->countByCamp($stay->id);
            $links += count($this->links->findByCamp($stay->id));
        }

        return [
            'stays' => count($stays),
            'contacts' => $contacts,
            'documents' => $documents,
            'links' => $links,
            'fields' => $this->placeFieldsGained($from, $to),
        ];
    }

    /**
     * Merges $from into $to and returns how many stays moved.
     *
     * The stays keep their own ids and simply change place, which is what
     * makes this cheap and safe: their contacts, documents, links,
     * reviews, history AND photo albums all hang off the stay, so nothing
     * has to be copied and no media moves at all.
     *
     * Field resolution: a value present on one side only is kept; present
     * on both, the more recently updated place wins. That is a rule a
     * human can predict, which matters more here than being clever.
     */
    public function mergePlaces(Place $from, Place $to, ?int $actorUserAccountId): int
    {
        if ($from->id === $to->id) {
            throw new CampsException('Un lieu ne peut pas être fusionné avec lui-même.');
        }

        $winner = $from->updatedAt > $to->updatedAt ? $from : $to;
        $loser = $winner === $from ? $to : $from;

        $this->places->update(
            $to->id,
            $to->name,
            $this->pick($winner->address, $loser->address),
            $this->pick($winner->postalCode, $loser->postalCode),
            $this->pick($winner->city, $loser->city),
            $this->pick($winner->country, $loser->country),
            $this->pick($winner->websiteUrl, $loser->websiteUrl),
        );

        // Coordinates are the one field that does not follow the
        // most-recently-updated rule: a point a human placed beats an
        // automatic one whatever the timestamps say, and a place with no
        // point at all takes whatever the other side has.
        if ($from->hasCoordinates()
            && (!$to->hasCoordinates() || ($from->coordinatesAreManual && !$to->coordinatesAreManual))
        ) {
            $this->places->copyCoordinates(
                $to->id,
                (float) $from->latitude,
                (float) $from->longitude,
                $from->coordinatesAreManual
            );
        }

        $moved = $this->camps->movePlace($from->id, $to->id);
        $this->places->archive($from->id, true);

        $this->audit->record(
            PlaceService::ENTITY_TYPE, $to->id, 'name', null, $to->name, AuditSource::Human,
            sprintf('Fusion : %d séjour(s) repris depuis « %s »', $moved, $from->name),
            null, $actorUserAccountId
        );
        $this->audit->record(
            PlaceService::ENTITY_TYPE, $from->id, 'name', $from->name, $to->name, AuditSource::Human,
            'Lieu fusionné dans un autre et archivé',
            null, $actorUserAccountId
        );

        return $moved;
    }

    /**
     * Merges one stay into another OF THE SAME PLACE.
     *
     * Across places is refused rather than supported: two stays at two
     * different fields are two stays, and a chief who thinks otherwise
     * wants to merge the PLACES first — which is an admin action, on
     * purpose.
     *
     * @return array<int, string> the lines appended to the surviving note
     */
    public function mergeCamps(Camp $from, Camp $to, ?int $actorUserAccountId, \DateTimeImmutable $today): array
    {
        if ($from->id === $to->id) {
            throw new CampsException('Un séjour ne peut pas être fusionné avec lui-même.');
        }
        if ($from->placeId !== $to->placeId) {
            throw new CampsException(
                'On ne fusionne que deux séjours d\'un même lieu. Fusionnez d\'abord les deux lieux.'
            );
        }

        $lost = $this->losingValues($from, $to);

        $this->camps->update(
            $to->id,
            $to->stayType,
            $to->startDate ?? $from->startDate,
            $to->endDate ?? $from->endDate,
            $to->endDate === null && $from->endDate === null ? ($to->yearOnly ?? $from->yearOnly) : null,
            $to->status,
            $to->priceCents ?? $from->priceCents,
            $to->participantCount ?? $from->participantCount,
            $to->bookedByMemberId ?? $from->bookedByMemberId,
            $to->bookedByName ?? $from->bookedByName,
            array_values(array_unique(array_merge($to->sectionIds, $from->sectionIds))),
        );

        $this->contacts->moveCamp($from->id, $to->id);
        $this->links->moveCamp($from->id, $to->id);
        $this->documents->moveCamp($from->id, $to->id);
        $this->movePhotos($from, $to);
        $this->moveReview($from, $to);

        $this->appendToNote($from, $to, $lost, $actorUserAccountId, $today);
        $this->camps->delete($from->id);

        $this->audit->record(
            CampService::ENTITY_TYPE, $to->id, 'camp', null, null, AuditSource::Human,
            'Séjour fusionné depuis un autre — les valeurs remplacées sont reprises dans la note',
            null, $actorUserAccountId
        );

        return $lost;
    }

    /**
     * The values the losing stay carried that the surviving one already
     * had. These are what get written into the note — nothing is silently
     * dropped, which is precisely what makes camp merges safe to open to
     * every chief.
     *
     * @return array<int, string>
     */
    private function losingValues(Camp $from, Camp $to): array
    {
        $lost = [];
        if ($from->priceCents !== null && $to->priceCents !== null && $from->priceCents !== $to->priceCents) {
            $lost[] = 'prix précédent : ' . CampLabels::money($from->priceCents);
        }
        if ($from->participantCount !== null && $to->participantCount !== null
            && $from->participantCount !== $to->participantCount
        ) {
            $lost[] = 'participants précédents : ' . $from->participantCount;
        }
        if ($from->bookedByName !== null && $to->bookedByName !== null
            && $from->bookedByName !== $to->bookedByName
        ) {
            $lost[] = 'réservation faite par : ' . $from->bookedByName;
        }
        $fromDates = CampLabels::dateRange($from->startDate, $from->endDate, $from->yearOnly);
        $toDates = CampLabels::dateRange($to->startDate, $to->endDate, $to->yearOnly);
        if ($fromDates !== '' && $toDates !== '' && $fromDates !== $toDates) {
            $lost[] = 'dates précédentes : ' . $fromDates;
        }
        if ($from->status !== $to->status) {
            $lost[] = 'statut précédent : ' . CampLabels::status($from->status);
        }

        return $lost;
    }

    /**
     * @param array<int, string> $lost
     */
    private function appendToNote(Camp $from, Camp $to, array $lost, ?int $actorUserAccountId, \DateTimeImmutable $today): void
    {
        $fromKey = CampService::noteKey($from->id);
        $toKey = CampService::noteKey($to->id);

        $existing = trim((string) ($this->editableContent->get($toKey, '') ?? ''));
        $incoming = trim((string) ($this->editableContent->get($fromKey, '') ?? ''));

        $paragraphs = [];
        if ($incoming !== '') {
            $paragraphs[] = $incoming;
        }
        if ($lost !== []) {
            $paragraphs[] = '<p>Fusionné le ' . $today->format('d/m/Y') . ' — '
                . htmlspecialchars(implode(' ; ', $lost), ENT_QUOTES, 'UTF-8') . '.</p>';
        }
        if ($paragraphs === []) {
            return;
        }

        $merged = trim($existing . "\n" . implode("\n", $paragraphs));
        $this->editableContent->set($toKey, $merged, 'rich_text', $actorUserAccountId ?? 0);
        $this->editableContent->delete($fromKey);
    }

    private function movePhotos(Camp $from, Camp $to): void
    {
        $fromAlbum = $this->albums->existingAlbumIdFor($from);
        if ($fromAlbum === null) {
            return;
        }
        $toAlbum = $this->albums->albumIdFor($to, 'Camp', 0);
        if ($toAlbum === null) {
            return;
        }

        $this->albums->movePhotos($fromAlbum, $toAlbum);
    }

    /**
     * A review moves only into a stay that has none: two reviews of one
     * field are two opinions, and silently overwriting the surviving one
     * would lose the very thing this module exists to keep.
     */
    private function moveReview(Camp $from, Camp $to): void
    {
        $incoming = $this->reviews->findByCamp($from->id);
        if ($incoming === null) {
            return;
        }
        if ($this->reviews->findByCamp($to->id) === null) {
            $this->reviews->save($to->id, $incoming->rating, $incoming->comment, $incoming->authorMemberId);
        }
        $this->reviews->delete($from->id);
    }

    /**
     * @return array<int, string>
     */
    private function placeFieldsGained(Place $from, Place $to): array
    {
        $gained = [];
        foreach ([
            'adresse' => [$from->address, $to->address],
            'code postal' => [$from->postalCode, $to->postalCode],
            'ville' => [$from->city, $to->city],
            'site web' => [$from->websiteUrl, $to->websiteUrl],
        ] as $label => [$fromValue, $toValue]) {
            if ($fromValue !== null && $toValue === null) {
                $gained[] = $label;
            }
        }
        if ($from->hasCoordinates() && !$to->hasCoordinates()) {
            $gained[] = 'coordonnées';
        }

        return $gained;
    }

    private function pick(?string $preferred, ?string $fallback): ?string
    {
        return $preferred ?? $fallback;
    }
}
