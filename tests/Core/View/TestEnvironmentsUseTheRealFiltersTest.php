<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Extension\AbstractExtension;

/**
 * No test may re-implement a filter this project already ships.
 *
 * A test that builds its own `Twig\Environment` — 137 files do, against
 * 75 that call `Core\View\TwigFactory::create()` — renders REAL
 * production templates through a filter list of its author's making. It
 * registers the ones the template complained about, in the shortest form
 * that stops the render from raising, and the result is a page that
 * nobody visiting the site will ever see. Issue #465 is that, with the
 * count.
 *
 * Two kinds of damage, and the second is the reason for this file:
 *
 * 1. A filter added to the real factory breaks suites that have nothing
 *    to do with it — five of them fell when #460 added the date filters.
 * 2. **A double can lie, and nothing says so.** The worst case found was
 *    `Tests\Core\View\DisplayNameFilterTest`, a file named after the
 *    filter, asserting six behaviours of a copy of it pasted into its own
 *    `setUp()`. Returning `'MUTANT'` from the real `display_name` left
 *    2 210 tests green — every test in the 77 files that build the real
 *    environment.
 *
 * So the rule, and it is one-directional on purpose: a test may not
 * register a filter name that a `Core\View` extension already defines. It
 * MAY register anything else — a module's own filter, a helper of its
 * own — because the failure this guards is a double standing in for
 * something real, not the act of registering a filter.
 *
 * The production side is **measured, not parsed**: each extension is
 * instantiated and asked for its filters. A regex over `core/View/` would
 * answer from prose, and the class it forgot would be the one a test is
 * free to fake.
 */
class TestEnvironmentsUseTheRealFiltersTest extends TestCase
{
    /**
     * The one file allowed to spell these registrations: this one, whose
     * fixtures have to quote them to prove the reader reads. Pinned by
     * testTheOneExemptionIsStillNeeded() below.
     */
    private const EXEMPT = 'tests/Core/View/TestEnvironmentsUseTheRealFiltersTest.php';

    /**
     * A floor, not a target. The assertion that matters is an
     * assertSame([], …), which a reader finding NOTHING satisfies
     * perfectly — so the count of what the reader found has to be
     * asserted too, or a broken reader reads as a clean repository.
     */
    private const AT_LEAST_THIS_MANY_FILTERS = 20;

