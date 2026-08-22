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
 * One tool today (the mail sandbox); the page exists so the second one has
 * somewhere to go, rather than the menu growing an entry per tool.
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
}
