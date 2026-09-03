<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Api;

/**
 * What this module publishes to the rest of the application
 * (ARCHITECTURE.md §7.5): the per-module aggregate, and nothing else.
 *
 * Its one consumer is `Core\Statistics\StatisticsPayloadBuilder`, which
 * takes it as a nullable dependency — this module disabled means the field
 * is `null` in the report, never `0` and never an empty list. That
 * distinction is load-bearing at the other end: a receiver has to be able
 * to tell « personne n'a ouvert ce module » from « cette installation ne
 * mesure pas », and confounding the two would make a used module look
 * abandoned (ARCHITECTURE.md §8.49).
 *
 * **The detail per page is deliberately not here.** It exists, on the
 * unit's own screens, and it stays there: the project's question is which
 * modules serve, not how often one unit opened its calendar. An interface
 * that could answer the second would eventually be asked to.
 */
interface ModuleUsageInterface
{
    /**
     * How far back the aggregate looks — long enough that a module used
     * once a season (camps, cotisations) does not read as abandoned, and
     * the same window the unit's own « personne ne l'a ouvert » block
     * uses, so the two can never contradict each other.
     */
    public const WINDOW_MONTHS = 12;

    /**
     * One entry per module actually opened over the window, sorted by
     * module id so two reports of an unchanged installation are identical.
     *
     * A module with no opening at all is ABSENT rather than present with
     * a zero: the report already carries the full list of installed
     * modules and their enabled state, so absence from this list is the
     * zero, said once.
     *
     * The application's own pages are not a module and are not listed.
     *
     * @return list<ModuleUsage>
     */
    public function aggregatedByModule(): array;
}
