<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Attention;

/**
 * A contributor to the attention-points page — core or module.
 *
 * ## Not `Core\Import\DeskImportListener`, and the difference is the point
 *
 * `DeskImportListener` runs **inside the import transaction**, before the
 * commit, and a listener that throws rolls the whole import back. That is
 * right for what it does: reconciling a module's own derived state, where
 * half-applied is worse than not applied.
 *
 * It would be catastrophic here. A module that gets something wrong while
 * merely *describing* the unit must never be able to stop an import, and
 * must never be able to break a page. So this hook is called **when the
 * page is displayed**, not during the import, and
 * {@see AttentionService} **catches** whatever a provider throws: the
 * module is listed as having been unable to contribute, and every other
 * provider still renders. A failure is shown, never hidden — a page that
 * silently drops a contributor is a page that quietly stops warning you.
 *
 * ## Stay bounded
 *
 * The page is opened on demand and a provider runs on every single
 * display. Use aggregate queries. A contributor that decrypts the whole
 * roster each time will make the page unusable, and it will do so
 * gradually, as the unit grows — which is the hardest kind of slowness to
 * attribute to anything.
 *
 * ## Most modules have nothing to contribute
 *
 * Do not implement this "for consistency". An empty implementation is
 * dead code that a reviewer cannot tell apart from "not done yet", and
 * this repository's convention is already the other one:
 * `DeskImportListener` has existed for a long time and one module out of
 * twenty implements it. See `docs/module-development.md`.
 */
interface AttentionPointProvider
{
    /**
     * The name shown on each of this provider's points — a French word
     * from the §7.1 lexicon, not a module id: « Cotisations »,
     * « Encadrement », « Cœur ».
     */
    public function sourceLabel(): string;

    /**
     * The unit's current attention points for that scout year, computed
     * fresh. Return `[]` when there is nothing wrong — which is the
     * normal case and is not a failure.
     *
     * @return AttentionPoint[]
     */
    public function collect(int $scoutYearId): array;
}
