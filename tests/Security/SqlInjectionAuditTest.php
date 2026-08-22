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
 * A fourth rule covers the other way SQL gets built here: INTERPOLATION
 * into a double-quoted string — `"… WHERE id IN ({$placeholders})"`,
 * `"… SET {$column} = ?"` — wherever it happens, prepare() included. It
 * reads whole strings rather than lines, because most of them span several
 * (the SQL keyword and the interpolation are rarely on the same one), and
 * what it judges is each INTERPOLATED EXPRESSION rather than the statement
 * around it: the expression is what carries the risk, and reviewing
 * `{$this->table}` once beats pasting six multi-line queries into a map.
 * Hence a second, separate list — REVIEWED_INTERPOLATIONS, keyed by file
 * and by the expression as it is written.
 *
 * Two safe forms are recognised structurally for it on top of the ones
 * above: an identifier chosen by a `match` whose arms are all literals,
 * and one checked against a whitelist by an `in_array()` that throws.
 * Together with the placeholder-list rule they clear most of the 93
 * interpolated statements (134 expressions) core/ and modules/ contain
 * today; the ~20 that remain are signed off one expression at a time.
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
     * Interpolated expressions that are not provably safe and are
     * nonetheless not values, with the reason. Keyed by repository-relative
     * path, then by the expression exactly as it is written in the string.
     *
     * Reviewing the EXPRESSION rather than the statement is deliberate: a
     * repository that interpolates `{$this->table}` into six queries has
     * one thing to check, not six, and a seventh query interpolating
     * something else still fails.
     *
     * The trade-off, stated so it is a choice and not a surprise: an entry
     * trusts that expression ANYWHERE in that file, including in a clause
     * it was not written for. That is why every entry below justifies the
     * expression's VALUE — a table name no caller can choose, a fragment
     * assembled from literals — and never its position: a value that
     * cannot carry request data cannot carry it in another clause either.
     *
     * @var array<string, array<string, string>>
     */
    private const REVIEWED_INTERPOLATIONS = [
        // The schema engine: every identifier below is read out of this
        // repository's own schema/*.sql (by Core\Database\SqlParser) or out
        // of INFORMATION_SCHEMA on the connection being migrated. Nothing a
        // request sends reaches any of it — there is no route into a
        // migration at all.
        'core/Database/SchemaComparator.php' => [
            '{$table->name}' => 'CREATE TABLE for a table the schema file declares.',
            '{$body}' => 'The column/index/constraint lines of that same CREATE TABLE, built from the parsed schema.',
            '{$declared->name}' => 'ALTER TABLE for a table the schema file declares.',
            '{$idx->name}' => 'An index name from the parsed schema.',
            '{$cols}' => 'That index\'s own column list, each name quoted, from the parsed schema.',
            '{$fk->name}' => 'A foreign-key constraint name from the parsed schema.',
            '{$fk->column}' => 'Its column, from the parsed schema.',
            '{$fk->referencedTable}' => 'Its referenced table, from the parsed schema.',
            '{$fk->referencedColumn}' => 'Its referenced column, from the parsed schema.',
        ],
        'core/Database/MigrationRunner.php' => [
            '{$drop[\'table\']}' => 'An explicit drop listed in the repository\'s own drops.sql, and checked against '
                . 'INFORMATION_SCHEMA before it runs.',
            '{$drop[\'column\']}' => 'Same drops.sql entry, same check.',
            '{$drop[\'constraint\']}' => 'Same drops.sql entry, same check.',
        ],

        // Column names the code picks, never a caller.
        'core/Security/LoginThrottler.php' => [
            '{$column}' => 'The parameter of two PRIVATE methods (lockoutFor/countRecentFailures) whose only call '
                . 'sites are in this same class and pass the literals \'email_blind_index\' and '
                . '\'ip_blind_index\'. The value being throttled on is bound, as always.',
        ],

        // Clause fragments assembled from literals, with every value bound.
        'modules/finance/src/Repository/AttachmentRepository.php' => [
            '{$fromWhere}' => 'buildFilterSql() returns a FROM/WHERE built only from string literals plus `?` '
                . 'placeholders, with its parameters returned alongside it and bound by execute().',
        ],
        'modules/mass_mail/src/Repository/EmailRepository.php' => [
            '{$whereSql}' => 'The conditions are literal strings pushed onto $where (each with `?` where a value '
                . 'goes) and joined with AND; every value travels in $params and is bound.',
        ],

        // One class, two tables: the table and item-column names are
        // interpolated because they are what varies between the post and
        // the reply flavour of the same query. Each class has a PRIVATE
        // constructor and two named constructors that pass string literals
        // (forPosts()/forReplies()), so no caller can choose either name —
        // the classes say so in their own docblocks too.
        'modules/groups/src/Repository/ReactionRepository.php' => [
            '{$this->table}' => 'Sealed constructor: only forPosts()/forReplies() build this, each with literals.',
            '{$this->itemColumn}' => 'Same sealed constructor.',
        ],
        'modules/groups/src/Repository/ReactionNoticeRepository.php' => [
            '{$this->table}' => 'Sealed constructor: only forPosts()/forReplies() build this, each with literals.',
            '{$this->itemColumn}' => 'Same sealed constructor.',
        ],
        'modules/groups/src/Repository/ReportRepository.php' => [
            '{$this->table}' => 'Sealed constructor: only forPosts()/forReplies() build this, each with literals.',
            '{$this->itemColumn}' => 'Same sealed constructor.',
            '{$this->groupJoin}' => 'Same sealed constructor: the JOIN reaching from a report to the group it '
                . 'belongs to differs between the post and the reply flavour, and both are string literals '
                . 'written in forPosts()/forReplies(). The group id itself is bound.',
            '{$this->groupCondition}' => 'Same sealed constructor, same two literals — the WHERE naming the '
                . 'joined table whose group_id is compared, with the id bound.',
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

    public function testNoSqlStringInterpolation(): void
    {
        $findings = $this->interpolationFindings();

        $this->assertSame(
            [],
            $findings,
            "SQL interpolating something other than a literal:\n" . implode("\n", $findings)
        );
    }

    /**
     * An exemption nobody can reach any more is an exemption that stopped
     * describing the code: it hides the next reviewer from the fact that
     * the risky line is gone, and it makes the list grow forever.
     */
    public function testEveryReviewedLineOrExpressionStillExists(): void
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

        foreach (self::REVIEWED_INTERPOLATIONS as $file => $entries) {
            $contents = @file_get_contents($root . '/' . $file);
            if ($contents === false) {
                $missing[] = "{$file} (file is gone)";
                continue;
            }

            foreach (array_keys($entries) as $expression) {
                if (!str_contains($contents, $expression)) {
                    $missing[] = "{$file}: {$expression}";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "A review entry names code that no longer exists — delete it:\n" . implode("\n", $missing)
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

    /**
     * The interpolation classifier, on the same footing.
     *
     * @return array<string, array{string, string, string, bool}>
     */
    public static function interpolationClassifierProvider(): array
    {
        return [
            'placeholder list inside an IN' => [
                '{$placeholders}',
                'SELECT * FROM t WHERE id IN ({$placeholders})',
                "\$placeholders = implode(',', array_fill(0, count(\$ids), '?'));",
                true,
            ],
            'the same variable outside an IN is judged on its own merits' => [
                '{$placeholders}',
                'SELECT * FROM t ORDER BY {$placeholders}',
                "\$placeholders = implode(',', array_fill(0, count(\$ids), '?'));",
                false,
            ],
            'placeholder-shaped name that no array_fill builds' => [
                '{$placeholders}',
                'SELECT * FROM t WHERE id IN ({$placeholders})',
                "\$placeholders = \$request->get('ids');",
                false,
            ],
            'a column chosen by a match over literals' => [
                '{$column}',
                'SELECT * FROM t WHERE {$column} = ?',
                "\$column = match (\$tier) {\n Tier::A => 'col_a',\n Tier::B => 'col_b',\n};",
                true,
            ],
            'a match with a variable in an arm is not a whitelist' => [
                '{$column}',
                'SELECT * FROM t WHERE {$column} = ?',
                "\$column = match (\$tier) {\n Tier::A => 'col_a',\n Tier::B => \$requested,\n};",
                false,
            ],
            'a column checked against a literal whitelist that throws' => [
                '{$channel}',
                'UPDATE t SET {$channel} = ? WHERE id = ?',
                "if (!in_array(\$channel, ['in_app', 'push', 'email'], true)) { throw new \\InvalidArgumentException('x'); }",
                true,
            ],
            'an in_array check that does not throw guards nothing' => [
                '{$channel}',
                'UPDATE t SET {$channel} = ? WHERE id = ?',
                "if (!in_array(\$channel, ['in_app', 'push'], true)) { return; }",
                false,
            ],
            'a property is never provable from here' => [
                '{$this->table}',
                'SELECT * FROM {$this->table} WHERE id = ?',
                '',
                false,
            ],
            'request data interpolated straight in' => [
                '{$name}',
                "SELECT * FROM t WHERE name = '{\$name}'",
                "\$name = \$request->getBody('name');",
                false,
            ],
        ];
    }

    #[DataProvider('interpolationClassifierProvider')]
    public function testTheInterpolationClassifierRecognisesSafeAndUnsafeSql(
        string $expression,
        string $statement,
        string $context,
        bool $expectedSafe
    ): void {
        $this->assertSame(
            $expectedSafe,
            $this->isSafeInterpolation($expression, $statement, $statement . "\n" . $context)
        );
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

        return $this->isBuiltFromArrayFill($variable, $fileContents);
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

    /**
     * Every double-quoted string that reads like a statement and
     * interpolates something, judged expression by expression.
     *
     * Whole-file rather than line-by-line: most of these statements span
     * several lines, and a line scanner sees the SQL keyword and the
     * interpolation as unrelated.
     *
     * @return string[]
     */
    private function interpolationFindings(): array
    {
        $root = dirname(__DIR__, 2);
        $findings = [];

        foreach ($this->sourceFiles($root) as $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $relative = str_replace($root . '/', '', $path);

            foreach ($this->interpolatedStatements($contents) as [$statement, $line]) {
                foreach ($this->interpolationsIn($statement) as $expression) {
                    if ($this->isSafeInterpolation($expression, $statement, $contents)) {
                        continue;
                    }

                    if (isset(self::REVIEWED_INTERPOLATIONS[$relative][$expression])) {
                        continue;
                    }

                    $findings[] = "{$relative}:{$line}: {$expression}";
                }
            }
        }

        $findings = array_values(array_unique($findings));
        sort($findings);

        return $findings;
    }

    /**
     * Double-quoted strings that open with a SQL keyword and interpolate
     * something. Single-quoted strings cannot interpolate at all, which is
     * why only one quote style is read here.
     *
     * Read with PHP's own tokenizer rather than a regex over the source.
     * A regex cannot tell a statement from prose that merely starts with
     * the same word — the first version of this matched the `"with a JSON
     * error body…"` in a docblock and then ran to the next quote several
     * hundred lines later, reporting every variable in between. The
     * tokenizer sees comments as comments and hands back exactly the
     * strings that interpolate something (an interpolating double-quoted
     * string is not one token: it is a `"`, then its pieces, then a `"`).
     *
     * @return array<array{string, int}> the string and the line it starts on
     */
    private function interpolatedStatements(string $contents): array
    {
        $statements = [];
        $line = 1;
        $tokens = token_get_all($contents);

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($token !== '"') {
                $line += substr_count($text, "\n");
                continue;
            }

            $startLine = $line;
            $body = '';
            $interpolates = false;

            for ($i++; $i < $count; $i++) {
                $inner = $tokens[$i];
                $innerText = is_array($inner) ? $inner[1] : $inner;

                if ($inner === '"') {
                    break;
                }

                if (is_array($inner) && in_array($inner[0], [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $interpolates = true;
                }

                $body .= $innerText;
                $line += substr_count($innerText, "\n");
            }

            if ($interpolates && $this->readsLikeAStatement($body)) {
                $statements[] = [$body, $startLine];
            }
        }

        return $statements;
    }

    /**
     * A SQL keyword in the position a statement starts with, plus a clause
     * only SQL has. Uppercase, deliberately: every statement in this
     * codebase is written that way, and matching case-insensitively is how
     * an English sentence becomes a finding.
     */
    private function readsLikeAStatement(string $body): bool
    {
        $opens = preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|WITH)\b/', $body) === 1;

        return $opens
            && preg_match('/\b(FROM|INTO|SET|TABLE|WHERE|VALUES|INDEX|CONSTRAINT|COLUMN|KEY)\b/', $body) === 1;
    }

    /**
     * The interpolated expressions in a double-quoted string, written the
     * way the source writes them: `{$this->table}`, `{$placeholders}`,
     * `$placeholders`.
     *
     * @return string[]
     */
    private function interpolationsIn(string $statement): array
    {
        preg_match_all(
            '/\{\$[^}]+\}|\$[A-Za-z_]\w*(?:->\w+|\[[^\]]*\])*/',
            $statement,
            $matches
        );

        return array_values(array_unique($matches[0]));
    }

    /**
     * Whether one interpolated expression provably cannot carry a value.
     */
    private function isSafeInterpolation(string $expression, string $statement, string $fileContents): bool
    {
        // `{$this->…}`, `$row['x']`, a method call: nothing this can follow
        // to an assignment, so nothing it can prove. REVIEWED_INTERPOLATIONS
        // is where those are signed off, by a human.
        if (preg_match('/^\{?\$([A-Za-z_]\w*)\}?$/', $expression, $matched) !== 1) {
            return false;
        }

        $variable = $matched[1];

        return $this->isInterpolatedPlaceholderList($variable, $statement, $fileContents)
            || $this->isLiteralOnlyVariable($variable, $fileContents)
            || $this->isWhitelistedIdentifier($variable, $fileContents);
    }

    /**
     * `IN ({$placeholders})` where the file builds the variable with
     * array_fill — the `?,?,?` half of a bound IN clause. Required to sit
     * inside the parentheses of an IN, so a placeholder-shaped name used
     * anywhere else in the statement is still judged on its own merits.
     */
    private function isInterpolatedPlaceholderList(string $variable, string $statement, string $fileContents): bool
    {
        $inClause = '/IN\s*\(\s*\{?\$' . preg_quote($variable, '/') . '\}?\s*\)/i';
        if (preg_match($inClause, $statement) !== 1) {
            return false;
        }

        return $this->isBuiltFromArrayFill($variable, $fileContents);
    }

    /**
     * An identifier the code cannot be talked into choosing: either every
     * assignment is a `match` whose arms are literals, or the value is
     * checked against a literal whitelist by an `in_array()` that throws
     * before any SQL is built.
     */
    private function isWhitelistedIdentifier(string $variable, string $fileContents): bool
    {
        return $this->isMatchOfLiterals($variable, $fileContents)
            || $this->isGuardedByInArray($variable, $fileContents);
    }

    /**
     * `$column = match ($tier) { Tier::CHEAP => 'is_tier_cheap', … };` —
     * the subject is a condition (a variable may be read there), every arm
     * yields a literal, and every assignment of the variable in the file
     * has to be one of these.
     */
    private function isMatchOfLiterals(string $variable, string $fileContents): bool
    {
        $pattern = '/\$' . preg_quote($variable, '/') . '\s*=\s*(match\s*\(.*?\}\s*);/s';
        if (preg_match_all($pattern, $fileContents, $matches, PREG_SET_ORDER) === 0) {
            return false;
        }

        // Every assignment must be one, not just the first one found.
        $assignments = preg_match_all('/\$' . preg_quote($variable, '/') . '\s*\.?=\s*/', $fileContents);
        if ($assignments !== count($matches)) {
            return false;
        }

        foreach ($matches as $match) {
            // Drop the subject — `match ($tier)` — the way a ternary
            // condition is dropped, then require the arms to be literals.
            $arms = (string) preg_replace('/^match\s*\([^)]*\)/', '', $match[1]);
            if (str_contains($this->withoutStringLiterals($arms), '$')) {
                return false;
            }
        }

        return true;
    }

    /**
     * `if (!in_array($channel, ['in_app', 'push', 'email'], true)) { throw …`
     * — the value is one of a fixed set of literals by the time any SQL
     * exists, whatever the caller passed.
     */
    private function isGuardedByInArray(string $variable, string $fileContents): bool
    {
        $pattern = '/!\s*in_array\s*\(\s*\$' . preg_quote($variable, '/')
            . '\s*,\s*\[([^\]]*)\]\s*,\s*true\s*\)\s*\)\s*\{\s*throw/s';

        if (preg_match($pattern, $fileContents, $matched) !== 1) {
            return false;
        }

        // The whitelist itself has to be literals — a list built from
        // request data would guard nothing.
        return !str_contains($this->withoutStringLiterals($matched[1]), '$');
    }

    private function isBuiltFromArrayFill(string $variable, string $fileContents): bool
    {
        $builtFromArrayFill = '/\$' . preg_quote($variable, '/')
            . '\s*=\s*implode\s*\(\s*\'\s*,\s*\'\s*,\s*array_fill\s*\(.*?\'\?\'\s*\)/s';

        return preg_match($builtFromArrayFill, $fileContents) === 1;
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
