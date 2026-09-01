<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A seeder is not a re-armer, and the difference is not visible at the
 * call site.
 *
 * `bootstrap()` and `ensureScheduled()` run from a composition root — on
 * every single web request. `rearm()`'s guard only looks at `pending`
 * rows, deliberately, so a handler re-arming itself from inside handle()
 * does not find its own claimed row. Borrowed by a seeder, that same
 * guard queues a duplicate for the whole length of every cron pass,
 * during which the chain's only row is `processing`; each duplicate makes
 * the next pass longer, which widens the window, which catches more
 * requests. One installation reached 16 387 runs of an hourly task in
 * forty-eight hours.
 *
 * Nothing about a call to `rearmAfter()` looks wrong inside a
 * `bootstrap()`, which is exactly why this is a test and not a comment.
 */
class ChainSeedingInvariantTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach ([$root . '/core', $root . '/modules'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The body of one method, from its signature to the matching closing
     * brace at the same indentation — enough for a four-line seeder.
     */
    private static function methodBody(string $source, string $signature): ?string
    {
        $start = strpos($source, $signature);
        if ($start === false) {
            return null;
        }

        $end = strpos($source, "\n    }\n", $start);

        return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function seederProvider(): array
    {
        $cases = [];
        foreach (self::sources() as $path) {
            $source = (string) file_get_contents($path);
            foreach (['public static function bootstrap(', 'public static function ensureScheduled('] as $signature) {
                if (str_contains($source, $signature)) {
                    $cases[basename($path) . ' — ' . rtrim($signature, '(')] = [
                        (string) self::methodBody($source, $signature),
                    ];
                }
            }
        }

        self::assertNotSame([], $cases, 'aucun amorceur trouvé — le test ne teste plus rien');

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('seederProvider')]
    public function testASeederArmsItsChainThroughSeedAndNeverThroughRearm(string $body): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/->rearm(After)?\s*\(/',
            $body,
            "Un amorceur appelé depuis une racine de composition doit passer par seed()/seedAfter() : "
                . "la garde de rearm() ne voit que les lignes `pending`, donc pendant toute une passe du "
                . "planificateur — où la ligne de la chaîne est `processing` — chaque requête web en "
                . "empile une de plus. Voir Core\\Scheduler\\SchedulerService::seed()."
        );
    }
}
