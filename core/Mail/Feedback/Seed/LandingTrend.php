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
 * **Its own weekly threshold, and the first version got this wrong.** It
 * reused `DomainRouting::MINIMUM_RUNS` on the argument that the same evidence
 * deserves the same judgement. That argument ignored the WINDOW: those five
 * mailings are five per THIRTY DAYS (`SEEDS_WINDOW`), and reused as a
 * per-ISO-week threshold they are four times stricter than the constant ever
 * meant. With « three to five boxes and a few mailings a year » — this
 * repository's own stated volume — no week ever reaches five, so every series
 * was empty, the « drawable » filter this class then carried returned
 * nothing, and the card could only ever render its own empty state. The
 * feature was inert, and measured as inert before this was written.
 *
 * **And the left edge is read from the purge**, never restated, so the curve
 * stops where the rows stop.
 */
final class LandingTrend
{
    /**
     * How many measured mailings a week needs before its share means anything.
     *
     * **One, decided rather than inherited** (maintainer, 27 September). The
     * job of a threshold here is to refuse ZERO evidence, not to demand five:
     * `MINIMUM_RUNS` guards an AUTOMATIC ACTION — routing a whole domain away
     * — which is a higher bar than showing a figure to somebody who can weigh
     * it. A week carrying one measured mailing is a measurement, and drawing
     * it as a hole would say « not measured » about a week that was.
     *
     * The admitted price is that a point can rest on a single mailing, whose
     * share over three to five boxes is coarse. That is why `sample` reaches
     * the tooltip: the screen says « 1 publipostage mesuré » and the reader
     * weighs the point accordingly, which a hole would never have let them do.
     *
     * It is deliberately a constant of its own, beside the data it judges,
     * exactly as {@see \Core\Mail\Feedback\Dmarc\AuthenticationTrend::MINIMUM_MESSAGES}
     * is — and for the same reason that one is not `MINIMUM_RUNS` either.
     */
    public const MINIMUM_MAILINGS = 1;

    /**
     * @param ?\DateTimeImmutable $now injected so a test can stand somewhere
     *   other than today — the partial week and the left edge both depend on it
     * **Every provider returned has at least one drawn week, so there is no
     * « drawable » filter to apply.** An earlier version carried one, and its
     * mutation survived — proof it could never fire. The reason is structural:
     * `landingsPerRunSince()` excludes pending copies, so a provider reaches
     * this map only with at least one ANSWERED mailing inside the window; that
     * mailing falls in one of the walked weeks, where `sample` is then at least
     * one and `total` at least one — which clears `MINIMUM_MAILINGS`. A second
     * guard for a case the query already refuses would be one boundary with two
     * mechanisms, the shape whose mutation survives because each copy hides the
     * other's absence. A test pins the invariant instead.
     *
     * @return array<string, WeeklySeries> keyed by the ATTRIBUTED provider —
     *   the same key the ranking screen groups on (issue #422), so a box on a
     *   personal domain is one of its host's lines and not a line of its own —
     *   in the order the repository returns them, which is alphabetical
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
                self::MINIMUM_MAILINGS,
                $edge,
                $now
            );
        }

        return $series;
    }

}
