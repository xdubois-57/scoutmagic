<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * A test renders a template through production's Twig, or through none.
 *
 * Issue #465: 140 test files built their own `Twig\Environment` over the
 * real templates and registered whatever functions and filters the render
 * complained about, in the shortest form that stopped it raising. Two
 * kinds of damage came of it:
 *
 * 1. A filter or function added to production broke suites that had
 *    nothing to do with it — fixing #460 took 52 tests in five files down.
 * 2. **A double can lie, and nothing says so.** A `french_date` stub
 *    returning its argument rendered « 2026-07-12 » where a visitor reads
 *    « 12 juillet 2026 »; `Tests\Core\View\DisplayNameFilterTest` once
 *    asserted six behaviours of a copy of `display_name` pasted into its
 *    own `setUp()`, and returning `'MUTANT'` from the real one left it green.
 *
 * `Tests\TestTwig` is the way out — `TwigFactory::create()` itself, with a
 * short, named list of session- and container-bound functions a test may
 * replace — and this file holds the line in two rules:
 *
 * - no file under tests/ but the helper registers a `TwigFilter` or
 *   `TwigFunction` under a name production registers;
 * - no file under tests/ but the helper builds its own `Environment` over
 *   the production templates, except those listed in
 *   {@see self::STILL_HAND_BUILT} — a list that can only shrink.
 *
 * The production names are **measured, not copied**: a real
 * `TwigFactory::create()` environment is asked for its filters and
 * functions, so a function added there tomorrow is covered tomorrow. The
 * sources are read as PHP tokens, so a comment or a string describing the
 * old shape is prose and trips nothing — which is also why this file's
 * own fixtures, all of them nowdoc text, need no exemption.
 */
final class TestEnvironmentsUseTheRealFactoryTest extends TestCase
{
    /** The one file that may register production function names. */
    private const HELPER = 'tests/TestTwig.php';

    /**
     * Files that still build their own environment over production
     * templates, each with the reason it must. Shrink-only: a listed file
     * that no longer does fails {@see testEveryHandBuiltExceptionIsStillNeeded()},
     * so the entry has to go with the code.
     *
     * Empty since #465 converted every such file, and meant to stay so:
     * an entry here is a decision for review, with its reason beside it.
     *
     * @var array<string, string>
     */
    private const STILL_HAND_BUILT = [];

    /** What the reader reports for a registration whose name is not a literal. */
    private const COMPUTED_NAME = '(a computed name)';

    /** Floors, not targets: an empty reader satisfies every assertSame([]). */
    private const AT_LEAST_THIS_MANY_FILTERS = 40;
    private const AT_LEAST_THIS_MANY_FUNCTIONS = 10;

