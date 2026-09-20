<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Page\TextPageService;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * Renders one free-text page (issue #368).
 *
 * **There is no role check in here, and that is the design rather than
 * an omission.** Each page registered its own route at boot with the
 * role floor of its menu ({@see \Core\Page\TextPageRouteRegistrar}), so
 * the RBAC guard has already run — before this class was even
 * instantiated — against the floor that belongs to *this* page. Adding a
 * check here would either restate what the router just proved, or,
 * worse, become the thing somebody trusts instead of the router
 * (ARCHITECTURE.md §2).
 *
 * The 404 below is not an access decision either: it answers a slug that
 * has no active page, which happens when a page is switched off between
 * the boot that registered the routes and this request, or when a stale
 * link is followed. A hidden page returns **404, not 403** — it does not
 * exist rather than being forbidden, so nothing confirms to a passer-by
 * that there is something behind the address.
 */
class TextPageController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private TextPageService $pages,
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /pages/{slug} — one concrete route per page, so `$params` is
     * empty and the slug comes from the path that matched.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $slug = $params['slug'] ?? self::slugFromPath($request->getPath());
        $page = $slug === '' ? null : $this->pages->findActiveBySlug($slug);

        if ($page === null) {
            // The shared helper, whose own docblock makes this exact
            // point: a refusal that looks different from "no such thing"
            // maps out which addresses exist.
            return $this->notFound();
        }

        return $this->render('pages/text_page.html.twig', [
            'page_title' => $page->title,
            'content_key' => $page->contentKey(),
        ]);
    }

    /**
     * The last segment of `/pages/{slug}`.
     *
     * The routes these pages register carry no placeholder — they are
     * concrete paths, one per page, which is what lets each one declare
     * its own `role_min`. So the slug is read back off the path that the
     * router matched, rather than out of `$params`.
     */
    private static function slugFromPath(string $path): string
    {
        $prefix = '/pages/';
        if (!str_starts_with($path, $prefix)) {
            return '';
        }

        return trim(substr($path, strlen($prefix)), '/');
    }
}
