<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

use Core\View\MenuBuilder;

/**
 * One menu entry contributed at request time by a module implementing
 * Core\Module\MenuEntryProvider — the value object that generalises what
 * used to be a fixed `array{label, url, subtitle}` pinned to the "Espace
 * animés" menu. Carrying $menuId, $roleMin and $group here (rather than
 * having the composition root supply them) is the whole point: a provider
 * can now target any menu, at any role, in any sort group, without the
 * caller knowing which module wants what.
 *
 * $order/$group/$isDynamic map one-to-one onto MenuBuilder::addPage()'s
 * parameters of the same name — see its docblock for the group-vs-isDynamic
 * distinction (sorting versus rendering; they are deliberately separate).
 *
 * An entry appearing in a menu is never a permission: $roleMin filters the
 * *display* in MenuBuilder::buildPages(), and the route the entry points at
 * carries its own `role_min` plus whatever fine-grained check its controller
 * performs. ARCHITECTURE.md §12 — a visible menu entry is not an ACL, and a
 * missing one is not a protection.
 */
final class MenuEntry
{
    /**
     * @param string $menuId One of MenuBuilder::MENU_* — which menu this entry belongs to.
     * @param string $roleMin Minimum role for the entry to be *displayed*; never a substitute for the route's own guard.
     * @param int $order Tie-break within $group only — see MenuBuilder::buildPages().
     * @param bool $isDynamic Renders with the per-visitor avatar treatment (nav.html.twig) and is matched exact-only for the active-page highlight.
     */
    public function __construct(
        public readonly string $menuId,
        public readonly string $label,
        public readonly string $url,
        public readonly string $roleMin = 'public',
        public readonly int $order = 100,
        public readonly bool $isDynamic = false,
        public readonly ?string $subtitle = null,
        public readonly string $group = MenuBuilder::GROUP_MODULE,
        // The Bootstrap Icons class shown in front of the entry in the
        // mobile menu, so its label lines up with the per-member entries'
        // avatars (partials/nav.html.twig). Trailing and optional: an
        // entry that names none renders the neutral fallback.
        public readonly ?string $icon = null
    ) {
    }
}
