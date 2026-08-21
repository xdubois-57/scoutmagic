<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SECURITY.md §1 / AGENTS.md § Security checklist: "All SQL uses prepared
 * statements (no concatenation)". This walks the source and enforces it.
 *
 * The rule it actually encodes — the one worth stating, because a plain
 * "no concatenation ever" is not what the codebase does and never could
 * be — is: **no VALUE may reach a SQL string except through a bound
 * parameter.** Three ways of building SQL are therefore recognised as safe
 * and are checked structurally, per line, rather than being exempted:
 *
 *   1. a placeholder list — `IN (' . $placeholders . ')` where the file
 *      builds $placeholders with `array_fill(…, '?')`. The values are
 *      bound by execute(); the concatenated text is `?,?,?`. This is the
 *      codebase's standard idiom for an IN clause (70-odd call sites), and
 *      the previous version of this audit flagged exactly one of them —
 *      the one that happened to put the SQL literal and the concatenation
 *      on the same source line. An audit that fires on where the author
 *      pressed Enter is worse than no audit: it teaches everyone that its
 *      findings are noise.
 *   2. a literal fragment — a variable every one of whose assignments in
 *      the file puts only quoted literals in value position, e.g.
 *      `$clause = $includeHidden ? '' : ' AND hidden_at IS NULL';`. A
 *      variable read in a ternary CONDITION is fine; one that reaches a
 *      value position is not (see isLiteralOnlyAssignment()).
 *   3. an explicitly reviewed line — REVIEWED below, keyed by file AND by
 *      the code itself, each with the reason it is safe. Unlike the
 *      whole-file allow-list this replaces, adding a new unsafe line to an
 *      already-listed file still fails: nothing is exempt but the exact
 *      line somebody read and signed off.
 *
 * Anything else is a finding. When a finding is a false positive, the fix
 * is a REVIEWED entry with a written reason — never a broader pattern.
 *
 * WHAT THIS DOES NOT COVER, so nobody reads a green run as more than it is:
 * the three patterns look at `$pdo->query()`, `$pdo->exec()`, and a quoted
 * SQL literal concatenated with `.`. SQL built by INTERPOLATION into a
 * double-quoted string — `"… WHERE id IN ({$placeholders})"`, `"… SET
 * {$column} = ?"` — is not examined, in prepare() or anywhere else. Every
 * one of the ~50 such sites in core/ and modules/ was read when this audit
 * was repaired (2026-08) and each is a placeholder list, a column name
 * chosen by a `match` over an enum or checked against a whitelist, or DDL
 * built from the repository's own schema files — none carries request
 * data. That was a manual pass, not a guarantee about the next one: a
 * future `"… WHERE name = '{$name}'"` would go unnoticed here.
 */
class SqlInjectionAuditTest extends TestCase
{
    /**
     * Lines that build SQL from something other than a literal and are
     * nonetheless safe, with the reason. Keyed by repository-relative path,
     * then by the exact (trimmed) line of code — so the entry follows the
     * code when it moves, and stops applying the moment it changes.
     *
     * Every entry here is administrative DDL whose identifiers come from
     * the database's own catalogue, or SQL text produced by this
     * application's own schema tooling. None of them can carry request
     * data: there is no code path from an HTTP request to any of these
     * strings.
     *
     * @var array<string, array<string, string>>
     */
    private const REVIEWED = [
        'core/Database/MigrationRunner.php' => [
            '$pdo->exec($statement);' =>
                'Executes DDL produced by SchemaComparator from the repository\'s own schema/*.sql '
                . 'files (parsed by SqlParser), one statement at a time. The input is SQL by '
                . 'construction and never contains anything a request supplied.',
        ],
        'core/Database/SchemaComparator.php' => [
            '$statements[] = "ALTER TABLE `{$declared->name}` ADD COLUMN " . $this->columnToSql($declaredCol);' =>
                'Builds DDL from a schema file this repository ships — $declared->name is a table name '
                . 'SqlParser read out of schema/*.sql, never a value from a request.',
            '$statements[] = "ALTER TABLE `{$declared->name}` MODIFY COLUMN " . $this->columnToSql($declaredCol);' =>
                'Same: the identifier comes from the repository\'s own schema file.',
        ],
        'core/Database/DatabaseRestorer.php' => [
            '$pdo->exec($statement);' =>
                'Restores a .sql backup this installation produced, statement by statement. Executing '
                . 'SQL text IS the operation; what protects it is the provenance of the dump (an '
                . 'admin-only restore of a file BackupService wrote), not escaping.',
        ],
        'core/Http/Controller/SetupController.php' => [
            '$pdo->exec(\'DROP TABLE IF EXISTS `\' . $table . \'`\');' =>
                'Empties the database during first-time setup. $table comes from '
                . 'SchemaIntrospector::getTables(), i.e. INFORMATION_SCHEMA on this very connection — '
                . 'the server\'s own list of its own tables, never a name a request chose.',
        ],
        'core/Maintenance/Task/FullResetHandler.php' => [
            '$pdo->exec(\'DELETE FROM "\' . $table . \'"\');' =>
                'Full reset, SQLite branch: $table comes from sqlite_master on this connection.',
            '$pdo->exec(\'TRUNCATE TABLE `\' . $table . \'`\');' =>
                'Full reset, MySQL branch: $table comes from SHOW TABLES on this connection.',
        ],
    ];

