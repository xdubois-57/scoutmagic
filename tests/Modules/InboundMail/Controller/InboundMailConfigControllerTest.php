<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Api\ReadMode;
use Modules\InboundMail\Controller\InboundMailConfigController;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\MailboxAdminService;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxScopeService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The screen where a superadmin says what each module may do with each box.
 *
 * `MailboxScopeTest` pins the rules; this pins the screen that carries
 * them — above all the two things a screen can get wrong on its own: what
 * it renders about a box, and which half of a form it believes.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InboundMailConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private InboundMailboxRepository $mailboxes;
    private MailboxScopeService $scopes;
    private MessageConsumerRegistry $registry;
    private InboundMailConfigController $controller;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->registry->register(new FakeMessageConsumer('rental'));
        $this->registry->register(new FakeMessageConsumer('camps'));
        $this->scopes = new MailboxScopeService($this->mailboxes, $this->registry);

        $adminService = new MailboxAdminService(
            $this->mailboxes,
            new MailboxClientFactory(),
            new MailboxErrorFormatter(),
            new InboundMessageRepository($this->pdo, $encryption),
            new SettingService(new SettingRepository($this->pdo))
        );

        $this->controller = new InboundMailConfigController(
            $this->twig(),
            $adminService,
            new JournalService(new JournalRepository($this->pdo)),
            $this->scopes,
            $this->registry
        );

        $this->mailboxId = $this->mailboxes->create(
            'Boîte des locations',
            ProviderType::IMAP,
            'imap.test',
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
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // ── What the screen shows ───────────────────────────────────────────

    public function testTheListNamesEachBoxAndNeverItsCredentials(): void
    {
        // The journal rule applies to the screen too: a host is half a
        // credential, and the page renders Mailbox objects precisely
        // because they carry no password at all (§7.4).
        $body = $this->controller->index($this->get('/config/courrier-entrant'), [])->getBody();

        $this->assertStringContainsString('Boîte des locations', $body);
        $this->assertStringNotContainsString('secret', $body);
    }

    public function testTheScopeFormOffersEveryRegisteredModuleAndEveryReadMode(): void
    {
        $body = $this->controller->editScopes($this->get($this->scopeUrl()), $this->id())->getBody();

        foreach ($this->registry->all() as $consumer) {
            $this->assertStringContainsString($consumer->consumerId(), $body);
        }
        foreach (ReadMode::cases() as $mode) {
            $this->assertStringContainsString($mode->label(), $body);
        }
    }

    public function testABoxThatDoesNotExistIsNotFound(): void
    {
        $this->assertSame(
            404,
            $this->controller->editScopes($this->get('/x'), ['id' => '9999'])->getStatusCode()
        );
    }

    // ── Which half of the form the server believes ──────────────────────

    public function testASharedBoxIsSavedFromTheSharedHalfOfTheForm(): void
    {
        $response = $this->post([
            'purpose' => MailboxPurpose::SHARED->value,
            // Submitted by the page even though it is the hidden half: the
            // server picks, because the server is the only place the choice
            // can be enforced.
            'dedicated_to' => 'camps',
            'scope' => ['rental' => ['analyze' => '1', 'read' => ReadMode::RELEVANT->value]],
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $scope = $this->scopes->scopeFor($this->mailbox(), 'rental');
        $this->assertTrue($scope->analyzes);
        $this->assertSame(ReadMode::RELEVANT, $scope->readMode);
        $this->assertFalse($this->mailbox()->isDedicated());
    }

    public function testADedicatedBoxIsSavedFromTheOtherHalfAndNamesItsModule(): void
    {
        $response = $this->post([
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'camps',
            'scope' => ['rental' => ['analyze' => '1', 'read' => ReadMode::ALL->value]],
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $mailbox = $this->mailbox();
        $this->assertTrue($mailbox->isDedicated());
        $this->assertSame('camps', $mailbox->dedicatedTo);
    }

    public function testADedicatedBoxWithNoModuleNamedIsRefusedRatherThanSavedEmpty(): void
    {
        // Saving it would leave a box dedicated to nobody, which reads on
        // every later screen as "dedicated" while behaving as nothing.
        $response = $this->post([
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => '',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->mailbox()->isDedicated());
    }

    public function testAModuleNobodyRegisteredCannotBeDedicatedTo(): void
    {
        $response = $this->post([
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'module-inexistant',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->mailbox()->isDedicated());
    }

    public function testSavingWithoutAValidTokenChangesNothing(): void
    {
        $request = new Request('POST', $this->scopeUrl(), [], [
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'camps',
            '_csrf_token' => 'pas-le-bon',
        ], [], []);

        $this->controller->saveScopes($request, $this->id());

        $this->assertFalse($this->mailbox()->isDedicated());
    }

    public function testTheJournalNamesTheBoxAndItsPurposeAndNeitherAccountNorHost(): void
    {
        $this->post([
            'purpose' => MailboxPurpose::DEDICATED->value,
            'dedicated_to' => 'camps',
        ]);

        $entry = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'inbound_mailbox_scopes_saved'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($entry);
        $row = json_encode($entry, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($row);

        $this->assertStringContainsString('locations', mb_strtolower($row));
        $this->assertStringContainsString('dedicated', $row);
        $this->assertStringNotContainsString('contact@unite.be', $row);
        $this->assertStringNotContainsString('imap.test', $row);
    }

    // ── « Rafraîchir maintenant » ───────────────────────────────────────

    public function testTheRefreshButtonIsNotOfferedWhenNothingCanRunIt(): void
    {
        // Not offered rather than offered and inert: a button that does
        // nothing is worse than no button.
        $body = $this->controller->index($this->get('/config/courrier-entrant'), [])->getBody();

        $this->assertStringNotContainsString('Rafraîchir maintenant', $body);
    }

    public function testAskingForARefreshWithNoServiceBehindItIsNotFound(): void
    {
        $request = new Request('POST', '/config/courrier-entrant/rafraichir', [], [
            '_csrf_token' => $this->token(),
        ], [], []);

        $this->assertSame(404, $this->controller->refreshNow($request, [])->getStatusCode());
    }

    // ── Harness ─────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function id(): array
    {
        return ['id' => (string) $this->mailboxId];
    }

    private function scopeUrl(): string
    {
        return '/config/courrier-entrant/boites/' . $this->mailboxId . '/portee';
    }

    private function mailbox(): \Modules\InboundMail\Mailbox\Mailbox
    {
        $mailbox = $this->mailboxes->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        return $mailbox;
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], [], []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): \Core\Http\Response
    {
        $body['_csrf_token'] = $this->token();

        return $this->controller->saveScopes(
            new Request('POST', $this->scopeUrl(), [], $body, [], []),
            $this->id()
        );
    }

    private function token(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
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
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', true);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig;
    }
}
