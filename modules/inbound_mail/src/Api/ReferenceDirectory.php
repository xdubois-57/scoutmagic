<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What a consumer can say about its business objects to a screen that
 * cannot know them — an **optional** companion of
 * `MessageConsumerInterface`, in the same family as
 * `MessageRetentionPreference`.
 *
 * **Why it exists.** `/courrier` is the screen that makes storing every
 * message defensible (§8.58): the Chef d'Unité reads what nobody
 * recognised and orients it. Until this, « orienter » meant confirming a
 * proposition and nothing else — a message no module had guessed about
 * could be read and could be left to the retention, and that was the
 * whole of what the screen allowed. Orienting it towards a stay, a
 * booking or an account needs two things only the consumer knows: which
 * objects exist, as a person would name them, and where each one lives.
 *
 * **Optional, deliberately.** A consumer that does not implement it is
 * exactly what it was: its associations are shown, its propositions are
 * confirmed, and the screen offers no free attachment towards it. Nothing
 * a consumer already does changes because this interface appeared.
 *
 * **The caller is the one who may reach everything.** `/courrier` is
 * `role_min: admin`, so a directory answers for the whole unit and
 * checks nobody's authorisation; a consumer that ever exposes a search
 * to a narrower audience scopes it in its own controller, as it does for
 * every other read (§7.7).
 */
interface ReferenceDirectory
{
    /**
     * The objects a person could mean by `$query`, best first — a place
     * and a month, a renter's name, a booking reference, an account name.
     *
     * An exact reference typed in full must come back as a suggestion of
     * its own: the screen accepts an association only towards a reference
     * this search returned, which is what stops a hand-crafted POST filing
     * a message under an object that does not exist.
     *
     * @return ReferenceSuggestion[] at most `$limit`, empty when nothing fits
     */
    public function searchReferences(string $query, int $limit = 10): array;

    /**
     * Where the object behind a reference lives — the page a reader opens
     * to see it — or null when there is no page for it (the finance
     * sorting pile) or the object is gone.
     */
    public function referenceUrl(string $businessReference): ?string;
}
