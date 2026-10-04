<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Rental\Availability\BlockDayPlan;
use Modules\Rental\Availability\BlockDayPlanner;
use Modules\Rental\Repository\RentalBlock;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Support;

/**
 * Manual blocks (§6.18): a period a manager takes off the market.
 *
 * **A block over an already-booked period is accepted, deliberately.** The
 * spec is explicit that it must neither fail silently nor overwrite the
 * booking — a caretaker away during a rental is a real thing to record, and
 * refusing would leave the manager no way to record it. The two coexist:
 * availability adds them up, so the period simply becomes doubly taken, and
 * the private calendar shows both. What the manager gets instead of a
 * refusal is a **warning naming the bookings involved**, so an accidental
 * overlap is visible rather than hidden.
 */
class RentalBlockService
{
    /** How many days one gesture may carry: a month, with room to spare. */
    public const MAX_DAYS_PER_GESTURE = 62;

    private BlockDayPlanner $planner;

    public function __construct(
        private RentalBlockRepository $blockRepository,
        private JournalService $journal,
        ?BlockDayPlanner $planner = null
    ) {
        $this->planner = $planner ?? new BlockDayPlanner();
    }

    /**
     * @throws RentalException
     */
    public function create(
        int $assetId,
        string $startDate,
        string $endDate,
        ?string $reason,
        ?int $createdByMemberId
    ): int {
        if (!Support::isDate($startDate) || !Support::isDate($endDate)) {
            throw new RentalException('Les dates du blocage ne sont pas valides.');
        }

        if ($endDate < $startDate) {
            throw new RentalException('La date de fin ne peut pas précéder la date de début.');
        }

        $id = $this->blockRepository->create(
            $assetId,
            $startDate,
            $endDate,
            self::cleanReason($reason),
            $createdByMemberId
        );

        // The reason is written by managers about the asset, never about a
        // person, so it is safe in the journal — which is exactly why the
        // interface has to keep it that way (SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_block_created',
            'info',
            'Blocage manuel du ' . $startDate . ' au ' . $endDate,
            ['asset_id' => $assetId, 'block_id' => $id]
        );

        return $id;
    }

    /**
     * Block or release days from the managed calendar (#708, IT-07), and
     * turn them back into periods.
     *
     * **Past days are refused, not skipped.** The grid never offers them, so
     * one arriving here is a stale page or a hand-made request — and quietly
     * applying the rest would tell the manager « 5 jours bloqués » about a
     * gesture that was not what they did.
     *
     * Overlapping a booking is accepted, as a period always was (§6.18): the
     * two coexist and the calendar shows both.
     *
     * @param string[] $days `Y-m-d`
     * @param array<string, string|null> $reasons Each day's reason — an undo's; a fresh gesture gives none.
     * @return array<string, string|null> What changed: each day, with the reason it carries now (blocked) or carried
     *     before (released) — exactly what an undo sends back.
     * @throws RentalException
     */
    public function applyDays(
        int $assetId,
        array $days,
        string $mode,
        array $reasons,
        \DateTimeImmutable $today,
        ?int $actorMemberId
    ): array {
        if (!in_array($mode, [BlockDayPlanner::MODE_BLOCK, BlockDayPlanner::MODE_RELEASE], true)) {
            throw new RentalException('Action inconnue sur le calendrier.');
        }

        if ($days === [] || count($days) > self::MAX_DAYS_PER_GESTURE) {
            throw new RentalException('Aucun jour à traiter.');
        }

        $todayKey = $today->format('Y-m-d');
        foreach ($days as $day) {
            if (!Support::isDate($day)) {
                throw new RentalException('Une des dates n\'est pas valide.');
            }
            if ($day < $todayKey) {
                throw new RentalException('Un jour passé ne peut plus être bloqué ni libéré.');
            }
        }

        // Read, plan and write under one lock: two gestures sent before the
        // first answer must not both plan from the same snapshot.
        $plan = $this->blockRepository->withAssetLocked(
            $assetId,
            function () use ($assetId, $days, $mode, $reasons, $actorMemberId): BlockDayPlan {
                $plan = $this->planner->plan($this->blockRepository->findAllForAsset($assetId), $days, $mode, $reasons);
                if (!$plan->isEmpty()) {
                    $this->blockRepository->replace($assetId, $plan->replacedBlockIds, $plan->periods, $actorMemberId);
                }

                return $plan;
            }
        );
        if ($plan->isEmpty()) {
            return [];
        }

        $changed = array_keys($plan->changed);
        $this->journal->log(
            'rental',
            $mode === BlockDayPlanner::MODE_BLOCK ? 'rental_block_days_blocked' : 'rental_block_days_released',
            'info',
            ($mode === BlockDayPlanner::MODE_BLOCK ? 'Jours bloqués' : 'Jours libérés')
                . ' du ' . $changed[0] . ' au ' . $changed[count($changed) - 1],
            ['asset_id' => $assetId, 'days' => count($changed)]
        );

        return $plan->changed;
    }

    /**
     * Give or change a period's reason, from the list under the calendar:
     * a period blocked by a gesture is created without one.
     *
     * @throws RentalException
     */
    public function setReason(int $assetId, int $blockId, ?string $reason): void
    {
        $block = $this->blockRepository->findById($blockId);
        if ($block === null || $block->assetId !== $assetId) {
            throw new RentalException("Ce blocage n'existe pas.");
        }

        $this->blockRepository->updateReason($blockId, self::cleanReason($reason));
    }

    /**
     * @throws RentalException
     */
    public function delete(int $assetId, int $blockId): void
    {
        $block = $this->blockRepository->findById($blockId);
        if ($block === null || $block->assetId !== $assetId) {
            // The asset check is the real guard: a block id alone must not
            // let a manager of one asset delete another asset's block.
            throw new RentalException("Ce blocage n'existe pas.");
        }

        $this->blockRepository->delete($blockId);

        $this->journal->log(
            'rental',
            'rental_block_deleted',
            'info',
            'Blocage manuel supprimé du ' . $block->startDate . ' au ' . $block->endDate,
            ['asset_id' => $assetId, 'block_id' => $blockId]
        );
    }

    /**
     * The blocks overlapping a window — what the managed calendar lays over
     * its days.
     *
     * @return RentalBlock[]
     */
    public function between(int $assetId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->blockRepository->findBetween($assetId, $from->format('Y-m-d'), $to->format('Y-m-d'));
    }

    /**
     * @return RentalBlock[]
     */
    public function upcomingFor(int $assetId, \DateTimeImmutable $from): array
    {
        return $this->blockRepository->findUpcoming($assetId, $from->format('Y-m-d'));
    }

    private static function cleanReason(?string $reason): ?string
    {
        $reason = $reason !== null ? trim($reason) : null;

        return $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null;
    }
}
