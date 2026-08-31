<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * `/test-tools` (`role_min: superadmin`) — the toolbox's index
 * (ARCHITECTURE.md §8.63).
 *
 * Two tools today (the mail sandbox, and the provoked error below); the
 * page exists so the next one has somewhere to go, rather than the menu
 * growing an entry per tool.
 */
class TestToolsController extends AbstractController
{
    public function __construct(protected Environment $twig)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@test_tools/index.html.twig');
    }

    /**
     * `GET /test-tools/uncaught-error` — throws, on purpose.
     *
     * The one thing no other page can do: prove, from a real browser
     * against a real installation, that an uncaught throwable ends up
     * BOTH in the PHP error log and in the site journal at level `error`
     * (Core\Http\ErrorHandler, ARCHITECTURE.md §8.6). Every PHPUnit test
     * of that path calls the handler directly; only this route exercises
     * the whole chain — controller, ErrorHandler::guard(), the journal
     * write, and the generic 500 page.
     *
     * It is not reachable in production, and that is a property of the
     * module rather than of this method: `test_tools` declares
     * `visible_when: [reference_installation, local_installation]`, so on
     * a deploying unit's installation ModuleManager never discovers the
     * module and none of its routes exist at all (§8.49). A route that
     * crashes the request on demand may live nowhere else.
     *
     * The message is French because it is read where it lands: in the
     * journal, by the operator who provoked it.
     *
     * @param array<string, string> $params
     */
    public function provokeUncaughtError(Request $request, array $params): Response
    {
        throw new \RuntimeException('Erreur provoquée volontairement depuis les outils de test.');
    }
}