    /**
     * $pdo->query() whose argument is not a plain literal.
     */
    private const PATTERN_QUERY = '/\$(?:this->)?pdo->query\s*\(\s*(?:.*\$|\s*"[^"]*\$)/';

    /**
     * $pdo->exec() whose argument is not a plain literal.
     */
    private const PATTERN_EXEC = '/\$(?:this->)?pdo->exec\s*\(\s*(?:.*\$|\s*"[^"]*\$)/';

    /**
     * A SQL literal concatenated with something.
     */
    private const PATTERN_CONCAT = '/["\'](?:SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b.*["\']\s*\.\s*\$/';

    public function testNoPdoQueryWithVariables(): void
    {
        $findings = $this->findings(self::PATTERN_QUERY);

        $this->assertSame(
            [],
            $findings,
            "\$pdo->query() built from something other than a literal:\n" . implode("\n", $findings)
        );
    }

    public function testNoPdoExecWithVariables(): void
    {
        $findings = $this->findings(self::PATTERN_EXEC);

        $this->assertSame(
            [],
            $findings,
            "\$pdo->exec() built from something other than a literal:\n" . implode("\n", $findings)
        );
    }

    public function testNoSqlStringConcatenation(): void
    {
        $findings = $this->findings(self::PATTERN_CONCAT);

        $this->assertSame(
            [],
            $findings,
            "SQL concatenated with something other than a literal:\n" . implode("\n", $findings)
        );
    }

    /**
     * An exemption nobody can reach any more is an exemption that stopped
     * describing the code: it hides the next reviewer from the fact that
     * the risky line is gone, and it makes the list grow forever.
     */
    public function testEveryReviewedLineStillExists(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];

