<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Modules\Calendar\Api\VirtualEvent;
use Modules\Calendar\Api\VirtualEventProviderInterface;
use Modules\Calendar\Api\VirtualEventViewer;

/**
 * Where virtual-event providers plug in (ARCHITECTURE.md §7.6).
 *
 * **Mutable on purpose, and that is what breaks the circular wiring.**
 * `rental` consumes `calendar` (it needs the list of calendars to publish
 * onto) and `calendar` consumes `rental` (the provider). Neither can be
 * constructed after the other. The way out is that the calendar builds this
 * registry *empty* and hands the same object to its controllers; the rental
 * block, further down `public/index.php`, appends its provider to it. The
 * controllers hold the registry rather than a snapshot of its contents, so
 * a provider registered after they were constructed still reaches them —
 * which is exactly why this is an object with a `register()` method and not
 * an array passed by value.
 *
 * The same shape as `Core\File\FileAccessGuard`'s ownership-checker
 * registry, one level up: core seeds it, module blocks append, the consumer
 * reads it at request time.
 *
 * **Deduplication is by UID.** The same booking can be published onto two
 * calendars a reader subscribes to, and a reader who sees both must see one
 * event, not two. The provider owns the UID and is told to make it stable;
 * this is where that promise is cashed in.
 */
class VirtualEventRegistry
{
    /** @var VirtualEventProviderInterface[] */
    private array $providers = [];

    public function register(VirtualEventProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    public function hasProviders(): bool
    {
        return $this->providers !== [];
    }

    /**
     * Every provider's contribution over the window, merged and deduplicated.
     *
     * **One call per provider, for the whole window** — never one per day.
     *
     * A provider that throws is skipped rather than allowed to take the page
     * down with it: a calendar missing one module's events is a far better
     * outcome than a calendar that 500s, and the alternative would let any
     * module make the calendar unusable for everyone.
     *
     * @return VirtualEvent[]
     */
    public function collect(
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        VirtualEventViewer $viewer
    ): array {
        $byUid = [];

        foreach ($this->providers as $provider) {
            try {
                $events = $provider->findVirtualEvents($windowStart, $windowEnd, $viewer);
            } catch (\Throwable) {
                continue;
            }

            foreach ($events as $event) {
                // First writer wins: a provider that somehow returns the
                // same UID twice contributes it once, and the order of
                // registration decides ties rather than the order of a
                // database result.
                $byUid[$event->uid] ??= $event;
            }
        }

        return array_values($byUid);
    }

    /**
     * Merges virtual events into an already-collected list, dropping any
     * whose UID is already present.
     *
     * Used where both kinds of event reach one output — the personal ICS
     * feed above all (§6.32).
     *
     * @param VirtualEvent[] $existing
     * @param VirtualEvent[] $incoming
     * @return VirtualEvent[]
     */
    public static function mergeUnique(array $existing, array $incoming): array
    {
        $byUid = [];
        foreach ([...$existing, ...$incoming] as $event) {
            $byUid[$event->uid] ??= $event;
        }

        return array_values($byUid);
    }
}
