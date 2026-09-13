<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Nothing after `ErrorHandler::guard()` may throw.
 *
 * `public/index.php` runs the controller inside
 * `\Core\Http\ErrorHandler::guard()`, which is the boundary that turns an
 * exception into an error page. What comes AFTER it — the block that
 * finishes the response: headers, the Content-Security-Policy, the
 * timeline — is outside that boundary, and the response is already built
 * by then. An exception there does not produce an error page; it
 * discards a finished response, on **every route of the site at once**,
 * including the configuration page somebody would need to reach to fix
 * whatever caused it.
 *
 * That is not hypothetical. The CSP `img-src` origins are computed there
 * from `storage_locations`, and reading those rows can throw: a row whose
 * `type` this version does not recognise is refused rather than misread
 * (`StorageLocationRepository::hydrate()`). One such row, and the site is
 * down everywhere — a failure with no relation to the page being served.
 *
 * So every database read in that tail carries its own `try`. The cost of
 * a failure becomes a missing header, which degrades something visible
 * and local (images from a bucket stop loading); the real error is met on
 * the page that actually manages those rows, inside the route boundary,
 * where it can say what is wrong.
 */
class ResponseTailCannotThrowTest extends TestCase
{
    private const FRONT_CONTROLLER = __DIR__ . '/../../public/index.php';

    public function testEveryRepositoryReadAfterTheErrorBoundaryIsGuarded(): void
    {
        $source = (string) file_get_contents(self::FRONT_CONTROLLER);

        $boundary = strpos($source, 'ErrorHandler::guard(');
        $this->assertNotFalse($boundary, 'public/index.php no longer runs the controller inside ErrorHandler::guard().');

        $tail = substr($source, $boundary);

        // Every call that reaches the database in the tail, by the one
        // shape they all have: a repository or service method on a
        // variable. Narrowed to the readers that actually appear there
        // rather than to any method call, so this test says something
        // precise instead of everything vaguely.
        preg_match_all('/^.*->(?:findAll|findById|findDefault|findByLabel|getAll|all)\(.*$/m', $tail, $matches);

        $this->assertNotEmpty(
            $matches[0],
            'No database read was found after the error boundary. If that is genuinely true now, this test has '
                . 'nothing left to defend and should be deleted with a note saying so — not left passing on an '
                . 'empty set.'
        );

        foreach ($matches[0] as $line) {
            $before = substr($tail, 0, (int) strpos($tail, $line));
            $this->assertTrue(
                $this->isInsideATry($before),
                "public/index.php reads the database after ErrorHandler::guard() without a try:\n"
                    . trim($line) . "\n"
                    . 'The response is already built at that point, so an exception there discards it on every '
                    . 'route of the site rather than producing an error page. Wrap it, and let the failure cost '
                    . 'a header instead of the site.'
            );
        }
    }

    /**
     * Whether $before leaves an unclosed `try {` open — counted by brace
     * depth rather than by proximity, so a guard several lines up still
     * counts and one that has already closed does not.
     */
    private function isInsideATry(string $before): bool
    {
        $depth = 0;
        $openTryDepths = [];
        $length = strlen($before);

        for ($i = 0; $i < $length; $i++) {
            if ($before[$i] === '{') {
                if (preg_match('/\btry\s*$/', substr($before, max(0, $i - 8), min(8, $i)))) {
                    $openTryDepths[] = $depth;
                }
                $depth++;
                continue;
            }
            if ($before[$i] === '}') {
                $depth--;
                $openTryDepths = array_values(array_filter(
                    $openTryDepths,
                    static fn (int $tryDepth): bool => $tryDepth < $depth
                ));
            }
        }

        return $openTryDepths !== [];
    }
}
