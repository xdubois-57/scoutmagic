<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `AGENTS.md`: **Layered MVC: Controller → Service → Repository.** That
 * arrow points one way. A controller calls a service; a service that
 * reaches back up into `Core\Http\Controller` has tied a rule about what
 * the application *does* to a class about how HTTP *answers*, and the
 * next caller of that service inherits the dependency whether it speaks
 * HTTP or not — a background task handler, a CLI entry point, a test.
 *
 * The violation this test exists for was small and plausible: a service
 * refusing a write threw with `AbstractController::FORBIDDEN_MESSAGE`,
 * because that is where the site's standard refusal sentence happened to
 * live. Nothing failed. The sentence moved to
 * {@see \Core\Exception\UserFacingMessage::FORBIDDEN}, which is the layer
 * that decides what a visitor may be told, and the rule is written down
 * here so the next shortcut of that shape fails instead of shipping.
 *
 * **Naming a controller is not depending on one.** `Foo::class`, handed
 * to `Router::addRoute()` or to a front controller's registry, is a
 * string that says which controller serves a route — routing, not a call
 * into the controller layer. So the rule is about *use*, not about
 * mention: any `SomeController::` that is not `SomeController::class`.
 */
final class LayersPointOneWayTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The HTTP layer itself, where a controller's API is legitimately in
     * reach: the front controller dispatches to controllers, and
     * `AbstractController` is their own base class.
     */
    private const HTTP_LAYER = 'core/Http/';

    public function testNothingOutsideTheHttpLayerCallsIntoAController(): void
    {
        $offenders = [];

        foreach (self::phpFilesUnder(['core', 'modules']) as $path) {
            $relative = str_replace(self::repoRoot() . '/', '', $path);
            if (str_starts_with($relative, self::HTTP_LAYER) || self::isAController($relative)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            // Which controller class names this file has imported...
            if (preg_match_all('/^use\s+Core\\\\Http\\\\Controller\\\\([A-Za-z0-9_]+)\s*;/m', $source, $m) === 0) {
                continue;
            }

            foreach (array_unique($m[1]) as $shortName) {
                // ...and whether any of them is used for something other
                // than naming the class.
                if (preg_match('/\b' . preg_quote($shortName, '/') . '::(?!class\b)(\w+)/', $source, $use) === 1) {
                    $offenders[] = "{$relative} uses {$shortName}::{$use[1]}";
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "A service, repository or value object reached up into the controller layer.\n"
            . "Move the constant or helper it wanted to a layer both can depend on —\n"
            . "Core\\Exception\\UserFacingMessage for a sentence a visitor reads — rather\n"
            . "than pointing the arrow backwards.\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The sentence has one home, and the controller constant is that home
     * rather than a copy of it: two literals of the same French sentence
     * drift, and the one that drifts is the one nobody is reading.
     */
    public function testTheStandardRefusalSentenceIsNotWrittenTwice(): void
    {
        $source = (string) file_get_contents(self::repoRoot() . '/core/Http/Controller/AbstractController.php');

        $this->assertStringContainsString(
            'FORBIDDEN_MESSAGE = UserFacingMessage::FORBIDDEN',
            $source,
            'AbstractController::FORBIDDEN_MESSAGE holds its own copy of the refusal sentence again. '
            . 'It must be UserFacingMessage::FORBIDDEN, which is the copy a service can also reach.'
        );
    }

    private static function isAController(string $relative): bool
    {
        return str_contains($relative, '/Controller/');
    }

    /**
     * @param string[] $directories
     *
     * @return string[]
     */
    private static function phpFilesUnder(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    self::repoRoot() . '/' . $directory,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
