<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Http\Request;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Camps\Controller\CampsMailController;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;
use Tests\Modules\InboundMail\InMemoryTriageMail;
use Tests\Modules\InboundMail\TriageScreenScenario;

/**
 * The camps' side of the shared triage screen (issue #462, IT-03): the
 * scenario the rentals' screen runs too (`TriageScreenScenario`), driven
 * through CampsMailController.
 */
final class CampsTriageScreenTest extends TestCase
{
    use TriageScreenScenario;

    private CampsMailController $controller;
    private int $campId;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $camps = new CampRepository($pdo, $encryption);
        $placeId = (new PlaceRepository($pdo))->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $this->campId = $camps->create(
            $placeId,
            Camp::STAY_GRAND_CAMP,
            '2028-07-12',
            '2028-07-19',
            null,
            Camp::STATUS_CONFIRMED,
            null,
            null,
            null,
            null,
            []
        );

        $root = dirname(__DIR__, 4);
        $this->controller = new CampsMailController(
            TwigFactory::create($root . '/core/View/templates', false, [
                'camps' => $root . '/modules/camps/views',
                'inbound_mail' => $root . '/modules/inbound_mail/views',
            ]),
            $camps,
            new InMemoryTriageMail(InMemoryTriageMail::aMessage())
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.com', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    protected function triageScreen(string $status = ''): string
    {
        return (string) $this->controller->unsorted(
            new Request('GET', '/chefs/camps/courrier', $status === '' ? [] : ['statut' => $status], [], [], []),
            []
        )->getBody();
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $action, int $id, array $body = []): void
    {
        $this->controller->{$action}(
            new Request('POST', '/chefs/camps/courrier', [], $body + ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            ['id' => (string) $id]
        );
    }

    protected function triageAttach(int $id): void
    {
        $this->post('attach', $id, ['camp_id' => (string) $this->campId]);
    }

    protected function triageDetach(int $id): void
    {
        $this->post('discard', $id, ['business_reference' => CampsMessageConsumer::referenceFor($this->campId)]);
    }

    protected function triageSetAside(int $id): void
    {
        $this->post('setAside', $id);
    }

    protected function triageRestore(int $id): void
    {
        $this->post('restore', $id);
    }
}
