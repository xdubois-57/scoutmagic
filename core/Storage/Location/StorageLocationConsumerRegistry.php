<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Storage\Location\Config\LocationConfig;

/**
 * Every {@see StorageLocationConsumer} this installation has.
 *
 * A mutable registry rather than a constructor list, for the reason
 * ARCHITECTURE.md §7.6 gives: `public/index.php` is a straight-line
 * script, and the services that ask the question are built before the
 * modules that answer it. The registry object is handed over once and
 * filled in later; a consumer registered after the fact still reaches
 * every holder of it.
 *
 * **The registry itself never swallows a consumer's exception**, and that
 * is a correction rather than a preference. It used to, on the grounds
 * that a module in trouble must not take the storage page down with it —
 * true, and the calendar makes exactly that trade for its contributed
 * events. But the same answer is also what AUTHORISES a deletion, and
 * there « I could not ask » and « nobody is using it » are opposite
 * conclusions: swallowed, a transient database error turned into a clean
 * removal of a location whose files something was still standing on.
 *
 * So the leniency moved to the callers that can afford it.
 * {@see StorageLocationService::usagesOf()} catches, for the screens —
 * showing one usage fewer beats a configuration page that cannot be
 * opened to fix whatever broke. {@see StorageLocationService::delete()}
 * does not, and refuses. {@see all()} keeps its own catch because it
 * feeds a dashboard and decides nothing.
 */
class StorageLocationConsumerRegistry
{
    /** @var list<StorageLocationConsumer> */
    private array $consumers = [];

    public function register(StorageLocationConsumer $consumer): void
    {
        $this->consumers[] = $consumer;
    }

    /**
     * Whether anything at all consumes storage locations on this
     * installation — **answered from memory, without a query**.
     *
     * The difference from `all() === []` matters: that one ASKS every
     * consumer, and each of them reads the database to answer. This one
     * only knows whether anybody registered, which is what a caller
     * deciding « is it worth looking at the locations at all » needs.
     *
     * The response tail uses it: the Content-Security-Policy only has to
     * name a storage origin when something is going to render an image
     * from one, and nothing renders anything when no consumer exists.
     * Without it, every request on the site — JSON endpoints and health
     * checks included — paid for a `SELECT` against `storage_locations`
     * on an installation that has no gallery and never will.
     */
    public function isEmpty(): bool
    {
        return $this->consumers === [];
    }

    /**
     * The French names of everything standing on $locationId, in
     * registration order and without duplicates.
     *
     * **Propagates a consumer's failure rather than skipping it.** A
     * caller that can afford « I could not ask » decides so itself, in
     * one place, with its eyes open; a caller whose next move depends on
     * the answer must not be handed an incomplete one that looks
     * complete. See the class docblock for what that cost when it was the
     * other way round.
     *
     * @return list<string>
     * @throws \Throwable whatever a consumer raised
     */
    public function usagesOf(int $locationId): array
    {
        $labels = [];

        foreach ($this->consumers as $consumer) {
            $ids = $consumer->locationIdsInUse();

            if (in_array($locationId, $ids, true) && !in_array($consumer->usageLabel(), $labels, true)) {
                $labels[] = $consumer->usageLabel();
            }
        }

        return $labels;
    }

    /**
     * The first consumer's objection to $location becoming
     * $proposedConfig, or null when nobody objects.
     *
     * **Fails closed, like {@see usagesOf()} and for the same reason.** A
     * consumer that cannot be asked has not said « no objection » — it has
     * said nothing, and the caller is about to write a configuration that
     * may strand what that consumer holds. So the exception travels, and
     * the screen turns it into a refusal rather than into a save.
     *
     * First rather than all of them: the administrator has to fix one
     * thing before the save can go through in any case, and a list of
     * sentences is not more actionable than the sentence at the top of it.
     *
     * @throws \Throwable whatever a consumer raised
     */
    public function objectionTo(
        StorageLocation $location,
        LocationConfig $proposedConfig,
        bool $wouldBeDefault
    ): ?string {
        foreach ($this->consumers as $consumer) {
            $objection = $consumer->objectionTo($location, $proposedConfig, $wouldBeDefault);
            if ($objection !== null) {
                return $objection;
            }
        }

        return null;
    }

    /**
     * Every usage and the location it currently stands on — what the
     * dashboard lists, and what says « Galeries photo → Nextcloud de
     * l'unité » without the storage page having to know what a gallery is.
     *
     * This one DOES skip a consumer that cannot answer, and may: it draws
     * a list and decides nothing. A line missing from a dashboard is a
     * line missing from a dashboard.
     *
     * @return list<array{usage: string, locationIds: list<int>}>
     */
    public function all(): array
    {
        $rows = [];

        foreach ($this->consumers as $consumer) {
            try {
                $ids = $consumer->locationIdsInUse();
            } catch (\Throwable) {
                continue;
            }

            $rows[] = ['usage' => $consumer->usageLabel(), 'locationIds' => array_values(array_unique($ids))];
        }

        return $rows;
    }
}
