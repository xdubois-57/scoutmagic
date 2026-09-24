<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * A module that adds lines to the description of REAL calendar events —
 * events stored in `calendar_events` — in the one feed that has an
 * identified reader (ARCHITECTURE.md §7.6).
 *
 * VirtualEventProviderInterface lets a module MAKE events; nothing let one
 * ENRICH an event that already exists. The carpool module is the first to
 * need it: « Covoiturage — aller 8 h 30, parking des locaux : en attente »
 * belongs under the weekend the reader already has in their agenda, not in
 * a second event beside it (docs/chantiers/covoiturage.md, IT-03). Same
 * direction as §7.6: `calendar` publishes the extension point, a module
 * plugs into it, and its data stays at home.
 *
 * **Wired into the personal feed and nowhere else.** The same event also
 * leaves in a calendar's own ICS feed and in the whole-unit feed, both
 * bearer links with no identified reader — a line about a family's seat in
 * a car would leak to whoever holds them. The personal feed is the only
 * one with a real reader, recomputed at every fetch, so a line an enricher
 * stops returning (a refused request) disappears on the client's next
 * refresh. `Tests\Modules\Calendar\Controller\EventDescriptionEnrichmentFeedTest`
 * pins one test per feed.
 *
 * The rules of VirtualEventProviderInterface hold here too: one call per
 * feed, never one per event; build only what $viewer may see; resolve the
 * viewer's rights once. And what an ICS file carries lands in plain text
 * at Google or iCloud: a line holds nothing a reader would not paste into
 * a shared calendar — never a phone number.
 */
interface EventDescriptionEnricherInterface
{
    /** A short, stable identifier — `covoiturage`, say — for diagnostics. */
    public function enricherId(): string;

    /**
     * Lines to append to the description of each of $eventIds, keyed by
     * event id, for $viewer. An id with nothing to add is simply absent;
     * an empty array is always a valid answer and must never be an
     * exception.
     *
     * Plain text, one line per string, unescaped: the ICS builder escapes
     * the whole description once.
     *
     * @param list<int> $eventIds every real event of the feed
     * @return array<int, list<string>>
     */
    public function describeEvents(array $eventIds, VirtualEventViewer $viewer): array;
}
