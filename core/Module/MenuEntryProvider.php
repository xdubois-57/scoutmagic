<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module implements to contribute extra menu entries at
 * request time, without core depending on the module directly
 * (ARCHITECTURE.md §7.4). The composition root wires the concrete
 * implementation in only when the module is enabled.
 *
 * This generalises the former Core\Module\EspaceAnimesEntryProvider, which
 * could only ever contribute to "Espace membres" and only for an
 * authenticated visitor. Two real needs broke both assumptions: a module
 * wanting its own entry in "Notre unité" (a public menu, no email at all),
 * and a module wanting an "Espace membres" entry gated on a per-object
 * permission rather than on the visitor's own records. Rather than grow a
 * second parallel hook, the menu id moved into the returned
 * Core\Module\MenuEntry and the email became nullable.
 *
 * Implementations are called on **every request** that builds a menu, so
 * they must stay cheap: a bounded query or two, never an N+1 walk, and
 * never a write. Returning an empty array is the normal case.
 */
interface MenuEntryProvider
{
    /**
     * Every entry this module wants to add for the current visitor.
     *
     * @param string|null $email The authenticated visitor's session email, or null for an anonymous visitor. A provider
     *     contributing only public entries ignores it; one contributing per-visitor entries returns [] when it is null.
     * @return MenuEntry[] Typically none, one, or a handful.
     */
    public function getMenuEntries(?string $email): array;
}
