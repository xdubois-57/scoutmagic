<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Availability;

use Core\Service\DateInput;
use Modules\Rental\Repository\RentalBlock;

/**
 * Turns a gesture on the manager's calendar — « these days, blocked » or
 * « these days, released » — into the unit's blocked PERIODS (#708, IT-07).
 *
 * A block stays a period (start, end, reason), never a set of days: the
 * gesture speaks in days, so this class translates. Days blocked next to an
 * existing period **extend** it and take its reason; releasing the middle of
 * a period **cuts** it in two, each piece keeping its reason; releasing a
 * whole period removes it. Two periods merge only when they carry the same
 * reason — so a period with a reason never swallows one with another.
 *
 * **Undo is the same call the other way round.** Releasing days reports
 * the reason each one had (`BlockDayPlan::$changed`); blocking them again
 * with those reasons rebuilds exactly the periods there were.
 *
 * Only the periods the gesture touches or borders are rebuilt. Two periods
 * the manager created side by side, far from the gesture, stay exactly as
 * they were.
 *
 * Pure: no database, no clock. The service applies the plan.
 */
final class BlockDayPlanner
{
    public const MODE_BLOCK = 'block';
    public const MODE_RELEASE = 'release';

    /**
     * @param RentalBlock[] $blocks The asset's blocks.
     * @param string[] $days The gesture's days, `Y-m-d`.
     * @param array<string, string|null> $reasons The reason a day should carry when blocked — an undo gives each day
     *     back the reason it had; a fresh gesture gives none.
     */
    public function plan(array $blocks, array $days, string $mode, array $reasons = []): BlockDayPlan
    {
        $days = array_values(array_unique(array_filter($days, static fn(string $d): bool => $d !== '')));
        sort($days);

        if ($days === []) {
            return new BlockDayPlan([], [], []);
        }

        $touched = [];
        foreach ($blocks as $block) {
            foreach ($days as $day) {
                if (self::shift($block->startDate, -1) <= $day && $day <= self::shift($block->endDate, 1)) {
                    $touched[$block->id] = $block;
                    break;
                }
            }
        }

        // Day → reason over every touched period. A day held by two
        // overlapping periods keeps the first one's reason: overlap was never
        // meaningful, and rebuilding is where it quietly goes away.
        $held = [];
        foreach ($touched as $block) {
            for ($day = $block->startDate; $day <= $block->endDate; $day = self::shift($day, 1)) {
                if (!array_key_exists($day, $held)) {
                    $held[$day] = $block->reason;
                }
            }
        }

        /** @var array<string, string|null> $changed */
        $changed = [];
        /** @var array<string, true> $inheriting new days with no reason of their own */
        $inheriting = [];
        foreach ($days as $day) {
            if ($mode === self::MODE_BLOCK && !array_key_exists($day, $held)) {
                if (array_key_exists($day, $reasons)) {
                    $held[$day] = self::cleanReason($reasons[$day]);
                } else {
                    $held[$day] = null;
                    $inheriting[$day] = true;
                }
                $changed[$day] = null;
            } elseif ($mode === self::MODE_RELEASE && array_key_exists($day, $held)) {
                $changed[$day] = $held[$day];
                unset($held[$day]);
            }
        }

        // Days blocked next to a period EXTEND it, reason included: from the
        // left first, then from the right for a run that only touches a
        // period on that side. A run bridging two periods joins the left
        // one, and the right one keeps its own reason unless it is the same.
        foreach ($days as $day) {
            $before = self::shift($day, -1);
            if (isset($inheriting[$day]) && array_key_exists($before, $held) && !isset($inheriting[$before])) {
                $held[$day] = $held[$before];
                unset($inheriting[$day]);
            }
        }
        foreach (array_reverse($days) as $day) {
            $after = self::shift($day, 1);
            if (isset($inheriting[$day]) && array_key_exists($after, $held) && !isset($inheriting[$after])) {
                $held[$day] = $held[$after];
                unset($inheriting[$day]);
            }
        }
        foreach (array_keys($changed) as $day) {
            if ($mode === self::MODE_BLOCK) {
                $changed[$day] = $held[$day];
            }
        }

        if ($changed === []) {
            return new BlockDayPlan([], [], []);
        }

        ksort($held);
        $periods = [];
        $current = null;
        foreach ($held as $day => $reason) {
            if ($current !== null && $current['end'] === self::shift($day, -1) && $current['reason'] === $reason) {
                $current['end'] = $day;
                continue;
            }
            if ($current !== null) {
                $periods[] = $current;
            }
            $current = ['start' => $day, 'end' => $day, 'reason' => $reason];
        }
        if ($current !== null) {
            $periods[] = $current;
        }

        return new BlockDayPlan(array_keys($touched), $periods, $changed);
    }

    private static function cleanReason(?string $reason): ?string
    {
        $reason = $reason !== null ? trim($reason) : null;

        return $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null;
    }

    private static function shift(string $day, int $by): string
    {
        return DateInput::requireFromStorage($day, 'block day')->modify(($by >= 0 ? '+' : '') . $by . ' day')->format('Y-m-d');
    }
}
