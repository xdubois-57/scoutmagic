<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Storage\Location\Config\LocationConfig;

/**
 * Something in the application that sends its files to a storage location.
 *
 * **Locations are declared centrally and chosen locally.** A consumer
 * keeps the identifier of the location it picked in its own setting — the
 * gallery in a gallery setting, the off-site backup in a backup one — and
 * there is no join table in the middle. That is the right shape (an
 * assignment belongs to whoever made it) and it leaves exactly one
 * question unanswerable from the storage side: *is anybody still using
 * this one?*
 *
 * This interface is that question, asked the other way round. A consumer
 * says what it is called, in French, and which locations it is currently
 * standing on. The Stockage screen renders « Sert : … » from it, and a
 * deletion is refused with the name of whatever still depends on the
 * location — never an orphaned usage, and never a foreign-key error
 * shown to an administrator.
 *
 * Implementations are registered in the composition root, into
 * {@see StorageLocationConsumerRegistry}. A consumer whose module is
 * disabled registers nothing and therefore holds nothing, which is the
 * correct answer: its files are still there, but nothing is reading them.
 */
interface StorageLocationConsumer
{
    /**
     * How this usage is named to an administrator — « Galeries photo »,
     * « Sauvegardes distantes ». French, a noun phrase, and stable: it is
     * what a refusal to delete will quote back at them.
     */
    public function usageLabel(): string;

    /**
     * The location identifiers this consumer depends on right now.
     *
     * Read at call time, never cached: an album moved a minute ago must
     * make its old location deletable and its new one protected, and a
     * snapshot taken at construction would answer for neither.
     *
     * @return list<int>
     */
    public function locationIdsInUse(): array;

    /**
     * Why this consumer could not live with $location reconfigured as
     * $proposedConfig — one French sentence — or **null** when it can.
     *
     * **The question the storage screen cannot answer for itself.** D4
     * puts the assignment on the consumer, which means the consumer is
     * also the only thing that knows what a given destination would do to
     * what it holds. The case this exists for is real and was found in
     * IT-01: a delegated album — a discussion group's photos, owned and
     * access-controlled by another module — may not sit on a location that
     * serves publicly for ever, because « private album » and « readable
     * by whoever has the link » cannot both be true. The gallery refuses
     * such a location when the album is created, and again when the bytes
     * are served. What neither of those covers is the third moment: the
     * album is created on a private location and the LOCATION is later
     * edited to carry a public URL. Nothing is exposed — the serve-time
     * guard holds, which is the point of having it — but every media of
     * every delegated album there turns into a permanent 404 with nothing
     * anywhere saying why.
     *
     * So the screen asks before it saves, and a consumer that has an
     * objection states it in words the administrator can act on.
     *
     * **On the interface rather than in an optional side-interface**, for
     * the reason `LocationConfig::servesPubliclyWithoutExpiry()` is on
     * one: a consumer that could be stranded and forgets to say so is a
     * silent breakage, and the compiler asking the question of every
     * future consumer is cheaper than discovering which one forgot. A
     * consumer with nothing to object to returns null, and that is one
     * line.
     *
     * @param StorageLocation $location the location as it stands today
     * @param LocationConfig $proposedConfig what it would become
     * @param bool $wouldBeDefault whether it would be the site's default
     *        afterwards — which matters because a consumer's rows that pin
     *        no location at all are standing on the default, and the
     *        promotion of a location is exactly how they arrive on one
     *        they were never checked against
     */
    public function objectionTo(
        StorageLocation $location,
        LocationConfig $proposedConfig,
        bool $wouldBeDefault
    ): ?string;
}
