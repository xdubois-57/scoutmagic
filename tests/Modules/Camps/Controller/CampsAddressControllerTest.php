<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Geo\AddressLocator;
use Core\Geo\GeocodingService;
use Core\Http\Request;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\TwigFactory;
use Modules\Camps\Controller\CampsAddressController;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The place forms' address lookup (#1353): the core's AddressLocator behind
 * a route of the camps module, under its own geocoding switch.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CampsAddressControllerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.be', Role::CHIEF->value);
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testAnAddressFoundIsAPointTheFormCanCentreOn(): void
    {
        $answer = $this->ask(
            $this->controller(['latitude' => 50.4421, 'longitude' => 4.9876]),
            'Rue du Tronquoy 4, 5340 Mozet'
        );

        $this->assertSame(['success' => true, 'found' => true, 'latitude' => 50.4421, 'longitude' => 4.9876], $answer);
    }

    public function testAnAddressNotFoundSaysWhy(): void
    {
        $answer = $this->ask($this->controller(null), 'Le pré de Jules, derrière la ferme');

        $this->assertSame(['success' => true, 'found' => false, 'reason' => 'not_found'], $answer);
    }

    /** `camps_geocoding_enabled` off: the composition root passes no locator, and nothing is asked. */
    public function testWithTheSwitchOffNothingIsAsked(): void
    {
        $controller = new CampsAddressController($this->twig(), null);

        $answer = $this->ask($controller, 'Rue du Tronquoy 4, 5340 Mozet');

        $this->assertSame(['success' => true, 'found' => false, 'reason' => 'unavailable'], $answer);
    }

    /** No signed-in account: the lookup is keyed by account, so nothing is asked. */
    public function testWithoutAnAccountNothingIsAsked(): void
    {
        AuthSession::logout();

        $answer = $this->ask($this->controller(['latitude' => 50.4421, 'longitude' => 4.9876]), 'Rue du Tronquoy 4');

        $this->assertSame(['success' => true, 'found' => false, 'reason' => 'unavailable'], $answer);
    }

    /**
     * @param array{latitude: float, longitude: float}|null $answer
     */
    private function controller(?array $answer): CampsAddressController
    {
        $geocoder = new class ($answer) extends GeocodingService {
            /** @param array{latitude: float, longitude: float}|null $answer */
            public function __construct(private ?array $answer)
            {
                parent::__construct('https://unit.test');
            }

            public function geocodeLine(?string $line): ?array
            {
                return $this->answer;
            }
        };

        return new CampsAddressController(
            $this->twig(),
            new AddressLocator($this->pdo, $geocoder, static function (int $microseconds): void {
            })
        );
    }

    /** @return array<string, mixed> */
    private function ask(CampsAddressController $controller, string $address): array
    {
        $response = $controller->locateAddress(
            new Request('GET', '/chefs/camps/adresse', ['q' => $address], [], [], []),
            []
        );
        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function twig(): \Twig\Environment
    {
        $root = dirname(__DIR__, 4);

        return TwigFactory::create($root . '/core/View/templates', false, []);
    }
}