        foreach (self::REVIEWED as $file => $entries) {
            $contents = @file_get_contents($root . '/' . $file);
            if ($contents === false) {
                $missing[] = "{$file} (file is gone)";
                continue;
            }

            foreach (array_keys($entries) as $code) {
                if (!$this->fileContainsLine($contents, $code)) {
                    $missing[] = "{$file}: {$code}";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "REVIEWED names lines that no longer exist — delete the entries:\n" . implode("\n", $missing)
        );
    }

    // -------------------------------------------------------------------
    // The safety classifier, tested on its own so its verdicts can be
    // trusted: an audit whose "this one is fine" logic is itself untested
    // is an audit that can silently stop auditing.
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function classifierProvider(): array
    {
        return [
            'placeholder list' => [
                "'SELECT * FROM t WHERE id IN (' . \$placeholders . ')'",
                "\$placeholders = implode(',', array_fill(0, count(\$ids), '?'));",
                true,
            ],
            'placeholder-shaped name without the array_fill that makes it one' => [
                "'SELECT * FROM t WHERE id IN (' . \$placeholders . ')'",
                "\$placeholders = \$request->get('ids');",
                false,
            ],
            'literal fragment chosen by a ternary' => [
                "\$sql = 'SELECT * FROM t WHERE a = 1' . \$clause;",
                "\$clause = \$includeHidden ? '' : ' AND hidden_at IS NULL';",
                true,
            ],
            'a value in the ternary, not just in its condition' => [
                "\$sql = 'SELECT * FROM t WHERE a = 1' . \$clause;",
                "\$clause = \$includeHidden ? '' : \$userSuppliedClause;",
                false,
            ],
            'literal fragment appended with .=' => [
                "\$sql = 'SELECT * FROM t' . \$tail;",
                "\$tail = ' WHERE a = 1';\n\$tail .= ' ORDER BY id';",
                true,
            ],
            'literal fragment later appended with a variable' => [
                "\$sql = 'SELECT * FROM t' . \$tail;",
                "\$tail = ' WHERE a = 1';\n\$tail .= \$order;",
                false,
            ],
            'inline ternary of literals, no variable in value position' => [
                "\$sql = 'SELECT * FROM t WHERE a = 1' . (\$includeHidden ? '' : ' AND b = 1');",
                '',
                true,
            ],
            'null-coalesced request data is not a literal' => [
                "\$pdo->query('SELECT * FROM t WHERE a = ' . \$evil);",
                "\$evil = \$_GET['q'] ?? '';",
                false,
            ],
            'Elvis-defaulted variable is not a literal' => [
                "\$sql = 'SELECT * FROM t' . \$tail;",
                "\$tail = \$order ?: '';",
                false,
            ],
            'a bare variable' => [
                "\$sql = 'SELECT * FROM t WHERE name = ' . \$name;",
                '',
                false,
            ],
            'a function call is not provably a literal' => [
                "\$sql = 'SELECT * FROM t WHERE a = 1' . \$clause;",
                "\$clause = \$this->buildClause();",
                false,
            ],
        ];
    }

    #[DataProvider('classifierProvider')]
    public function testTheClassifierRecognisesSafeAndUnsafeSql(string $line, string $context, bool $expectedSafe): void
    {
        $this->assertSame($expectedSafe, $this->isProvablySafe($line, $line . "\n" . $context));
    }

    // -------------------------------------------------------------------
    // Implementation
    // -------------------------------------------------------------------

    /**
     * @return string[] one entry per line that is neither provably safe
     *                  nor explicitly reviewed
     */
    private function findings(string $pattern): array
    {
        $root = dirname(__DIR__, 2);
        $findings = [];

        foreach ($this->sourceFiles($root) as $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $relative = str_replace($root . '/', '', $path);

            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                if ($this->isComment($line) || $this->isProvablySafe($line, $contents)) {
                    continue;
                }

                if (isset(self::REVIEWED[$relative][trim($line)])) {
                    continue;
                }

                $findings[] = "{$relative}:" . ($index + 1) . ': ' . trim($line);
            }
        }

        sort($findings);

        return $findings;
    }

