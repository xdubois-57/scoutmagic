<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Http\Controller\AbstractController;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Support\RouteInventory;

/**
 * « La page ne s'affiche pas et à la place de ça, j'ai une erreur qui dit
 * Forbidden » — issue #347, reported by somebody who owns the site.
 *
 * Two things went wrong that day and this file is about the second one.
 * The menu offered a page the controller would refuse
 * (Tests\Architecture\MenuEntriesAreNotDeadLinksTest), and the refusal
 * itself was the string "Forbidden", served as the entire document: no
 * stylesheet, no theme, no navigation, no site name, no way back, and no
 * hint of why. In the installed PWA, where there is no browser chrome
 * either, that is a black word on a white page in the middle of a
 * dark-mode session.
 *
 * `AbstractController::forbidden()` and `errors/403.html.twig` were
 * written to end that, and ended it in the two modules that adopted them
 * while forty-odd call sites elsewhere went on answering a bare body. A
 * helper nothing obliges anybody to use fixes the page somebody
 * remembered to fix.
 *
 * So the rule, scoped exactly where a bare body is indefensible:
 *
 *     a PAGE never answers a refusal with a literal body.
 *
 * **A page is a route that declares a breadcrumb** — the application's own
 * definition, not one invented here: `Core\Http\FrontController` sets
 * `route_breadcrumb` and `route_help` side by side on exactly those
 * routes, so anything carrying one is somewhere a person lands and can
 * press the help button. `Tests\Core\Help\HelpMenuCoverageTest` counts
 * pages the same way.
 *
 * That scope is the whole of the judgement here, and the three things it
 * deliberately leaves out are each a place where a bare body is the right
 * answer:
 *
 *   - **a JSON endpoint**, which refuses in the shape its caller can show
 *     inline — `json([...], 403)`, or `forbidden($message, $request)`,
 *     which answers JSON to a caller that asked for it or sent it;
 *   - **a refusal only a forged request reaches**
 *     (`Core\Http\Controller\SectionDocumentController` says so in its own
 *     words): no page offers it, so there is no reader to write a sentence
 *     for;
 *   - **the installer's gate** (`SetupController`), which runs before
 *     there is an instance to render `base.html.twig` against.
 *
 * What this cannot see is a refusal a *service* returns rather than the
 * controller, which is the same blind spot its sister test has and the
 * same reason the authorization matrix replays every route for real.
 */
final class APageRefusalIsReadableTest extends TestCase
{
    /**
     * The floor: the helper the rule points at has to exist, or every
     * assertion below is advice with nowhere to send anybody.
     */
    public function testTheReadableRefusalStillExists(): void
    {
        $this->assertTrue(
            method_exists(AbstractController::class, 'forbidden'),
            'AbstractController::forbidden() is gone — the one way a controller has of refusing with a page '
            . 'a visitor can read.',
        );
        $this->assertFileExists(
            RouteInventory::root() . '/core/View/templates/errors/403.html.twig',
            'The 403 page itself is gone, so forbidden() has nothing to render.',
        );
        $this->assertNotSame(
            '',
            AbstractController::FORBIDDEN_MESSAGE,
            'The default French sentence a refusal carries when it has none of its own is empty.',
        );
    }

    /**
     * THE RULE ITSELF.
     */
    public function testNoPageAnswersARefusalWithALiteralBody(): void
    {
        $offenders = [];

        foreach (RouteInventory::getRoutes() as $route) {
            if (!$route['isPage']) {
                continue;
            }

            $file = RouteInventory::controllerFile($route['class']);
            if ($file === null) {
                throw new \RuntimeException(
                    "No file on disk for controller '{$route['class']}' — a page whose controller cannot be "
                    . 'read is a page nobody is checking.',
                );
            }

            $bare = self::literalRefusalIn(RouteInventory::reachableSource($file, $route['action']));
            if ($bare !== null) {
                $offenders[] = sprintf(
                    '%s %s (%s::%s) — %s',
                    $route['origin'],
                    $route['path'],
                    basename($file, '.php'),
                    $route['action'],
                    $bare,
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A page answers a refusal with a bare body: the visitor gets that word as the whole document, '
            . 'with no stylesheet, no navigation and no reason — which is what issue #347 was reported as. '
            . 'Return $this->forbidden(\'<une phrase en français>\', $request) instead; it renders the '
            . 'site\'s own 403 page, and answers JSON to a caller that wanted JSON.',
        );
    }

    /**
     * The pattern the rule forbids, or null.
     *
     * Two spellings, both of which were in this codebase: the body passed
     * to the constructor (`new Response('Forbidden', 403)`) and the body
     * set afterwards (`(new Response('', 403))->setBody('Forbidden')`).
     * A 403 carrying a *variable* body is a rendered template — rental's
     * « vous ne gérez aucun bien » page is one — and is not what this
     * looks for.
     */
    private static function literalRefusalIn(string $source): ?string
    {
        if (preg_match('/new Response\(\s*(\'[^\']*\'|"[^"]*")\s*,\s*403/', $source, $m) === 1) {
            return 'new Response(' . $m[1] . ', 403)';
        }

        if (preg_match('/403\s*\)\s*\)?\s*->setBody\(\s*(\'[^\']*\'|"[^"]*")/', $source, $m) === 1) {
            return '->setBody(' . $m[1] . ') on a 403';
        }

        return null;
    }
}
