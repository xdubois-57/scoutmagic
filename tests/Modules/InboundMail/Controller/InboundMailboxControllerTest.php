<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Controller;

use Core\File\FileRepository;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Controller\InboundMailboxController;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\GeneralMailboxService;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * `/courrier`, the screen itself.
 *
 * `GeneralMailboxTest` pins the paging and the filters. This pins what the
 * screen adds on top of them: that a filter arriving from a query string
 * nobody controls cannot widen what is shown, that a gesture without a
 * valid token changes nothing, and that the journal records these gestures
 * by internal id alone — this is the archive of everything the unit was
 * ever sent, and a journal that quoted it would be a second copy without a
 * retention (§7.9, §8.58).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InboundMailboxControllerTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private GeneralMailboxService $mailbox;
    private InboundMailboxController $controller;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $registry = new MessageConsumerRegistry();
        $registry->register(new FakeMessageConsumer('rental'));

        $this->mailbox = new GeneralMailboxService($this->messages, $mailboxes, $registry);

        $this->controller = new InboundMailboxController(
            $this->twig(),
            $this->mailbox,
            new InboundMailService($this->messages, $mailboxes, new FileRepository($this->pdo), $registry),
            $registry,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->mailboxId = $mailboxes->create(
            'Boîte des locations',
            ProviderType::FAKE,
            'h',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            [],
            true
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(7, 'chef@unite.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // ── The list ────────────────────────────────────────────────────────

    public function testTheListShowsWhatNothingPointsAtByDefault(): void
    {
        $bare = $this->store('bare@x');
        $linked = $this->store('linked@x');
        $this->messages->addLink($linked, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $body = $this->controller->index($this->get('/courrier'), [])->getBody();

        $this->assertStringContainsString('/courrier/' . $bare, $body);
        $this->assertStringNotContainsString('/courrier/' . $linked, $body);
    }

    public function testAnAssociationFilterNobodyRecognisesFallsBackToTheNarrowestOne(): void
    {
        // The value arrives in a query string, so it is whatever the caller
        // typed. Falling back to 'none' keeps an unrecognised word from
        // widening the view rather than narrowing it.
        $linked = $this->store('linked@x');
        $this->messages->addLink($linked, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $body = $this->controller->index($this->get('/courrier?association=tout-montrer'), [])->getBody();

        $this->assertStringNotContainsString('/courrier/' . $linked, $body);
    }

    public function testBulkMailIsOutOfTheListUntilItIsAskedFor(): void
    {
        $bulk = $this->store('bulk@x', isBulk: true);

        $this->assertStringNotContainsString(
            '/courrier/' . $bulk,
            $this->controller->index($this->get('/courrier'), [])->getBody()
        );
        $this->assertStringContainsString(
            '/courrier/' . $bulk,
            $this->controller->index($this->get('/courrier?automatique=1'), [])->getBody()
        );
    }

    // ── One message ─────────────────────────────────────────────────────

    public function testAMessageThatDoesNotExistIsNotFound(): void
    {
        $this->assertSame(404, $this->controller->show($this->get('/courrier/9999'), ['id' => '9999'])->getStatusCode());
    }

    public function testTheMessagePageShowsItsPropositions(): void
    {
        $id = $this->store('m@x');
        $this->addCandidate($id);

        $body = $this->controller->show($this->get('/courrier/' . $id), ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('La Grange', $body);
    }

    /**
     * The same page, rendered by a controller whose one consumer names its
     * references with $naming — the seam the badge reads through.
     */
    private function showWithConsumerNaming(int $messageId, \Closure $naming): string
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $registry = new MessageConsumerRegistry();
        $registry->register(new FakeMessageConsumer('rental', null, null, true, false, false, $naming));

        $controller = new InboundMailboxController(
            $this->twig(),
            new GeneralMailboxService($this->messages, $mailboxes, $registry),
            new InboundMailService($this->messages, $mailboxes, new FileRepository($this->pdo), $registry),
            $registry,
            new JournalService(new JournalRepository($this->pdo))
        );

        return $controller->show($this->get('/courrier/' . $messageId), ['id' => (string) $messageId])->getBody();
    }

    // ── Ce que le badge affiche ─────────────────────────────────────────

    /**
     * The screens fall back to the raw business reference, and for half
     * the consumers that is a slug: « account-unknown » on a green badge
     * teaches a Chef d'Unité nothing, in a language that is not theirs.
     * `inbound_mail` cannot do better on its own, so the consumer names
     * its own object (Api\MessageConsumerInterface::describeReference()).
     */
    public function testALinkShowsTheNameItsConsumerGivesItsReference(): void
    {
        $id = $this->store('m@x');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE, 0, null);

        $body = $this->showWithConsumerNaming($id, static fn(string $r): string => 'compte inconnu');

        $this->assertStringContainsString('compte inconnu', $body);
        $this->assertStringNotContainsString('— LOC-1', $body);
    }

    public function testAReferenceItsConsumerCannotNameIsShownAsItStands(): void
    {
        // « LOC-2027-0012 » is already the name a manager uses out loud;
        // null must not blank the badge.
        $id = $this->store('m@x');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE, 0, null);

        $body = $this->controller->show($this->get('/courrier/' . $id), ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('LOC-1', $body);
    }

    public function testAConsumerThatThrowsWhileNamingDoesNotTakeThePageDown(): void
    {
        $id = $this->store('m@x');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE, 0, null);

        $body = $this->showWithConsumerNaming($id, static function (string $r): string {
            throw new \RuntimeException('boom');
        });

        $this->assertStringContainsString('LOC-1', $body);
    }

    // ── Gestures ────────────────────────────────────────────────────────

    public function testConfirmingAPropositionAssociatesTheMessage(): void
    {
        $id = $this->store('m@x');
        $this->addCandidate($id);
        $candidate = $this->mailbox->candidatesFor($id)[0];

        $response = $this->post('confirmCandidate', $id, ['candidate_id' => (string) $candidate->id]);

        $this->assertSame(302, $response->getStatusCode());
        $links = $this->messages->findLinksForMessage($id);
        $this->assertCount(1, $links);
        $this->assertSame('LOC-1', $links[0]->businessReference);
    }

    public function testConfirmingAPropositionThatIsGoneChangesNothing(): void
    {
        $id = $this->store('m@x');

        $response = $this->post('confirmCandidate', $id, ['candidate_id' => '4242']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->messages->findLinksForMessage($id));
    }

    public function testDismissingAPropositionLeavesTheMessageUnassociated(): void
    {
        $id = $this->store('m@x');
        $this->addCandidate($id);
        $candidate = $this->mailbox->candidatesFor($id)[0];

        $this->post('dismissCandidate', $id, ['candidate_id' => (string) $candidate->id]);

        $this->assertSame([], $this->mailbox->candidatesFor($id));
        $this->assertSame([], $this->messages->findLinksForMessage($id));
    }

    public function testDetachingRemovesTheAssociationAndKeepsTheMessage(): void
    {
        // Detaching is almost always a correction, and destroying what is
        // being corrected makes re-filing it impossible (§8.58).
        $id = $this->store('m@x');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $this->post('detach', $id, ['consumer_id' => 'rental', 'business_reference' => 'LOC-1']);

        $this->assertSame([], $this->messages->findLinksForMessage($id));
        $this->assertNotNull($this->mailbox->find($id), 'the message stays in the unit\'s mail');
    }

    public function testAGestureWithoutAValidTokenChangesNothing(): void
    {
        $id = $this->store('m@x');
        $this->messages->addLink($id, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $request = new Request('POST', '/courrier/' . $id . '/detacher', [], [
            'consumer_id' => 'rental',
            'business_reference' => 'LOC-1',
            '_csrf_token' => 'pas-le-bon',
        ], [], []);
        $this->controller->detach($request, ['id' => (string) $id]);

        $this->assertCount(1, $this->messages->findLinksForMessage($id));
    }

    // ── The journal ─────────────────────────────────────────────────────

    public function testTheJournalRecordsTheGestureByIdAndQuotesNothing(): void
    {
        // This screen is the archive of everything the unit was ever sent.
        // A journal that named a sender or a subject would be a second copy
        // of it, and one with no retention at all.
        $id = $this->store('m@x');
        $this->addCandidate($id);
        $candidate = $this->mailbox->candidatesFor($id)[0];

        $this->post('confirmCandidate', $id, ['candidate_id' => (string) $candidate->id]);

        $entry = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'inbound_candidate_confirmed'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($entry);
        $row = json_encode($entry, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($row);

        $this->assertStringContainsString((string) $id, $row);
        $this->assertStringNotContainsString('jeanne@example.be', $row);
        $this->assertStringNotContainsString('Sujet', $row);
        $this->assertStringNotContainsString('La Grange', $row);
    }

    // ── Harness ─────────────────────────────────────────────────────────

    private function get(string $path): Request
    {
        $query = [];
        if (str_contains($path, '?')) {
            parse_str(explode('?', $path, 2)[1], $query);
        }

        return new Request('GET', explode('?', $path, 2)[0], $query, [], [], []);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $action, int $messageId, array $body): \Core\Http\Response
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $body['_csrf_token'] = $token;

        return $this->controller->{$action}(
            new Request('POST', '/courrier/' . $messageId, [], $body, [], []),
            ['id' => (string) $messageId]
        );
    }

    private function store(string $messageId, bool $isBulk = false): int
    {
        static $uid = 100;

        return $this->messages->create(
            mailboxId: $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: null,
            subject: 'Sujet',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00'),
            isBulk: $isBulk
        );
    }

    private function addCandidate(int $messageId): void
    {
        $this->messages->addCandidate($messageId, 'rental', new MessageCandidate(
            businessReference: 'LOC-1',
            label: 'La Grange — 12 au 15 septembre',
            evidenceType: 'sender_window',
            explanation: "L'expéditeur est le locataire, et le message est arrivé pendant son séjour."
        ));
    }

    private function twig(): Environment
    {
        $root = dirname(__DIR__, 4);
        $loader = new FilesystemLoader($root . '/core/View/templates');
        $loader->addPath($root . '/modules/inbound_mail/views', 'inbound_mail');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);

        $twig->addFunction(new TwigFunction('asset', static fn(string $path): string => $path));
        $twig->addFunction(new TwigFunction(
            'csrf_field',
            static fn(): string => '<input type="hidden" name="_csrf_token" value="test">',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(): string => 'test'));
        $twig->addFunction(new TwigFunction('get_flash', static fn() => null));
        $twig->addFunction(new TwigFunction('file_url', static fn(): string => ''));
        $twig->addFilter(new \Twig\TwigFilter('datetime_fr', static function (mixed $value): string {
            if ($value === null || $value === '') {
                return '';
            }
            $date = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);

            return $date->format('d/m/Y à H:i');
        }));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig;
    }
}
