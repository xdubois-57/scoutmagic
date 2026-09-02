<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Menu;

use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;
use Core\View\MenuBuilder;
use Modules\News\Repository\FormRepository;

/**
 * The « Scanner un billet » shortcut in Espace animateurs — for the
 * animateur already standing at the door, who does not want to cross two
 * screens to get there.
 *
 * It leads to `/news/scan`, the generic page, because a menu cannot know
 * which evening is being held. The other way in is the article's own tab
 * (`partials/_form_tabs.html.twig`), which opens straight onto its event.
 *
 * **The entry is conditional, and `visible_when` could not do it.** That
 * manifest key gates a module on the KIND of installation it runs on —
 * `Core\Module\InstallationProfile`'s flags — and knows nothing about what
 * is in the database. What decides here is whether this unit has ever run
 * a ticketed event, so it has to be `Core\Module\MenuEntryProvider`, the
 * same hook `rental` and `registration` already use. A unit that never
 * organises a paying event must not carry a dead entry in its menu.
 *
 * This runs on **every request that builds a menu**, so it is one bounded
 * `EXISTS`-shaped query and never a walk over the forms.
 *
 * **Menu visibility is never a permission** (ARCHITECTURE.md §12): the
 * route carries its own `role_min: chief`, and `ScanController` re-checks
 * that the form it is handed actually issues a ticket. A hidden entry
 * protects nothing and a visible one grants nothing.
 */
class NewsMenuHookService implements MenuEntryProvider
{
    /**
     * Well clear of the core pages' small orders — MenuBuilder ranks by
     * group first anyway, so this only orders module entries against each
     * other.
     */
    private const SCAN_ORDER = 520;

    public function __construct(private FormRepository $forms)
    {
    }

    public function getMenuEntries(?string $email): array
    {
        if (!$this->forms->anyFormIssuesTickets()) {
            return [];
        }

        return [
            new MenuEntry(
                MenuBuilder::MENU_ESPACE_CHEFS,
                'Scanner un billet',
                '/news/scan',
                'chief',
                self::SCAN_ORDER,
                false,
                null,
                MenuBuilder::SORT_GROUP_MODULE,
                'bi-qr-code-scan',
                'communication'
            ),
        ];
    }
}
