<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Request;
use Core\Http\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('AUTHZ_SUPPORT_TEST')) {
    define('AUTHZ_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/authz-support.php';

/**
 * A placeholder named like a row identifier matches digits and nothing
 * else (`Core\Http\Router::placeholderPattern`), and this walks the
 * REAL route table to prove it — every route the application actually
 * registers, not a handful written for the test.
 *
 * What it is protecting against is a cast, not an injection. Every one
 * of the ~230 id-named placeholders is read by its controller as
 * `(int) $params[…]`, and `(int) '2-1'` is 2: `/gallery/2-1/edit` used
 * to edit album 2. Nobody named album 2. PHP picked it, quietly, and
 * the page then looked entirely normal — which is why this needs a test
 * rather than review.
 *
 * The rule is the placeholder's NAME, deliberately, so that there is no
 * opt-out flag anyone can forget: a parameter that is not a row
 * identifier is not named like one. `/aide/{topic}` carries a help
 * topic's slug and says so. If a future route needs a non-numeric
 * parameter, it renames the parameter — and this test is what tells
 * them, by failing.
 */
class RouterIdentifierParametersTest extends TestCase
{
    /**
     * Every distinct (path, id-named placeholder) the application
     * registers, from both places it registers routes.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function identifierParameters(): array
    {
        $cases = [];

        foreach (\authz_routes() as $route) {
            foreach (\authz_placeholders($route['path']) as $name) {
                if (!self::isIdentifierName($name)) {
                    continue;
                }
                $cases[$route['path'] . ' {' . $name . '}'] = [$route['path'], $name];
            }
        }

        return $cases;
    }

    private static function isIdentifierName(string $name): bool
    {
        return $name === 'id' || str_ends_with($name, '_id') || (str_ends_with($name, 'Id') && $name !== 'Id');
    }

    /**
     * The payload shape an active scanner sends, and the one the cast
     * salvaged: an id with something stuck to it.
     *
     * @return list<string>
     */
    private static function nonNumericValues(): array
    {
        return ['2-1', '4-2', '2abc', 'abc', '2.5', ' 2', '2%00'];
    }

    /**
     * A route is built with its own real role_min so nothing here
     * depends on the RBAC guard; only `resolve()`'s matching is under
     * test.
     */
    #[DataProvider('identifierParameters')]
    public function testAnIdentifierParameterOnlyEverMatchesDigits(string $path, string $name): void
    {
        $router = new Router();
        $router->addRoute('GET', $path, \Tests\Core\Http\RouteMatchStub::class, 'index', 'public');

        $values = [];
        foreach (\authz_placeholders($path) as $placeholder) {
            $values[$placeholder] = '1';
        }

        // The control: with every parameter a plain number, the route
        // matches. Without this, a typo in the test's own URL building
        // would make every assertion below pass for the wrong reason.
        $this->assertNotNull(
            $router->resolve(new Request('GET', self::build($path, $values), [], [], [], [])),
            "the test could not even build a matching URL for {$path}"
        );

        foreach (self::nonNumericValues() as $payload) {
            $hostile = $values;
            $hostile[$name] = $payload;

            $this->assertNull(
                $router->resolve(new Request('GET', self::build($path, $hostile), [], [], [], [])),
                "{$path} still matches with {$name}=" . var_export($payload, true)
                . " — the controller will cast that to a row nobody named"
            );
        }
    }

    /**
     * @param array<string, string> $values
     */
    private static function build(string $path, array $values): string
    {
        foreach ($values as $name => $value) {
            $path = str_replace('{' . $name . '}', $value, $path);
        }

        return $path;
    }

    /**
     * The other direction: a parameter that is NOT an identifier keeps
     * accepting what it is supposed to. Without this the rule could be
     * "tightened" into matching digits everywhere, and every slug and
     * token route would 404 with no test objecting.
     */
    public function testAParameterThatIsNotAnIdentifierStillTakesText(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/aide/{topic}', RouteMatchStub::class, 'index', 'public');
        $router->addRoute('GET', '/locations/{slug}', RouteMatchStub::class, 'index', 'public');

        $resolved = $router->resolve(new Request('GET', '/aide/premiers-pas', [], [], [], []));
        $this->assertNotNull($resolved);
        $this->assertSame('premiers-pas', $resolved->params['topic']);

        $this->assertNotNull($router->resolve(new Request('GET', '/locations/salle-des-fetes', [], [], [], [])));
    }

    /**
     * The help topic is the one route that had to be renamed for the
     * rule to be a rule rather than a rule-with-an-exception. Pinned so
     * it cannot drift back.
     */
    public function testTheHelpTopicIsNotNamedLikeAnIdentifier(): void
    {
        $helpRoutes = array_filter(
            \authz_routes(),
            static fn (array $route): bool => str_starts_with($route['path'], '/aide/')
        );

        $this->assertNotEmpty($helpRoutes);

        foreach ($helpRoutes as $route) {
            foreach (\authz_placeholders($route['path']) as $name) {
                $this->assertFalse(
                    self::isIdentifierName($name),
                    "{$route['path']} names a slug like a row identifier, so the router now refuses every real"
                    . ' help topic. Rename the placeholder instead of loosening the rule.'
                );
            }
        }
    }
}

class RouteMatchStub
{
}
