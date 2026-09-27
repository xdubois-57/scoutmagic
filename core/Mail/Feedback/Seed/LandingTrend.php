<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

use Core\Mail\Feedback\Seed\Task\PurgeSeedCopiesHandler;
use Core\Mail\Feedback\Trend\WeeklySeries;

/**
 * « Ce fournisseur se met-il à filtrer, ou a-t-il toujours filtré ? » — the
 * question the seed ranking could not answer (issue #420).
 *
 * **One series per provider**, because the question is comparative: a
 * provider that starts filtering is read against the ones that did not. The
 * screen draws them on one chart for that reason (maintainer, 27 September).
 *
 * **No threshold of its own.** It is `MINIMUM_RUNS`, the same constant the
 * automatic routing is gated on, because it is the same judgement about the
 * same evidence — a provider's figures mean nothing under five measured
 * mailings. A second number here would be a second opinion about when this
 * data is worth acting on, and the two would drift.
 *
 * **And the left edge is read from the purge**, never restated, so the curve
 * stops where the rows stop.
 */
final class LandingTrend
{
    /**
     * @param ?\DateTimeImmutable $now injected so a test can stand somewhere
     *   other than today — the partial week and the left edge both depend on it
     * @return array<string, WeeklySeries> keyed by provider, in the order the
     *   repository returns them, which is alphabetical
     */
    public static function build(
        SeedCopyRepository $copies,
        ?\DateTimeImmutable $now = null
    ): array {
        $now ??= new \DateTimeImmutable();
        $edge = $now->modify('-' . PurgeSeedCopiesHandler::RETENTION_DAYS . ' days');

        $series = [];
        foreach ($copies->landingsPerRunSince($edge) as $provider => $rows) {
            $series[$provider] = WeeklySeries::build(
                $rows,
                DomainRouting::MINIMUM_RUNS,
                $edge,
                $now
            );
        }

        return $series;
    }

    /**
     * The providers whose series has at least one drawable week.
     *
     * **A provider measured too thinly to draw is left off the chart rather
     * than drawn as a flat empty line.** A legend entry with no line beside it
     * reads as « this provider delivered nothing », which is the opposite of
     * « we have not measured it enough to say ».
     *
     * @param array<string, WeeklySeries> $series
     * @return array<string, WeeklySeries>
     */
    public static function drawable(array $series): array
    {
        return array_filter($series, static fn(WeeklySeries $one): bool => !$one->isEmpty());
    }
}
