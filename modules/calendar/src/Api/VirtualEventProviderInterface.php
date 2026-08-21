<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * A module that computes calendar events instead of storing them
 * (ARCHITECTURE.md §7.6).
 *
 * This is the **"a module extended by another module"** direction, and it is
 * neither of the two patterns that came before it: §7.4 is core extended by
 * a module, §7.5 is one module consuming another's read API. Here `calendar`
 * publishes an extension point and another module plugs into it, with the
 * data staying where it belongs.
 *
 * Three rules an implementation must respect, all of them learned from what
 * goes wrong otherwise:
 *
 * 1. **One call per window, never one per day or per entity.** A month view
 *    asks once for the whole grid; an ICS feed asks once for its bounded
 *    range. An implementation that queried per day would turn a calendar
 *    render into forty queries and a feed into hundreds.
 * 2. **Build only what the viewer may see.** The viewer is a parameter, not
 *    a filter applied afterwards: never assemble the detailed version and
 *    then strip it at render time. A field that is never built cannot leak
 *    through a template, a JSON payload, a tooltip or an ICS line.
 * 3. **Resolve the viewer's rights once**, at the top of the call, not per
 *    event — see `VirtualEventViewer`.
 *
 * Degradation runs both ways and needs no special case at either end: with
 * `calendar` disabled nobody ever calls a provider, and with the providing
 * module disabled the registry simply has one fewer entry.
 */
interface VirtualEventProviderInterface
{
    /**
     * A short, stable identifier for this provider — `rental`, say. Used in
     * diagnostics and to keep one misbehaving provider identifiable.
     */
    public function providerId(): string;

    /**
     * Events this provider contributes over [$windowStart, $windowEnd],
     * both inclusive, **already reduced to what $viewer may see**.
     *
     * Returning an empty array is always valid and is the right answer for
     * a provider with nothing configured, a viewer with no rights, or a
     * window nothing falls in. It must never throw for those cases: a
     * calendar page that 500s because one provider had nothing to say is
     * worse than a calendar missing one module's events.
     *
     * @return VirtualEvent[]
     */
    public function findVirtualEvents(
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        VirtualEventViewer $viewer
    ): array;
}
