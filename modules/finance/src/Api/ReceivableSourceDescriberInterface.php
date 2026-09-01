<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * How a module that registers expected payments names them on
 * « Paiements attendus » (ARCHITECTURE.md §7.5, the module-extended-by-a-
 * module pattern — the same shape `inbound_mail` uses for its consumers).
 *
 * **Finance deliberately knows nothing about a source instance beyond its
 * numeric id**, and that is what lets the page work unchanged for any
 * future module. The price was a screen naming its groups « Location #45 »
 * and « Formulaire #12 » — a database id in front of a chef d'unité, above
 * rows that already read « LOC-2027-0012 — Jean Dupont ». Finance was the
 * only place that could have improved it and the one place that must not
 * learn what a booking is.
 *
 * So the module that created the expectation names it. Nothing here lets a
 * module read another's receivables, or reach any account: it answers two
 * questions about text, and finance decides what to do with the answers.
 *
 * **Registration is the composition root's business.** A describer is
 * wired only when its module is enabled, exactly like every other
 * cross-module capability; a source with no describer keeps the previous
 * behaviour — its module id and its reference id — rather than
 * disappearing.
 */
interface ReceivableSourceDescriberInterface
{
    /**
     * The `source_module` this describer speaks for — the same string the
     * module passes to {@see ExpectedReceivableInterface::createReceivable()}.
     *
     * Asked of the describer rather than configured beside it: a registry
     * keyed by hand is a registry where one entry eventually names the
     * wrong module.
     */
    public function sourceModule(): string;

    /**
     * What this module goes by on the page, plural and in French —
     * « Locations », « Formulaires ». The heading of the group that holds
     * everything this module expects.
     *
     * Finance used to hold this as a hardcoded map, which meant a module
     * could not be named without editing finance, and an unlisted one was
     * shown as `ucfirst($sourceModule)` — « Rental » in front of a French
     * reader.
     */
    public function sourceLabel(): string;

    /**
     * A human name for ONE of this module's references — « Location
     * LOC-2027-0012 — Jean Dupont », « Formulaire : inscription au camp ».
     *
     * Null when the module no longer recognises the reference, which is the
     * ordinary answer for an object somebody deleted: the page then falls
     * back to naming the group by its id, which is honest, where a made-up
     * name would not be.
     *
     * Called once per group rendered, never per row.
     */
    public function describeInstance(int $sourceReferenceId): ?string;
}
