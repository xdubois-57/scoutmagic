<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

use Core\Mail\Feedback\Dmarc\Task\PurgeDmarcReportsHandler;
use Core\Mail\Feedback\Trend\WeeklySeries;

/**
 * « Est-ce que ça se dégrade, semaine après semaine ? » — the question the
 * DMARC page could not answer (issue #420).
 *
 * **The threshold lives here, beside the data it judges**, the way
 * {@see \Core\Mail\Feedback\Seed\DomainRouting::MINIMUM_RUNS} lives beside
 * the routing it guards rather than in whatever screen happens to read it.
 *
 * **And the left edge is read from the purge**, never restated: the two
 * cannot then drift apart, and a curve that outlived its own retention would
 * promise a « before » that was deleted.
 */
final class AuthenticationTrend
{
    /**
     * How many reported messages a week needs before its authentication rate
     * means anything.
     *
     * **Twenty, counted in MESSAGES, and both halves were decided rather than
     * assumed** (maintainer, 27 September). The rate DMARC reports is a
     * per-message ratio and the page already shows it as one, so messages are
     * what the data actually carries: a report says nothing about which
     * messages belonged to one mailing, and counting anything else here would
     * be asserting a grouping the reporters never sent.
     *
     * The number is not `MINIMUM_RUNS`'s five. Five messages is a couple of
     * individual e-mails, and a rate over them swings to 0 % or 100 % on one
     * of them — the noise a threshold exists to refuse. Twenty is small
     * enough that an ordinary mailing clears it in a single week and large
     * enough that a week of stray messages does not draw a cliff.
     */
    public const MINIMUM_MESSAGES = 20;

    /**
     * @param ?\DateTimeImmutable $now injected so a test can stand somewhere
     *   other than today — the partial week and the left edge both depend on
     *   it, and a series built from the real clock could not pin either
     */
    public static function build(
        DmarcReportRepository $reports,
        ?\DateTimeImmutable $now = null
    ): WeeklySeries {
        $now ??= new \DateTimeImmutable();
        $edge = $now->modify('-' . PurgeDmarcReportsHandler::RETENTION_DAYS . ' days');

        return WeeklySeries::build(
            $reports->messagesPerReportSince($edge),
            self::MINIMUM_MESSAGES,
            $edge,
            $now
        );
    }
}
