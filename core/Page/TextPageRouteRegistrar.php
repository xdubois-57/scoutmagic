<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Page;

use Core\Http\Controller\TextPageController;
use Core\Http\Router;
use Core\View\MenuBuilder;

/**
 * **The one place in this codebase where a route is born from a database
 * row** (ARCHITECTURE.md §8.116). Everything else the router knows is
 * declared in `public/index.php` or in a module manifest, and that is
 * worth stating rather than discovering.
 *
 * ### Why a route each, and not one `/pages/{slug}`
 *
 * A single pattern registered at `role_min: public` with the real check
 * done afterwards in the controller would violate two written promises
 * at once: SECURITY.md §3 ("the RBAC guard is called by the Router
 * BEFORE any controller code") and ARCHITECTURE.md §2 ("a controller may
 * re-check a fine-grained permission, but this is never the primary
 * protection"). It would also be the kind of protection that is one
 * forgotten early-return away from being no protection at all.
 *
 * So each active page registers its own concrete path with the role
 * floor of the menu it was filed in, and {@see TextPageController}
 * checks nothing. The guard that runs is the same guard that runs for
 * every other page of the site.
 *
 * ### Why this cannot be allowed to fail
 *
 * This runs in the front controller, on every request. An installation
 * whose database is unreachable — during the first install, on a server
 * whose credentials have just been rotated, during a restore — must
 * still be able to answer, in particular on `/setup` and on the error
 * page that says what is wrong. So a failure here registers **no routes
 * and rethrows nothing**: the pages disappear until the database
 * answers again, which is exactly what they are if it does not.
 *
 * That is a deliberate silence, and the narrow kind: it swallows the
 * failure of one optional read, not of the request.
 */
final class TextPageRouteRegistrar
{
    /**
     * Registers one GET route per active page and returns them.
     *
     * @return TextPage[] the pages that got a route — empty when the
     *         database could not be read, which is not an error here
     */
    public static function register(Router $router, TextPageRepository $repository): array
    {
        try {
            $pages = $repository->findActive();
        } catch (\Throwable) {
            // No database, no routes — see the class docblock. Nothing
            // is journalled: the journal lives in the same database that
            // has just failed to answer, and a second failing write
            // would turn a degraded request into a broken one.
            return [];
        }

        foreach ($pages as $page) {
            $router->addRoute(
                'GET',
                $page->path(),
                TextPageController::class,
                'show',
                $page->roleMin(),
                ['label' => $page->menuLabel, 'parents' => [MenuBuilder::labelFor($page->menuId)]],
            );
        }

        return $pages;
    }
}
