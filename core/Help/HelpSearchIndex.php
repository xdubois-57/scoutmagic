<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help;

use Core\Security\Role;

/**
 * The corpus, flattened and reduced to what a search result has to show,
 * for one role — serialized into every page as a
 * `<script type="application/json">` blob that
 * public/assets/js/help-search.js reads.
 *
 * Same shape and the same reason as `#offline-config-data`: the data is
 * decided server-side, once, and the browser only ranks and renders it.
 * Shipping it inside the page rather than fetching it is what makes the
 * search work offline and instantly — the whole point of it existing
 * alongside the assistant rather than behind it.
 *
 * **The role filter is the list, not an instruction.** It goes through
 * HelpService::listForRole(), never HelpRegistry::all(): a topic the
 * visitor may not read must not travel to their browser at all, since a
 * blob in the page source is readable whatever the script does with it.
 *
 * Bodies are never included. Front matter is what the registry already
 * holds in memory (and caches), a body is a file read per topic, and the
 * index would go from ~15 KB to several hundred. The search ranks on
 * title, questions, summary and category; a match opens /aide/{id},
 * which is where the body lives.
 */
final class HelpSearchIndex
{
    public function __construct(
        private readonly HelpService $helpService,
        private readonly HelpPageLinkResolver $pageLinks,
    ) {
    }

    /**
     * Every topic this role may see, in /aide's own category order.
     *
     * `link` is the « aller sur la page » target (IT-01), resolved
     * without a current path: a result list is not a page context, and
     * the reader clicking a result is by definition not on it yet.
     *
     * @return array<int, array{id: string, title: string, summary: string, category: string, questions: string[], link: ?array{path: string, label: string}}>
     */
    public function forRole(Role $role): array
    {
        $entries = [];
        foreach ($this->helpService->listForRole($role) as $category => $topics) {
            foreach ($topics as $topic) {
                $entries[] = [
                    'id' => $topic->id,
                    'title' => $topic->title,
                    'summary' => $topic->summary,
                    'category' => $category,
                    'questions' => $topic->questions,
                    'link' => $this->pageLinks->resolve($topic, $role),
                ];
            }
        }

        return $entries;
    }
}
