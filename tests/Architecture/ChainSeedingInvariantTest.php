<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A seeder is not a re-armer, and the difference is not visible at the
 * call site.
 *
 * A composition root arms a chain on every single web request. `rearm()`'s
 * guard only looks at `pending` rows, deliberately, so a handler re-arming
 * itself from inside `handle()` does not find its own claimed row. Borrowed
 * by a seeder, that same guard queues a duplicate for the whole length of
 * every cron pass, during which the chain's only row is `processing`; each
 * duplicate makes the next pass longer, which widens the window, which
 * catches more requests. One installation reached 16 387 runs of an hourly
 * task in forty-eight hours.
 *
 * Nothing about a call to `rearm()` looks wrong inside a `bootstrap()`,
 * which is exactly why this is a test and not a comment.
 *
 * **Two kinds of seeder, and the second is the one that got away.** The
 * first has a method to name — a handler's `bootstrap()` or
 * `ensureScheduled()` — and was all this test looked at. The second is
 * written straight into an entry point, where there is no method to name
 * and therefore nothing this test could match: twenty-two of them had
 * accumulated in `public/index.php` (issue #435), next to six neighbours
 * that already said `seed()`.
 *
 * The scanner reads TOKENS and not text, for a reason paid for in the
 * sibling guard of issue #433: the prose in these files discusses `rearm()`
 * constantly, so a regular expression over the source reports the comments
 * that explain the rule as violations of it. It also has to know that
 * `->` and `?->` are two different tokens, and that whitespace may sit
 * anywhere between them and the parenthesis.
 *
 * **What it still does not see, said out loud rather than discovered
 * later.** A seeder that an entry point delegates to a class under
 * `core/` or `modules/` is only read if that class names the method
 * `bootstrap()` or `ensureScheduled()` — any other name is invisible to
 * both halves. So is a call made through a variable method name
 * (`$scheduler->$arm(…)`) or `call_user_func`, neither of which appears
 * anywhere in this codebase. Writing that down is the point: a guard whose
 * limits are unknown is a guard nobody can reason about.
 */
class ChainSeedingInvariantTest extends TestCase
{
    /**
     * The entry points: everything a web request or a crontab can reach
     * directly. A file here is never a task handler, so an arming call in
     * one is always a seed — whatever it is spelled.
     *
     * Directories rather than a list of files, so an entry point added
     * tomorrow is scanned without anyone remembering to add it.
     */
    private const ENTRY_POINT_DIRS = ['public', 'bootstrap', 'scripts'];

    /** The arming methods that ask « is there a PENDING row », the wrong question for a seeder. */
    private const REARMING_METHODS = ['rearm', 'rearmAfter'];

    /** The arming methods that ask « is this chain alive », which is the seeder's question. */
    private const SEEDING_METHODS = ['seed', 'seedAfter'];

    /** The method names a handler exposes for its composition root to call. */
    private const SEEDER_SIGNATURES = ['bootstrap', 'ensureScheduled'];

    // ── The rule ────────────────────────────────────────────────────────

    public function testNoEntryPointArmsAChainThroughRearm(): void
    {
        $offences = [];
        foreach (self::entryPointFiles() as $path) {
            foreach (self::armingCalls((string) file_get_contents($path), self::REARMING_METHODS) as $call) {
                $offences[] = self::relative($path) . ':' . $call['line'] . ' — ' . $call['method'] . '()';
            }
        }

        $this->assertSame(
            [],
            $offences,
            "Un point d'entrée amorce une chaîne : il n'est jamais le gestionnaire en train de se replanifier, "
                . "donc il doit passer par seed()/seedAfter(). La garde de rearm() ne voit que les lignes "
                . "`pending`, donc pendant toute une passe du planificateur — où la ligne de la chaîne est "
                . "`processing` — chaque requête web en empile une de plus. Voir ARCHITECTURE.md §8.5 et "
                . "Core\\Scheduler\\SchedulerService::seed(). Points d'appel fautifs :\n  "
                . implode("\n  ", $offences)
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('seederProvider')]
    public function testASeederArmsItsChainThroughSeedAndNeverThroughRearm(string $body): void
    {
        $offences = [];
        foreach (self::armingCalls('<?php ' . $body, self::REARMING_METHODS) as $call) {
            $offences[] = $call['method'] . '() ligne ' . $call['line'];
        }

        $this->assertSame(
            [],
            $offences,
            "Un amorceur appelé depuis une racine de composition doit passer par seed()/seedAfter() : "
                . "la garde de rearm() ne voit que les lignes `pending`, donc pendant toute une passe du "
                . "planificateur — où la ligne de la chaîne est `processing` — chaque requête web en "
                . "empile une de plus. Voir Core\\Scheduler\\SchedulerService::seed(). Trouvé : "
                . implode(', ', $offences)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function seederProvider(): array
    {
        $cases = [];
        foreach (self::sourceFiles() as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::SEEDER_SIGNATURES as $name) {
                foreach (self::methodBodies($source, $name) as $index => $body) {
                    $cases[basename($path) . ' — ' . $name . '#' . $index] = [$body];
                }
            }
        }

        self::assertNotSame([], $cases, 'no seeding call found — the test no longer tests anything');

        return $cases;
    }

    // ── The non-vacuity guards ──────────────────────────────────────────
    //
    // A scan that stops seeing anything passes for ever, and looks exactly
    // like a scan that found nothing wrong. So each half has to prove it is
    // still reading what it claims to read.

    public function testTheEntryPointScanStillSeesTheSeedsItWouldHaveJudged(): void
    {
        $seeds = [];
        foreach (self::entryPointFiles() as $path) {
            foreach (self::armingCalls((string) file_get_contents($path), self::SEEDING_METHODS) as $call) {
                $seeds[] = self::relative($path) . ':' . $call['line'];
            }
        }

        $this->assertGreaterThan(
            20,
            count($seeds),
            "The entry-point scan barely finds any seeding left: either the file moved, or the token "
                . "reader no longer recognises these calls. Either way it judges nothing any more, and a "
                . "reintroduced rearm() would pass green."
        );
    }

    public function testTheEntryPointScanReachesPublicIndex(): void
    {
        $this->assertContains(
            'public/index.php',
            array_map([self::class, 'relative'], self::entryPointFiles()),
            "public/index.php is THE web entry point and the composition root where the twenty-two "
                . "seeding calls of issue #435 had accumulated. If it leaves the scan, the test loses its subject."
        );
    }

    // ── What the scanner does NOT see, checked rather than assumed ───────
    //
    // The sibling guard of issue #433 had four holes, all of them because
    // it had been written and validated only against the call sites it
    // already knew how to see. These cases are that lesson applied: each
    // one is a way of writing the offence that a naive scan misses.

    public function testTheScanReadsCallsWrittenWithTheNullsafeOperator(): void
    {
        // `->` is T_OBJECT_OPERATOR and `?->` is T_NULLSAFE_OBJECT_OPERATOR:
        // two different constants, so a scanner that matches only the first
        // reads `$scheduler?->rearm(…)` as no call at all.
        $calls = self::armingCalls(
            '<?php $scheduler?->rearm("core", "k", "daily", new DateTimeImmutable());',
            self::REARMING_METHODS
        );

        $this->assertCount(1, $calls);
        $this->assertSame('rearm', $calls[0]['method']);
    }

    public function testTheScanIsNotFooledByWhitespaceAroundTheCall(): void
    {
        $calls = self::armingCalls(
            "<?php \$scheduler\n    ->rearmAfter(\n        'core',\n        'k',\n        'daily',\n        3600\n    );",
            self::REARMING_METHODS
        );

        $this->assertCount(1, $calls);
        $this->assertSame('rearmAfter', $calls[0]['method']);
    }

    public function testProseAboutRearmIsNotAnOffence(): void
    {
        // This is not a hypothetical: public/index.php and
        // scheduler-bootstrap.php explain this very rule in their comments,
        // and SchedulerService's own docblocks name rearm() a dozen times.
        // A text scan turns every one of those into a violation.
        $calls = self::armingCalls(
            <<<'CODE'
                <?php
                // $scheduler->rearm() here would queue a duplicate.
                /** @see $this->rearm() and ->rearmAfter() */
                $message = 'call $scheduler->rearm() and you get 16 387 runs';
                $scheduler->seed('core', 'k', 'daily', new DateTimeImmutable());
                CODE,
            self::REARMING_METHODS
        );

        $this->assertSame([], $calls);
    }

    public function testAMethodNameThatMerelyStartsWithRearmIsNotTheOne(): void
    {
        $this->assertSame(
            [],
            self::armingCalls('<?php $scheduler->rearmingPolicy(); $s->seedling();', self::REARMING_METHODS)
        );
    }

    public function testASeederDeclaredWithoutStaticIsStillRead(): void
    {
        // Every seeder in the tree happens to be `public static function`
        // today. Matching that exact spelling would mean the first one
        // written as an instance method is simply not looked at.
        $bodies = self::methodBodies(
            '<?php class A { public function bootstrap($s): void { $s->rearm("a", "b", "c", "now"); } }',
            'bootstrap'
        );

        $this->assertCount(1, $bodies);
        $this->assertCount(1, self::armingCalls('<?php ' . $bodies[0], self::REARMING_METHODS));
    }

    public function testASeedersBodyStopsAtItsOwnClosingBrace(): void
    {
        // Brace matching rather than « the next line that is exactly
        // `    }` »: a seeder holding a closure or a match block closes
        // inner braces first, and a seeder whose class is indented
        // differently closes its own somewhere else entirely.
        $bodies = self::methodBodies(
            <<<'CODE'
                <?php
                class A {
                    public static function bootstrap($s): void {
                        $when = (static function () { return 'tomorrow 05:00'; })();
                        $s->seed('a', 'b', 'c', $when);
                    }

                    public static function later($s): void {
                        $s->rearm('a', 'b', 'c', 'now');
                    }
                }
                CODE,
            'bootstrap'
        );

        $this->assertCount(1, $bodies);
        $this->assertSame([], self::armingCalls('<?php ' . $bodies[0], self::REARMING_METHODS));
    }

    public function testEveryDeclarationOfTheSameSeederNameIsRead(): void
    {
        // One file, two classes, both with a bootstrap(): a scan built on
        // strpos() finds the first and stops, so the second is free to do
        // whatever it likes.
        $bodies = self::methodBodies(
            <<<'CODE'
                <?php
                class A {
                    public static function bootstrap($s): void {
                        $s->seed('a', 'b', 'c', 'now');
                    }
                }
                class B {
                    public static function bootstrap($s): void {
                        $s->rearm('a', 'b', 'c', 'now');
                    }
                }
                CODE,
            'bootstrap'
        );

        $this->assertCount(2, $bodies);
        $this->assertCount(1, self::armingCalls('<?php ' . $bodies[1], self::REARMING_METHODS));
    }

    public function testTheScanFindsTheOffenceItIsSupposedToFind(): void
    {
        // The whole point, stated once as a positive: the shape that was in
        // public/index.php twenty-two times is reported, with its line.
        $calls = self::armingCalls(
            "<?php\n\$schedulerService->rearm('core', 'auto_backup', 'auto', new DateTimeImmutable());",
            self::REARMING_METHODS
        );

        $this->assertCount(1, $calls);
        $this->assertSame(2, $calls[0]['line']);
    }

    // ── The scanner ─────────────────────────────────────────────────────

    /**
     * Every `->name(` / `?->name(` in $code whose name is one of $methods.
     *
     * Tokens, not text: comments and string literals are skipped because
     * PHP itself tells us what they are, and both object operators are
     * matched because they are two distinct constants.
     *
     * @param list<string> $methods
     * @return list<array{method: string, line: int}>
     */
    private static function armingCalls(string $code, array $methods): array
    {
        $tokens = token_get_all($code);
        $calls = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] !== T_OBJECT_OPERATOR && $token[0] !== T_NULLSAFE_OBJECT_OPERATOR) {
                continue;
            }

            $name = self::nextMeaningful($tokens, $index + 1);
            if ($name === null || !is_array($tokens[$name]) || $tokens[$name][0] !== T_STRING) {
                continue;
            }
            if (!in_array($tokens[$name][1], $methods, true)) {
                continue;
            }

            // A method name is only a call when a parenthesis follows it;
            // `$this->rearm` on its own is a property read.
            $paren = self::nextMeaningful($tokens, $name + 1);
            if ($paren === null || $tokens[$paren] !== '(') {
                continue;
            }

            $calls[] = ['method' => $tokens[$name][1], 'line' => (int) $tokens[$name][2]];
        }

        return $calls;
    }

    /**
     * The body of every method called $name in $code, braces matched.
     *
     * @return list<string>
     */
    private static function methodBodies(string $code, string $name): array
    {
        $tokens = token_get_all($code);
        $bodies = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = self::nextMeaningful($tokens, $index + 1);
            if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                continue;
            }
            if ($tokens[$nameIndex][1] !== $name) {
                continue;
            }

            $body = self::bodyFrom($tokens, $nameIndex + 1);
            if ($body !== null) {
                $bodies[] = $body;
            }
        }

        return $bodies;
    }

    /**
     * The text between the brace that opens the next block and the one
     * that closes it, counting depth so an inner block cannot end it.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function bodyFrom(array $tokens, int $from): ?string
    {
        $depth = 0;
        $body = '';
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if ($text === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            } elseif ($depth === 0 && $text === ';') {
                // An abstract or interface declaration: a signature with no
                // body at all, which must not swallow whatever follows it.
                return null;
            }

            if ($depth >= 1) {
                $body .= $text;
            }
        }

        return null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    // ── The files ───────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private static function entryPointFiles(): array
    {
        return self::phpFilesIn(self::ENTRY_POINT_DIRS);
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        return self::phpFilesIn(['core', 'modules']);
    }

    /**
     * @param list<string> $dirs
     * @return list<string>
     */
    private static function phpFilesIn(array $dirs): array
    {
        $root = self::root();
        $files = [];
        foreach ($dirs as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        return ltrim(str_replace(self::root(), '', $path), '/');
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
