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
use Modules\Camps\Repository\Review;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;

/**
 * What a staff thought of a stay, written for the staff that comes after.
 *
 * Two rules carry the whole feature, and both are enforced here rather
 * than only in the form — a form is a suggestion, a service is the answer:
 *
 * 1. A review can be left on ANY past stay, cancelled ones included. A
 *    place that cancelled on its guests three weeks before departure is
 *    precisely what a future staff needs to know, and refusing to record
 *    it would lose the single most useful thing about that booking.
 * 2. A CANCELLED stay gets a comment and never a rating. Nobody camped
 *    there, so there is nothing to rate; and letting it carry a number
 *    would let a cancellation become the place's displayed rating.
 */
class ReviewService
{
    public function __construct(
        private ReviewRepository $reviews,
        private AuditService $audit,
        private ?PlaceRepository $places = null
    ) {
    }

    /**
     * Whether the form should be offered at all. A stay still to come has
     * nothing to review yet — which is a different sentence from "you may
     * not", and the template says so.
     */
    public function isOpen(Camp $camp, \DateTimeImmutable $today): bool
    {
        // A year-only stay opens on 1 January of the following year: it
        // has no end date to be past, and its year is the only thing that
        // can be over.
        return !$camp->isUpcoming($today);
    }

    public function allowsRating(Camp $camp): bool
    {
        return !$camp->isCancelled();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function save(
        Camp $camp,
        array $fields,
        ?int $authorMemberId,
        ?int $actorUserAccountId,
        \DateTimeImmutable $today
    ): void
    {
        if (!$this->isOpen($camp, $today)) {
            throw new CampsException(
                'L\'avis s\'ouvrira au retour du camp — il n\'y a encore rien à raconter.'
            );
        }

        $rating = $this->cleanRating($fields['rating'] ?? null);
        $comment = $this->cleanComment($fields['comment'] ?? null);

        if ($rating !== null && !$this->allowsRating($camp)) {
            throw new CampsException(
                'Un séjour annulé ne reçoit pas de note : personne n\'y a campé. '
                . 'Le commentaire, lui, est précieux — dites ce qui s\'est passé.'
            );
        }
        if ($rating === null && $comment === null) {
            throw new CampsException('Un avis a besoin d\'une note ou d\'un commentaire.');
        }

        $before = $this->reviews->findByCamp($camp->id);
        $this->reviews->save($camp->id, $rating, $comment, $authorMemberId);

        // A review is the single most summary-changing thing about a
        // place, so this is where staleness matters most.
        $this->places?->markSummaryStale($camp->placeId);

        $this->audit->record(
            CampService::ENTITY_TYPE,
            $camp->id,
            'review',
            $before !== null ? $this->describe($before->rating, $before->comment) : null,
            $this->describe($rating, $comment),
            AuditSource::Human,
            $before !== null ? 'Avis modifié' : 'Avis ajouté',
            null,
            $actorUserAccountId
        );
    }

    public function delete(Camp $camp, ?int $actorUserAccountId): void
    {
        $before = $this->reviews->findByCamp($camp->id);
        if ($before === null) {
            return;
        }

        $this->reviews->delete($camp->id);
        $this->places?->markSummaryStale($camp->placeId);
        $this->audit->record(
            CampService::ENTITY_TYPE, $camp->id, 'review',
            $this->describe($before->rating, $before->comment), null,
            AuditSource::Human, 'Avis supprimé', null, $actorUserAccountId
        );
    }

    /**
     * The review as one line for the change history — the rating, and
     * whether there is a comment, never the comment itself. A comment is
     * a paragraph a chief wrote; repeating both versions of it on every
     * edit would bury every other change on the timeline.
     */
    private function describe(?int $rating, ?string $comment): string
    {
        $parts = [];
        if ($rating !== null) {
            $parts[] = $rating . '/' . Review::MAX_RATING;
        }
        if ($comment !== null && trim($comment) !== '') {
            $parts[] = 'commentaire';
        }

        return $parts !== [] ? implode(', ', $parts) : 'aucun avis';
    }

    private function cleanRating(mixed $value): ?int
    {
        $value = is_string($value) || is_int($value) ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $value)) {
            throw new CampsException('La note doit être un nombre entre 1 et 5.');
        }

        $rating = (int) $value;
        if ($rating < Review::MIN_RATING || $rating > Review::MAX_RATING) {
            throw new CampsException('La note doit être un nombre entre 1 et 5.');
        }

        return $rating;
    }

    private function cleanComment(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
