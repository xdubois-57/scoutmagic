<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A module's setting is read with the module's id, never without it.
 *
 * `SettingService` files every setting under a SCOPE — `module_id` for a
 * module's setting, `_core_` for core's — and every scoped method takes
 * that scope as an optional argument:
 *
 *     public function get(string $key, ?string $moduleId = null, ...)
 *     {
 *         $cacheKey = ($moduleId ?? '_core_') . '::' . $key;
 *
 * `ModuleManager::registerModule()` registers a module's declared settings
 * WITH its id. So a call that omits it looks up `_core_::<key>`, finds
 * nothing, and answers the default — **with no error anywhere**. The
 * feature simply behaves as though nobody had configured it.
 *
 * **That is not hypothetical.** `ParentalAuthorizationService::unitLabel()`
 * read `official_documents_unit_code` without a scope for three iterations
 * (issue #433): the federation code a chief typed into the module's
 * settings screen never once reached the parental authorization, and the
 * screen went on displaying it as saved. What makes the mistake easy is
 * that these keys are prefixed with their module's name — they read like
 * global keys.
 *
 * **And a unit test could not catch it**, which is the other half of the
 * story and the reason this file is an architecture test. The service's
 * own suite covered that method three ways and was green, because it
 * registered the setting with no module id either. Both halves of one
 * mistake, cancelling out. A test that sets up its own fixture can always
 * agree with the code it tests; only a check against the manifests can
 * disagree.
 *
 * **What it cannot see**, said plainly rather than left to be discovered:
 * a key built at runtime (`$this->settings->get($key)`) is invisible to a
 * static scan. The check covers a literal and a class constant, which is
 * how every call site in this repository is written today.
 */
class ModuleSettingsAreReadInTheirScopeTest extends TestCase
{
    /**
     * The `SettingService` methods that take a scope, and which argument it
     * is (zero-based). All seven, not just `get()`: writing a module's
     * setting into `_core_` is the same defect seen from the other side,
     * and it creates a row nothing will ever read.
     *
     * **`setMany()` is the odd one and was nearly the hole in this check.**
     * Its first argument is an array of `key => value` pairs, not a single
     * key, so a scan that reads argument 0 as a scalar resolves nothing and
     * skips the call — silently, while the docblock above claims to cover
     * it. `keyExpressionsOf()` takes it apart entry by entry instead.
     */
    private const SCOPED_METHODS = [
        'get' => 1,
        'set' => 2,
        'setMany' => 1,
        'setInternal' => 2,
        'claimIfEmpty' => 2,
        'replaceIfUnchanged' => 3,
        'validate' => 2,
    ];

    public function testNoModuleSettingIsReadOrWrittenOutsideItsOwnScope(): void
    {
        $moduleKeys = self::declaredModuleKeys();
        $offenders = [];

        foreach (self::phpFiles() as $file) {
            foreach (self::unscopedCalls($file, $moduleKeys) as $offence) {
                $offenders[] = $offence;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These calls read or write a MODULE's setting without its module id.\n"
            . "SettingService files it under the module's scope, so the call looks up\n"
            . "`_core_::<key>`, finds nothing and answers the default — silently, with\n"
            . "the feature behaving as though nobody had configured it (issue #433):\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The guard above is only worth something if it is reading real files
     * and resolving real keys: a scan that matched nothing would pass for
     * ever, which is the failure mode of every check written against a
     * pattern.
     */
    public function testTheScanActuallyFindsTheCallSitesItIsMeantToJudge(): void
    {
        $moduleKeys = self::declaredModuleKeys();
        $this->assertGreaterThan(50, count($moduleKeys), 'No module settings were read from the manifests.');

        $scoped = 0;
        foreach (self::phpFiles() as $file) {
            $scoped += count(self::scopedCallsToModuleKeys($file, $moduleKeys));
        }

        $this->assertGreaterThan(
            20,
            $scoped,
            'The scan found almost no calls carrying a module scope, so it is not '
            . 'reading the call sites it claims to judge.'
        );
    }

    /**
     * **And it reads the nullsafe ones**, which is a separate claim because
     * it was separately false.
     *
     * PHP tokenises `?->` as `T_NULLSAFE_OBJECT_OPERATOR`, a constant of
     * its own. The first version of this file matched `T_OBJECT_OPERATOR`
     * alone, so `$this->settingService?->get(…)` never reached the key
     * resolution, let alone the scope check — and because every nullsafe
     * call in this repository happens to be correctly scoped, the guard
     * stayed green while no longer looking at them.
     *
     * Asserted against the real files rather than a fixture: what has to
     * hold is that THESE call sites are seen.
     */
    public function testTheScanReadsCallsWrittenWithTheNullsafeOperator(): void
    {
        $moduleKeys = self::declaredModuleKeys();
        $nullsafe = [];

        foreach (self::phpFiles() as $file) {
            foreach (self::callsToModuleKeys($file, $moduleKeys) as $call) {
                if ($call['nullsafe'] === true) {
                    $nullsafe[] = $call['file'] . ':' . $call['line'];
                }
            }
        }

        $this->assertNotSame(
            [],
            $nullsafe,
            'Not one module setting read through `?->` was seen, although this repository '
            . 'has several — the scan is matching `->` only.'
        );
    }

    /**
     * **The hole this check nearly shipped with**, pinned where it can be
     * seen: `setMany()` is inspected entry by entry.
     *
     * The first version of this file read argument 0 as a scalar key. For
     * `setMany(['a' => 1, 'b' => 2], …)` that resolves nothing, so the call
     * was skipped before its scope was ever looked at — and the docblock
     * went on claiming all seven methods were covered. A guard that quietly
     * inspects six of the seven it advertises is worse than one that
     * advertises six.
     *
     * Asserted on the parser directly rather than through a fixture file:
     * what broke was the taking-apart, and this is the smallest thing that
     * can say it works.
     */
    public function testSetManyIsTakenApartEntryByEntry(): void
    {
        $this->assertSame(
            ['MailIdentity::SETTING_FROM_ADDRESS', "'dkim_selector'"],
            self::keyExpressionsOf('setMany', "[MailIdentity::SETTING_FROM_ADDRESS=>\$a,'dkim_selector'=>\$b,]")
        );

        // `array(…)` spelling, and a value that itself contains a comma.
        $this->assertSame(
            ["'first'", "'second'"],
            self::keyExpressionsOf('setMany', "array('first'=>implode(',', \$x),'second'=>\$y)")
        );

        // Not a literal array: nothing can be known, and saying « no keys »
        // is honest where saying « argument zero » was wrong.
        $this->assertSame([], self::keyExpressionsOf('setMany', '$values'));

        // Every other method still names exactly one key.
        $this->assertSame(["'a_key'"], self::keyExpressionsOf('get', "'a_key'"));
    }

    /**
     * **A wrong scope is as silent as no scope**, so the rule is equality
     * with the owner rather than mere presence.
     *
     * `get('groups_draft_ttl_minutes', 'rental')` passes any « was a scope
     * given? » test and looks up `rental::groups_draft_ttl_minutes`, where
     * nothing was ever written — the same default, the same silence, the
     * same invisible defect as #433.
     *
     * `(null)` is here because an expression can be parenthesised and still
     * be null; a check that compares text has to say so.
     */
    public function testAScopeThatIsNotTheOwnersIsAnOffenceToo(): void
    {
        $this->assertSame('null', self::normalise('(null)'));
        $this->assertSame('null', self::normalise('((null))'));
        $this->assertSame("'rental'", self::normalise("('rental')"));

        // And a genuine argument list is not mistaken for a wrapped one.
        $this->assertSame("('a', 'b')", self::normalise("('a', 'b')"));
    }

    /**
     * Both quote forms resolve — for a key written inline and for the
     * constant that names it.
     *
     * None of this repository's setting keys is written with double quotes
     * today, which is exactly why the gap was worth closing: a guard whose
     * coverage depends on a spelling nobody has used yet is a guard that
     * works by luck.
     *
     * A double-quoted string that interpolates is refused rather than taken
     * at face value: its value is not knowable here, and guessing would be
     * worse than declining.
     */
    public function testAKeyResolvesInEitherQuoteForm(): void
    {
        $this->assertSame('a_key', self::stringLiteral("'a_key'"));
        $this->assertSame('a_key', self::stringLiteral('"a_key"'));
        $this->assertSame('', self::stringLiteral("''"));

        $this->assertNull(self::stringLiteral('"prefix_$suffix"'));
        $this->assertNull(self::stringLiteral('"{$computed}"'));
        $this->assertNull(self::stringLiteral('$key'));
        $this->assertNull(self::stringLiteral('self::SOME_CONST'));
    }

    // ---------------------------------------------------------------
    // The scan
    // ---------------------------------------------------------------

    /**
     * Every setting key declared by a module manifest, mapped to its owner.
     *
     * @return array<string, string>
     */
    private static function declaredModuleKeys(): array
    {
        $keys = [];

        foreach (glob(self::root() . '/modules/*/module.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data['settings'] ?? [] as $setting) {
                $keys[(string) $setting['key']] = (string) $data['id'];
            }
        }

        return $keys;
    }

    /**
     * Every call whose scope is not the one the setting belongs to.
     *
     * **Not merely « a scope was passed ».** An earlier version of this
     * method accepted anything that was not empty or the literal `null`,
     * which let `get('groups_draft_ttl_minutes', 'rental')` through — a
     * scope that exists, is wrong, and looks up `rental::…` where nothing
     * was ever written. That fails in exactly the same silent way as
     * passing none, so the rule is equality with the owner, not presence.
     *
     * A scope this scan cannot resolve is an offence too, and deliberately
     * so: the whole point of this file is that a wrong scope has no
     * symptom, and a value only known at runtime cannot be checked. There
     * is no such call site today; the day one is wanted, this failure is
     * where the decision gets made rather than avoided.
     *
     * @param array<string, string> $moduleKeys
     * @return list<string>
     */
    private static function unscopedCalls(string $file, array $moduleKeys): array
    {
        $constants = self::constantsByClass();
        $ownClass = self::classDeclaredIn((string) file_get_contents($file));
        $offences = [];

        foreach (self::callsToModuleKeys($file, $moduleKeys) as $call) {
            $scope = self::normalise($call['scope']);
            $complaint = null;

            if ($scope === '' || $scope === 'null') {
                $complaint = 'no scope at all, so it reads `_core_`';
            } else {
                $resolved = self::resolveKey($scope, $ownClass, $constants);
                if ($resolved === null) {
                    $complaint = 'a scope this check cannot resolve (' . $scope . ')';
                } elseif ($resolved !== $call['owner']) {
                    $complaint = 'the scope « ' . $resolved .' », which is not that module';
                }
            }

            if ($complaint !== null) {
                $offences[] = sprintf(
                    '%s:%d — %s(%s) is a setting of module « %s » and is read with %s',
                    $call['file'],
                    $call['line'],
                    $call['method'],
                    $call['key_expression'],
                    $call['owner'],
                    $complaint
                );
            }
        }

        return $offences;
    }

    /**
     * An expression with its redundant outer parentheses removed, so
     * `(null)` is recognised as the `null` it is.
     */
    private static function normalise(string $expression): string
    {
        $expression = trim($expression);

        while (
            str_starts_with($expression, '(')
            && str_ends_with($expression, ')')
            && count(self::splitAtDepthZero(substr($expression, 1, -1), ',')) === 1
        ) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    /**
     * @param array<string, string> $moduleKeys
     * @return list<array<string, mixed>>
     */
    private static function scopedCallsToModuleKeys(string $file, array $moduleKeys): array
    {
        return array_values(array_filter(
            self::callsToModuleKeys($file, $moduleKeys),
            static function (array $call): bool {
                $scope = self::normalise($call['scope']);

                return $scope !== '' && $scope !== 'null';
            }
        ));
    }

    /**
     * Every call to a scoped `SettingService` method whose key resolves to a
     * module's own setting.
     *
     * @param array<string, string> $moduleKeys
     * @return list<array<string, mixed>>
     */
    private static function callsToModuleKeys(string $file, array $moduleKeys): array
    {
        $source = (string) file_get_contents($file);
        $constants = self::constantsByClass();
        $ownClass = self::classDeclaredIn($source);

        $tokens = token_get_all($source);
        $count = count($tokens);
        $calls = [];

        for ($i = 0; $i < $count; $i++) {
            // BOTH operators. PHP tokenises `?->` as its own constant, so
            // matching only `T_OBJECT_OPERATOR` skips every settings call
            // written nullsafe — and this repository has a dozen, several
            // of them on module settings (`RentalManagerService`,
            // `GroupController`, `SupportDashboardService`). They are all
            // correctly scoped today, which is precisely what makes the
            // omission invisible: the guard stays green while no longer
            // looking.
            $operator = is_array($tokens[$i]) ? $tokens[$i][0] : null;
            if ($operator !== T_OBJECT_OPERATOR && $operator !== T_NULLSAFE_OBJECT_OPERATOR) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;
            if (!is_array($name) || $name[0] !== T_STRING || !isset(self::SCOPED_METHODS[$name[1]])) {
                continue;
            }
            if (($tokens[$i + 2] ?? null) !== '(') {
                continue;
            }

            $arguments = self::argumentsAt($tokens, $i + 2);
            $scope = trim($arguments[self::SCOPED_METHODS[$name[1]]] ?? '');

            foreach (self::keyExpressionsOf($name[1], trim($arguments[0] ?? '')) as $expression) {
                $key = self::resolveKey($expression, $ownClass, $constants);

                if ($key === null || !isset($moduleKeys[$key])) {
                    continue;
                }

                $calls[] = [
                    'file' => substr($file, strlen(self::root()) + 1),
                    'line' => $name[2],
                    'method' => $name[1],
                    'key_expression' => $expression,
                    'owner' => $moduleKeys[$key],
                    'scope' => $scope,
                    'nullsafe' => $operator === T_NULLSAFE_OBJECT_OPERATOR,
                ];
            }
        }

        return $calls;
    }

    /**
     * The setting keys one call names — one for six of the seven methods,
     * and **one per entry** for `setMany()`.
     *
     * Returns expressions, still unresolved: `resolveKey()` turns each into
     * a value. An argument that is not a literal array — a variable, a
     * spread — yields nothing, because nothing about it can be known
     * without running the code, and answering « no keys » is honest where
     * answering « argument zero » was wrong.
     *
     * @return list<string>
     */
    private static function keyExpressionsOf(string $method, string $firstArgument): array
    {
        if ($method !== 'setMany') {
            return [$firstArgument];
        }

        $inner = trim($firstArgument);
        if (str_starts_with($inner, '[') && str_ends_with($inner, ']')) {
            $inner = substr($inner, 1, -1);
        } elseif (preg_match('/^array\s*\((.*)\)$/s', $inner, $literal) === 1) {
            $inner = $literal[1];
        } else {
            return [];
        }

        $expressions = [];
        foreach (self::splitAtDepthZero($inner, ',') as $entry) {
            $pair = self::splitAtDepthZero($entry, '=>');
            if (count($pair) >= 2 && trim($pair[0]) !== '') {
                $expressions[] = trim($pair[0]);
            }
        }

        return $expressions;
    }

    /**
     * Split on a separator that is not inside brackets, parentheses or a
     * quoted string.
     *
     * @return list<string>
     */
    private static function splitAtDepthZero(string $text, string $separator): array
    {
        $parts = [''];
        $depth = 0;
        $quote = null;
        $length = strlen($text);
        $step = strlen($separator);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                $parts[count($parts) - 1] .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $parts[count($parts) - 1] .= $text[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $parts[count($parts) - 1] .= $char;
                continue;
            }

            if ($char === '[' || $char === '(') {
                $depth++;
            } elseif ($char === ']' || $char === ')') {
                $depth--;
            }

            if ($depth === 0 && substr($text, $i, $step) === $separator) {
                $parts[] = '';
                $i += $step - 1;
                continue;
            }

            $parts[count($parts) - 1] .= $char;
        }

        return $parts;
    }

    /**
     * A key expression as its string value, or null when it cannot be known
     * without running the code.
     *
     * @param array<string, array<string, string>> $constants
     */
    private static function resolveKey(string $expression, string $ownClass, array $constants): ?string
    {
        $literal = self::stringLiteral($expression);
        if ($literal !== null) {
            return $literal;
        }

        // **Per class, never by name alone.** `SETTING_KEY` is declared in a
        // dozen unrelated classes here; a global name→value map resolves
        // `InstallationDateService::SETTING_KEY` to whatever some module
        // happened to call its own, and invents a dozen offences that do not
        // exist. That mistake was made while writing this file.
        if (preg_match('/^(?:self|static)::([A-Z][A-Z0-9_]*)$/', $expression, $own) === 1) {
            return $constants[$ownClass][$own[1]] ?? null;
        }

        if (preg_match('/([A-Za-z0-9_]+)::([A-Z][A-Z0-9_]*)$/', $expression, $other) === 1) {
            return $constants[$other[1]][$other[2]] ?? null;
        }

        return null;
    }

    /**
     * The value of a string literal, in either quote form, or null when the
     * expression is not one.
     *
     * A double-quoted string carrying `$` or `{` is refused rather than
     * taken at face value: it interpolates, so its value is not knowable
     * here, and guessing would be worse than declining.
     */
    private static function stringLiteral(string $expression): ?string
    {
        $expression = trim($expression);

        if (preg_match("/^'([^']*)'$/", $expression, $single) === 1) {
            return $single[1];
        }

        if (preg_match('/^"([^"]*)"$/', $expression, $double) === 1) {
            return str_contains($double[1], '$') || str_contains($double[1], '{')
                ? null
                : $double[1];
        }

        return null;
    }

    /**
     * The arguments of the call whose opening parenthesis is at `$start`,
     * split at depth zero so a nested call or array keeps its commas.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private static function argumentsAt(array $tokens, int $start): array
    {
        $depth = 0;
        $arguments = [''];

        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if ($text === '(' || $text === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === ')' || $text === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $arguments[] = '';
                continue;
            }

            $arguments[count($arguments) - 1] .= $text;
        }

        return $arguments;
    }

    /**
     * Class constants with a literal string value, keyed by class then name.
     *
     * @return array<string, array<string, string>>
     */
    private static function constantsByClass(): array
    {
        static $constants = null;
        if ($constants !== null) {
            return $constants;
        }

        $constants = [];
        foreach (self::phpFiles() as $file) {
            $source = (string) file_get_contents($file);
            $class = self::classDeclaredIn($source);
            if ($class === '') {
                continue;
            }
            // Both quote forms, for the same reason `resolveKey()` takes
            // both: a constant declared with double quotes is no less a
            // setting key, and skipping it would let the call sites that
            // name it out of the check entirely.
            if (preg_match_all('/const\s+([A-Z][A-Z0-9_]*)\s*=\s*((?:\'[^\']*\')|(?:"[^"$\\\\{]*"))/', $source, $m, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($m as $declaration) {
                $value = self::stringLiteral($declaration[2]);
                if ($value !== null) {
                    $constants[$class][$declaration[1]] = $value;
                }
            }
        }

        return $constants;
    }

    /**
     * The name of the class a file declares, or an empty string when it
     * declares none — which is how `self::` is resolved against the right
     * class rather than against a name that happens to match.
     */
    private static function classDeclaredIn(string $source): string
    {
        return preg_match(
            '/(?:^|\n)(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z0-9_]+)/',
            $source,
            $m
        ) === 1 ? $m[1] : '';
    }

    /**
     * Every PHP file this check reads, sorted so a failure names them in a
     * stable order. Cached: the scan walks them several times.
     *
     * @return list<string>
     */
    private static function phpFiles(): array
    {
        static $files = null;
        if ($files !== null) {
            return $files;
        }

        $files = [];
        foreach (['core', 'modules', 'public', 'bootstrap', 'scripts'] as $directory) {
            $path = self::root() . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = (string) $file->getRealPath();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** The repository root, which every path in this file is relative to. */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
