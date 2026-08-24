<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help;

use Core\Security\Role;

/**
 * One help topic — the parsed front matter of a docs/help/ (or module
 * help/) Markdown file, plus lazy access to its body.
 *
 * Read-only by construction. The body is deliberately NOT loaded with the
 * front matter: the help button needs to know on every request whether a
 * topic covers the current page, which only takes the front-matter block,
 * and reading every topic's full text on every page load would be paying
 * for content nobody asked to see (ARCHITECTURE.md §8.64). body() reads
 * the file the first time a topic is actually displayed.
 */
final class HelpTopic
{
    private ?string $loadedBody = null;

    /**
     * @param Role $roleMin Below this role the topic does not exist
     *        anywhere — not in the panel, the index, the search, nor by
     *        direct /aide/{id} URL (404, never 403). Core\Help\HelpService
     *        is the single layer that enforces this.
     * @param array<int, array{path: string, match: string}> $paths Pages
     *        this topic covers — 'exact' entries hold the literal path,
     *        'child' entries hold the parent path WITH its trailing slash
     *        ('/members/' covers '/members/12' but not '/members/12/x'),
     *        the same exact/child semantics as Core\Offline\OfflineWhitelist.
     *        Empty for a purely documentary topic only reachable from /aide.
     * @param string[] $related Ids of other topics — an unknown id is
     *        ignored and an out-of-role topic filtered at display time,
     *        both by HelpService, never here.
     * @param ?string $moduleId The module that ships this topic, null for
     *        a core topic (docs/help/). Display-only (the /aide index
     *        badges module topics) — a topic behaves identically either way.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $category,
        public readonly Role $roleMin,
        public readonly array $paths,
        public readonly array $related,
        public readonly string $filePath,
        public readonly ?string $moduleId = null,
    ) {
    }

    /**
     * The topic's raw Markdown body (everything after the closing ---),
     * read from disk on first call. Render it through
     * Core\View\MarkdownRenderer — never print it raw.
     */
    public function body(): string
    {
        if ($this->loadedBody === null) {
            $this->loadedBody = HelpFrontMatterParser::extractBody($this->filePath);
        }

        return $this->loadedBody;
    }
}
