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
 * « Courrier des camps » — this module's view of the business triage list.
 *
 * It used to be « Courrier non classé », backed by a reserved `unsorted`
 * reference. What the screen shows now comes from
 * `InboundMailInterface::findForTriage()`, which every consumer will call;
 * what it promises is unchanged — a message is readable in full before
 * anybody decides its fate, and « Créer un camp depuis ce message » is a
 * real action rather than a setting describing one.
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
            // Attached to nothing — the ordinary state of a message on a
            // dedicated box that no stay claimed.
            consumerId: '',
            businessReference: '',
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
        $this->camps = $camps;
        $this->controller = new CampsMailController(
            TwigFactory::create($root . '/core/View/templates', false, ['camps' => $root . '/modules/camps/views']),
            $camps,
            $places,
            $this->inbound,
            $this->proposals,
            $this->fieldCompletion,
            new \Modules\Camps\Service\StaySearchService($camps),
            new \Modules\Camps\Mail\ExistingStayMatcher($camps, new \Modules\Camps\Mail\MessageReader())
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

    private CampRepository $camps;

    private function screen(string $status = ''): string
    {
        return $this->controller->unsorted(
            new Request('GET', '/chefs/camps/courrier', $status === '' ? [] : ['statut' => $status], [], [], []),
            []
        )->getBody();
    }

    /**
     * A second message, already filed under a stay by this module.
     *
     * @return InboundMessage
     */
    private function attachedMessage(): InboundMessage
    {
        return new InboundMessage(
            id: 43,
            mailboxId: 2,
            consumerId: CampsMessageConsumer::CONSUMER_ID,
            businessReference: CampsMessageConsumer::referenceFor($this->campId),
            linkOrigin: LinkOrigin::SENDER,
            subject: 'Deja classe',
            fromEmail: 'info@mozet.be',
            fromName: 'Domaine de Mozet',
            messageId: '<def@mail>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-11-03 09:00:00'),
            bodyText: 'Corps',
            bodyHtml: '',
            links: [new \Modules\InboundMail\Api\MessageLink(
                CampsMessageConsumer::CONSUMER_ID,
                CampsMessageConsumer::referenceFor($this->campId),
                LinkOrigin::SENDER
            )]
        );
    }

    // ── The status filter ───────────────────────────────────────────────

    /**
     * What a chief comes here to do is decide about the mail nobody could
     * attribute. A list that opens on everything buries the dozen that
     * need a decision under the hundreds that do not.
     */
    public function testTheScreenOpensOnWhatStillNeedsADecision(): void
    {
        $this->inbound->setMessages([$this->attachedMessage()]);

        $html = $this->screen();

        $this->assertStringNotContainsString('Deja classe', $html);
        $this->assertStringContainsString('Aucun message dans ce filtre', html_entity_decode($html));
    }

    public function testTheAttachedTabShowsWhatTheDefaultHides(): void
    {
        $this->inbound->setMessages([$this->attachedMessage()]);

        $this->assertStringContainsString('Deja classe', $this->screen('rattaches'));
    }

    public function testTheAllTabShowsBoth(): void
    {
        $this->inbound->setMessages([$this->attachedMessage()]);

        $html = $this->screen('tous');

        $this->assertStringContainsString('Deja classe', $html);
    }

    public function testAnUnknownFilterIsTheDefaultRatherThanAnError(): void
    {
        $this->inbound->setMessages([$this->attachedMessage()]);

        $this->assertStringNotContainsString('Deja classe', $this->screen('n-importe-quoi'));
    }

    /**
     * « Rien à trier » and « le filtre cache tout » look identical on an
     * empty list, and only a number tells them apart.
     */
    public function testEachTabCarriesItsOwnCount(): void
    {
        $this->inbound->setMessages([$this->attachedMessage()]);

        $html = $this->screen();

        $this->assertStringContainsString('?statut=rattaches', $html);
        $this->assertStringContainsString('?statut=tous', $html);
        // One attached, none to sort: the numbers have to say so.
        $this->assertMatchesRegularExpression('/Rattach\S*s\s*<span[^>]*>\s*1\s*</u', $html);
    }

    public function testAnInstallationWithNoMailboxShowsNoFilterAtAll(): void
    {
        // Filtering nothing is furniture, and the screen already says why
        // there is nothing.
        $this->inbound->setMessages([]);
        $this->inbound->collecting = false;

        $html = $this->screen();

        $this->assertStringNotContainsString('Filtrer le courrier', $html);
        $this->assertStringContainsString('Aucune bo', $html);
    }

    public function testTheWholeMessageCanBeRead(): void
    {
        $html = $this->screen();

        // The screen used to show 220 characters and offer « Supprimer
        // définitivement » next to them. The whole body is still on the
        // page — it is what the dialog borrows — but it is no longer a
        // three-line paragraph in the middle of a list of decisions.
        $this->assertStringContainsString('La citerne a été remplacée cet hiver', $html);
        $this->assertStringContainsString('data-camps-message-open="camps-message-body-42"', $html);
        $this->assertStringContainsString('id="camps-message-body-42"', $html);
    }

    public function testTheBodyIsHiddenUntilTheDialogBorrowsIt(): void
    {
        // A 220-character excerpt occupied three lines per message and
        // turned a list of decisions into a wall of text. One truncated
        // line replaces it; the body sits hidden next to it.
        $html = $this->screen();

        $this->assertStringContainsString('text-truncate', $html);
        $this->assertStringContainsString('class="d-none camps-message-body"', $html);
        $this->assertStringNotContainsString('<details', $html);
    }

    public function testThePageCarriesExactlyOneDialogHoweverManyMessages(): void
    {
        // One per message would hold a hundred message bodies' worth of
        // markup on a screen that shows their subjects.
        $html = $this->screen();

        $this->assertSame(1, substr_count($html, 'id="camps-message-modal"'));
    }

    public function testAMessageOffersToBecomeACamp(): void
    {
        $html = $this->screen();

        $this->assertStringContainsString('/chefs/camps/nouveau?message=42', $html);
        $this->assertStringContainsString('Créer un camp depuis ce message', html_entity_decode($html));
    }

    public function testTheScreenStillStatesThatUnattachedMailIsRemoved(): void
    {
        // The duration itself belongs to inbound_mail now, and quoting a
        // number this module no longer owns is how a screen ends up
        // promising six months while the setting says three.
        $this->assertStringContainsString(
            'supprimé automatiquement au terme du délai de conservation',
            $this->screen()
        );
    }

    // ── attach() ────────────────────────────────────────────────────

    public function testAttachingAMessagePutsItOnTheStayAndSendsTheChiefThere(): void
    {
        $response = $this->post('attach', ['camp_id' => (string) $this->campId], ['id' => '42']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/chefs/camps/sejours/' . $this->campId, $response->getHeaders()['Location'] ?? null);
        // An association, not a move: there is no reserved reference to
        // move the message OFF any more, and the message may legitimately
        // belong to something else as well.
        $this->assertSame(
            [[CampsMessageConsumer::CONSUMER_ID, CampsMessageConsumer::referenceFor($this->campId), 42]],
            $this->inbound->attaches
        );
    }

    // ── « Relancer l'analyse » ──────────────────────────────────────────

    /**
     * The site's knowledge moves and the mail already collected does not
     * follow it: attaching one e-mail of a thread to a stay makes the rest
     * of that thread attributable, creating a place makes a farmer's
     * address start matching.
     */
    public function testTheButtonAsksThisModuleToReadItsUnattachedMailAgain(): void
    {
        $response = $this->post('reanalyze', [], []);

        $this->assertSame('/chefs/camps/courrier', $response->getHeaders()['Location'] ?? null);
        $this->assertSame([[CampsMessageConsumer::CONSUMER_ID, 100]], $this->inbound->reanalyses);
    }

    public function testARunThatFoundSomethingSaysWhat(): void
    {
        $this->inbound->reanalysisReport = ['examined' => 4, 'linked' => 1, 'proposed' => 2];

        $this->post('reanalyze', [], []);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertStringContainsString('4 messages réexaminés', (string) $flash['message']);
        $this->assertStringContainsString('1 rattachement', (string) $flash['message']);
        $this->assertStringContainsString('2 propositions', (string) $flash['message']);
    }

    public function testARunThatFoundNothingSaysThatPlainly(): void
    {
        // The ordinary outcome, and it has to read like one: a button whose
        // success message always sounds like something happened teaches
        // people to stop reading it.
        $this->inbound->reanalysisReport = ['examined' => 3, 'linked' => 0, 'proposed' => 0];

        $this->post('reanalyze', [], []);

        $this->assertStringContainsString(
            'rien de neuf',
            (string) \Core\Http\FlashMessage::get()['message']
        );
    }

    public function testNothingLeftToExamineIsItsOwnSentence(): void
    {
        $this->post('reanalyze', [], []);

        $this->assertStringContainsString(
            'déjà rattaché',
            (string) \Core\Http\FlashMessage::get()['message']
        );
    }

    public function testReanalysisWithoutACsrfTokenDoesNothing(): void
    {
        $response = $this->controller->reanalyze(
            new Request('POST', '/chefs/camps/courrier/relancer', [], [], [], []),
            []
        );

        $this->assertSame([], $this->inbound->reanalyses);
        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * A stay id nobody has must not move the message anywhere: the id
     * comes from a form field and is as forgeable as any other.
     */
    public function testAttachingToAStayThatDoesNotExistMovesNothing(): void
    {
        $response = $this->post('attach', ['camp_id' => '999999'], ['id' => '42']);

        $this->assertSame('/chefs/camps/courrier', $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->inbound->attaches);
    }

    public function testAttachingWithNoStayChosenMovesNothing(): void
    {
        $response = $this->post('attach', [], ['id' => '42']);

        $this->assertSame('/chefs/camps/courrier', $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->inbound->attaches);
    }

    public function testAttachWithoutACsrfTokenMovesNothing(): void
    {
        $request = new Request('POST', '/chefs/camps/courrier/42/rattacher', [], ['camp_id' => (string) $this->campId], [], []);

        $this->controller->attach($request, ['id' => '42']);

        $this->assertSame([], $this->inbound->attaches);
    }

    // ── discard() ───────────────────────────────────────────────────

    /**
     * `detach()` removes ONE association and destroys nothing: the message
     * falls back into the unit's general mail, where the chef d'unité can
     * still re-orient it and where inbound_mail's retention removes it.
     */
    public function testDiscardingAMessageDetachesItFromTheStayItNames(): void
    {
        $response = $this->post(
            'discard',
            ['business_reference' => CampsMessageConsumer::referenceFor($this->campId)],
            ['id' => '42']
        );

        $this->assertSame('/chefs/camps/courrier', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            [[CampsMessageConsumer::CONSUMER_ID, CampsMessageConsumer::referenceFor($this->campId), 42]],
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

    // ── « Rattacher à » : une recherche, pas tout l'historique ───────────

    /**
     * The screen no longer renders the unit's whole history in a
     * `<select>`.
     *
     * It used to render the CROSS PRODUCT of every visible place and every
     * stay it ever hosted — two hundred lines on a unit in its tenth year,
     * built with one query per place. What is left is a ranked shortlist
     * that works without JavaScript, plus a search box that reaches
     * everything.
     */
    public function testTheAttachControlOffersASearchAndNotTheWholeHistory(): void
    {
        $html = $this->screen();

        $this->assertStringContainsString('data-stay-picker', $html);
        $this->assertStringContainsString('data-stay-picker-results', $html);
        // The `<select>` is still there: it is what posts when JavaScript
        // does not run, and the script removes it on upgrade.
        $this->assertStringContainsString('name="camp_id"', $html);
    }

    public function testTheShortlistIsBoundedHoweverManyStaysTheUnitHas(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->camps->create(
                1,
                \Modules\Camps\Repository\Camp::STAY_OTHER,
                sprintf('2019-%02d-01', $i % 12 + 1),
                sprintf('2019-%02d-02', $i % 12 + 1),
                null,
                \Modules\Camps\Repository\Camp::STATUS_CONFIRMED,
                null, null, null, null, []
            );
        }

        // One `<option>` per stay plus the empty « Choisir un séjour… ».
        // The exact cap is the controller's business; what must hold is
        // that forty-one stays do not become forty-one options.
        $this->assertLessThan(30, substr_count($this->screen(), '<option'));
    }

    /**
     * The first suggestion, before a single keystroke: the stay whose
     * dates the message itself announces.
     *
     * The same reading the automatic pass uses — so the line a chief lands
     * on is the line ScoutMagic would have chosen if it had been sure.
     */
    public function testThePickerCarriesTheStayTheMessagesOwnDatesName(): void
    {
        // The suite's own message already announces the stay's dates —
        // « du 12 au 19 juillet 2028 » — which is the ordinary shape of a
        // booking confirmation and the case this exists for.
        $this->inbound->setMessages([$this->messageSaying('Séjour du 12 au 19 juillet 2028')]);

        $this->assertStringContainsString(
            'data-preferred="' . $this->campId . '"',
            $this->screen()
        );
    }

    public function testAMessageAnnouncingNothingCarriesNoPreference(): void
    {
        // A lone date is not a range and `Mail\MessageReader` refuses it,
        // so there is nothing to prefer — and the picker must open on the
        // ordinary shortlist rather than on a guess.
        $this->inbound->setMessages([
            $this->messageSaying('Merci de votre réponse du 12 juillet 2028.'),
        ]);

        $this->assertStringContainsString('data-preferred=""', $this->screen());
    }

    // ── The endpoint behind the search box ──────────────────────────────

    public function testTheSearchEndpointAnswersWithTheStaysThatMatch(): void
    {
        $body = $this->searchStays(['q' => 'mozet']);

        $this->assertTrue($body['success']);
        $this->assertSame($this->campId, $body['stays'][0]['id']);
        $this->assertStringContainsString('Domaine de Mozet', $body['stays'][0]['label']);
    }

    public function testTheSearchEndpointRanksThePreferredStayFirstAndSaysWhy(): void
    {
        $second = $this->camps->create(
            1,
            \Modules\Camps\Repository\Camp::STAY_GRAND_CAMP,
            '2029-07-12',
            '2029-07-19',
            null,
            \Modules\Camps\Repository\Camp::STATUS_CONFIRMED,
            null, null, null, null, []
        );

        $body = $this->searchStays(['q' => '', 'preferred' => (string) $second]);

        $this->assertSame($second, $body['stays'][0]['id']);
        $this->assertSame(
            \Modules\Camps\Service\StaySearchService::REASON_PREFERRED,
            $body['stays'][0]['reason']
        );
    }

    public function testTheSearchEndpointIgnoresAnythingThatIsNotAnId(): void
    {
        // These ids come from the page's own markup and are a hint about
        // ORDER, never an authorisation — but a query string is a query
        // string, and rubbish in it must rank nothing rather than reach
        // the database as itself.
        $body = $this->searchStays(['q' => '', 'preferred' => "1 OR 1=1,,abc, {$this->campId} "]);

        $this->assertTrue($body['success']);
        $this->assertSame($this->campId, $body['stays'][0]['id']);
    }

    public function testTheSearchEndpointAnswersNothingRatherThanFailingWithoutTheService(): void
    {
        $root = dirname(__DIR__, 4);
        $bare = new CampsMailController(
            TwigFactory::create($root . '/core/View/templates', false, ['camps' => $root . '/modules/camps/views']),
            $this->camps,
            new PlaceRepository($this->pdo),
            $this->inbound
        );

        $response = $bare->searchStays(new Request('GET', '/chefs/camps/courrier/sejours', [], [], [], []), []);

        $this->assertSame([], json_decode($response->getBody(), true)['stays']);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function searchStays(array $query): array
    {
        $response = $this->controller->searchStays(
            new Request('GET', '/chefs/camps/courrier/sejours', $query, [], [], []),
            []
        );

        return json_decode($response->getBody(), true);
    }

    /** The same message the suite starts from, saying something else. */
    private function messageSaying(string $body): InboundMessage
    {
        return new InboundMessage(
            id: 42,
            mailboxId: 2,
            consumerId: '',
            businessReference: '',
            linkOrigin: LinkOrigin::SENDER,
            subject: 'Confirmation de réservation',
            fromEmail: 'info@mozet.be',
            fromName: 'Domaine de Mozet',
            messageId: '<abc@mail>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-11-02 09:00:00'),
            bodyText: $body,
            bodyHtml: ''
        );
    }

}

/**
 * An InboundMailInterface that records what it was asked to do.
 *
 * A stub returning true would prove the controller redirected; what these
 * tests are about is WHICH reference a message ends up on, and what the
 * screen offers before it does.
 *
 * @internal
 */
class RecordingInboundMail implements InboundMailInterface
{
    use \Tests\Modules\InboundMail\InertInboundMail;

    /** @var array<int, array{0: string, 1: string, 2: string, 3: int}> */
    public array $moves = [];

    /** @var array<int, array{0: string, 1: string, 2: int}> */
    public array $detaches = [];

    public bool $collecting = true;

    /** @param InboundMessage[] $messages */
    public function __construct(private array $messages)
    {
    }

    /** @param InboundMessage[] $messages */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function isCollecting(): bool
    {
        return $this->collecting;
    }

    /** @var array<int, array{0: string, 1: string, 2: int}> */
    public array $attaches = [];

    /** @var array<int, array{0: string, 1: int}> */
    public array $reanalyses = [];

    /** @var array{examined: int, linked: int, proposed: int} */
    public array $reanalysisReport = ['examined' => 0, 'linked' => 0, 'proposed' => 0];

    /**
     * @return array{examined: int, linked: int, proposed: int}
     */
    public function reanalyzeUnlinked(string $consumerId, int $limit = 100): array
    {
        $this->reanalyses[] = [$consumerId, $limit];

        return $this->reanalysisReport;
    }

    /** @return InboundMessage[] */
    public function findForReference(string $consumerId, string $businessReference): array
    {
        return $this->messages;
    }

    /**
     * @param string[] $ownReferences
     * @return InboundMessage[]
     */
    public function findForTriage(string $consumerId, array $ownReferences, int $limit = 50): array
    {
        return $this->messages;
    }

    public function attach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        ?int $userAccountId = null
    ): bool {
        $this->attaches[] = [$consumerId, $businessReference, $messageId];

        return true;
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
