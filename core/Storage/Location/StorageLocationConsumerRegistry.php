<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

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
 * A consumer in trouble must not take the storage page down with it —
 * {@see usagesOf()} swallows a provider's exception, exactly as the
 * calendar does with its contributed events. The cost of that choice is a
 * usage silently missing from a « Sert : … » line; the cost of the other
 * choice is a configuration page that 500s and cannot be used to fix
 * whatever broke.
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
     * The French names of everything standing on $locationId, in
     * registration order and without duplicates.
     *
     * @return list<string>
     */
    public function usagesOf(int $locationId): array
    {
        $labels = [];

        foreach ($this->consumers as $consumer) {
            try {
                $ids = $consumer->locationIdsInUse();
            } catch (\Throwable) {
                continue;
            }

            if (in_array($locationId, $ids, true) && !in_array($consumer->usageLabel(), $labels, true)) {
                $labels[] = $consumer->usageLabel();
            }
        }

        return $labels;
    }

    /**
     * Every usage and the location it currently stands on — what the
     * dashboard lists, and what says « Galeries photo → Nextcloud de
     * l'unité » without the storage page having to know what a gallery is.
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
