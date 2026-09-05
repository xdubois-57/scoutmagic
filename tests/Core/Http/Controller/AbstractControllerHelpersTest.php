<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The two helpers every controller was writing for itself.
 *
 * `notFound()` mattered most: three camps controllers each returned a bare
 * `Not Found` body, so a chief who mistyped an id landed on a blank page
 * with no navigation, no site name and no way back — a different page from
 * the one the router serves for an unknown path, which is also a hint that
 * the id exists.
 */
class AbstractControllerHelpersTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        $twig = new Environment(new ArrayLoader([
            'errors/404.html.twig' => '<h1>Page non trouvée</h1><a href="/">Retour à l\'accueil</a>',
            'errors/403.html.twig' => '<h1>Accès refusé</h1>{{ message }}',
        ]));

        $this->controller = new class ($twig) extends AbstractController {
            public function callNotFound(): Response
            {
                return $this->notFound();
            }

            public function callForbidden(string $message, ?Request $request): Response
            {
                return $this->forbidden($message, $request);
            }

            /**
             * @param array<string, string> $labels
             * @return array<int, array{value: string, label: string, selected: bool}>
             */
            public function callOptions(array $labels, string $selected): array
            {
                return $this->options($labels, $selected);
            }
        };
    }

    public function testNotFoundRendersTheRealPageWithA404Status(): void
    {
        $response = $this->controller->callNotFound();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Page non trouvée', $response->getBody());
        $this->assertStringContainsString('Retour à l\'accueil', $response->getBody());
    }

    /**
     * A media type is case-insensitive (RFC 9110 8.3.1), so a client that
     * spells its Accept header differently from ours still gets JSON. It
     * would otherwise be handed a full HTML page to parse as JSON, and
     * report a syntax error instead of the refusal's reason.
     */
    #[DataProvider('jsonAcceptHeaders')]
    public function testAJsonAcceptHeaderGetsAJsonRefusalWhateverItsCase(string $accept): void
    {
        $response = $this->controller->callForbidden('Pas pour vous', $this->requestAccepting($accept));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'Pas pour vous'], json_decode($response->getBody(), true));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function jsonAcceptHeaders(): array
    {
        return [
            'lowercase' => ['application/json'],
            'capitalised' => ['Application/JSON'],
            'mixed case among others' => ['text/html, Application/Json;q=0.9'],
        ];
    }

    public function testABrowserAcceptHeaderGetsTheThemedPage(): void
    {
        $response = $this->controller->callForbidden(
            'Pas pour vous',
            $this->requestAccepting('text/html,application/xhtml+xml')
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Accès refusé', $response->getBody());
    }

    private function requestAccepting(string $accept): Request
    {
        return new Request('POST', '/groupes/1', [], [], [], ['HTTP_ACCEPT' => $accept]);
    }

    public function testOptionsKeepsTheDeclaredOrderAndMarksTheSelectedOne(): void
    {
        $options = $this->controller->callOptions(
            ['confirmed' => 'Confirmé', 'cancelled' => 'Annulé', 'planned' => 'Prévu'],
            'cancelled'
        );

        $this->assertSame(['confirmed', 'cancelled', 'planned'], array_column($options, 'value'));
        $this->assertSame(['Confirmé', 'Annulé', 'Prévu'], array_column($options, 'label'));
        $this->assertSame([false, true, false], array_column($options, 'selected'));
    }

    public function testOptionsSelectsNothingWhenTheCurrentValueIsUnknown(): void
    {
        $options = $this->controller->callOptions(['a' => 'A', 'b' => 'B'], '');

        $this->assertSame([false, false], array_column($options, 'selected'));
    }

    /**
     * A numeric-string key arrives from PHP as an int. Comparing it to the
     * submitted value without casting silently selects nothing.
     */
    public function testOptionsHandlesNumericKeys(): void
    {
        $options = $this->controller->callOptions(['2026' => '2026', '2027' => '2027'], '2027');

        $this->assertSame(['2026', '2027'], array_column($options, 'value'));
        $this->assertSame([false, true], array_column($options, 'selected'));
    }
}
