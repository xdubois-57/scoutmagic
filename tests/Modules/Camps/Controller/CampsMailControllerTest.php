<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Camps\Controller\CampsMailController;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * « Courrier non classé ».
 *
 * Two promises this screen was not keeping: a message could be deleted for
 * good by somebody who could only ever see 220 characters of it, and the
 * setting `camps_auto_create_from_mail` described an action
 * (« Créer un camp depuis ce message ») that existed nowhere.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampsMailControllerTest extends TestCase
{
    private \PDO $pdo;
    private CampsMailController $controller;

    private const LONG_BODY = 'Bonjour, nous vous confirmons la réservation du terrain du 12 au 19 '
        . 'juillet 2028 pour votre unité. Merci de nous renvoyer le contrat signé avant la fin du '
        . 'mois, et de prévoir le paiement de l\'acompte dans les quinze jours qui suivent. '
        . 'La citerne a été remplacée cet hiver, ce qui change la consigne pour l\'eau.';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        // Without a dedicated mailbox the screen renders an empty state and
        // nothing else — the messages below would never arrive.
        $this->pdo->exec(
            "INSERT INTO settings (setting_key, setting_value, module_id, setting_type, label, description)
             VALUES ('camps_dedicated_mailbox_ids', '2', 'camps', 'text', 'x', 'x')"
        );

        $message = new InboundMessage(
            id: 42,
            mailboxId: 2,
            consumerId: CampsMessageConsumer::CONSUMER_ID,
            businessReference: CampsMessageConsumer::UNSORTED_REFERENCE,
            linkOrigin: LinkOrigin::SENDER,
            subject: 'Confirmation de réservation',
            fromEmail: 'info@mozet.be',
            fromName: 'Domaine de Mozet',
            messageId: '<abc@mail>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-11-02 09:00:00'),
            bodyText: self::LONG_BODY,
            bodyHtml: ''
        );

        $inbound = $this->createStub(InboundMailInterface::class);
        $inbound->method('findForReference')->willReturn([$message]);

        $root = dirname(__DIR__, 4);
        $this->controller = new CampsMailController(
            TwigFactory::create($root . '/core/View/templates', false, ['camps' => $root . '/modules/camps/views']),
            new CampRepository($this->pdo, $encryption),
            new PlaceRepository($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            $inbound
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

    private function screen(): string
    {
        return $this->controller->unsorted(
            new Request('GET', '/chefs/camps/courrier', [], [], [], []),
            []
        )->getBody();
    }

    public function testTheWholeMessageCanBeRead(): void
    {
        $html = $this->screen();

        // The screen used to show 220 characters and offer « Supprimer
        // définitivement » next to them.
        $this->assertStringContainsString('La citerne a été remplacée cet hiver', $html);
        $this->assertStringContainsString('<details', $html);
    }

    public function testAMessageOffersToBecomeACamp(): void
    {
        $html = $this->screen();

        $this->assertStringContainsString('/chefs/camps/nouveau?message=42', $html);
        $this->assertStringContainsString('Créer un camp depuis ce message', html_entity_decode($html));
    }

    public function testTheRetentionWarningStillStatesTheDelay(): void
    {
        $this->assertStringContainsString('effacés après 6 mois', $this->screen());
    }
}
