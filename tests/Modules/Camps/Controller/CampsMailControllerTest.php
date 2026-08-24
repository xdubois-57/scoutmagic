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
    private RecordingInboundMail $inbound;
    private \Modules\Camps\Repository\FieldProposalRepository $proposals;
    private \Modules\Camps\Mail\MailFieldCompletionService $fieldCompletion;
    private int $campId;

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

        $this->inbound = new RecordingInboundMail([$message]);

        $places = new PlaceRepository($this->pdo);
        $camps = new CampRepository($this->pdo, $encryption);
        $placeId = $places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $this->campId = $camps->create(
            $placeId,
            \Modules\Camps\Repository\Camp::STAY_GRAND_CAMP,
            '2028-07-12',
            '2028-07-19',
            null,
            \Modules\Camps\Repository\Camp::STATUS_CONFIRMED,
            null,
            null,
            null,
            null,
            []
        );

        $audit = new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($this->pdo, $encryption));
        $this->proposals = new \Modules\Camps\Repository\FieldProposalRepository($this->pdo, $encryption);
        $this->fieldCompletion = new \Modules\Camps\Mail\MailFieldCompletionService(
            $camps,
            $this->proposals,
            $audit,
            new \Modules\Camps\Mail\MessageReader()
        );

        $root = dirname(__DIR__, 4);
        $this->controller = new CampsMailController(
            TwigFactory::create($root . '/core/View/templates', false, ['camps' => $root . '/modules/camps/views']),
            $camps,
            $places,
            new SettingService(new SettingRepository($this->pdo)),
            $this->inbound,
            $this->proposals,
            $this->fieldCompletion
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

    // ── attach() ────────────────────────────────────────────────────

    public function testAttachingAMessageMovesItOntoTheStayAndSendsTheChiefThere(): void
    {
        $response = $this->post('attach', ['camp_id' => (string) $this->campId], ['id' => '42']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/chefs/camps/sejours/' . $this->campId, $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            [[
                CampsMessageConsumer::CONSUMER_ID,
                CampsMessageConsumer::UNSORTED_REFERENCE,
                CampsMessageConsumer::referenceFor($this->campId),
                42,
            ]],
            $this->inbound->moves
        );
    }

    /**
     * A stay id nobody has must not move the message anywhere: the id
     * comes from a form field and is as forgeable as any other.
     */
    public function testAttachingToAStayThatDoesNotExistMovesNothing(): void
    {
        $response = $this->post('attach', ['camp_id' => '999999'], ['id' => '42']);

        $this->assertSame('/chefs/camps/courrier', $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->inbound->moves);
    }

    public function testAttachingWithNoStayChosenMovesNothing(): void
    {
        $response = $this->post('attach', [], ['id' => '42']);

        $this->assertSame('/chefs/camps/courrier', $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->inbound->moves);
    }

    public function testAttachWithoutACsrfTokenMovesNothing(): void
    {
        $request = new Request('POST', '/chefs/camps/courrier/42/rattacher', [], ['camp_id' => (string) $this->campId], [], []);

        $this->controller->attach($request, ['id' => '42']);

        $this->assertSame([], $this->inbound->moves);
    }

    // ── discard() ───────────────────────────────────────────────────

    /**
     * `detach()` from the reserved 'unsorted' reference DELETES, per
     * inbound_mail's own semantics: there is no unattached queue to fall
     * back into, and 'unsorted' is itself the fallback.
     */
    public function testDiscardingAMessageDetachesItFromUnsorted(): void
    {
        $response = $this->post('discard', [], ['id' => '42']);

        $this->assertSame('/chefs/camps/courrier', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            [[CampsMessageConsumer::CONSUMER_ID, CampsMessageConsumer::UNSORTED_REFERENCE, 42]],
            $this->inbound->detaches
        );
    }

    public function testDiscardWithoutACsrfTokenDeletesNothing(): void
    {
        $this->controller->discard(
            new Request('POST', '/chefs/camps/courrier/42/supprimer', [], [], [], []),
            ['id' => '42']
        );

        $this->assertSame([], $this->inbound->detaches);
    }

    // ── applyProposal() / dismissProposal() ─────────────────────────

    public function testApplyingAProposalWritesItOntoTheStayAndConsumesIt(): void
    {
        $this->aProposal();
        $proposalId = $this->proposals->findByCamp($this->campId)[0]->id;

        $response = $this->post('applyProposal', [], ['id' => (string) $proposalId]);

        $this->assertSame('/chefs/camps/sejours/' . $this->campId, $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->proposals->findByCamp($this->campId), 'a decided proposal is gone');
        $this->assertSame(24500, (new CampRepository($this->pdo, $this->encryption()))->findById($this->campId)?->priceCents);
    }

    /**
     * Dismissing is recorded too: six months later somebody asks why the
     * page does not say what the mail says.
     */
    public function testDismissingAProposalConsumesItWithoutTouchingTheStay(): void
    {
        $this->aProposal();
        $proposalId = $this->proposals->findByCamp($this->campId)[0]->id;

        $response = $this->post('dismissProposal', [], ['id' => (string) $proposalId]);

        $this->assertSame('/chefs/camps/sejours/' . $this->campId, $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->proposals->findByCamp($this->campId));
        $this->assertNull((new CampRepository($this->pdo, $this->encryption()))->findById($this->campId)?->priceCents);
    }

    /**
     * A proposal id is sequential and guessable; an unknown one answers
     * the site's own 404 rather than a bare body or a 500.
     */
    public function testAnUnknownProposalIsNotFound(): void
    {
        $response = $this->post('applyProposal', [], ['id' => '999999']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Page non trouvée', $response->getBody());
    }

    public function testDecidingAProposalWithoutACsrfTokenChangesNothing(): void
    {
        $this->aProposal();
        $proposalId = $this->proposals->findByCamp($this->campId)[0]->id;

        $this->controller->applyProposal(
            new Request('POST', '/chefs/camps/propositions/' . $proposalId . '/appliquer', [], [], [], []),
            ['id' => (string) $proposalId]
        );

        $this->assertCount(1, $this->proposals->findByCamp($this->campId));
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function aProposal(): void
    {
        $this->proposals->save($this->campId, 'price', null, '245,00 €', '24500', 'msg-42');
    }

    private function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $params
     */
    private function post(string $action, array $body, array $params): \Core\Http\Response
    {
        $token = \Core\Security\CsrfGuard::generateToken();

        return $this->controller->{$action}(
            new Request('POST', '/chefs/camps/courrier', [], array_merge($body, ['_csrf_token' => $token]), [], []),
            $params
        );
    }
}

/**
 * An InboundMailInterface that records what it was asked to do.
 *
 * A stub returning true would prove the controller redirected; what these
 * tests are about is WHICH reference a message was moved between, which is
 * the whole of the reserved-'unsorted' design.
 *
 * @internal
 */
class RecordingInboundMail implements InboundMailInterface
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: int}> */
    public array $moves = [];

    /** @var array<int, array{0: string, 1: string, 2: int}> */
    public array $detaches = [];

    /** @param InboundMessage[] $messages */
    public function __construct(private array $messages)
    {
    }

    /** @return InboundMessage[] */
    public function findForReference(string $consumerId, string $businessReference): array
    {
        return $this->messages;
    }

    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage
    {
        foreach ($this->messages as $message) {
            if ($message->id === $messageId) {
                return $message;
            }
        }

        return null;
    }

    /** @param int[] $preserveFileIds */
    public function detach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        array $preserveFileIds = []
    ): bool {
        $this->detaches[] = [$consumerId, $businessReference, $messageId];

        return true;
    }

    public function move(string $consumerId, string $fromReference, string $toReference, int $messageId): bool
    {
        $this->moves[] = [$consumerId, $fromReference, $toReference, $messageId];

        return true;
    }

    public function purgeReference(string $consumerId, string $businessReference): int
    {
        return 0;
    }

    public function isCollecting(): bool
    {
        return true;
    }

    /** @param string[] $messageIds */
    public function findReferenceByThread(string $consumerId, int $mailboxId, array $messageIds): ?string
    {
        return null;
    }

    /** @return array<int, array{name: string, state: string, is_enabled: bool}> */
    public function listMailboxSummaries(): array
    {
        return [];
    }
}
