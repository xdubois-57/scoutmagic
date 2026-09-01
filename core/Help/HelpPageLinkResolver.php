<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help;

use Core\Http\Router;
use Core\Security\Role;

/**
 * Turns a help topic into the one link that was missing from it: « aller
 * sur la page ». A topic declares the pages it covers (`paths`); nothing
 * until now went the other way, so someone reading « Importer le fichier
 * Desk » on /aide had to find the import page through the menus.
 *
 * Three rules, and each of them is the reason this is a class rather than
 * two lines in a template:
 *
 * - **Only an `exact` path can be a link.** A `child` rule ('/members/')
 *   and a segment pattern (a `*` standing for one whole segment) both
 *   stand for a family of pages, and the topic names no member of it.
 *   Roughly a quarter of the shipped corpus is in that case; so is the
 *   one topic that declares no `paths` at all. That is the feature
 *   working, not a gap to paper over with a guessed URL.
 * - **The role checked is the TARGET route's, not the topic's.** They
 *   are not the same floor and there is no rule saying they should be: a
 *   topic can be written for a wider audience than the page it describes
 *   ("voici ce que votre animateur voit"), and Core\Http\Router is the
 *   only thing that knows what the page itself demands. A link the
 *   visitor cannot follow is a link to a 403 — it disappears instead,
 *   exactly as a breadcrumb ancestor does (Router::ancestorTrailFor()).
 * - **A link to the page you are already on is not offered.** The caller
 *   passes the current path; the contextual panel opens ON the documented
 *   page most of the time, and « aller sur la page » there is noise.
 *
 * Visibility is a convenience, never a boundary (SECURITY.md §3): the
 * target route keeps enforcing its own role_min whoever follows the link.
 */
final class HelpPageLinkResolver
{
    public function __construct(private readonly Router $router)
    {
    }

    /**
     * The page link for one topic, or null when there is none to offer.
     *
     * @param ?string $currentPath The path being rendered, so the link to
     *        the page the reader is already on is suppressed. Null when
     *        the caller has no page context.
     * @return ?array{path: string, label: string}
     */
    public function resolve(HelpTopic $topic, Role $viewerRole, ?string $currentPath = null): ?array
    {
        foreach ($topic->paths as $rule) {
            if ($rule['match'] !== 'exact') {
                continue;
            }
            if ($currentPath !== null && $rule['path'] === $currentPath) {
                // The reader is on it. Keep looking: a topic covering
                // several pages can still point at another one.
                continue;
            }

            $page = $this->router->pageAtPath($rule['path']);
            if ($page === null || !$viewerRole->hasAccess(Role::fromString($page['roleMin']))) {
                continue;
            }

            return ['path' => $rule['path'], 'label' => $page['label']];
        }

        return null;
    }
}
