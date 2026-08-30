<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Controller;

use Core\Config\ScoutYearService;
use Core\Exception\UserFacingMessage;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Service\IntegerInput;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Service\BatchPublicationService;
use Modules\Attestations\Service\BatchVerificationService;
use Modules\Attestations\Service\DuplicateDetector;
use Modules\Attestations\Value\DeliveryState;
use Modules\Attestations\Value\BatchStatus;
use Modules\Attestations\Value\MatchState;
use Twig\Environment;

/**
 * One batch: the verification screen and the two decisions taken on it.
 *
 * **This screen is the reader's only chance to check anything.** Once
 * published, a certificate is readable by its family and by nobody else —
 * `files.owner_member_id` (ARCHITECTURE.md §8.3) — so nothing downstream
 * can notice that a line went to the wrong person. Everything about this
 * page is built around that: the name as PRINTED beside the member it was
 * matched to, an unresolved line that cannot be ticked, and a filter that
 * hides rows without ever changing what they hold.
 */
class BatchController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private BatchRepository $batches,
        private BatchLineRepository $lines,
        private MemberNameRepository $members,
        private BatchVerificationService $verification,
        private BatchPublicationService $publication,
        private DuplicateDetector $duplicates,
        private ScoutYearService $scoutYears
    ) {
    }

    /**
     * GET /admin/attestations/{id}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $batchId = IntegerInput::id($params['id'] ?? null);
        if ($batchId === null) {
            return $this->notFound();
        }

        $batch = $this->batches->findById($batchId);
        if ($batch === null) {
            return $this->notFound();
        }

        $lines = $this->lines->findByBatch($batchId);

        // Every member any line names — matched or merely a candidate — so
        // the screen can name them all in one decryption pass rather than
        // one per row.
        $memberIds = [];
        foreach ($lines as $line) {
            if ($line->memberId !== null) {
                $memberIds[] = $line->memberId;
            }
            foreach ($line->candidateMemberIds as $candidateId) {
                $memberIds[] = $candidateId;
            }
        }
        $summaries = $this->members->findSummaries(array_values(array_unique($memberIds)));

        $year = $this->scoutYears->findById($batch->scoutYearId);

        return $this->render('@attestations/batch.html.twig', [
            'batch' => $batch,
            'lines' => $lines,
            'summaries' => $summaries,
            'warnings' => $this->duplicates->warningsFor($batch, $lines),
            'functions' => $this->functionFilterItems($lines, $summaries),
            'scout_year_label' => $year['label'] ?? '',
            'selected_count' => $this->countSelected($lines),
            'pending_count' => $this->countPending($lines),
            'is_editable' => $batch->status === BatchStatus::Draft,
            'delivery_counts' => $this->deliveryCounts($batchId),
            'delivery_labels' => array_combine(
                array_map(static fn(DeliveryState $state): string => $state->value, DeliveryState::cases()),
                array_map(static fn(DeliveryState $state): string => $state->label(), DeliveryState::cases())
            ),
            'match_state_matched' => MatchState::Matched,
            'match_state_ambiguous' => MatchState::Ambiguous,
        ]);
    }

    /**
     * POST /admin/attestations/{id}/rattacher — resolve one line.
     *
     * @param array<string, string> $params
     */
    public function assign(Request $request, array $params): Response
    {
        $batchId = IntegerInput::id($params['id'] ?? null);
        if ($batchId === null) {
            return $this->notFound();
        }

        $path = AttestationsController::PATH . '/' . $batchId;
        if (($guard = $this->guardCsrf($request, $path)) !== null) {
            return $guard;
        }

        $lineId = IntegerInput::id($request->getBody('line_id', ''));
        $memberId = IntegerInput::id($request->getBody('member_id', ''));

        if ($lineId === null || $memberId === null) {
            FlashMessage::set('error', 'Choisissez un membre dans la liste proposée.');

            return $this->redirect($path);
        }

        try {
            $this->verification->assignMember($batchId, $lineId, $memberId);
            FlashMessage::set('success', 'Attestation rattachée.');
        } catch (\Throwable $e) {
            FlashMessage::set('error', UserFacingMessage::from(
                $e,
                'Le rattachement a échoué. Rechargez la page et réessayez.'
            ));
        }

        return $this->redirect($path);
    }

    /**
     * POST /admin/attestations/{id}/publier — commit the selection and put
     * the kept certificates on their members' pages.
     *
     * One gesture, because it is one decision. From here the batch is
     * read-only to its own staff: `owner_member_id` makes every certificate
     * unreadable by whoever published it, so there is nothing left to check
     * and nothing left to correct — only to take back in full.
     *
     * @param array<string, string> $params
     */
    public function publish(Request $request, array $params): Response
    {
        $batchId = IntegerInput::id($params['id'] ?? null);
        if ($batchId === null) {
            return $this->notFound();
        }

        $path = AttestationsController::PATH . '/' . $batchId;
        if (($guard = $this->guardCsrf($request, $path)) !== null) {
            return $guard;
        }

        // All or nothing: a list of ids where one does not parse is a
        // selection nobody made, and applying the rest would delete
        // certificates the reader meant to keep (Core\Service\IntegerInput
        // ::idList()'s own reasoning, SECURITY.md §35).
        $selected = IntegerInput::idList($request->getBody('line_ids', []));
        if ($selected === null) {
            FlashMessage::set('error', 'La sélection n\'a pas pu être lue. Rechargez la page et réessayez.');

            return $this->redirect($path);
        }

        try {
            $result = $this->publication->publish($batchId, $selected, AuthSession::getUserAccountId());
            FlashMessage::set('success', sprintf(
                '%d attestation(s) publiée(s)%s.',
                $result['published'],
                $result['discarded'] === 0
                    ? ''
                    : sprintf(', %d écartée(s) et supprimée(s)', $result['discarded'])
            ));
        } catch (\Throwable $e) {
            FlashMessage::set('error', UserFacingMessage::from(
                $e,
                'La publication a échoué. Rechargez la page et réessayez.'
            ));
        }

        return $this->redirect($path);
    }

    /**
     * POST /admin/attestations/{id}/prevenir — send the batch out.
     *
     * Always a gesture, never automatic: a certificate has a short window
     * of use, and a family that does not know theirs is there will ask for
     * it in June, by e-mail, to the treasurer.
     *
     * @param array<string, string> $params
     */
    public function notify(Request $request, array $params): Response
    {
        $batchId = IntegerInput::id($params['id'] ?? null);
        if ($batchId === null) {
            return $this->notFound();
        }

        $path = AttestationsController::PATH . '/' . $batchId;
        if (($guard = $this->guardCsrf($request, $path)) !== null) {
            return $guard;
        }

        try {
            $this->publication->startDistribution($batchId, AuthSession::getUserAccountId());
            FlashMessage::set(
                'success',
                'Envoi lancé. Les messages partent par petits groupes ; revenez sur cette page pour suivre.'
            );
        } catch (\Throwable $e) {
            FlashMessage::set('error', UserFacingMessage::from(
                $e,
                'L\'envoi n\'a pas pu être lancé. Rechargez la page et réessayez.'
            ));
        }

        return $this->redirect($path);
    }

    /**
     * How many certificates are in each delivery state, in the enum's own
     * order so the screen reads the same every time.
     *
     * @return array<string, int>
     */
    private function deliveryCounts(int $batchId): array
    {
        $stored = $this->lines->countByDeliveryState($batchId);

        $counts = [];
        foreach (DeliveryState::cases() as $state) {
            $counts[$state->value] = $stored[$state->value] ?? 0;
        }

        return $counts;
    }

    /**
     * The function filter's items, in `partials/select_bar.html.twig`'s
     * shape.
     *
     * The set comes from the database, varies from one unit to the next and
     * carries long labels — « Animateur d'unité », « Équipier d'unité » —
     * which is exactly the rule's "open-ended set, coming from the
     * database → select bar" side (design.md §1.4). A nav rail is for a
     * fixed set declared in code.
     *
     * Only functions somebody on screen actually holds are offered: a
     * filter naming a function no row carries hides everything and explains
     * nothing.
     *
     * @param list<\Modules\Attestations\Repository\BatchLine> $lines
     * @param array<int, \Modules\Attestations\Value\MemberSummary> $summaries
     * @return list<array{id: string, label: string, badge: string, selected: bool}>
     */
    private function functionFilterItems(array $lines, array $summaries): array
    {
        $counts = [];
        foreach ($lines as $line) {
            $function = $line->memberId !== null
                ? ($summaries[$line->memberId]->functionLabel ?? null)
                : null;

            if ($function !== null) {
                $counts[$function] = ($counts[$function] ?? 0) + 1;
            }
        }

        ksort($counts);

        $items = [];
        foreach ($counts as $label => $count) {
            $items[] = [
                'id' => (string) $label,
                'label' => (string) $label,
                'badge' => (string) $count,
                'selected' => false,
            ];
        }

        return $items;
    }

    /** @param list<\Modules\Attestations\Repository\BatchLine> $lines */
    private function countSelected(array $lines): int
    {
        return count(array_filter($lines, static fn($line): bool => $line->isSelected));
    }

    /** @param list<\Modules\Attestations\Repository\BatchLine> $lines */
    private function countPending(array $lines): int
    {
        return count(array_filter($lines, static fn($line): bool => $line->needsAttention()));
    }
}
