<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Rental\Repository\RentalBlock;
use Modules\Rental\Repository\RentalBlockRepository;

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
    public function __construct(
        private RentalBlockRepository $blockRepository,
        private JournalService $journal
    ) {
    }

    /**
     * @throws RentalException
     */
    public function create(
        int $assetId,
        string $startDate,
        string $endDate,
        int $units,
        ?string $reason,
        ?int $createdByMemberId
    ): int {
        if (!self::isDate($startDate) || !self::isDate($endDate)) {
            throw new RentalException('Les dates du blocage ne sont pas valides.');
        }

        if ($endDate < $startDate) {
            throw new RentalException('La date de fin ne peut pas précéder la date de début.');
        }

        $reason = $reason !== null ? trim($reason) : null;

        $id = $this->blockRepository->create(
            $assetId,
            $startDate,
            $endDate,
            max(1, $units),
            $reason !== '' ? $reason : null,
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
     * @return RentalBlock[]
     */
    public function upcomingFor(int $assetId, \DateTimeImmutable $from): array
    {
        return $this->blockRepository->findUpcoming($assetId, $from->format('Y-m-d'));
    }

    private static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
