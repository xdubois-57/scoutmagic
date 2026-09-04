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
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\InboundMail\Api\InboundMailInterface;

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
 *
 * **Both merges are one transaction.** Each moves half a dozen tables, and
 * a failure halfway through used to leave the pair in a state neither
 * screen can describe: stays under an archived place, contacts on a stay
 * that no longer exists, a note appended to a merge that never happened.
 * Every table involved is InnoDB and every repository here shares one PDO,
 * so one `BEGIN`/`COMMIT` covers the lot.
 *
 * **The gallery is the exception, and runs last.** Moving photos is
 * another module's write behind a public API (§7.5); it is done after the
 * commit and its failure is explicitly non-fatal — a merge that is
 * otherwise complete must not be rolled back because the gallery is
 * disabled, misconfigured, or simply refused the storage location.
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
        private CampAlbumService $albums,
        private \PDO $pdo,
        private ?InboundMailInterface $inboundMail = null
    ) {
    }

    /**
     * Runs $work as one unit of work.
     *
     * Nested calls are tolerated (`inTransaction()`): PDO has no savepoint
     * abstraction and a second `beginTransaction()` would throw, so an
     * outer transaction simply wins and this becomes a plain call.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transactionally(callable $work): mixed
    {
        $owned = !$this->pdo->inTransaction();
        if ($owned) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $work();
            if ($owned) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owned) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
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
        // Merging INTO an archived place would move live stays onto a row
        // that no ordinary screen shows: the stays would simply vanish
        // from the module, and the chief who pressed the button would have
        // no way of guessing where they went.
        if ($to->isArchived) {
            throw new CampsException(
                'Ce lieu est archivé : désarchivez-le d\'abord si vous voulez y regrouper des séjours.'
            );
        }

        $winner = $from->updatedAt > $to->updatedAt ? $from : $to;
        $loser = $winner === $from ? $to : $from;

        return $this->transactionally(function () use ($from, $to, $winner, $loser, $actorUserAccountId): int {
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
            // automatic one whatever the timestamps say, and a place with
            // no point at all takes whatever the other side has.
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

            // What the AI wrote about either place describes stays that
            // have just moved. Left alone, the surviving place's summary
            // goes on describing a shorter history than it now has, and
            // nothing would ever mark it stale — no stay was created or
            // edited, only re-parented.
            $this->places->markSummaryStale($to->id);
            $this->places->markSummaryStale($from->id);

            return $moved;
        });
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

        $this->transactionally(function () use ($from, $to, $lost, $actorUserAccountId, $today): void {
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
            $this->moveReview($from, $to);
            $this->moveMail($from, $to);

            $this->appendToNote($from, $to, $lost, $actorUserAccountId, $today);
            $this->camps->delete($from->id);

            $this->audit->record(
                CampService::ENTITY_TYPE, $to->id, 'camp', null, null, AuditSource::Human,
                'Séjour fusionné depuis un autre — les valeurs remplacées sont reprises dans la note',
                null, $actorUserAccountId
            );

            // The place has one stay fewer, and the surviving one carries
            // different dates: whatever the AI wrote about the place is now
            // describing a history that no longer exists.
            $this->places->markSummaryStale($to->placeId);
        });

        // Another module's write, after the commit and explicitly
        // non-fatal: a merge that is otherwise complete must not be undone
        // — nor reported as failed — because the gallery is disabled,
        // misconfigured, or refused the storage location.
        $this->movePhotos($from, $to);

        return $lost;
    }

    /**
     * The correspondence follows the stay.
     *
     * `inbound_mail` keys its messages on a business reference of ours
     * (`camp-{id}`), which no database constraint knows about: without
     * this, every message filed under the losing stay would point at a row
     * that has just been deleted, and would be reachable from no screen at
     * all. Moved one message at a time because that is the whole API §7.11
     * offers — and it is scoped to this consumer, so nothing else's mail
     * can move by accident.
     */
    private function moveMail(Camp $from, Camp $to): void
    {
        if ($this->inboundMail === null) {
            return;
        }

        $fromReference = CampsMessageConsumer::referenceFor($from->id);
        $toReference = CampsMessageConsumer::referenceFor($to->id);

        foreach ($this->inboundMail->findForReference(CampsMessageConsumer::CONSUMER_ID, $fromReference) as $message) {
            $this->inboundMail->move(
                CampsMessageConsumer::CONSUMER_ID,
                $fromReference,
                $toReference,
                $message->id
            );
        }
    }

    /**
     * The values a side carried that the merged stay will NOT carry.
     *
     * These are what get written into the note — nothing is silently
     * dropped, which is precisely what makes camp merges safe to open to
     * every chief. So they are computed against the row the merge actually
     * produces, never against the surviving row as it stands beforehand:
     * every field resolves to $to's value only when $to HAS one, and the
     * dates resolve across both sides at once. A bare year on the
     * surviving stay next to a real range on the losing one produces a
     * merged stay carrying the range — and it is then the surviving stay's
     * year that was dropped, which comparing $from against $to could never
     * say.
     *
     * @return array<int, string>
     */
    private function losingValues(Camp $from, Camp $to): array
    {
        $price = $to->priceCents ?? $from->priceCents;
        $participants = $to->participantCount ?? $from->participantCount;
        $bookedBy = $to->bookedByName ?? $from->bookedByName;
        $dates = CampLabels::dateRange(
            $to->startDate ?? $from->startDate,
            $to->endDate ?? $from->endDate,
            $to->endDate === null && $from->endDate === null ? ($to->yearOnly ?? $from->yearOnly) : null
        );

        $lost = [];
        foreach ([$from, $to] as $side) {
            if ($side->priceCents !== null && $side->priceCents !== $price) {
                $lost[] = 'prix précédent : ' . CampLabels::money($side->priceCents);
            }
        }
        foreach ([$from, $to] as $side) {
            if ($side->participantCount !== null && $side->participantCount !== $participants) {
                $lost[] = 'participants précédents : ' . $side->participantCount;
            }
        }
        foreach ([$from, $to] as $side) {
            if ($side->bookedByName !== null && $side->bookedByName !== $bookedBy) {
                $lost[] = 'réservation faite par : ' . $side->bookedByName;
            }
        }
        foreach ([$from, $to] as $side) {
            $sideDates = CampLabels::dateRange($side->startDate, $side->endDate, $side->yearOnly);
            if ($sideDates !== '' && $sideDates !== $dates) {
                $lost[] = 'dates précédentes : ' . $sideDates;
            }
        }
        // The status is the one field the surviving stay always keeps, so
        // only the losing side's can be dropped.
        if ($from->status !== $to->status) {
            $lost[] = 'statut précédent : ' . CampLabels::status($from->status);
        }

        return $lost;
    }

    /**
     * @param array<int, string> $lost
     */
    private function appendToNote(
        Camp $from,
        Camp $to,
        array $lost,
        ?int $actorUserAccountId,
        \DateTimeImmutable $today
    ): void
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

    /**
     * Gallery's half of a camp merge — deliberately outside the
     * transaction and deliberately swallowed.
     *
     * The stays are already merged when this runs. Letting a gallery
     * failure propagate would report a completed merge as an error and
     * invite the chief to try again on a pair that no longer exists;
     * letting it roll the merge back would make a photo album's storage
     * configuration able to veto a data correction. Photos left behind are
     * visible and re-movable by hand — neither of the other two is.
     */
    private function movePhotos(Camp $from, Camp $to): void
    {
        try {
            $fromAlbum = $this->albums->existingAlbumIdFor($from);
            if ($fromAlbum === null) {
                return;
            }
            // Named the way every other album of a stay is named
            // (CampsAttachmentController::albumId()): "Domaine de Mozet —
            // 12–19 juillet 2027". An album called "Camp" tells a reader
            // browsing the gallery nothing at all, and this is the ONE
            // place that could create one. Re-read, because the merge has
            // just changed the surviving stay's dates.
            $merged = $this->camps->findById($to->id) ?? $to;
            $toAlbum = $this->albums->albumIdFor($merged, $this->albumTitleFor($merged), 0);
            if ($toAlbum === null) {
                return;
            }

            $this->albums->movePhotos($fromAlbum, $toAlbum);
        } catch (\Throwable) {
            // Non-fatal, by design. See the docblock above.
        }
    }

    private function albumTitleFor(Camp $camp): string
    {
        $place = $this->places->findById($camp->placeId);

        return trim(
            ($place !== null ? $place->name : 'Camp')
            . ' — '
            . CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly)
        );
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
