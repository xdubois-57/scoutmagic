<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Response;
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
        ]));

        $this->controller = new class ($twig) extends AbstractController {
            public function callNotFound(): Response
            {
                return $this->notFound();
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