    public function testNoTestRegistersAFilterOrFunctionProductionRegisters(): void
    {
        [$filters, $functions] = $this->productionNames();
        $offenders = [];

        foreach ($this->testFiles() as $relative => $source) {
            if ($relative === self::HELPER) {
                continue;
            }
            foreach (self::registrationsIn($source) as [$kind, $name]) {
                $shipped = $kind === 'TwigFilter' ? $filters : $functions;
                if ($name === self::COMPUTED_NAME || isset($shipped[$name])) {
                    $offenders[] = $relative . ' → new ' . $kind . "('" . $name . "')";
                }
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A test may not register a Twig filter or function that production registers — '
                . 'its double is free to drift from the real one, and nothing watches it (issue #465):'],
            $offenders,
            [
                '',
                'Render through Tests\\TestTwig::create() (or ::withTemplates() for templates of the '
                    . 'test\'s own): every real filter and function is already there.',
                'Only ' . implode(', ', array_keys(TestTwig::REPLACEABLE_FUNCTIONS)) . ' may be '
                    . 'replaced, through its $functions argument. For anything else, supply what the '
                    . 'real function reads (a service in the globals) and assert on the real output.',
            ],
        )));
    }

    public function testNoTestBuildsItsOwnEnvironmentOverProductionTemplates(): void
    {
        $offenders = [];

        foreach ($this->testFiles() as $relative => $source) {
            if ($relative === self::HELPER || isset(self::STILL_HAND_BUILT[$relative])) {
                continue;
            }
            if (self::buildsItsOwnEnvironmentOverProductionTemplates($source)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These tests build their own Twig\\Environment over the production templates:'],
            $offenders,
            [
                '',
                'Use Tests\\TestTwig::create([\'<module>\', …]) instead: it is Core\\View\\TwigFactory::create() '
                    . 'itself, so the page rendered is the page a visitor gets (issue #465). A test of one '
                    . 'extension or of a Twig mechanism may keep a bare Environment over an ArrayLoader of '
                    . 'its own templates.',
            ],
        )));
    }

    public function testEveryHandBuiltExceptionIsStillNeeded(): void
    {
        $sources = $this->testFiles();
        $dead = [];

        foreach (array_keys(self::STILL_HAND_BUILT) as $relative) {
            if (!isset($sources[$relative]) || !self::buildsItsOwnEnvironmentOverProductionTemplates($sources[$relative])) {
                $dead[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $dead,
            'No longer building their own environment over production templates — remove them from '
                . 'STILL_HAND_BUILT so the list only ever shrinks.'
        );
    }

    /**
     * The other direction: a reader that finds nothing passes both sweeps
     * on a repository full of doubles.
     */
    public function testTheProductionNamesAreMeasuredFromTheRealFactory(): void
    {
        [$filters, $functions] = $this->productionNames();

        $this->assertGreaterThanOrEqual(self::AT_LEAST_THIS_MANY_FILTERS, count($filters));
        $this->assertGreaterThanOrEqual(self::AT_LEAST_THIS_MANY_FUNCTIONS, count($functions));
        foreach (['money', 'french_date', 'display_name', 'sanitized_html', 'filesize', 'compact_html'] as $filter) {
            $this->assertArrayHasKey($filter, $filters, "|$filter ships in TwigFactory and the reader must see it.");
        }
        foreach (['csrf_field', 'csrf_token', 'get_flash', 'asset', 'file_url', 'editable', 'editable_image',
            'member_photo', 'person_avatar', 'section_photo', 'param'] as $function) {
            $this->assertArrayHasKey($function, $functions, "$function() ships and the reader must see it.");
        }
    }

    /**
     * And that the reader recognises a registration when it is put in
     * front of it, in every shape this repository wrote one — and does
     * NOT recognise prose, a string, or a registration of a name of the
     * test's own.
     */
    public function testTheRegistrationReaderRecognisesWhatItClaimsTo(): void
    {
        $shapes = <<<'FIXTURE'
            <?php
            $twig->addFilter(new TwigFilter('money', fn($a) => $a . ' EUR'));
            $twig->addFilter(new \Twig\TwigFilter('french_date', fn($d) => (string) $d));
            $this->twig->addFunction(new \Twig\TwigFunction(
                'csrf_field',
                static fn (): string => '<input>',
                ['is_safe' => ['html']]
            ));
            $twig->addFunction(new TwigFunction("asset", fn (string $p) => $p));
            foreach (['csrf_field', 'editable'] as $name) {
                $twig->addFunction(new \Twig\TwigFunction($name, fn () => ''));
            }
            FIXTURE;
        $notRegistrations = <<<'FIXTURE'
            <?php
            // The old shape was new TwigFilter('money', …), and it lied.
            /** new \Twig\TwigFunction('asset', …) */
            $message = "new TwigFunction('asset', fn () => '')";
            $twig->addFilter(new TwigFilter('module_own_helper', fn($x) => $x));
            FIXTURE;

        $this->assertSame(
            [
                ['TwigFilter', 'money'],
                ['TwigFilter', 'french_date'],
                ['TwigFunction', 'csrf_field'],
                ['TwigFunction', 'asset'],
                ['TwigFunction', self::COMPUTED_NAME],
            ],
            self::registrationsIn($shapes)
        );
        $this->assertSame(
            [['TwigFilter', 'module_own_helper']],
            self::registrationsIn($notRegistrations),
            'A comment, a docblock and a string are prose; a filter of the test\'s own is not a double.'
        );
    }

    public function testTheEnvironmentReaderRecognisesWhatItClaimsTo(): void
    {
        $filesystem = <<<'FIXTURE'
            <?php
            use Twig\Environment;
            use Twig\Loader\FilesystemLoader;
            $loader = new FilesystemLoader($templateDir);
            $twig = new Environment($loader, ['cache' => false]);
            FIXTURE;
        $qualifiedInline = <<<'FIXTURE'
            <?php
            $controller = new FileController(new \Twig\Environment(
                new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates')
            ));
            FIXTURE;
        $productionTemplateIntoAnArray = <<<'FIXTURE'
            <?php
            $source = file_get_contents(dirname(__DIR__, 4) . '/modules/finance/views/index.html.twig');
            $twig = new Environment(new ArrayLoader(['page' => $source]));
            FIXTURE;
        $ownTemplates = <<<'FIXTURE'
            <?php
            // Not new Environment(new FilesystemLoader(...)): an ArrayLoader of its own.
            $twig = new Environment(new ArrayLoader(['t' => '{{ x|money }}']));
            $twig->addExtension(new \Core\View\FormatFilterExtension());
            FIXTURE;
        $helper = <<<'FIXTURE'
            <?php
            $twig = TestTwig::create(['finance']);
            FIXTURE;

        $this->assertTrue(self::buildsItsOwnEnvironmentOverProductionTemplates($filesystem));
        $this->assertTrue(self::buildsItsOwnEnvironmentOverProductionTemplates($qualifiedInline));
        $this->assertTrue(
            self::buildsItsOwnEnvironmentOverProductionTemplates($productionTemplateIntoAnArray),
            'Reading a production template into an ArrayLoader is rendering it through a hand-built environment.'
        );
        $this->assertFalse(self::buildsItsOwnEnvironmentOverProductionTemplates($ownTemplates));
        $this->assertFalse(self::buildsItsOwnEnvironmentOverProductionTemplates($helper));
    }

    /**
     * `TwigFactory::create()` registers no filter of its own: every one
     * comes from a `Core\View` extension, which is what lets a test of one
     * filter add the real thing in a line instead of a copy.
     */
    public function testTheFactoryRegistersNoFilterOfItsOwn(): void
    {
        $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 3) . '/core/View/TwigFactory.php'));
        $calls = 0;
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'addFilter') {
                $calls++;
            }
        }

        $this->assertSame(
            0,
            $calls,
            'A filter registered inside TwigFactory::create() cannot be added on its own to a test of it. '
                . 'Put it in a Core\\View extension instead (issue #465).'
        );
    }

    /**
     * Every filter and function name production registers: a real
     * factory environment, asked — plus the functions the composition
     * root adds on top of it, which Tests\TestTwig stands in for.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function productionNames(): array
    {
        $twig = TwigFactory::create(TestTwig::templateDirectory(), true);

        $filters = array_fill_keys(array_keys($twig->getFilters()), true);
        $functions = array_fill_keys(array_keys($twig->getFunctions()), true)
            + array_fill_keys(array_keys(TestTwig::REPLACEABLE_FUNCTIONS), true);

        return [$filters, $functions];
    }

    /**
     * `new TwigFilter('name'` / `new \Twig\TwigFunction('name'`, read as
     * tokens: the first argument must be a literal string.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function registrationsIn(string $source): array
    {
        $tokens = self::significantTokens($source);
        $found = [];

        foreach ($tokens as $i => $token) {
            if ($token[0] !== T_NEW) {
                continue;
            }
            $class = self::shortName($tokens[$i + 1] ?? null);
            if (!in_array($class, ['TwigFilter', 'TwigFunction'], true)) {
                continue;
            }
            if (($tokens[$i + 2][1] ?? null) !== '(') {
                continue;
            }
            $name = $tokens[$i + 3] ?? null;
            // A name the reader cannot read is reported as such: a loop
            // over three production names is three doubles all the same.
            $found[] = [$class, $name !== null && $name[0] === T_CONSTANT_ENCAPSED_STRING
                ? substr($name[1], 1, -1)
                : self::COMPUTED_NAME];
        }

        return $found;
    }

    /**
     * A file builds its own environment over production templates when it
     * constructs a `Twig\Environment` AND either a `FilesystemLoader` or
     * names a production template directory in a string.
     */
    private static function buildsItsOwnEnvironmentOverProductionTemplates(string $source): bool
    {
        $tokens = self::significantTokens($source);
        $environment = false;
        $production = false;

        foreach ($tokens as $i => $token) {
            if ($token[0] === T_NEW) {
                $class = self::shortName($tokens[$i + 1] ?? null);
                $environment = $environment || $class === 'Environment';
                $production = $production || $class === 'FilesystemLoader';
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING
                && preg_match('#core/View/templates|modules/[a-z_]+/views#', $token[1]) === 1
            ) {
                $production = true;
            }
        }

        return $environment && $production;
    }

    /**
     * @param array{0: int|string, 1: string}|null $token
     */
    private static function shortName(?array $token): ?string
    {
        if ($token === null || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        $parts = explode('\\', $token[1]);

        return end($parts);
    }

    /**
     * Code tokens only, re-indexed: whitespace, comments and docblocks
     * dropped, a one-character token as [its text, its text].
     *
     * @return array<int, array{0: int|string, 1: string}>
     */
    private static function significantTokens(string $source): array
    {
        $kept = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $kept[] = [$token[0], $token[1]];
            } else {
                $kept[] = [$token, $token];
            }
        }

        return $kept;
    }

    /**
     * @return array<string, string> path relative to the repository => source
     */
    private function testFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $files[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
        }
        ksort($files);

        return $files;
    }
}
