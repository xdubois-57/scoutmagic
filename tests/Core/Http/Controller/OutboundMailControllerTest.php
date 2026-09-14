<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\OutboundMailController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\View\TwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Configuration > Courrier sortant — the page that decides where the
 * site's own mail leaves from (ARCHITECTURE.md §8.106).
 *
 * The RBAC floor is the first thing pinned, and it is read out of
 * `public/index.php` and fed to the real `RbacGuard` rather than
 * asserted against a string this test also wrote. « Chef d'unité » is a
 * role that reaches a great deal of this site; which relay carries the
 * sign-in links is not part of it — a chief who reordered the
 * authentication chain by accident could lock the whole unit out.
 *
 * @group database
 */
class OutboundMailControllerTest extends TestCase
{
    private \PDO $pdo;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private OutboundMailController $controller;
    private string $secretsDirectory = '';
    private SettingService $settings;
    private \Core\Mail\Transport\DeferredMailRepository $deferred;
    private \Core\Mail\Transport\DeferredMailQueue $queue;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);

        $twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates');
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/config/courrier-sortant');

        $settings = new SettingService(new SettingRepository($this->pdo));
        // A real SecretManager over a scratch directory: a provider's host
        // and credentials live in secrets.enc, so saving one writes there
        // — and a null manager would leave the write paths untestable
        // rather than merely unexercised.
        $this->secretsDirectory = sys_get_temp_dir() . '/scoutmagic-outbound-ctrl-' . bin2hex(random_bytes(6));
        mkdir($this->secretsDirectory, 0700, true);
        $secretManager = new \Core\Security\SecretManager(
            $this->secretsDirectory . '/master.key',
            $this->secretsDirectory . '/secrets.enc'
        );
        $secretManager->generateMasterKey();
        $secretManager->writeSecrets([]);
        $connections = new ProviderConnections([], $secretManager);
        $counters = new SendCounterRepository($this->pdo);
        $directory = new MailProviderDirectory($this->providers, $connections, $settings);

        $this->deferred = new \Core\Mail\Transport\DeferredMailRepository(
            $this->pdo,
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->queue = new \Core\Mail\Transport\DeferredMailQueue($this->deferred, $settings);

        $this->settings = $settings;
        $this->controller = new OutboundMailController(
            $twig,
            $directory,
            $this->chains,
            $counters,
            new TransportService(
                $this->providers,
                $this->chains,
                $counters,
                $connections,
                $directory,
                new JournalService(new JournalRepository($this->pdo)),
                new \Core\Mail\Transport\ProviderHealthRepository($this->pdo)
            ),
            $settings,
            new \Core\Mail\Transport\MailReserve($counters, $this->chains),
            new \Core\Mail\Transport\ProviderHealthRepository($this->pdo),
            $this->deferred,
            $this->queue
        );

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];

        if ($this->secretsDirectory === '') {
            return;
        }
        foreach (glob($this->secretsDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->secretsDirectory);
    }

    // ── the RBAC floor ────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function outboundRoutes(): array
    {
        return [
            'the providers' => ['GET', '/config/courrier-sortant'],
            'the chains' => ['GET', '/config/courrier-sortant/acheminement'],
            'reordering a chain' => ['POST', '/config/courrier-sortant/acheminement/{lane}/ordre'],
            'enabling an entry' => ['POST', '/config/courrier-sortant/acheminement/{lane}/activation'],
            'the new-provider form' => ['GET', '/config/courrier-sortant/fournisseurs/nouveau'],
            'adding a provider' => ['POST', '/config/courrier-sortant/fournisseurs'],
            'one provider' => ['GET', '/config/courrier-sortant/fournisseurs/{id}'],
            'saving a provider' => ['POST', '/config/courrier-sortant/fournisseurs/{id}'],
            'deleting a provider' => ['POST', '/config/courrier-sortant/fournisseurs/{id}/suppression'],
            'relaunching abandoned mail' => ['POST', '/config/courrier-sortant/relance'],
        ];
    }

    #[DataProvider('outboundRoutes')]
    public function testEveryRouteIsRegisteredWithRoleMinSuperadmin(string $method, string $path): void
    {
        $this->assertSame('superadmin', self::registeredRoleMin($method, $path));
    }

    #[DataProvider('outboundRoutes')]
    public function testSuperadminIsAllowedThrough(string $method, string $path): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $this->assertNull((new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path))));
    }

    #[DataProvider('outboundRoutes')]
    public function testAdminIsRefused(string $method, string $path): void
    {
        AuthSession::login(2, 'chef-unite@test.be', 'admin');

        $response = (new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path)));

        $this->assertNotNull($response, "A chief d'unité must not reach {$method} {$path}.");
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The route table lives in a procedural bootstrap no unit test loads,
     * so it is read at source — the same technique as
     * Tests\Core\Http\Controller\EmailTemplateControllerTest.
     */
    private static function registeredRoleMin(string $method, string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents);

        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]' . $method . '[\'"]\s*,\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*,'
                . '[^;]*?OutboundMailController::class\s*,\s*[\'"][a-zA-Z]+[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]/',
            $contents,
            $m
        );

        self::assertSame(1, $matched, "No addRoute registration found for {$method} {$path}");

        return $m[1];
    }

    // ── the pages render ──────────────────────────────────────────────

    public function testTheProvidersPageAlwaysShowsTheLocalSend(): void
    {
        $response = $this->controller->providers($this->getRequest(), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(MailProvider::LOCAL_NAME, (string) $response->getBody());
    }

    public function testTheChainsPageRendersTheThreeLanes(): void
    {
        $this->chains->append(MailLane::Authentication, MailProvider::LOCAL_ID, true);

        $body = (string) $this->controller->routing($this->getRequest(), [])->getBody();

        foreach (MailLane::ordered() as $lane) {
            $this->assertStringContainsString($lane->label(), $body);
        }
    }

    // ── the refusals reach the caller ─────────────────────────────────

    /**
     * The one refusal this page exists to make: emptying the
     * authentication lane would lock everybody out of the site, the
     * person doing it included. It comes back as a 409 carrying the
     * sentence, not as a silent no-op.
     */
    public function testEmptyingTheAuthenticationLaneIsRefusedWithAnExplanation(): void
    {
        $this->chains->append(MailLane::Authentication, MailProvider::LOCAL_ID, true);

        $response = $this->controller->toggle(
            $this->jsonRequest(['id' => MailProvider::LOCAL_ID, 'active' => false]),
            ['lane' => MailLane::Authentication->value]
        );

        $this->assertSame(409, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('Authentification', $payload['error']);
        $this->assertSame(1, $this->chains->countEnabled(MailLane::Authentication), 'Nothing was changed.');
    }

    public function testAnUnknownLaneIsA404RatherThanASilentSuccess(): void
    {
        $response = $this->controller->toggle(
            $this->jsonRequest(['id' => 0, 'active' => false]),
            ['lane' => 'inventee']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAStaleCsrfTokenRefusesTheReordering(): void
    {
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'périmé';

        $response = $this->controller->reorder(
            $this->rawJsonRequest('{"ids":[0],"_csrf_token":"périmé"}'),
            ['lane' => MailLane::Bulk->value]
        );

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * A body this endpoint cannot read is a 400 and not a set of
     * defaults: an empty order applied silently, answered `success`, is
     * the failure this whole decoding path exists to prevent.
     */
    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $response = $this->controller->reorder(
            $this->rawJsonRequest('pas du json'),
            ['lane' => MailLane::Bulk->value]
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testOneProviderFormIs404ForAnIdThatDoesNotExist(): void
    {
        $this->assertSame(404, $this->controller->editForm($this->getRequest(), ['id' => '4242'])->getStatusCode());
    }

    /**
     * The local send has a form of its own — cadence only — because it
     * has no host, no credentials and, deliberately, no quota (D6).
     */
    public function testTheLocalSendHasACadenceFormAndNoQuotaField(): void
    {
        $body = (string) $this->controller->editForm($this->getRequest(), ['id' => '0'])->getBody();

        $this->assertStringContainsString('batch_interval_minutes', $body);
        $this->assertStringNotContainsString('name="daily_quota"', $body);
    }

    private function getRequest(): Request
    {
        return new Request('GET', '/config/courrier-sortant', [], [], [], []);
    }

    // ── the write paths ───────────────────────────────────────────────

    public function testAddingAProviderPutsItLastInEveryLaneAndDisabledEverywhere(): void
    {
        $response = $this->controller->create($this->formRequest([
            'name' => 'Brevo',
            'host' => 'smtp-relay.brevo.test',
            'port' => '587',
            'username' => 'unite@exemple.be',
            'password' => 'secret',
            'daily_quota' => '300',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());

        $providers = $this->providers->findAll();
        $this->assertCount(1, $providers);
        $this->assertSame('Brevo', $providers[0]['name']);
        $this->assertSame(300, $providers[0]['daily_quota']);

        foreach (MailLane::ordered() as $lane) {
            $entries = $this->chains->forLane($lane);
            $this->assertCount(1, $entries);
            $this->assertFalse(
                $entries[0]->enabled,
                'A relay that started carrying the sign-in links the moment it was saved would be a '
                    . 'routing decision nobody took.'
            );
        }
    }

    /**
     * An empty quota field means « no known daily ceiling », which is
     * null — never zero, which would read as « this relay accepts
     * nothing » and step every lane past it for ever.
     */
    public function testAnEmptyQuotaFieldStoresNoCeilingRatherThanZero(): void
    {
        $this->controller->create($this->formRequest([
            'name' => 'Sans quota',
            'host' => 'smtp.exemple.test',
            'port' => '587',
            'daily_quota' => '',
        ]), []);

        $this->assertNull($this->providers->findAll()[0]['daily_quota']);
    }

    public function testANamelessProviderIsRefusedWithAnExplanation(): void
    {
        $response = $this->controller->create($this->formRequest([
            'name' => '   ',
            'host' => 'smtp.exemple.test',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->providers->findAll(), 'Nothing was stored.');
    }

    public function testAProviderWithNoHostIsRefused(): void
    {
        $this->controller->create($this->formRequest(['name' => 'Sans hôte', 'host' => '']), []);

        $this->assertSame([], $this->providers->findAll());
    }

    public function testSavingAProviderKeepsItsCadenceAndQuota(): void
    {
        $id = $this->providers->create('Ancien nom', 300, 50, 10);

        $this->controller->update($this->formRequest([
            'name' => 'Nouveau nom',
            'host' => 'smtp.exemple.test',
            'port' => '465',
            'daily_quota' => '500',
            'batch_size' => '100',
            'batch_interval_minutes' => '2',
        ]), ['id' => (string) $id]);

        $row = $this->providers->findById($id);
        $this->assertNotNull($row);
        $this->assertSame('Nouveau nom', $row['name']);
        $this->assertSame(500, $row['daily_quota']);
        $this->assertSame(100, $row['batch_size']);
        $this->assertSame(2, $row['batch_interval_minutes']);
    }

    public function testSavingAProviderThatIsGoneIsRefusedRatherThanRecreatingIt(): void
    {
        $response = $this->controller->update(
            $this->formRequest(['name' => 'Fantôme', 'host' => 'smtp.exemple.test']),
            ['id' => '4242']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->providers->findAll());
    }

    public function testDeletingAProviderRemovesItFromEveryLane(): void
    {
        $id = $this->providers->create('À supprimer', null, 50, 10);
        foreach (MailLane::ordered() as $lane) {
            $this->chains->append($lane, MailProvider::LOCAL_ID, true);
            $this->chains->append($lane, $id, true);
        }

        $this->controller->delete($this->formRequest([]), ['id' => (string) $id]);

        $this->assertNull($this->providers->findById($id));
        foreach (MailLane::ordered() as $lane) {
            $this->assertFalse($this->chains->exists($lane, $id));
        }
    }

    public function testReorderingALaneIsPersisted(): void
    {
        $first = $this->providers->create('Premier', null, 50, 10);
        $this->chains->append(MailLane::Bulk, $first, true);
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);

        $response = $this->controller->reorder(
            $this->jsonRequest(['ids' => [MailProvider::LOCAL_ID, $first]]),
            ['lane' => MailLane::Bulk->value]
        );

        $this->assertSame(200, $response->getStatusCode());
        $order = array_map(
            static fn($entry): int => $entry->providerId,
            $this->chains->forLane(MailLane::Bulk)
        );
        $this->assertSame([MailProvider::LOCAL_ID, $first], $order);
    }

    public function testReorderingRefusesABodyThatIsNotAList(): void
    {
        $response = $this->controller->reorder(
            $this->jsonRequest(['ids' => 'pas-une-liste']),
            ['lane' => MailLane::Bulk->value]
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAnEntryIsEnabledWhenAnotherKeepsTheLaneAlive(): void
    {
        $relay = $this->providers->create('Relais', null, 50, 10);
        $this->chains->append(MailLane::Bulk, MailProvider::LOCAL_ID, true);
        $this->chains->append(MailLane::Bulk, $relay, false);

        $response = $this->controller->toggle(
            $this->jsonRequest(['id' => $relay, 'active' => true]),
            ['lane' => MailLane::Bulk->value]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $this->chains->countEnabled(MailLane::Bulk));
    }

    public function testTogglingAnEntryThatIsNotInThatLaneIsRefused(): void
    {
        $response = $this->controller->toggle(
            $this->jsonRequest(['id' => 4242, 'active' => true]),
            ['lane' => MailLane::Bulk->value]
        );

        $this->assertSame(409, $response->getStatusCode());
    }

    /**
     * The local send has no row, so « saving » it writes the two settings
     * that carry its cadence — and nothing else. It has no host, no
     * credentials and, deliberately, no quota (D6).
     */
    public function testSavingTheLocalSendWritesItsCadenceAndNothingElse(): void
    {
        $this->registerLocalCadenceSettings();

        $response = $this->controller->update(
            $this->formRequest(['batch_size' => '4', 'batch_interval_minutes' => '30']),
            ['id' => (string) MailProvider::LOCAL_ID]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('4', $this->settings->get(MailProviderDirectory::SETTING_LOCAL_BATCH_SIZE));
        $this->assertSame('30', $this->settings->get(MailProviderDirectory::SETTING_LOCAL_BATCH_INTERVAL));
        $this->assertSame([], $this->providers->findAll(), 'The local send never becomes a row.');
    }

    /**
     * Zero messages per lot would stop the mailing lane outright, and a
     * zero interval would busy-loop it. Both are floored at one rather
     * than refused: the field is a cadence, not a switch.
     */
    public function testTheLocalCadenceIsFlooredRatherThanAcceptedAtZero(): void
    {
        $this->registerLocalCadenceSettings();

        $this->controller->update(
            $this->formRequest(['batch_size' => '0', 'batch_interval_minutes' => '0']),
            ['id' => (string) MailProvider::LOCAL_ID]
        );

        $this->assertSame('1', $this->settings->get(MailProviderDirectory::SETTING_LOCAL_BATCH_SIZE));
        $this->assertSame('1', $this->settings->get(MailProviderDirectory::SETTING_LOCAL_BATCH_INTERVAL));
    }

    /**
     * A setting that vanished under the form must not 500 the page: the
     * exception is user-facing by construction, so its own sentence is
     * what the person reads.
     */
    public function testSavingTheLocalCadenceWithNoSettingDeclaredIsARedirectRatherThanACrash(): void
    {
        $response = $this->controller->update(
            $this->formRequest(['batch_size' => '4', 'batch_interval_minutes' => '30']),
            ['id' => (string) MailProvider::LOCAL_ID]
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    private function registerLocalCadenceSettings(): void
    {
        $this->settings->register(
            MailProviderDirectory::SETTING_LOCAL_BATCH_SIZE,
            (string) MailProviderDirectory::DEFAULT_LOCAL_BATCH_SIZE,
            'number',
            'Envoi local — messages par lot',
            'Test'
        );
        $this->settings->register(
            MailProviderDirectory::SETTING_LOCAL_BATCH_INTERVAL,
            (string) MailProviderDirectory::DEFAULT_LOCAL_BATCH_INTERVAL,
            'number',
            'Envoi local — minutes entre deux lots',
            'Test'
        );
    }

    // ── la file et sa relance (D9, D17) ───────────────────────────────

    /** « Un report n'est pas un silence » : la page le dit. */
    public function testThePageShowsWhatIsWaiting(): void
    {
        $this->queueOne(MailLane::Transactional);
        $this->queueOne(MailLane::Bulk);

        $body = (string) $this->controller->providers($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Messages différés', $body);
        $this->assertStringContainsString('1 en attente', $body);
        $this->assertStringContainsString('jamais différée', $body, 'The authentication lane says why it is at zero.');
    }

    /**
     * The queue holds a recipient and a subject; the page is a screen a
     * superadmin looks at, and neither belongs on it (SECURITY.md §11).
     */
    public function testThePageNamesNoRecipient(): void
    {
        $this->queueOne(MailLane::Transactional);

        $body = (string) $this->controller->providers($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('parent@exemple.test', $body);
        $this->assertStringNotContainsString('Reçu de paiement', $body);
    }

    /**
     * **The window is what makes this button safe (D17).** The form's own
     * default carries this morning's failures and leaves last week's
     * where they are: a relaunch that quietly re-sent a fortnight of
     * messages would be used once and never again.
     *
     * No window is named in the request, on purpose — that is what a
     * volunteer who submits the form without touching the selector
     * sends, and the only way to exercise the default the screen shows.
     */
    public function testTheDefaultWindowLeavesTheOldFailuresAlone(): void
    {
        $recent = $this->abandonOne(MailLane::Transactional, date('Y-m-d H:i:s', time() - 3600));
        $old = $this->abandonOne(MailLane::Transactional, date('Y-m-d H:i:s', time() - 8 * 86400));

        $this->controller->relaunch($this->formRequest(['lanes' => ['transactional']]), []);

        $this->assertSame([$recent], $this->pendingIds());
        $this->assertSame([$old], $this->deferred->abandonedIds());
    }

    /** Relaunching the newsletters and the receipts are different decisions. */
    public function testTheLaneChoiceIsRespected(): void
    {
        $transactional = $this->abandonOne(MailLane::Transactional, date('Y-m-d H:i:s', time() - 3600));
        $bulk = $this->abandonOne(MailLane::Bulk, date('Y-m-d H:i:s', time() - 3600));

        $this->controller->relaunch(
            $this->formRequest(['lanes' => ['transactional'], 'window' => 'day']),
            []
        );

        $this->assertSame([$transactional], $this->pendingIds());
        $this->assertSame([$bulk], $this->deferred->abandonedIds());
    }

    /**
     * A form naming the authentication lane relaunches nothing, because
     * that lane never queued anything (D9) — and the screen offers no
     * such box, so a request carrying one did not come from the screen.
     */
    public function testTheAuthenticationLaneCannotBeRelaunched(): void
    {
        $response = $this->controller->relaunch(
            $this->formRequest(['lanes' => ['authentication'], 'window' => 'day']),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->pendingIds());
    }

    public function testRelaunchingWithNoLaneChosenDoesNothing(): void
    {
        $this->abandonOne(MailLane::Bulk, date('Y-m-d H:i:s', time() - 3600));

        $this->controller->relaunch($this->formRequest(['window' => 'day']), []);

        $this->assertSame([], $this->pendingIds());
    }

    /**
     * The reserve is the one figure on these screens that looks
     * arbitrary, so Acheminement prints where it came from — under the
     * lane it exists FOR, not the one it is subtracted from.
     */
    public function testTheReserveIsShownUnderTheAuthenticationLaneWithItsProvenance(): void
    {
        $relay = $this->providers->create('Relais', 1000, 50, 10);
        $this->chains->append(MailLane::Authentication, $relay, true);
        $this->chains->append(MailLane::Bulk, $relay, true);

        $body = (string) $this->controller->routing($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Relais', $body);
        $this->assertStringContainsString('le minimum retenu tant que ce site', $body);
    }

    /**
     * @return array<int, int>
     */
    private function pendingIds(): array
    {
        $ids = [];
        foreach ($this->deferred->due(100, '2099-01-01 00:00:00') as $message) {
            $ids[] = $message->id;
        }

        return $ids;
    }

    private function queueOne(MailLane $lane): int
    {
        return $this->deferred->add(
            $lane,
            \Core\Mail\MailPurpose::Ordinary,
            [
                'to' => 'parent@exemple.test',
                'subject' => 'Reçu de paiement',
                'bodyHtml' => '<p>Bonjour</p>',
                'bodyText' => 'Bonjour',
                'replyTo' => null,
                'fromAddressOverride' => null,
                'fromNameOverride' => null,
                'extraHeaders' => [],
                'attachments' => [],
            ],
            'quota épuisé',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600)
        );
    }

    /**
     * One abandoned message, given up on at `$settledAt`.
     *
     * `created_at` a day earlier, because that is what a real row looks
     * like — nothing is abandoned before its deadline has passed — and
     * because the relaunch window is measured from the moment the site
     * gave up, not from the moment the message was written.
     */
    private function abandonOne(MailLane $lane, string $settledAt): int
    {
        $id = $this->queueOne($lane);
        $this->deferred->abandon($id, 3, 'délai de vie dépassé', $settledAt);
        $this->pdo->prepare('UPDATE mail_deferred_messages SET created_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', strtotime($settledAt) - 86400), $id]);

        return $id;
    }

    /**
     * A form POST, with the CSRF token the guard will look for.
     *
     * @param array<string, mixed> $body
     */
    private function formRequest(array $body): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request(
            'POST',
            '/config/courrier-sortant',
            [],
            $body + ['_csrf_token' => $token],
            [],
            []
        );
    }

    /**
     * The same double as jsonRequest(), with the raw body written out by
     * the caller — for the cases where the body is deliberately malformed
     * or carries a token the guard must refuse.
     */
    private function rawJsonRequest(string $raw): Request
    {
        return new class ('POST', '/config/courrier-sortant', [], [], [], [], $raw) extends Request {
            /**
             * @param array<string, mixed> $query
             * @param array<string, mixed> $body
             * @param array<string, mixed> $cookies
             * @param array<string, mixed> $server
             */
            public function __construct(
                string $method,
                string $path,
                array $query,
                array $body,
                array $cookies,
                array $server,
                private string $raw
            ) {
                parent::__construct($method, $path, $query, $body, $cookies, $server);
            }

            public function getRawBody(): string
            {
                return $this->raw;
            }
        };
    }

    /**
     * A request shaped like the one this screen's JavaScript really sends.
     *
     * **The empty `body` array is the point of this helper**, and an
     * earlier version of it got that wrong. `public/assets/js/
     * list-editor.js` posts through `ScoutMagicApi.postJson()`, which
     * sends `Content-Type: application/json` — and PHP never populates
     * `$_POST` for a JSON body, so the real `Request::fromGlobals()`
     * builds `body` from an empty array and everything travels in the raw
     * body instead. A helper that pre-filled `body` bypassed that
     * distinction entirely and reported a controller reading `$_POST` as
     * working, while on the real screen no provider could ever be enabled
     * in a lane.
     *
     * `getRawBody()` reads `php://input`, which no unit test can write,
     * so it is overridden here — the one thing this double does.
     *
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $raw = (string) json_encode($body + ['_csrf_token' => $token]);

        return new class ('POST', '/config/courrier-sortant', [], [], [], [], $raw) extends Request {
            /**
             * @param array<string, mixed> $query
             * @param array<string, mixed> $body
             * @param array<string, mixed> $cookies
             * @param array<string, mixed> $server
             */
            public function __construct(
                string $method,
                string $path,
                array $query,
                array $body,
                array $cookies,
                array $server,
                private string $raw
            ) {
                parent::__construct($method, $path, $query, $body, $cookies, $server);
            }

            public function getRawBody(): string
            {
                return $this->raw;
            }
        };
    }
}
