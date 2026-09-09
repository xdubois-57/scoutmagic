<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook (ARCHITECTURE.md §7.4) a module implements when it POINTS
 * AT badges by id, so that `Core\Badge\BadgeService::delete()` refuses to
 * hard-delete one that is still in use somewhere core cannot see.
 *
 * The guard exists because a badge reference held by a module is a
 * foreign key with its own `ON DELETE CASCADE`, and a cascade is silent
 * by construction. `mass_mail_list_badges` is today's case: a mailing
 * list crossing « la meute ET le badge X » loses its badge row when X is
 * deleted, and an axis with no row left does not narrow the list to
 * nobody — it stops constraining anything, so the list quietly widens to
 * every member of the meute. Deactivating a badge is the operation that
 * means "stop using this"; deleting one must not mean "widen whatever
 * pointed at it".
 *
 * Core defines the interface and the module implements it, never the
 * other way around: `BadgeService` asks whoever is registered, and
 * degrades to today's behaviour when nothing is (the module disabled, or
 * absent from this installation).
 *
 * One implementation, like every hook in `HookRegistry`. A second module
 * pointing at badges would make this a multi-contributor hook and it
 * would move to its own dedicated registry, as `SubProcessorProvider`
 * and `MenuEntryProvider` did.
 */
interface BadgeUsageProvider
{
    /**
     * Every badge id this module still points at. Read on a delete
     * attempt only, so a full scan is fine — it is never on a page path.
     *
     * @return int[]
     */
    public function badgeIdsInUse(): array;

    /**
     * What holds them, as the fragment of the French refusal message that
     * follows « Ce badge est » — e.g. « utilisé comme critère par une
     * liste de diffusion ». A refusal that does not say WHERE the badge
     * is used leaves the user with nowhere to go.
     */
    public function describeBadgeUsage(): string;
}
