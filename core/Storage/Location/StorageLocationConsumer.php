<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

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
}
