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
 * static scan. The check covers a literal and a class constant, and
 * nothing else.
 *
 * That blind spot is **occupied**, and the first version of this docblock
 * claimed it was empty — a false claim about coverage, in the one file
 * whose whole subject is that a claim about coverage gets believed.
 * Counted rather than guessed: **37** `SettingService` calls in this
 * repository pass a key that is only known at run time, 15 of them in
 * module code — `ReenrollmentCampaignService` choosing between two
 * reminder constants, `GroupLifecycleService::months()` taking its key as
 * a parameter, `RegistrationConfigController` writing a threshold picked
 * from a loop, and their like in `OpenRegistrationHandler`,
 * `RetroChiefController`, `SupportDashboardService`,
 * `GalleryConfigController`, `CalendarConfigController` and
 * `ReenrollmentConfigController`.
 *
 * Every one of those passes its module's scope correctly today — which
 * was verified, not assumed. So there was no bug hiding there; there was
 * a part of the codebase this test said nothing about, while reading as
 * though it did.
 *
 * **Closed from the other side** (issue #443).
 * `testAModuleCallWithAnUnreadableKeyStillNamesItsScope()` asks the one
 * question that does not need the key: a call in a module, on a
 * `SettingService`, that names **no scope at all**. Whatever that key
 * turns out to be, the call will look in `_core_`, and a module's own
 * setting is not there. It covers two families the main scan drops: the 17
 * calls whose key never resolves, and the 18 whose key resolves to
 * something no manifest declares. Thirty-four of those thirty-five are
 * scoped; the thirty-fifth is a setting nothing declares at all, found by
 * this check on its first run (issue #497).
 *
 * What is still not covered, and is now the whole of it: a call that
 * DOES name a scope under a key nobody can read is taken at its word.
 * Judging that one means knowing which module the key belongs to, which
 * means resolving the key — the thing that could not be done in the first
 * place. #443's options 2 and 3 (following a ternary of constants back to
 * its branches, or a key parameter back to its callers) are a flow
 * analysis, and a test is not the place for one.
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

    /**
     * The same quote forms, through the path that actually uses them.
     *
     * The test above proves `stringLiteral()`; it proves nothing about
     * `constantsByClass()` or `resolveKey()`, which is where the gap was.
     * A helper that works, reached by a caller that does not, is exactly
     * the shape of the four holes this guard has already had — so the two
     * callers are asserted here rather than assumed from the helper.
     */
    public function testAConstantDeclaredInEitherQuoteFormResolvesThroughTheCallPath(): void
    {
        $declared = self::constantsDeclaredIn(
            <<<'FIXTURE'
                <?php
                class Whatever
                {
                    public const DOUBLE_QUOTED = "official_documents_unit_code";
                    public const SINGLE_QUOTED = 'official_documents_retention_months';
                    public const INTERPOLATING = "prefix_$suffix";
                }
                FIXTURE
        );

        $this->assertSame(
            [
                'DOUBLE_QUOTED' => 'official_documents_unit_code',
                'SINGLE_QUOTED' => 'official_documents_retention_months',
            ],
            $declared,
            'a constant declared with double quotes names a setting key just as one with single quotes does'
        );

        // And `resolveKey()` reaches it — both through `self::` and through
        // the declaring class's own name, which are two different branches.
        $constants = ['Whatever' => $declared];

        $this->assertSame(
            'official_documents_unit_code',
            self::resolveKey('self::DOUBLE_QUOTED', 'Whatever', $constants)
        );
        $this->assertSame(
            'official_documents_unit_code',
            self::resolveKey('Whatever::DOUBLE_QUOTED', 'SomeOtherClass', $constants)
        );
    }

    /**
     * **Two classes with the same basename are two classes.**
     *
     * Thirteen basenames are declared more than once under the scanned
     * roots. A constants map keyed by bare name lets the file that sorts
     * last overwrite the others, and the loss is silent in the worst way:
     * `Modules\\Groups\\Service\\ModerationService::SETTING_ENABLED` holds
     * the manifest key `groups_ai_moderation_enabled`, its namesake in
     * `retro` sorts after it, and the day that one declares any string
     * constant the groups entry is replaced. `resolveKey()` then answers
     * null for the groups call site, which drops out of the check with
     * nothing going red — or, on a same-named constant with a different
     * value, gets attributed to another module's key outright.
     */
    public function testTwoClassesSharingABasenameDoNotOverwriteEachOther(): void
    {
        $groups = 'Modules\\Groups\\Service\\ModerationService';
        $retro = 'Modules\\Retro\\Service\\ModerationService';
        $constants = [
            $groups => ['SETTING_ENABLED' => 'groups_ai_moderation_enabled'],
            $retro => ['SETTING_ENABLED' => 'retro_ai_moderation_enabled'],
        ];

        // A caller importing one of them means that one, and the `use` line
        // is what says which — exactly as PHP reads it.
        $this->assertSame(
            'groups_ai_moderation_enabled',
            self::resolveKey(
                'ModerationService::SETTING_ENABLED',
                'Modules\\Groups\\Controller\\GroupController',
                $constants,
                ['ModerationService' => $groups]
            )
        );
        $this->assertSame(
            'retro_ai_moderation_enabled',
            self::resolveKey(
                'ModerationService::SETTING_ENABLED',
                'Modules\\Retro\\Controller\\RetroController',
                $constants,
                ['ModerationService' => $retro]
            )
        );

        // A sibling in the caller's own namespace needs no import, which is
        // also how PHP reads it.
        $this->assertSame(
            'retro_ai_moderation_enabled',
            self::resolveKey('ModerationService::SETTING_ENABLED', $retro, $constants)
        );

        // And written out, there is nothing to resolve.
        $this->assertSame(
            'groups_ai_moderation_enabled',
            self::resolveKey('\\' . $groups . '::SETTING_ENABLED', 'Whatever\\Else', $constants)
        );

        // Neither imported nor a sibling, and two classes answer to the
        // name: **null rather than a guess**. Guessing here attributes a
        // call site to another module's key, which reads as an offence
        // that does not exist or as a green nobody earned.
        $this->assertNull(
            self::resolveKey('ModerationService::SETTING_ENABLED', 'Somewhere\\Unrelated', $constants)
        );
    }

    /**
     * The namespace is part of the name, and `classDeclaredIn()` is where
     * that had been dropped.
     */
    public function testAClassIsIdentifiedByItsFullyQualifiedName(): void
    {
        $this->assertSame(
            'Modules\\Groups\\Service\\ModerationService',
            self::classDeclaredIn(
                "<?php\nnamespace Modules\\Groups\\Service;\n\nfinal class ModerationService\n{\n}\n"
            )
        );

        // A `use` line is not a declaration, and must not be mistaken for
        // the namespace of the class that follows it.
        $this->assertSame(
            ['SettingService' => 'Core\\Config\\SettingService', 'Alias' => 'Core\\Member\\MemberService'],
            self::useMapOf(
                "<?php\nnamespace X;\n\nuse Core\\Config\\SettingService;\n"
                . "use Core\\Member\\MemberService as Alias;\n"
            )
        );
    }

    /**
     * End to end on a real file: a double-quoted key, read with no scope,
     * is reported.
     *
     * This is the assertion the three above cannot make between them. The
     * scan has to tokenise the call, take argument zero apart, resolve the
     * literal, match it against a manifest and then judge the scope — and
     * a break anywhere along that chain looks, from outside, exactly like
     * a repository with nothing wrong in it.
     */
    public function testADoubleQuotedKeyReadWithoutItsScopeIsReportedByTheScan(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'scope') . '.php';
        file_put_contents(
            $fixture,
            <<<'FIXTURE'
                <?php
                class Offender
                {
                    public function label(): string
                    {
                        return (string) $this->settings->get("official_documents_unit_code");
                    }
                }
                FIXTURE
        );

        try {
            $offences = self::unscopedCalls($fixture, ['official_documents_unit_code' => 'official_documents']);
        } finally {
            unlink($fixture);
        }

        $this->assertCount(1, $offences, 'the double-quoted unscoped read must be seen');
        $this->assertStringContainsString('"official_documents_unit_code"', $offences[0]);
        $this->assertStringContainsString('official_documents', $offences[0]);
        $this->assertStringContainsString('no scope at all', $offences[0]);
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
        $source = (string) file_get_contents($file);
        $constants = self::constantsByClass();
        $ownClass = self::classDeclaredIn($source);
        $useMap = self::useMapOf($source);
        $offences = [];

        foreach (self::callsToModuleKeys($file, $moduleKeys) as $call) {
            $scope = self::normalise($call['scope']);
            $complaint = null;

            if ($scope === '' || $scope === 'null') {
                $complaint = 'no scope at all, so it reads `_core_`';
            } else {
                $resolved = self::resolveKey($scope, $ownClass, $constants, $useMap);
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
        $useMap = self::useMapOf($source);

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
                $key = self::resolveKey($expression, $ownClass, $constants, $useMap);

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
     * **Per class, never by name alone** — and « class » means the fully
     * qualified one. `SETTING_KEY` is declared in a dozen unrelated classes
     * here, so a global name→value map resolves
     * `InstallationDateService::SETTING_KEY` to whatever some module
     * happened to call its own and invents a dozen offences that do not
     * exist; that mistake was made while writing this file. Keying by the
     * BARE class name is the same mistake one level up, and it was made
     * here too — see `classDeclaredIn()` for the namesake pair that was
     * one commit away from silently emptying this check.
     *
     * @param array<string, array<string, string>> $constants keyed by FQCN
     * @param array<string, string> $useMap alias → FQCN, from the file's own `use` lines
     */
    private static function resolveKey(
        string $expression,
        string $ownClass,
        array $constants,
        array $useMap = []
    ): ?string {
        $literal = self::stringLiteral($expression);
        if ($literal !== null) {
            return $literal;
        }

        if (preg_match('/^(?:self|static)::([A-Z][A-Z0-9_]*)$/', $expression, $own) === 1) {
            return $constants[$ownClass][$own[1]] ?? null;
        }

        if (preg_match('/^\\\\?([A-Za-z0-9_\\\\]+)::([A-Z][A-Z0-9_]*)$/', $expression, $other) !== 1) {
            return null;
        }

        $class = self::qualify(ltrim($other[1], '\\'), $ownClass, $constants, $useMap);

        return $class === null ? null : ($constants[$class][$other[2]] ?? null);
    }

    /**
     * A class reference as written at a call site, turned into the one FQCN
     * it can only mean — or null when it could mean more than one.
     *
     * Already qualified, imported by a `use` line, or a sibling in the same
     * namespace: three exact answers, in the order PHP itself would take
     * them. Only when none applies does it fall back to matching the bare
     * name against every known class, and **a bare name matching two
     * classes resolves to neither**: guessing there is how a call site gets
     * attributed to another module's key, which reads as an offence that
     * does not exist or as a green that was never earned.
     *
     * @param array<string, array<string, string>> $constants keyed by FQCN
     * @param array<string, string> $useMap
     */
    private static function qualify(string $written, string $ownClass, array $constants, array $useMap): ?string
    {
        if (str_contains($written, '\\')) {
            return $written;
        }

        if (isset($useMap[$written])) {
            return $useMap[$written];
        }

        $ownNamespace = strrpos($ownClass, '\\');
        if ($ownNamespace !== false) {
            $sibling = substr($ownClass, 0, $ownNamespace + 1) . $written;
            if (isset($constants[$sibling])) {
                return $sibling;
            }
        }

        $candidates = array_values(array_filter(
            array_keys($constants),
            static fn(string $fqcn): bool => $fqcn === $written || str_ends_with($fqcn, '\\' . $written)
        ));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The `use` lines of one source, as alias → FQCN.
     *
     * Only plain class imports: a grouped or function import names nothing
     * this check resolves, and answering « I do not know » for those is the
     * posture the rest of this file takes.
     *
     * @return array<string, string>
     */
    private static function useMapOf(string $source): array
    {
        if (preg_match_all(
            '/(?:^|\n)use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/',
            $source,
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }

        $map = [];
        foreach ($matches as $import) {
            $fqcn = ltrim($import[1], '\\');
            $alias = ($import[2] ?? '') !== '' ? $import[2] : substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
            $map[$alias] = $fqcn;
        }

        return $map;
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
            $declared = self::constantsDeclaredIn($source);
            if ($declared !== []) {
                $constants[$class] = $declared;
            }
        }

        return $constants;
    }

    /**
     * The string constants one source declares, in either quote form.
     *
     * Split out of `constantsByClass()` so it can be handed a fixture:
     * that method reads the whole repository and caches, so nothing could
     * ask it what it makes of a declaration this repository does not yet
     * contain — which is the only kind that matters here, since no setting
     * key is written with double quotes today.
     *
     * Both quote forms, for the same reason `resolveKey()` takes both: a
     * constant declared with double quotes is no less a setting key, and
     * skipping it would let the call sites that name it out of the check
     * entirely. An interpolating one is refused by `stringLiteral()`.
     *
     * @return array<string, string>
     */
    private static function constantsDeclaredIn(string $source): array
    {
        if (preg_match_all('/const\s+([A-Z][A-Z0-9_]*)\s*=\s*((?:\'[^\']*\')|(?:"[^"$\\\\{]*"))/', $source, $m, PREG_SET_ORDER) === 0) {
            return [];
        }

        $constants = [];
        foreach ($m as $declaration) {
            $value = self::stringLiteral($declaration[2]);
            if ($value !== null) {
                $constants[$declaration[1]] = $value;
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
        if (preg_match(
            '/(?:^|\n)(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z0-9_]+)/',
            $source,
            $m
        ) !== 1) {
            return '';
        }

        // **Fully qualified, because a bare name is not a class.** Thirteen
        // basenames are declared twice or more under the scanned roots —
        // `ModerationService`, `ConfigController`, `RateLimitService`,
        // `ImportController` among them — so a map keyed by basename lets
        // the file that sorts last overwrite the others' constants.
        // `Modules\Groups\Service\ModerationService::SETTING_ENABLED` holds
        // a real manifest key (`groups_ai_moderation_enabled`), and its
        // namesake in `retro` sorts after it: the day that one declares a
        // string constant, the groups entry is replaced, `resolveKey()`
        // answers null for the groups call site, and it leaves the check
        // without a single test going red.
        //
        // The docblock on `resolveKey()` said « per class, never by name
        // alone » while the class side was resolved by name alone. One
        // level too shallow, which is the whole lesson of this file.
        return preg_match('/(?:^|\n)namespace\s+([A-Za-z0-9_\\\\]+)\s*;/', $source, $ns) === 1
            ? $ns[1] . '\\' . $m[1]
            : $m[1];
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

    /**
     * The blind spot, closed from the other side.
     *
     * The check above judges a call only when it can resolve the key **and**
     * a `module.json` declares it. Two families fall outside that, and
     * both were dropped before their scope was ever looked at:
     *
     *  - **17** calls whose key is built at run time and does not resolve
     *    at all (issue #443's count);
     *  - **18** whose key resolves to a literal or a class constant that no
     *    manifest declares — `Modules\Finance\Service\
     *    BulkCategorizationService` holds four of them as constants. This
     *    second family was missed by the first version of this very method,
     *    which assumed « resolves » meant « already judged »: the same
     *    over-claim, one level down, found in review.
     *
     * This asks the one question that does not need the key: **a call in a
     * module, on a `SettingService`, that names no scope at all.** Whatever
     * that key turns out to be, `_core_` is where the call will look, and a
     * module's own setting is not there. It is issue #433's defect in the
     * shape the main scan cannot see.
     *
     * Thirty-four of those thirty-five pass today. The thirty-fifth is real
     * and is named below — a setting no composition root declares, so the
     * rental contract prints an empty landlord address (issue #497). This
     * check found it on its first run, which is more than was expected of
     * it: it was written for the call that has not been made yet.
     *
     * **The trap, named rather than left to be met.** A module file may
     * legitimately read a *core* setting with a computed key, and this test
     * would call it an offence. None exists today. When one is written, it
     * is a decision to take explicitly — add it to `CORE_KEYS_IN_MODULES`
     * with the reason — rather than a rule to soften, because « a module
     * reaching into core's scope with a key nobody can read » deserves to
     * be looked at once by a person.
     *
     * What it still does not see, said as plainly as the docblock above:
     * a call whose receiver is not named for what it is. That is what
     * `testEverySettingServiceReceiverIsRecognisable()` holds.
     *
     * @var list<string> file:line, with the reason on the line
     */
    private const CORE_KEYS_IN_MODULES = [
        // `unit_address` is declared by no manifest, no composition root
        // and no schema — the only place in this repository that registers
        // it is a fixture in RentalDocumentServiceTest. So the contract's
        // `adresse_bailleur` is the empty string on every real
        // installation, while its own description promises « l'adresse de
        // l'unité, telle que configurée ». Found by this very check on its
        // first real run; whether the setting belongs to core or to the
        // rental module is a decision, and it is issue #497's.
        'modules/rental/src/Service/RentalDocumentService.php:603',
    ];

    /**
     * How a `SettingService` is held, everywhere it is held in this
     * repository: `$this->settingService`, `$this->settings`,
     * `$settingService`, `$settings`, `$context->settings`,
     * `$this->coreSettingService`.
     */
    private const RECEIVER_PATTERN = '/(^|>)(core|setup)?[sS]etting(s|Service)$/';

    public function testAModuleCallWithAnUnreadableKeyStillNamesItsScope(): void
    {
        $offenders = [];
        $seen = 0;

        foreach (self::phpFiles() as $file) {
            $relative = substr($file, strlen(self::root()) + 1);
            if (!str_starts_with($relative, 'modules/')) {
                continue;
            }

            foreach (self::settingCallsIn($file) as $call) {
                if ($call['judged_above']) {
                    continue;
                }

                $seen++;
                $scope = self::normalise($call['scope']);
                if ($scope !== '' && $scope !== 'null') {
                    continue;
                }

                $where = $relative . ':' . $call['line'];
                if (in_array($where, self::CORE_KEYS_IN_MODULES, true)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s — %s(%s) names no scope, so whatever that key is it will be read from `_core_`',
                    $where,
                    $call['method'],
                    $call['key_expression']
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A module reading a setting under a key this check cannot resolve must still say
"
            . "which module's scope it means. Without one the call reads `_core_`, finds
"
            . "nothing and answers the default — the defect of issue #433, in the shape the
"
            . "main scan above cannot see (issue #443).

"
            . "If the key really is a CORE setting, that is a decision to take out loud:
"
            . "add the line to CORE_KEYS_IN_MODULES with its reason.
  "
            . implode("
  ", $offenders)
        );

        $this->assertGreaterThanOrEqual(
            30,
            $seen,
            'The scan found almost no runtime-keyed settings call in modules/, which means it '
            . 'has stopped reading them rather than that they are gone — there were '
            . 'thirty-five when this check last counted them.'
        );
    }

    /**
     * A `SettingService` held under a name this file does not recognise is
     * a call neither check sees, so the naming is the invariant.
     *
     * Read from the constructor promotions and properties typed
     * `SettingService`, rather than from a list somebody has to keep: the
     * type is what makes it one, and the name is what makes it findable.
     */
    public function testEverySettingServiceReceiverIsRecognisable(): void
    {
        $offenders = [];

        foreach (self::phpFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'SettingService')) {
                continue;
            }

            preg_match_all(
                '/\bSettingService\s+\$([A-Za-z_][A-Za-z0-9_]*)/',
                $source,
                $found,
                PREG_SET_ORDER
            );
            foreach ($found as $declaration) {
                if (preg_match(self::RECEIVER_PATTERN, $declaration[1]) === 1) {
                    continue;
                }

                $offenders[] = substr($file, strlen(self::root()) + 1) . ' — $' . $declaration[1];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A SettingService has to be held under a name saying so — `\$settingService`,
"
            . "`\$settings`, or one of the forms RECEIVER_PATTERN knows. The checks in this
"
            . "file find their call sites by that name, so one held under another goes
"
            . "unread by both of them, silently.
  "
            . implode("
  ", $offenders)
        );
    }

    /**
     * Every call on a `SettingService` in one file, whether or not its key
     * can be resolved — which is the difference from `callsToModuleKeys()`,
     * where an unresolvable key ends the examination.
     *
     * The receiver is recognised by name, and that is a choice with a
     * guard: `testEverySettingServiceReceiverIsRecognisable()` is what
     * keeps the names true. Reading the type through to the call site would
     * mean resolving `$this->x` back to a promoted constructor property
     * across a whole file, which is a type checker, not a test.
     *
     * @return list<array<string, mixed>>
     */
    private static function settingCallsIn(string $file): array
    {
        $source = (string) file_get_contents($file);
        $constants = self::constantsByClass();
        $ownClass = self::classDeclaredIn($source);
        $useMap = self::useMapOf($source);

        $tokens = token_get_all($source);
        $count = count($tokens);
        $calls = [];

        for ($i = 0; $i < $count; $i++) {
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
            if (!self::receiverIsASettingService($tokens, $i)) {
                continue;
            }

            $arguments = self::argumentsAt($tokens, $i + 2);
            $scope = trim($arguments[self::SCOPED_METHODS[$name[1]]] ?? '');

            foreach (self::keyExpressionsOf($name[1], trim($arguments[0] ?? '')) as $expression) {
                $calls[] = [
                    'line' => $name[2],
                    'method' => $name[1],
                    'key_expression' => $expression,
                    'scope' => $scope,
                    'judged_above' => self::isJudgedByTheMainScan(
                        self::resolveKey($expression, $ownClass, $constants, $useMap)
                    ),
                ];
            }
        }

        return $calls;
    }

    /**
     * Whether `testNoModuleSettingIsReadOrWrittenOutsideItsOwnScope()`
     * above has already had its say about this key.
     *
     * Resolving is **not** enough, and assuming it was left a second hole
     * beside the one this was written to close. That scan judges a call
     * only when the key it resolved is one a `module.json` declares
     * (`!isset($moduleKeys[$key])` drops the rest), so a key that resolves
     * to a literal or a class constant and is declared **nowhere** fell
     * between the two: judged by neither.
     *
     * They exist. `Modules\Finance\Service\BulkCategorizationService`
     * holds four — `ai_categorization_enabled` and its neighbours — as
     * class constants that `modules/finance/module.json` does not declare.
     * All four calls pass `'finance'` today; nothing would have said so if
     * one stopped.
     */
    private static function isJudgedByTheMainScan(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        // A key core itself registers is one a module reads WITHOUT a
        // scope, correctly and by design — `site_name`, `base_url`,
        // `unit_address` and their like are read that way from seven
        // modules. Judging those as offences would not close a gap, it
        // would make the check wrong about thirty-one call sites that are
        // right.
        return isset(self::declaredModuleKeys()[$key]) || isset(self::declaredCoreKeys()[$key]);
    }

    /**
     * Every key core registers on its own behalf — the settings a
     * scope-less read is correct for.
     *
     * Read from the composition roots rather than a list kept by hand, for
     * the same reason the module keys are read from the manifests: a list
     * somebody maintains is a list that stops being true, and this one
     * decides whether a call site is an offence.
     *
     * **Both roots**, and the second is not an afterthought:
     * `public/cron.php` registers `cron_last_run` and nothing else does,
     * so reading only `index.php` reported two correct call sites as
     * offences. A composition root left out of the scan is the same defect
     * as a manifest left out of it.
     *
     * @return array<string, true>
     */
    private static function declaredCoreKeys(): array
    {
        static $keys = null;
        if ($keys !== null) {
            return $keys;
        }

        $keys = [];
        foreach (['public/index.php', 'public/cron.php'] as $root) {
            foreach (self::registeredIn(self::root() . '/' . $root) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * The keys one composition root passes to `SettingService::register()`.
     *
     * @return list<string>
     */
    private static function registeredIn(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $keys = [];
        $source = (string) file_get_contents($path);
        $constants = self::constantsByClass();
        $useMap = self::useMapOf($source);

        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $operator = is_array($tokens[$i]) ? $tokens[$i][0] : null;
            if ($operator !== T_OBJECT_OPERATOR && $operator !== T_NULLSAFE_OBJECT_OPERATOR) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;
            if (!is_array($name) || $name[0] !== T_STRING || $name[1] !== 'register') {
                continue;
            }
            if (($tokens[$i + 2] ?? null) !== '(') {
                continue;
            }

            $arguments = self::argumentsAt($tokens, $i + 2);
            $key = self::resolveKey(trim($arguments[0] ?? ''), self::classDeclaredIn($source), $constants, $useMap);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Walks back from the `->` to the identifier the call is made on, and
     * asks whether it is named for a `SettingService`.
     *
     * `$this->settingService`, `$settings`, `$context->settings` — the walk
     * stops at the first `$variable` or `->property` to its left, which is
     * enough for every shape this repository writes.
     *
     * @param array<int, mixed> $tokens
     */
    private static function receiverIsASettingService(array $tokens, int $operatorIndex): bool
    {
        for ($i = $operatorIndex - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT)) {
                continue;
            }

            if (is_array($token) && ($token[0] === T_VARIABLE || $token[0] === T_STRING)) {
                $identifier = ltrim($token[1], '$');
                $previous = $tokens[$i - 1] ?? null;
                $afterAnArrow = is_array($previous)
                    && ($previous[0] === T_OBJECT_OPERATOR || $previous[0] === T_NULLSAFE_OBJECT_OPERATOR);

                return preg_match(self::RECEIVER_PATTERN, ($afterAnArrow ? '>' : '') . $identifier) === 1;
            }

            return false;
        }

        return false;
    }

    /** The repository root, which every path in this file is relative to. */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
