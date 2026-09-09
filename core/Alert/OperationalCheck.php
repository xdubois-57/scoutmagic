<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

/**
 * One thing about this installation that can be wrong, and that nobody
 * would otherwise notice.
 *
 * The site observes a great deal and, before this, alerted about none of
 * it: sixteen support collectors, request timelines, a supervision
 * dashboard — all of it *consulted*. On shared hosting, with a volunteer
 * who passes by once a week, the difference between « the site told me »
 * and « the parents told me » is the whole subject.
 *
 * A check is deliberately tiny: it reads one fact and compares it against
 * its own two thresholds. It does not know about notifications, about the
 * `operational_alerts` table, or about whether it has fired before —
 * {@see OperationalAlertService} owns all of that, once, for every check.
 *
 * **A check must not throw for an ordinary "I cannot tell".** A host that
 * will not report its disk, a table that is empty on a fresh install, a
 * setting nobody has filled in: each of those is
 * {@see AlertReading::inconclusive()}, which leaves a triggered alert
 * triggered and an armed one armed. Throwing is for a genuine fault, and
 * the service catches it so one broken check cannot stop the others.
 */
interface OperationalCheck
{
    /**
     * This check's stable key — `operational_alerts.alert_key`, and the
     * identity its state is stored under. Lowercase snake_case, and never
     * changed once shipped: a renamed key silently re-arms an alert that
     * was triggered, because the row it used to read no longer matches.
     */
    public function key(): string;

    /** A short French name for the surface, for the journal and the screen. */
    public function label(): string;

    public function read(): AlertReading;
}
