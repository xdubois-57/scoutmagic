<?php

declare(strict_types=1);

namespace Tests;

use Core\View\SessionFunctionExtension;
use Core\View\TwigFactory;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Node\Nodes;
use Twig\TwigFunction;

/**
 * The Twig environment a test renders templates through: production's own.
 *
 * `create()` is `Core\View\TwigFactory::create()` over the real template
 * directories, in debug mode so nothing is compiled to disk — every filter,
 * every extension and every function a page gets in production, and the
 * same autoescape rule. The one thing a test may change is a function from
 * {@see self::REPLACEABLE_FUNCTIONS}: those whose production implementation
 * needs a live request or the application's container. Everything else —
 * `asset()`, `file_url()`, `editable()`, `person_avatar()` … — runs for
 * real; the ones that read a service out of the globals degrade to what an
 * install without that content renders, and a test that needs content
 * supplies the service as a global, exactly as `public/index.php` does.
 *
 * Why (issue #465): 140 test files used to build their own
 * `Twig\Environment` and register the functions and filters their author
 * noticed were missing. A filter added to production then broke unrelated
 * suites, and a stub could lie — a `french_date` double rendering
 * « 2026-07-12 » where a visitor reads « 12 juillet 2026 », under a test
 * asserting something about the page. `Tests\Core\View\
 * TestEnvironmentsUseTheRealFactoryTest` keeps it this way: outside this
 * file, no test registers a function or filter production registers, and
 * none builds its own environment over the production templates.
 */
final class TestTwig
{
    /**
     * The functions a test may replace, each with the reason it may.
     * Nothing else can be replaced — a filter least of all.
     */
    public const REPLACEABLE_FUNCTIONS = [
        'csrf_field' => 'Reads, and on first use writes, the token in $_SESSION (Core\\Security\\CsrfGuard).',
        'csrf_token' => 'Reads, and on first use writes, the token in $_SESSION (Core\\Security\\CsrfGuard).',
        'get_flash' => 'Consumes the pending flash message from $_SESSION (Core\\Http\\FlashMessage).',
        'param' => 'Registered by public/index.php, not TwigFactory: it reads a setting from the database. '
            . 'Defaults here to the empty string an unset setting renders.',
    ];

    /**
     * @param array<int|string, string> $modules module template namespaces:
     *        `'finance'` for `modules/finance/views` under `@finance`, or
     *        `'finance' => $path` to name the directory explicitly
     * @param array<string, callable> $functions replacements, keyed by a
     *        name from {@see self::REPLACEABLE_FUNCTIONS}
     */
    public static function create(array $modules = [], array $functions = []): Environment
    {
        $replacements = self::checkedReplacements($functions);

        $moduleDirs = [];
        foreach ($modules as $key => $value) {
            if (is_int($key)) {
                $moduleDirs[$value] = self::root() . '/modules/' . $value . '/views';
            } else {
                $moduleDirs[$key] = $value;
            }
        }
        foreach ($moduleDirs as $namespace => $dir) {
            if (!is_dir($dir)) {
                throw new \InvalidArgumentException("No template directory for @$namespace: $dir");
            }
        }

        $twig = TwigFactory::create(self::templateDirectory(), true, $moduleDirs);

        // The session functions come from an extension, so a function
        // added here takes precedence over them (Twig resolves `addFunction`
        // after every extension). Twig refuses a second `addFunction` of a
        // name TwigFactory adds itself — which is why only those three and
        // `param` can be replaced at all.
        $options = self::shippedOptions();
        foreach ($replacements as $name => $callable) {
            $twig->addFunction(new TwigFunction($name, $callable, $options[$name] ?? []));
        }

        return $twig;
    }

    /**
     * The same environment, with templates of the test's own in front of
     * the production ones — so a fixture can still `{% extends %}` a real
     * layout, and still gets every real filter and function.
     *
     * @param array<string, string> $templates name => source
     * @param array<int|string, string> $modules see {@see self::create()}
     * @param array<string, callable> $functions see {@see self::create()}
     */
    public static function withTemplates(array $templates, array $modules = [], array $functions = []): Environment
    {
        $twig = self::create($modules, $functions);
        $twig->setLoader(new ChainLoader([new ArrayLoader($templates), $twig->getLoader()]));

        return $twig;
    }

    public static function templateDirectory(): string
    {
        return self::root() . '/core/View/templates';
    }

    /**
     * @param array<string, callable> $functions
     * @return array<string, callable>
     */
    private static function checkedReplacements(array $functions): array
    {
        foreach (array_keys($functions) as $name) {
            if (!array_key_exists($name, self::REPLACEABLE_FUNCTIONS)) {
                throw new \InvalidArgumentException(sprintf(
                    'Tests\\TestTwig cannot replace "%s": only %s may be, because their production '
                        . 'implementation needs a live request or the container. A filter is never '
                        . 'replaceable, and neither is any other function: render with the real one '
                        . '(a function that reads a service takes it from the globals, as '
                        . 'public/index.php supplies it) and assert on what a visitor would see.',
                    $name,
                    implode(', ', array_keys(self::REPLACEABLE_FUNCTIONS))
                ));
            }
        }

        // `param()` exists on every production page; an unset setting
        // renders as the empty string.
        return $functions + ['param' => static fn (string $key, ?string $moduleId = null): string => ''];
    }

    /**
     * The options (html safety) production gives each replaceable
     * function, so a replacement is escaped exactly like the original.
     *
     * @return array<string, array{is_safe: array<int, string>}>
     */
    private static function shippedOptions(): array
    {
        $options = [];
        foreach ((new SessionFunctionExtension())->getFunctions() as $function) {
            $options[$function->getName()] = ['is_safe' => $function->getSafe(new Nodes()) ?? []];
        }

        return $options;
    }

    private static function root(): string
    {
        return dirname(__DIR__);
    }
}
