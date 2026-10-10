<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * The contract for showing a preview card for a member-supplied link.
 *
 * **It lives beside `Core\Security\SsrfUrlValidator` because that is what
 * it is really about.** Fetching a URL a member typed is the site's most
 * dangerous outbound operation (SECURITY.md §17), and the rule — one
 * implementation, one validator, no second scraper anywhere — is a rule
 * about the whole installation, not about the module that happened to
 * need it first. The contract moved here first, so that a module wanting a
 * preview declares a dependency on core rather than reaching into another
 * module's namespace; its one implementation, `Core\Http\LinkPreviewService`,
 * followed in issue #840, when the rich-text editors needed it too and an
 * editor every unit has could not depend on a module a unit may switch off.
 *
 * It is always there now, but a consumer still takes it as a NULLABLE
 * dependency and degrades to a plain link without it (ARCHITECTURE.md
 * §7.5): a test, a task or a future installation without it is a perfectly
 * normal case.
 *
 * Deliberately narrow: this only resolves metadata for a URL. It never
 * stores anything and never enforces authorisation or rate limits of its
 * own — a caller (`Modules\Groups\Service\PostLinkService`, first
 * consumer) decides whether the current session may post a link at all,
 * whether it has already fetched too many this window, and how and where
 * to persist the result. Whatever caching the implementation does behind
 * this is its own business and non-authoritative.
 */
interface LinkPreviewFetcher
{
    /**
     * Never throws: an invalid URL, an unreachable page, one with no Open
     * Graph tags at all, or a failed image download all degrade to a
     * result the caller can still use — at minimum, a plain link. Returns
     * null only when nothing at all could be resolved (the URL itself was
     * rejected, or the fetch failed outright) — the caller falls back to a
     * plain link with no title/description/image in that case too.
     */
    public function fetch(string $url): ?LinkPreview;
}
