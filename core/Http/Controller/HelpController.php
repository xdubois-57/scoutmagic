<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Help\HelpService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\MarkdownRenderer;
use Twig\Environment;

/**
 * The help pages (ARCHITECTURE.md §8.64): /aide, the index of every topic
 * the visitor's role may see, grouped by category with a ?q= search; and
 * /aide/{topic}, one topic rendered as a full page.
 *
 * The placeholder is `{topic}`, not `{id}`: a help topic is addressed by
 * its slug ("premiers-pas"), and Core\Http\Router matches an id-NAMED
 * placeholder against digits only (see Router::placeholderPattern). A
 * route whose parameter is not a row identifier must not be named as
 * though it were — that naming IS the rule, which is why there is no
 * opt-out flag to forget.
 *
 * Both routes are role_min: public — the per-topic gate is
 * Core\Help\HelpService's role filter, and a topic below the caller's
 * role answers 404 exactly like an unknown id (never 403: a 403 would
 * confirm the topic exists, SECURITY.md §19's posture).
 *
 * The search deliberately travels as a plain GET ?q= — no cookie, no
 * session state, nothing persisted (locked decision: no journaling, no
 * personal data anywhere in the help system).
 */
class HelpController extends AbstractController
{
    /**
     * The rendering profile for a topic body, shared by this controller
     * and the contextual panel (Core\Http\FrontController::buildRouteHelp())
     * so a topic can never render differently in its two homes: a `##`
     * section renders as the <h2> it reads as (base level 1 — topics
     * write their sections `##`-first, and a lone `#` is forbidden by
     * tests/Core/Help/HelpInvariantsTest.php because it would render a
     * second <h1> next to the page's own, design.md §7.6), images
     * restricted to /assets/, and the charter's single warning callout
     * (design.md §7.11).
     */
    public const RENDER_OPTIONS = [
        'heading_base_level' => 1,
        'allow_asset_images' => true,
        'blockquotes' => true,
        'ordered_lists' => true,
        'wrapped_list_items' => true,
    ];

    public function __construct(
        Environment $twig,
        private readonly HelpService $helpService,
    ) {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $query = trim((string) $request->getQuery('q', ''));

        $grouped = $query === ''
            ? $this->helpService->listForRole($role)
            : $this->helpService->search($query, $role);

        return $this->render('help/index.html.twig', [
            'grouped_topics' => $grouped,
            'query' => $query,
            // A topic the registry could not read costs only itself
            // (Core\Help\HelpRegistry::load()) — which would make it
            // invisible if nothing said so anywhere. /aide is where the
            // corpus is on display, so this is where the gap is named,
            // and only for the person who can act on it: the message
            // carries a server file path, which no one else has any
            // business seeing.
            'load_errors' => $role === Role::SUPERADMIN ? $this->helpService->loadErrors() : [],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $topic = $this->helpService->findById($params['topic'] ?? '', $role);

        if ($topic === null) {
            // Unknown id and below-role topic share this branch on
            // purpose — indistinguishable from outside.
            return $this->render('errors/404.html.twig')->setStatusCode(404);
        }

        return $this->render('help/show.html.twig', [
            'topic' => $topic,
            'content_html' => MarkdownRenderer::toHtml($topic->body(), self::RENDER_OPTIONS),
            'related_topics' => $this->helpService->relatedTopics($topic, $role),
            'breadcrumb_current' => $topic->title,
            // Real ancestor-page link (design.md §7.3's breadcrumb_trail),
            // so a topic page always offers the way back up to the index.
            'breadcrumb_trail' => [
                ['label' => 'Aide', 'url' => '/aide'],
            ],
        ]);
    }
}