    public function testNoTestRedefinesAFilterTheProjectAlreadyShips(): void
    {
        $shipped = $this->shippedFilterNames();
        $offenders = [];

        foreach ($this->testFiles() as $relative => $source) {
            if ($relative === self::EXEMPT) {
                continue;
            }
            foreach ($this->filtersRegisteredIn($source) as $name) {
                if (isset($shipped[$name])) {
                    $offenders[] = $relative . ' → |' . $name
                        . ' (ships in ' . $shipped[$name] . ')';
                }
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A test may not re-implement a filter this project ships. Use the extension:'],
            $offenders,
            ['', 'Registering `new ' . 'Core\\View\\DateFilterExtension()` and its siblings '
                . 'gives the real list in one line each — see Core\\View\\TwigFactory::create().'],
        )));
    }

    /**
     * The other direction, and the reason this file is not just a sweep:
     * a reader that finds nothing would pass the assertion above on a
     * repository full of doubles.
     */
    public function testTheReaderFindsTheShippedFiltersAtAll(): void
    {
        $shipped = $this->shippedFilterNames();

        $this->assertGreaterThanOrEqual(
            self::AT_LEAST_THIS_MANY_FILTERS,
            count($shipped),
            'The extension reader found almost nothing — it is broken, not the repository clean.'
        );
        foreach (['money', 'french_date', 'display_name', 'sanitized_html', 'filesize'] as $expected) {
            $this->assertArrayHasKey(
                $expected,
                $shipped,
                "|$expected ships in a Core\\View extension and the reader must see it."
            );
        }
    }

    /**
     * And the direction a floor cannot cover: that the reader recognises
     * a redefinition when it is put in front of it, and does NOT
     * recognise the correct form. Literal source, with a known answer —
     * a reader that approves everything passes every sweep ever written.
     */
    public function testTheReaderRecognisesWhatItClaimsTo(): void
    {
        $registers = <<<'FIXTURE'
            $twig->addFilter(new TwigFilter('money', fn($a) => $a . ' EUR'));
            FIXTURE;
        $qualified = <<<'FIXTURE'
            $twig->addFilter(new \Twig\TwigFilter('french_date', fn($d) => (string) $d));
            FIXTURE;
        $multiline = <<<'FIXTURE'
            $twig->addFilter(new TwigFilter(
                'sanitized_html',
                static fn (?string $html): string => (string) $html,
                ['is_safe' => ['html']]
            ));
            FIXTURE;
        $theRightWay = <<<'FIXTURE'
            $twig->addExtension(new \Core\View\FormatFilterExtension());
            $twig->addFilter(new TwigFilter('module_own_helper', fn($x) => $x));
            $twig->addFunction(new TwigFunction('money', fn($x) => $x));
            FIXTURE;
        $prose = <<<'FIXTURE'
            // The old shape was new TwigFilter('money', …), and it lied.
            $twig->addExtension(new \Core\View\FormatFilterExtension());
            FIXTURE;

        $this->assertSame(['money'], $this->filtersRegisteredIn($registers));
        $this->assertSame(['french_date'], $this->filtersRegisteredIn($qualified));
        $this->assertSame(
            ['sanitized_html'],
            $this->filtersRegisteredIn($multiline),
            'The registration this repository actually writes spans four lines.'
        );
        $this->assertSame(
            ['module_own_helper'],
            $this->filtersRegisteredIn($theRightWay),
            'An extension is not a redefinition, and a FUNCTION of the same name is not a filter.'
        );
        $this->assertSame(
            [],
            $this->filtersRegisteredIn($prose),
            'A comment describing the old shape is prose, not a registration.'
        );
    }

    /**
     * `TwigFactory::create()` must register no filter of its own — that is
     * the whole point of the extensions, and a closure added back there
     * is invisible to every hand-built environment again.
     */
    public function testTheFactoryRegistersNoFilterOfItsOwn(): void
    {
        $source = (string) file_get_contents($this->root() . '/core/View/TwigFactory.php');

        $this->assertSame(
            0,
            preg_match_all('/->addFilter\s*\(/', self::withoutComments($source)),
            'A filter registered inside TwigFactory::create() cannot be reached by a test that '
                . 'builds its own Environment. Put it in a Core\\View extension instead (issue #465).'
        );
    }

    public function testTheOneExemptionIsStillNeeded(): void
    {
        $source = (string) file_get_contents($this->root() . '/' . self::EXEMPT);

        $this->assertNotSame(
            [],
            $this->filtersRegisteredIn($source),
            'This file no longer quotes a shipped filter name, so the exemption is dead weight — '
                . 'remove EXEMPT rather than leaving a hole nobody needs.'
        );
    }

    /**
     * Every filter name the project's own Twig extensions define, mapped
     * to the extension that defines it. Instantiated and asked, never
     * read: see the class docblock.
     *
     * @return array<string, string>
     */
    private function shippedFilterNames(): array
    {
        $names = [];

        foreach (glob($this->root() . '/core/View/*.php') ?: [] as $path) {
            $class = 'Core\\View\\' . basename($path, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isSubclassOf(AbstractExtension::class) || $reflection->isAbstract()) {
                continue;
            }
            /** @var AbstractExtension $extension */
            $extension = $reflection->newInstance();
            foreach ($extension->getFilters() as $filter) {
                $names[$filter->getName()] = $reflection->getShortName();
            }
        }

        return $names;
    }

    /**
     * The filter names a piece of PHP registers by hand, comments
     * stripped — a docblock naming a filter is prose, not a registration,
     * and this file's own fixtures rely on that being true.
     *
     * @return array<int, string>
     */
    private function filtersRegisteredIn(string $source): array
    {
        preg_match_all(
            '/TwigFilter\s*\(\s*([\'"])([a-zA-Z_0-9]+)\1/',
            self::withoutComments($source),
            $found
        );

        return array_values(array_unique($found[2]));
    }

    /**
     * @return array<string, string>
     */
    private function testFiles(): array
    {
        $root = $this->root();
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tests'));

        foreach ($walk as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getPathname();
            $files[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
        }
        ksort($files);

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * `token_get_all()` only tokenises PHP once it has seen an opening
     * tag: a fragment without one comes back as a single T_INLINE_HTML
     * token, comments included and nothing stripped. The fixtures above
     * ARE fragments, and the one that proves prose is not a registration
     * would have passed for the wrong reason.
     */
    private static function withoutComments(string $source): string
    {
        $fragment = !str_contains($source, '<?php');
        $kept = '';

        foreach (token_get_all($fragment ? '<?php ' . $source : $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $fragment ? substr($kept, strlen('<?php ')) : $kept;
    }
}