    /**
     * core/ plus every module's src/ — the application's own PHP. Never
     * modules/*&#47;vendor/, which is bundled third-party code excluded from
     * static analysis too (phpstan.neon).
     *
     * @return string[]
     */
    private function sourceFiles(string $root): array
    {
        $directories = [$root . '/core'];
        foreach ((array) glob($root . '/modules/*/src', GLOB_ONLYDIR) as $moduleSrc) {
            $directories[] = (string) $moduleSrc;
        }

        $files = [];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * A line inside a comment is documentation about SQL, not SQL — the
     * docblocks in this codebase quote query fragments constantly.
     */
    private function isComment(string $line): bool
    {
        return preg_match('#^\s*(//|\*|/\*)#', $line) === 1;
    }

    /**
     * Whether every variable this line puts into SQL is provably not a
     * value: a placeholder list, or a variable assigned only literals.
     */
    private function isProvablySafe(string $line, string $fileContents): bool
    {
        foreach ($this->variablesIn($line) as $variable) {
            if ($this->isPlaceholderList($variable, $line, $fileContents)) {
                continue;
            }

            if ($this->isLiteralOnlyVariable($variable, $fileContents)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * The variables a line puts into SQL. `$this` and `$pdo` are the
     * receiver of the call, not part of the statement; a variable that
     * only appears inside a ternary CONDITION is a decision, not a value,
     * and is dropped with the conditions themselves.
     *
     * @return string[] variable names, without the $
     */
    private function variablesIn(string $line): array
    {
        // The variable being ASSIGNED is the statement's destination, not
        // something it puts into SQL — `$sql = 'SELECT …' . $clause;` is
        // about $clause. Left in, every such line would judge itself by
        // its own assignment and never be provably anything.
        $withoutTarget = (string) preg_replace('/^\s*\$\w+(\[[^\]]*\])?\s*\.?=\s*/', '', $line);

        $valuePositions = $this->valuePositions($this->withoutStringLiterals($withoutTarget));

        preg_match_all('/\$(\w+)/', $valuePositions, $matches);

        return array_values(array_unique(array_diff($matches[1], ['this', 'pdo'])));
    }

    /**
     * Everything a fragment says apart from its ternary conditions: the
     * text before the first `?` of a ternary is where a variable may be
     * read safely, the text after `?` and after `:` is where its VALUE
     * lands. Splitting on both and keeping the value halves is what lets
     * `$cond ? '' : ' AND x'` pass while `$cond ? '' : $x` does not.
     */
    private function valuePositions(string $withoutLiterals): string
    {
        if (!str_contains($withoutLiterals, '?')) {
            return $withoutLiterals;
        }

        // `??`, `?:` and `?->` all keep their LEFT-hand side in value
        // position, unlike a real ternary — so none of them may have that
        // side dropped as if it were a condition. `$sql = $_GET['q'] ?? '';`
        // read as a ternary is exactly how an audit ends up calling
        // request data a literal. Judged whole instead, which fails closed.
        if (preg_match('/\?\?|\?:|\?->/', $withoutLiterals) === 1) {
            return $withoutLiterals;
        }

        $parts = preg_split('/[?:]/', $withoutLiterals) ?: [];
        // The first part is everything before the first `?` — the
        // condition — so it is the one part dropped.
        array_shift($parts);

        return implode(' ', $parts);
    }

    /**
     * Quoted string literals removed, so what is left is the code around
     * them. Handles backslash escapes inside both quote styles.
     */
    private function withoutStringLiterals(string $code): string
    {
        return (string) preg_replace('/(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")/', "''", $code);
    }

    /**
     * `IN (' . $x . ')` where $x is the `?,?,?` list built by array_fill —
     * the values themselves are bound by execute().
     */
    private function isPlaceholderList(string $variable, string $line, string $fileContents): bool
    {
        $inClause = '/IN\s*\(\s*\'\s*\.\s*\$' . preg_quote($variable, '/') . '\s*\.\s*\'\s*\)/';
        if (preg_match($inClause, $line) !== 1) {
            return false;
        }

        // `implode(',', array_fill(0, count($ids), '?'))` — the inner
        // count() means the arguments cannot be matched with [^)]*.
        $builtFromArrayFill = '/\$' . preg_quote($variable, '/')
            . '\s*=\s*implode\s*\(\s*\'\s*,\s*\'\s*,\s*array_fill\s*\(.*?\'\?\'\s*\)/s';

        return preg_match($builtFromArrayFill, $fileContents) === 1;
    }

    /**
     * Whether every assignment of $variable in the file puts only quoted
     * literals in value position. One assignment that does not is enough
     * to disqualify it everywhere — the audit never has to guess which
     * assignment reached this line.
     */
    private function isLiteralOnlyVariable(string $variable, string $fileContents): bool
    {
        $pattern = '/\$' . preg_quote($variable, '/') . '\s*(\.?=)\s*([^;]*);/';
        if (preg_match_all($pattern, $fileContents, $matches, PREG_SET_ORDER) === 0) {
            // Never assigned in this file: a parameter, a foreach value, a
            // property — none of which this can prove anything about.
            return false;
        }

        foreach ($matches as $match) {
            if (!$this->isLiteralOnlyAssignment($match[2])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The right-hand side of an assignment, judged the same way a line is:
     * strip the literals, drop the ternary conditions, and if a variable
     * survives in value position it is not provably a literal.
     */
    private function isLiteralOnlyAssignment(string $rightHandSide): bool
    {
        $values = $this->valuePositions($this->withoutStringLiterals($rightHandSide));

        return !str_contains($values, '$') && !str_contains($values, '(');
    }

    private function fileContainsLine(string $contents, string $code): bool
    {
        foreach (explode("\n", $contents) as $line) {
            if (trim($line) === $code) {
                return true;
            }
        }

        return false;
    }
}
