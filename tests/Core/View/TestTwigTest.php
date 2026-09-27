<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * `Tests\TestTwig` is production's environment, and replaces only what it
 * says it may (issue #465).
 */
final class TestTwigTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testEveryFilterAndFunctionOfTheFactoryIsPresent(): void
    {
        $factory = TwigFactory::create(TestTwig::templateDirectory(), true);
        $helper = TestTwig::create();

        $this->assertSame(array_keys($factory->getFilters()), array_keys($helper->getFilters()));
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($factory->getFunctions()), array_keys($helper->getFunctions()))),
            'A function TwigFactory registers is missing from the test environment.'
        );
        $this->assertNotNull($helper->getFunction('param'), 'param() exists on every production page.');
    }

    public function testTheFiltersAreTheRealOnesNotLookalikes(): void
    {
        $html = TestTwig::withTemplates(['t' => '{{ d|french_date }}'])
            ->render('t', ['d' => '2026-07-12']);

        $this->assertStringContainsString('12 juillet 2026', $html);
    }

    public function testTheSessionFunctionsAreRealByDefault(): void
    {
        $_SESSION['_csrf_token'] = 'jeton-de-session';

        $html = TestTwig::withTemplates(['t' => '{{ csrf_field() }}|{{ csrf_token() }}'])->render('t');

        $this->assertSame('<input type="hidden" name="_csrf_token" value="jeton-de-session">|jeton-de-session', $html);
    }

    public function testAnAllowListedReplacementTakesEffect(): void
    {
        $twig = TestTwig::withTemplates(
            ['t' => '{{ csrf_field() }}|{{ get_flash().message }}|{{ param("site_name") }}'],
            [],
            [
                'csrf_field' => static fn (): string => '<input name="_csrf_token" value="fixe">',
                'get_flash' => static fn (): array => ['type' => 'success', 'message' => 'Enregistré'],
                'param' => static fn (string $key): string => 'Unité ' . $key,
            ]
        );

        $this->assertSame(
            '<input name="_csrf_token" value="fixe">|Enregistré|Unité site_name',
            $twig->render('t'),
            'A replacement keeps production\'s html safety: csrf_field() is not escaped.'
        );
    }

    public function testParamRendersAnUnsetSettingByDefault(): void
    {
        $this->assertSame('[]', TestTwig::withTemplates(['t' => '[{{ param("site_name") }}]'])->render('t'));
    }

    public function testAFunctionOutsideTheAllowListCannotBeReplaced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot replace "asset"');

        TestTwig::create([], ['asset' => static fn (string $path): string => $path]);
    }

    public function testAFilterCannotBeReplaced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A filter is never replaceable');

        TestTwig::create([], ['french_date' => static fn (string $d): string => $d]);
    }

    public function testNoMethodOfTheHelperAcceptsAFilter(): void
    {
        foreach ((new \ReflectionClass(TestTwig::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertStringNotContainsStringIgnoringCase('filter', $parameter->getName());
                $this->assertStringNotContainsString('TwigFilter', (string) $parameter->getType());
            }
        }
    }

    public function testEveryReplaceableFunctionSaysWhy(): void
    {
        foreach (TestTwig::REPLACEABLE_FUNCTIONS as $name => $reason) {
            $this->assertGreaterThan(20, strlen($reason), "$name() needs a reason to be replaceable.");
        }
        $this->assertSame(
            ['csrf_field', 'csrf_token', 'get_flash', 'param'],
            array_keys(TestTwig::REPLACEABLE_FUNCTIONS),
            'The list is short on purpose: widening it is a decision, not a convenience.'
        );
    }

    public function testModuleNamespacesResolveToTheModuleViews(): void
    {
        $loader = TestTwig::create(['finance'])->getLoader();

        $this->assertTrue($loader->exists('@finance/dashboard.html.twig'));
        $this->assertTrue($loader->exists('base.html.twig'));
    }

    public function testAMissingModuleDirectoryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TestTwig::create(['no_such_module']);
    }
}
