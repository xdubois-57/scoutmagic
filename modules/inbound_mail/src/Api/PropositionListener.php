<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * Optional companion to `MessageConsumerInterface`: a consumer that wants
 * to be told when propositions of its own were **actually written** — so
 * it can tell the people who will settle them.
 *
 * Fired once per proposition, ever, from every path that writes one
 * (arrival, deferred, re-analysis): a re-read after a UIDVALIDITY reset
 * or a second « Relancer l'analyse » that proposes the same thing again
 * writes nothing new and calls nobody. Failure inside is caught and
 * journalled by the gateway — the proposition rows are already written,
 * and a notification that could not be sent must not undo an analysis.
 *
 * Same shape as `ReferenceDirectory` and `MessageRetentionPreference`:
 * nothing changes for a consumer that does not implement it.
 */
interface PropositionListener
{
    /**
     * @param MessageCandidate[] $candidates the propositions just written
     *   for this consumer on that message, in the order the consumer gave
     *   them
     */
    public function onProposed(InboundMessage $message, array $candidates): void;
}
