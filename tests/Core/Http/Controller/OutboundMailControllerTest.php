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
#[\PHPUnit\Framework\Attributes\Group('database')]
class OutboundMailControllerTest extends TestCase
{
    private \PDO $pdo;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private \Core\Mail\DkimManager $dkim;
    private \Core\Mail\Probe\MailProbeRepository $mailProbes;
    private \Core\Mail\Feedback\Bounce\BounceStateRepository $bounceStates;
    private \Core\Mail\Feedback\Dmarc\DmarcReportRepository $dmarcReports;
    private \Core\Mail\Feedback\Seed\SeedMailboxes $seedMailboxes;
    private \Core\Mail\Feedback\Seed\SeedCopyRepository $seedCopies;
    private OutboundMailController $controller;
    /** @var list<mixed> the arguments $controller was built from */
    private array $controllerArguments = [];
    private \Core\Mail\Transport\DomainPreferences $mailPreferences;
    private string $secretsDirectory = '';
    private SettingService $settings;
    private \Core\Mail\Transport\DeferredMailRepository $deferred;
    private \Core\Mail\Transport\DeferredMailQueue $queue;
    private \Core\Mail\Feedback\ReturnPathVerifier $returns;

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

        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->deferred = new \Core\Mail\Transport\DeferredMailRepository($this->pdo, $encryption);
        $this->queue = new \Core\Mail\Transport\DeferredMailQueue($this->deferred, $settings);

        $this->settings = $settings;
        self::registerMailIdentitySettings($settings);
        $settings->register(
            \Core\Mail\Feedback\Seed\SeedMailboxes::SETTING_ENABLED,
            '0',
            'boolean',
            'Copies témoins',
            '',
            null,
            null,
            null,
            false,
            58
        );
        $settings->register(
            \Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC,
            '0',
            'boolean',
            'Routage automatique',
            '',
            null,
            null,
            null,
            false,
            59
        );
        $settings->register(
            \Core\Mail\Transport\DomainPreferences::SETTING_KEY,
            '',
            'text',
            'Routage par domaine',
            '',
            null,
            null,
            null,
            false,
            60
        );
        $this->mailPreferences = new \Core\Mail\Transport\DomainPreferences($settings);
        // Kept as a list rather than spent on the spot, because the two
        // probe arguments are optional and the sub-page has a whole
        // branch for an installation that did not build them. Dropping
        // the last two is that installation, exactly — not a double
        // standing in for it.
        $this->controllerArguments = [
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
            $this->queue,
            $this->dkim = new \Core\Mail\DkimManager($this->secretsDirectory),
            // A FAKE, never the real verifier: `checkSpfForHosts()` calls
            // `dns_get_record()`, so a test that exercises the lookup
            // would reach out to a real resolver — the suite would then
            // depend on the network, and hang for as long as an
            // unanswering resolver takes.
            self::fakeDnsVerifier($this->dkim),
            $this->returns = new \Core\Mail\Feedback\ReturnPathVerifier(
                new \Core\Mail\Feedback\ReturnProbeRepository($this->pdo, $encryption),
                $this->createMock(\Core\Mail\MailService::class),
                new JournalService(new JournalRepository($this->pdo)),
                // No `inbound_mail` here: the default installation this
                // test builds has no module at all, so the verification
                // answers « impossible » — which is the state the page
                // has to render without erroring (D2).
                null
            ),
            new JournalService(new JournalRepository($this->pdo)),
            // A REAL probe sender, not null. With null every probe action
            // returns « la sonde n'est pas disponible » before touching
            // anything — and a test asserting « no row was written » then
            // passes for the wrong reason, whatever the code does. That
            // is how a guard against sending through the wrong relay came
            // to be verified by a page that never sends at all.
            new \Core\Mail\Probe\MailProbeSender(
                new \Core\Mail\MailService(
                    'local',
                    'info@unite.be',
                    'Unité Test',
                    'EX',
                    $this->dkim,
                    's2026',
                    transport: $probeTransport = new class implements \Core\Mail\MailTransportInterface {
                        public function deliver(
                            \PHPMailer\PHPMailer\PHPMailer $mail,
                            \Core\Mail\MailPurpose $purpose
                        ): void {
                            $mail->preSend();
                        }
                    }
                ),
                $directory,
                new \Core\Mail\Transport\TransportConfigurator($connections),
                $probeTransport,
                $this->mailProbes = new \Core\Mail\Probe\MailProbeRepository($this->pdo, $encryption),
                $twig,
                // The send receipts. A probe stamps its own, because its
                // destination is never an address the site holds on file —
                // and without one, its own bounce is refused and #419's
                // tracing never runs.
                new \Core\Mail\Feedback\Bounce\BounceStateRepository($this->pdo, $encryption),
                new JournalService(new JournalRepository($this->pdo))
            ),
            $this->mailProbes,
            // REAL bounce dependencies, not null: with null every bounce
            // action returns « le suivi des rebonds demande le module »
            // before touching anything, and a test asserting on the page
            // would pass whatever the code does.
            new \Core\Mail\Feedback\Bounce\BounceService(
                $this->bounceStates = new \Core\Mail\Feedback\Bounce\BounceStateRepository($this->pdo, $encryption)
            ),
            $this->bounceStates,
            // REAL, for the same reason as the bounce pair above: with
            // null the sub-page answers « indisponible » before reading
            // anything, and every assertion about what it shows would
            // hold whatever the code does.
            $this->dmarcReports = new \Core\Mail\Feedback\Dmarc\DmarcReportRepository($this->pdo),
            // The REMEMBERED reading, written the way the DNS-check
            // action writes it. It is not built from a resolver here for
            // the reason the class itself no longer resolves at render
            // time: the page reads what that action left behind, and a
            // test that handed the controller a live resolver would be
            // exercising a road nothing travels.
            self::rememberedRelays($settings),
            // REAL, like the bounce and DMARC pairs: with null the page
            // answers « indisponible » before reading anything, and every
            // assertion about what it shows would hold whatever the code
            // does.
            $this->seedMailboxes = new \Core\Mail\Feedback\Seed\SeedMailboxes(
                $this->seedCopies = new \Core\Mail\Feedback\Seed\SeedCopyRepository($this->pdo, $encryption),
                $settings
            ),
            $this->seedCopies,
            // REAL again, and with a real lane chain behind it: with null
            // the recommendation table is simply absent and every
            // assertion about what it offers would hold whatever the code
            // does.
            $this->seedRouting($directory),
            // The SPF reading, stored the way « Vérifier les
            // enregistrements » stores it, and for `rememberedRelays()`'s
            // reason: the page reads what that action left behind.
            self::rememberedSpfCoverage($settings),
        ];
        $this->controller = new OutboundMailController(...$this->controllerArguments);

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

        // Recursively, because `DkimManager::generateKey()` writes into a
        // `dkim/` subdirectory: the flat version left the temporary
        // directory behind on every test that generates a key, and said
        // so only as a PHP warning nobody reads.
        self::removeDirectory($this->secretsDirectory);
    }

    /**
     * A relay reading, stored as « Vérifier les enregistrements » stores
     * it — the resolver injected so the suite never reaches a real one
     * (the same reason `$dns` above is a fake).
     */
    private static function rememberedRelays(SettingService $settings): \Core\Mail\Feedback\Dmarc\KnownSenders
    {
        $settings->register(
            \Core\Mail\Feedback\Dmarc\KnownSenders::SETTING_KEY,
            '',
            'text',
            'Adresses des relais',
            '',
            null,
            null,
            null,
            false,
            57
        );

        \Core\Mail\Feedback\Dmarc\KnownSenders::refresh(
            $settings,
            [new \Core\Mail\Transport\MailProvider(
                id: 77,
                name: 'Brevo',
                host: 'smtp-relay.brevo.test',
                port: 587,
                username: '',
                dailyQuota: null,
                batchSize: 50,
                batchIntervalMinutes: 10
            )],
            static fn(string $host): array => $host === 'smtp-relay.brevo.test' ? ['198.51.100.7'] : []
        );

        return \Core\Mail\Feedback\Dmarc\KnownSenders::remembered($settings);
    }

    /**
     * An SPF reading, stored as the DNS check stores it (issue #421).
     *
     * **The range is `192.0.2.0/24` and nothing wider, deliberately.** The
     * fixtures of every other test on this page live in `198.51.100.0/24`
     * and `203.0.113.0/24`, and a default reading that covered those would
     * quietly retitle rows the tests around it were written to read as
     * « Autre » — a fixture changing other tests' meaning without
     * changing a line of them.
     */
    /**
     * The site sends from the domain the SPF fixture is about.
     *
     * **Not done in `setUp()`, deliberately.** Since the review of #571 the
     * page refuses a reading taken for a domain that is no longer the sending
     * one, so these tests have to pair the two — but a site with NO sending
     * address is a real state another test on this page asserts about, and
     * setting it globally silently rewrote that test's premise. It failed,
     * which is how this ended up here instead.
     */
    private function theSiteSendsFromTheFixturesDomain(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
    }

    public const SPF_ZONE = [
        'unite.be' => 'v=spf1 include:_spf.google.test -all',
        '_spf.google.test' => 'v=spf1 ip4:192.0.2.0/24 -all',
    ];

    /**
     * @param ?array<string, string> $zone host => its TXT record
     */
    private static function rememberedSpfCoverage(
        SettingService $settings,
        ?array $zone = null,
        ?\DateTimeImmutable $at = null
    ): \Core\Mail\Feedback\Dmarc\SpfCoverage {
        $zone ??= self::SPF_ZONE;
        $settings->register(
            \Core\Mail\Feedback\Dmarc\SpfCoverage::SETTING_KEY,
            '',
            'text',
            'Plages autorisées par le SPF',
            '',
            null,
            null,
            null,
            false,
            58
        );

        \Core\Mail\Feedback\Dmarc\SpfCoverage::refresh(
            $settings,
            'unite.be',
            static fn(string $host): array => isset($zone[$host]) ? [$zone[$host]] : [],
            $at
        );

        return \Core\Mail\Feedback\Dmarc\SpfCoverage::remembered($settings);
    }

    /**
     * The same controller with one dependency replaced rather than
     * removed. Positions come from the constructor, for the reason
     * {@see controllerWithout()} writes down.
     */
    private function controllerWith(string $name, mixed $dependency): OutboundMailController
    {
        $positions = [];
        foreach ((new \ReflectionMethod(OutboundMailController::class, '__construct'))->getParameters() as $p) {
            $positions[$p->getName()] = $p->getPosition();
        }

        self::assertArrayHasKey($name, $positions, "Unknown constructor dependency '{$name}'.");

        return new OutboundMailController(...array_replace(
            $this->controllerArguments,
            [$positions[$name] => $dependency]
        ));
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? self::removeDirectory($entry) : unlink($entry);
        }

        rmdir($directory);
    }

    // ── the RBAC floor ────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function outboundRoutes(): array
    {
        return [
            'the dashboard' => ['GET', '/config/courrier-sortant'],
            'the chains' => ['GET', '/config/courrier-sortant/acheminement'],
            'reordering a chain' => ['POST', '/config/courrier-sortant/acheminement/{lane}/ordre'],
            'enabling an entry' => ['POST', '/config/courrier-sortant/acheminement/{lane}/activation'],
            'the new-provider form' => ['GET', '/config/courrier-sortant/fournisseurs/nouveau'],
            'adding a provider' => ['POST', '/config/courrier-sortant/fournisseurs'],
            'one provider' => ['GET', '/config/courrier-sortant/fournisseurs/{id}'],
            'saving a provider' => ['POST', '/config/courrier-sortant/fournisseurs/{id}'],
            'deleting a provider' => ['POST', '/config/courrier-sortant/fournisseurs/{id}/suppression'],
            'relaunching abandoned mail' => ['POST', '/config/courrier-sortant/relance'],
            'the providers' => ['GET', '/config/courrier-sortant/fournisseurs'],
            'authentication' => ['GET', '/config/courrier-sortant/authentification'],
            'saving the addresses' => ['POST', '/config/courrier-sortant/authentification'],
            'checking the returns' => ['POST', '/config/courrier-sortant/authentification/verification'],
            'checking the DNS' => ['POST', '/config/courrier-sortant/authentification/dns'],
            // The rotation that moved here from « Installation & serveur »
            // (#336). Its two behaviour tests call the controller method
            // directly, which is the right shape for what they assert and
            // the wrong shape for the boundary: they never see RbacGuard.
            // AGENTS.md § Tests asks for the floor on EVERY new route.
            'regenerating the DKIM key' => ['POST', '/config/courrier-sortant/authentification/cle-dkim'],
            'the probe' => ['GET', '/config/courrier-sortant/sonde'],
            'sending a probe' => ['POST', '/config/courrier-sortant/sonde/envoi'],
            'recording a verdict' => ['POST', '/config/courrier-sortant/sonde/verdict'],
            'the bounces' => ['GET', '/config/courrier-sortant/rebonds'],
            'lifting a block' => ['POST', '/config/courrier-sortant/rebonds/{id}/reprise'],
            'the DMARC reports' => ['GET', '/config/courrier-sortant/dmarc'],
            'the seed mailboxes' => ['GET', '/config/courrier-sortant/temoins'],
            'toggling the seed copies' => ['POST', '/config/courrier-sortant/temoins/activation'],
            'routing a domain' => ['POST', '/config/courrier-sortant/temoins/routage'],
            'the automatic routing switch' => ['POST', '/config/courrier-sortant/temoins/routage-automatique'],
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

        $this->assertNotNull($response, "A « chef d'unité » must not reach {$method} {$path}.");
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

    // ── the dashboard and the authentication page (roadmap IT-03) ─────

    public function testTheDashboardShowsTheThreeEssentialsAndSaysWhatItCannotSee(): void
    {
        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Authentification du domaine', $body);
        $this->assertStringContainsString('Un fournisseur d’envoi', $body);
        $this->assertStringContainsString('Retours relevés', $body);
        // Without it, three green lines let somebody conclude everything
        // is fine while a provider is quietly filing the lot as spam.
        $this->assertStringContainsString(
            'Un message classé en indésirables n\'apparaît nulle part ici',
            $body
        );
    }

    public function testTheDashboardListsTheAdvancedOptionsRatherThanHidingThem(): void
    {
        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Adresse de réponse distincte', $body);
        $this->assertStringContainsString('Rapports DMARC', $body);
        $this->assertStringContainsString('Chaîne de repli', $body);
    }

    public function testADomainWithNoAddressAtAllIsTheFirstThingTheDashboardSays(): void
    {
        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Aucune adresse d’expédition', $body);
    }

    public function testWithoutTheInboundModuleTheReturnLineExplainsItselfInsteadOfAlarming(): void
    {
        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Rien ne relève le courrier qui revient', $body);
        $this->assertStringContainsString('Envoyer fonctionne sans cela.', $body);
    }

    /**
     * Without a key pair there is nothing to publish, so the DKIM reading
     * is neither « publié » nor « absent » — but it is certainly not
     * « tout va bien » either: the site signs nothing at all. The
     * dashboard used to show a green tick over exactly the state the
     * Authentification sub-page flags as « Clé DKIM requise ».
     */
    public function testTheDashboardNeverCallsAMissingDkimKeyGreen(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        // No key pair on this installation, and the fake resolver answers
        // a valid SPF record — the exact combination that read « ok ».
        $this->controller->checkDns($this->formRequest([]), []);

        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Aucune clé DKIM n’a été générée', $body);
        $this->assertStringNotContainsString('enregistrement en place', $body);
    }

    /**
     * `0` is the one id on this page where a missing value is not merely
     * absent but wrong: `MailProvider::LOCAL_ID` IS zero, and the local
     * send is unconditionally usable. A blank `provider_id` casting to it
     * would send the probe through the server's own `mail()` and record
     * that as the road the operator chose — the exact opposite of pinning
     * one relay, which is the whole feature.
     */
    public function testAProbeWithoutAProviderIsRefusedRatherThanSentThroughTheLocalRelay(): void
    {
        foreach (['', 'abc', '-1'] as $value) {
            $this->controller->sendProbe($this->formRequest([
                'destination' => 'vous@exemple.be',
                'provider_id' => $value,
                'lane' => 'bulk',
            ]), []);

            $statement = $this->pdo->query('SELECT COUNT(*) FROM mail_probes');
            $this->assertNotFalse($statement);
            $this->assertSame(
                0,
                (int) $statement->fetchColumn(),
                "provider_id « {$value} » must be refused, never resolved to the local send."
            );
        }
    }

    /**
     * The same controller with some optional dependencies left out, BY
     * NAME.
     *
     * The earlier spelling was `array_slice($args, 0, -2)`, which meant
     * « drop the probe pair » until IT-05 appended two more arguments —
     * at which point the same expression silently started dropping the
     * bounce pair instead, and the probe test went on passing while
     * testing something else. Positions move; names do not.
     */
    /**
     * The routing, built fresh.
     *
     * Rebuilt rather than reused because `DomainRouting` memoises the
     * mailing chain: a test that adds a relay and then asks the
     * controller built in `setUp()` would be asking about the chain as it
     * was when nothing had been configured.
     */
    private function seedRouting(\Core\Mail\Transport\MailProviderDirectory $directory):
        \Core\Mail\Feedback\Seed\DomainRouting
    {
        return new \Core\Mail\Feedback\Seed\DomainRouting(
            $this->seedCopies,
            $this->settings,
            $this->mailPreferences,
            new \Core\Mail\Transport\LaneChainRepository($this->pdo),
            $directory
        );
    }

    private function controllerWithout(string ...$omitted): OutboundMailController
    {
        // **Positions read off the constructor itself**, not written
        // down here. The previous version counted from the END on the
        // grounds that appending could not shift it — which is exactly
        // backwards, and IT-06 appending two dependencies is what showed
        // it: every offset moved by two and nothing said so. Reflection
        // cannot drift, because it is asking the thing itself.
        $positions = [];
        foreach ((new \ReflectionMethod(OutboundMailController::class, '__construct'))->getParameters() as $p) {
            $positions[$p->getName()] = $p->getPosition();
        }

        $arguments = $this->controllerArguments;
        foreach ($omitted as $name) {
            self::assertArrayHasKey($name, $positions, "Unknown constructor dependency '{$name}'.");
            $arguments[$positions[$name]] = null;
        }

        return new OutboundMailController(...$arguments);
    }

    // ── the bounces, end to end (roadmap IT-05) ───────────────────────

    private function blockOne(string $email = 'parent@exemple.be'): int
    {
        $now = new \DateTimeImmutable('2026-09-19 10:00:00');
        // Vouched for: these fixtures stand in for addresses the unit
        // writes to, and `recordSend()` stamps a receipt only for one
        // the site holds on file.
        $this->bounceStates->recordSend($email, $now->modify('-1 hour'), true);
        $state = $this->bounceStates->record(
            $email,
            \Core\Mail\Feedback\Bounce\BounceCategory::NoSuchAddress,
            \Core\Mail\Feedback\Bounce\BounceSeverity::Permanent,
            '5.1.1',
            $now
        );
        self::assertNotNull($state);
        $this->bounceStates->block($state->id, $now);

        return $state->id;
    }

    public function testTheBouncePageSaysWhatItCannotSee(): void
    {
        $response = $this->controller->bounces($this->getRequest(), []);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucune adresse suspendue', $body);
        // The most expensive confusion of the whole chantier, and it is
        // on the screen rather than only in the help topic.
        $this->assertStringContainsString('Ce que cette page ne voit pas', $body);
        $this->assertStringContainsString('ne refusent presque jamais', $body);
    }

    public function testASuspendedAddressIsListedWithItsReasonAndItsProvider(): void
    {
        $this->blockOne();

        $body = (string) $this->controller->bounces($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('parent@exemple.be', $body);
        $this->assertStringContainsString(
            \Core\Mail\Feedback\Bounce\BounceCategory::NoSuchAddress->label(),
            $body
        );
        $this->assertStringContainsString('exemple.be', $body);
    }

    public function testTheSuperAdminLiftsABlockAndTheAddressLeavesTheList(): void
    {
        $id = $this->blockOne();

        $response = $this->controller->unblockBounce($this->formRequest([]), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertSame(0, $this->bounceStates->countBlocked());
    }

    /** A stale link must not report a success about nothing. */
    public function testLiftingABlockThatIsGoneIsReportedAsAnError(): void
    {
        $this->controller->unblockBounce($this->formRequest([]), ['id' => '4242']);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
    }

    public function testAStaleTokenRefusesToLiftABlock(): void
    {
        $id = $this->blockOne();
        $_POST = [];

        $stale = new Request(
            'POST',
            '/config/courrier-sortant/rebonds/' . $id . '/reprise',
            [],
            ['_csrf_token' => 'périmé'],
            [],
            []
        );

        $this->controller->unblockBounce($stale, ['id' => (string) $id]);

        $this->assertSame(1, $this->bounceStates->countBlocked(), 'no block may be lifted on a stale token.');
    }

    // ── the seed mailboxes (roadmap IT-07) ────────────────────────────

    /**
     * The page sends the operator to the page that already declares
     * mailboxes rather than growing a second one — D10's whole point:
     * « aucun nouveau type de boîte, aucun nouveau concept de
     * configuration ».
     */
    public function testTheSeedsPageSendsTheOperatorToTheInboundMailPage(): void
    {
        $response = $this->controller->seeds($this->getRequest(), []);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('/config/courrier-entrant', $body);
        $this->assertStringContainsString('Aucune boîte témoin déclarée', $body);
    }

    /**
     * **« Trois à cinq suffisent », et au-delà cela se retourne contre
     * vous.** The warning is the one thing on this page an operator could
     * get wrong in the expensive direction: seed boxes never read their
     * mail, so measuring harder makes the delivery worse.
     */
    public function testTooManySeedBoxesRaisesTheWarningThatMoreIsWorse(): void
    {
        $controller = $this->controllerWithSeedAddresses([
            'a@gmail.com', 'b@outlook.com', 'c@yahoo.fr', 'd@proton.me', 'e@orange.fr', 'f@free.fr',
        ]);

        $body = (string) $controller->seeds($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Trois à cinq boîtes suffisent', $body);
        $this->assertStringContainsString('6', $body);
    }

    /** And a reasonable number raises nothing. */
    public function testAHandfulOfSeedBoxesRaisesNoWarning(): void
    {
        $controller = $this->controllerWithSeedAddresses(['a@gmail.com', 'b@outlook.com']);

        $body = (string) $controller->seeds($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('Trois à cinq boîtes suffisent', $body);
        $this->assertStringContainsString('gmail.com', $body, 'The providers are the results columns.');
    }

    /**
     * **The providers, never the addresses.** A seed box is the unit's
     * own mailbox, and an address on a screen is an address in every
     * screenshot of it (SECURITY.md §11). « gmail.com » names a company
     * and is what the results are read by.
     */
    public function testThePageNamesProvidersAndNeverAMailboxAddress(): void
    {
        $controller = $this->controllerWithSeedAddresses(['temoin-secret@gmail.com']);

        $body = (string) $controller->seeds($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('gmail.com', $body);
        $this->assertStringNotContainsString('temoin-secret@gmail.com', $body);
        $this->assertStringNotContainsString('temoin-secret', $body);
    }

    /** One row per mailing, one cell per provider. */
    public function testTheResultsShowOneRowPerMailingAndOneCellPerProvider(): void
    {
        $now = new \DateTimeImmutable();
        $this->seedCopies->claim('mass_mail:42', 'a@gmail.com', $now);
        $this->seedCopies->recordLanding('mass_mail:42', 'a@gmail.com', 'INBOX', $now);
        $this->seedCopies->claim('mass_mail:42', 'b@outlook.com', $now);
        $this->seedCopies->recordLanding('mass_mail:42', 'b@outlook.com', 'Junk', $now);

        $controller = $this->controllerWithSeedAddresses(['a@gmail.com', 'b@outlook.com']);
        $body = (string) $controller->seeds($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Boîte de réception', $body);
        $this->assertStringContainsString('Indésirables', $body);
        // The provider's own folder name, beside the verdict: « Junk » and
        // « Indésirables » are one verdict and two names, and the name is
        // what an operator recognises when they go and look.
        $this->assertStringContainsString('Junk', $body);
    }

    /**
     * **A provider stops being measured; its measurements stay.**
     *
     * The columns used to be derived from the boxes as they are now, and
     * `addresses()` answers `[]` rather than throwing when the module
     * cannot be reached — so removing one box, or a single failed
     * resolution, emptied a table whose rows were all still there. The
     * page would read « rien mesuré » for a period it had measured,
     * while the recommendation below went on acting on those very rows.
     */
    public function testAProviderNoLongerDeclaredKeepsTheResultsItProduced(): void
    {
        $now = new \DateTimeImmutable();
        $this->seedCopies->claim('mass_mail:42', 'b@orange.fr', $now);
        $this->seedCopies->recordLanding('mass_mail:42', 'b@orange.fr', 'Junk', $now);

        // The box at that provider is gone from the scope since.
        $controller = $this->controllerWithSeedAddresses(['a@gmail.com']);
        $body = (string) $controller->seeds($this->getRequest(), [])->getBody();

        // The results table's COLUMN, not merely the name somewhere on the
        // page: the recommendation table below reads the same stored rows
        // and would print « orange.fr » in a cell whatever this code does.
        $this->assertStringContainsString('<th scope="col">orange.fr</th>', $body);
        $this->assertStringContainsString('Indésirables', $body, 'and the verdict inside it.');
    }

    /**
     * **The blind spot the default configuration has, named before the
     * reader trusts a figure it makes wrong.**
     *
     * A mailbox is read in its INBOX and nowhere else until somebody
     * names more folders. For a seed box that is not a limitation but a
     * wrong answer: the copy filed as spam is never fetched, never
     * recorded, and two days later the sweep writes « jamais arrivé » on
     * it — the gravest verdict this screen has, produced systematically
     * by the one outcome the screen exists to detect.
     */
    public function testABoxThatCannotSeeItsJunkFolderIsSaidSoOnThePage(): void
    {
        $controller = $this->controllerWithSeedBoxes(
            ['a@gmail.com', 'b@outlook.com'],
            [['INBOX'], ['INBOX', 'Junk']]
        );

        $body = (string) $controller->seeds($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('1 boîte(s) témoin(s) ne surveille(nt) pas', $body);
        $this->assertStringContainsString('jamais arrivé', $body, 'and what it costs, in the page\'s own words.');
    }

    /** A unit that watches its junk folders is told nothing of the sort. */
    public function testABoxWatchingItsJunkFolderRaisesNothing(): void
    {
        $controller = $this->controllerWithSeedBoxes(['a@gmail.com'], [['INBOX', 'Spam']]);

        $body = (string) $controller->seeds($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('ne surveille(nt) pas', $body);
    }

    /**
     * **And the automatism is refused outright while that is true**,
     * which is the one place a warning is not enough: this switch moves a
     * whole provider's mail to another relay, unattended, on the strength
     * of a measurement that cannot tell « indésirables » from « jamais
     * arrivé » — the very difference it would act on.
     */
    public function testTheAutomaticRoutingCannotBeArmedWhileABoxIsBlind(): void
    {
        $controller = $this->controllerWithSeedBoxes(['a@gmail.com'], [['INBOX']]);

        $controller->toggleRouting($this->formRequest(['enabled' => '1']), []);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame(
            '0',
            (string) ($this->settings->get(\Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC) ?? '0'),
            'and the switch is still off.'
        );
    }

    /**
     * **And with no seed box at all it is refused too**, which it was
     * not: `boxesBlindToSpam()` counts blind boxes, so with no boxes it
     * counts zero — « nothing wrong » and « nothing measured » giving
     * the same figure. A unit could arm the automatism before declaring
     * anything, add an inbox-only box later, and have the sweep reroute
     * a provider on exactly the evidence the guard refuses.
     */
    public function testTheAutomaticRoutingCannotBeArmedWithNoSeedBoxAtAll(): void
    {
        $controller = $this->controllerWithSeedBoxes([], []);

        $controller->toggleRouting($this->formRequest(['enabled' => '1']), []);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame(
            '0',
            (string) ($this->settings->get(\Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC) ?? '0')
        );
    }

    /** Turning it OFF is never refused: that direction removes a risk. */
    public function testTheAutomaticRoutingCanAlwaysBeTurnedOff(): void
    {
        $this->controllerWithSeedBoxes(['a@gmail.com'], [['INBOX', 'Junk']])
            ->toggleRouting($this->formRequest(['enabled' => '1']), []);
        $this->assertSame(
            '1',
            $this->settings->get(\Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC),
            'it has to be on for turning it off to mean anything.'
        );

        // And the boxes have since gone blind.
        $controller = $this->controllerWithSeedBoxes(['a@gmail.com'], [['INBOX']]);

        $controller->toggleRouting($this->formRequest(['enabled' => '0']), []);

        $this->assertSame(
            '0',
            $this->settings->get(\Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC)
        );
    }

    /** With the junk folders watched, the switch arms as it always did. */
    public function testTheAutomaticRoutingArmsWhenTheBoxesCanSeeTheirJunkFolder(): void
    {
        $controller = $this->controllerWithSeedBoxes(['a@gmail.com'], [['INBOX', 'Indésirables']]);

        $controller->toggleRouting($this->formRequest(['enabled' => '1']), []);

        $this->assertSame(
            '1',
            $this->settings->get(\Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC)
        );
    }

    /**
     * **The limit the page must state.** A seed box says where ITS copy
     * landed at ITS provider — not what each family saw, since filing
     * also depends on what that person has opened and marked before.
     */
    public function testThePageSaysWhatASeedBoxCannotTellYou(): void
    {
        $body = (string) $this->controller->seeds($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Ce que ces résultats ne disent pas', $body);
        $this->assertStringContainsString('deux comptes Gmail', $body);
    }

    /** Turning the copies on is journalled at `security`, as the roadmap asks. */
    public function testTurningTheCopiesOnIsJournalledAsASecurityDecision(): void
    {
        $response = $this->controller->toggleSeeds($this->formRequest(['enabled' => '1']), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertTrue($this->seedMailboxes->isEnabled());

        $row = $this->journalRow('mail_seed_boxes_toggled');
        $this->assertNotNull($row);
        $this->assertSame('security', $row['level']);
    }

    public function testTurningThemOffIsJournalledToo(): void
    {
        $this->controller->toggleSeeds($this->formRequest(['enabled' => '1']), []);
        $this->controller->toggleSeeds($this->formRequest(['enabled' => '0']), []);

        $this->assertFalse($this->seedMailboxes->isEnabled());
    }

    /** A stale token changes nothing. */
    public function testAStaleTokenDoesNotTurnTheCopiesOn(): void
    {
        $_POST = [];
        $stale = new Request(
            'POST',
            '/config/courrier-sortant/temoins/activation',
            [],
            ['_csrf_token' => 'périmé', 'enabled' => '1'],
            [],
            []
        );

        $this->controller->toggleSeeds($stale, []);

        $this->assertFalse($this->seedMailboxes->isEnabled());
    }

    // ── routing by domain, the button and the switch (D13) ────────────

    /**
     * Two relays on the mailing lane, and a controller that has read
     * them.
     */
    private function controllerWithTwoRelays(): OutboundMailController
    {
        $ids = [];
        foreach (['Premier', 'Second'] as $position => $name) {
            $statement = $this->pdo->prepare(
                'INSERT INTO mail_providers (name, secret_prefix, batch_size, batch_interval_minutes)
                 VALUES (?, ?, 50, 10)'
            );
            $statement->execute([$name, 'mail_provider_' . $name]);
            $ids[] = (int) $this->pdo->lastInsertId();

            $entry = $this->pdo->prepare(
                'INSERT INTO mail_lane_entries (lane, provider_id, position, is_enabled) VALUES (?, ?, ?, 1)'
            );
            $entry->execute([\Core\Mail\Transport\MailLane::Bulk->value, end($ids), $position + 1]);
        }

        $arguments = $this->controllerArguments;
        $positions = [];
        foreach ((new \ReflectionMethod(OutboundMailController::class, '__construct'))->getParameters() as $p) {
            $positions[$p->getName()] = $p->getPosition();
        }
        $arguments[$positions['routing']] = $this->seedRouting($arguments[$positions['directory']]);

        return new OutboundMailController(...$arguments);
    }

    /** A provider the figures plainly condemn, over enough mailings. */
    private function recordTrouble(string $address = 'temoin@gmail.com'): void
    {
        for ($i = 0; $i < \Core\Mail\Feedback\Seed\DomainRouting::MINIMUM_RUNS; $i++) {
            $sent = new \DateTimeImmutable('-1 day');
            $this->seedCopies->claim('envoi-' . $i, $address, $sent);
            $this->seedCopies->recordLanding('envoi-' . $i, $address, 'Junk', $sent);
        }
    }

    /**
     * **The button D13 asks for by name**: « l'écran affiche le constat
     * et un bouton pour appliquer ».
     */
    public function testTheScreenOffersToRouteATroubledProvider(): void
    {
        $this->recordTrouble();

        $body = (string) $this->controllerWithTwoRelays()->seeds($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Passer par Second', $body);
    }

    /**
     * **With one relay there is nowhere to route to**, which is most
     * units, and the screen says so rather than drawing a button that
     * would explain nothing when it did nothing.
     */
    public function testWithASingleRelayTheScreenSaysSoInsteadOfOfferingAButton(): void
    {
        $this->recordTrouble();

        $body = (string) $this->controller->seeds($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('Passer par', $body);
        $this->assertStringContainsString('Un seul relais', $body);
    }

    /** Applying writes the decision where the transport reads it. */
    public function testApplyingRoutesTheDomainAndJournalsItAtSecurity(): void
    {
        $controller = $this->controllerWithTwoRelays();

        $response = $controller->routeSeeds($this->formRequest(['domain' => 'gmail.com']), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->mailPreferences->forDomain('gmail.com'));

        $row = $this->journalRow('mail_seed_routing_changed');
        $this->assertNotNull($row);
        $this->assertSame('security', $row['level']);
        $this->assertStringContainsString('gmail.com', (string) $row['context']);
    }

    /** And undoing it puts the domain back under the lane's own order. */
    public function testUndoingPutsTheDomainBackUnderTheLanesOrder(): void
    {
        $controller = $this->controllerWithTwoRelays();
        $controller->routeSeeds($this->formRequest(['domain' => 'gmail.com']), []);

        $controller->routeSeeds($this->formRequest(['domain' => 'gmail.com', 'undo' => '1']), []);

        $this->assertNull($this->mailPreferences->forDomain('gmail.com'));
    }

    /** With nowhere to route to, the answer is an error and no decision. */
    public function testApplyingWithoutAnAlternativeSaysSoAndWritesNothing(): void
    {
        $response = $this->controller->routeSeeds($this->formRequest(['domain' => 'gmail.com']), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertNull($this->mailPreferences->forDomain('gmail.com'));
    }

    /**
     * A posted value that is not a domain is refused with its own
     * message: « aucun autre relais » and « ceci n'est pas un domaine »
     * send somebody looking in two different places.
     */
    public function testAPostedValueThatIsNotADomainIsRefusedOnItsOwnTerms(): void
    {
        $controller = $this->controllerWithTwoRelays();

        $controller->routeSeeds($this->formRequest(['domain' => "gmail\ncom"]), []);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->mailPreferences->all());
    }

    /** A stale token routes nothing, like every other POST here. */
    public function testAStaleTokenRoutesNothing(): void
    {
        $_POST = [];
        $stale = new Request(
            'POST',
            '/config/courrier-sortant/temoins/routage',
            [],
            ['_csrf_token' => 'périmé', 'domain' => 'gmail.com'],
            [],
            []
        );

        $this->controllerWithTwoRelays()->routeSeeds($stale, []);

        $this->assertNull($this->mailPreferences->forDomain('gmail.com'));
    }

    /** The second lock of D13, and it starts off. */
    public function testTheAutomaticSwitchIsOffUntilSomebodyTurnsItOn(): void
    {
        // The switch lives on the recommendation card, so there has to be
        // something to recommend: an automatism offered before a single
        // mailing has been measured would be a switch with no reading
        // behind it.
        $this->recordTrouble();

        $body = (string) $this->controllerWithTwoRelays()->seeds($this->getRequest(), [])->getBody();
        $this->assertStringContainsString('Appliquer automatiquement', $body);

        // A box that can see its junk folder, because arming the switch
        // without one is refused — see the two tests below.
        $this->controllerWithSeedBoxes(['temoin@gmail.com'], [['INBOX', 'Junk']])
            ->toggleRouting($this->formRequest(['enabled' => '1']), []);

        $this->assertSame(
            '1',
            $this->settings->get(\Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC)
        );
        $row = $this->journalRow('mail_seed_routing_automatic_toggled');
        $this->assertNotNull($row);
        $this->assertSame('security', $row['level']);
    }

    public function testTurningTheAutomaticSwitchBackOffIsJournalledToo(): void
    {
        $this->controller->toggleRouting($this->formRequest(['enabled' => '1']), []);
        $this->controller->toggleRouting($this->formRequest(['enabled' => '0']), []);

        $this->assertSame(
            '0',
            $this->settings->get(\Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC)
        );
    }

    /**
     * **The page says the remedy is not free**, which is the half of D13
     * a table of figures cannot carry: sending part of the volume
     * elsewhere gives each relay less of the regular traffic its standing
     * depends on.
     */
    public function testThePageSaysWhatChangingRelayCosts(): void
    {
        $this->recordTrouble();

        $body = (string) $this->controllerWithTwoRelays()->seeds($this->getRequest(), [])->getBody();

        $this->assertStringContainsString("n'est pas gratuit", $body);
    }

    /**
     * **An installation without `inbound_mail` gets a refusal, not a
     * half-working page** — the same shape already pinned for the
     * bounces, for DMARC and for the probe.
     *
     * It matters here more than elsewhere: these three actions each have
     * a null-dependency branch, and a page that merely rendered empty
     * would tell a unit its mailings are fine when in fact nothing is
     * being measured at all.
     */
    public function testAnInstallationWithoutTheInboundModuleSaysSoOnAllThreeSeedRoutes(): void
    {
        $controller = $this->controllerWithout('seedMailboxes', 'seedCopies', 'routing');

        $body = (string) $controller->seeds($this->getRequest(), [])->getBody();
        $this->assertStringContainsString('demandent le module', $body);
        $this->assertStringNotContainsString('Copier les publipostages', $body);

        $controller->toggleSeeds($this->formRequest(['enabled' => '1']), []);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertFalse($this->seedMailboxes->isEnabled(), 'and nothing was switched on.');

        $controller->routeSeeds($this->formRequest(['domain' => 'gmail.com']), []);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->mailPreferences->all(), 'and nothing was routed.');

        // **The fourth route, which used to fall through.** Its guard was
        // a nullsafe chain rather than an early return, so « no module »
        // reached the blind-box test as « zero blind boxes » — « nothing
        // wrong » — and the switch armed itself on an installation that
        // measures nothing at all.
        $controller->toggleRouting($this->formRequest(['enabled' => '1']), []);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(
            '0',
            (string) ($this->settings->get(\Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC) ?? '0'),
            'and the automatism is still off.'
        );
        // **And it says the right thing.** « Aucune boîte témoin ne peut
        // mesurer » sends the reader to « Courrier entrant » to declare
        // boxes they cannot declare: the module is off, which is a
        // different answer and the one its two siblings give.
        $this->assertStringContainsString(
            'demandent le module',
            (string) ($flash['message'] ?? ''),
            'the switch answered « no seed box » where the truth is « no module ».'
        );
    }

    /**
     * @param list<string> $addresses
     */
    private function controllerWithSeedAddresses(array $addresses): OutboundMailController
    {
        $inbound = $this->createStub(\Modules\InboundMail\Api\InboundMailInterface::class);
        $inbound->method('probeAddressesFor')->willReturn($addresses);
        $this->seedMailboxes->useInboundMail($inbound);

        return $this->controller;
    }

    /**
     * The same, saying which folders each of those boxes is read in —
     * the answer the module gives about boxes it already reads, and the
     * one that decides whether a spam verdict is observable at all.
     *
     * @param list<string> $addresses
     * @param list<list<string>> $folders box for box, in the same order
     */
    private function controllerWithSeedBoxes(array $addresses, array $folders): OutboundMailController
    {
        $inbound = $this->createStub(\Modules\InboundMail\Api\InboundMailInterface::class);
        $inbound->method('probeAddressesFor')->willReturn($addresses);
        $inbound->method('watchedFoldersFor')->willReturn($folders);
        $this->seedMailboxes->useInboundMail($inbound);

        return $this->controller;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function journalRow(string $eventType): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ? LIMIT 1');
        $statement->execute([$eventType]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    // ── the DMARC reports (roadmap IT-06) ─────────────────────────────

    /**
     * @param list<array{0: string, 1: int, 2: bool}> $sources address, messages, authenticated
     */
    private function recordDmarcReport(string $organisation, string $reportId, array $sources): void
    {
        $now = new \DateTimeImmutable();
        $records = [];
        foreach ($sources as [$ip, $count, $passed]) {
            $records[] = new \Core\Mail\Feedback\Dmarc\DmarcRecord($ip, $count, 'none', $passed, false, 'unite.be');
        }

        self::assertTrue($this->dmarcReports->record(
            new \Core\Mail\Feedback\Dmarc\DmarcReport(
                $organisation,
                $reportId,
                'unite.be',
                $now->modify('-2 days'),
                $now->modify('-1 day'),
                'none',
                $records
            ),
            $now
        ), 'The fixture must actually reach the tables the page reads.');
    }

    /**
     * The empty page is not a blank one: it says why no report has
     * arrived, because « rien » here means « votre DNS ne demande rien »
     * far more often than « personne n'envoie en votre nom ».
     */
    public function testTheDmarcPageSaysWhyNothingHasArrived(): void
    {
        $response = $this->controller->dmarc($this->getRequest(), []);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucun rapport reçu', $body);
        $this->assertStringContainsString('rua', $body, 'The DNS tag that asks for the reports.');
        // The page's sibling sentence to the Rebonds one, on the screen
        // rather than only in the help topic.
        $this->assertStringContainsString('authentifié', $body);
        $this->assertStringContainsString('lu', $body);
    }

    /**
     * A relay of the unit's own is named, so the volunteer reads
     * « Brevo » rather than an address that answers no question.
     */
    public function testAKnownRelayIsShownUnderItsProviderName(): void
    {
        $this->recordDmarcReport('google.com', 'r-1', [['198.51.100.7', 120, true]]);

        $body = (string) $this->controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Brevo', $body);
        $this->assertStringContainsString('google.com', $body, 'Who reported.');
        $this->assertStringContainsString('120', $body);
        $this->assertStringNotContainsString('smtp-relay.brevo.test', $body, 'A relay host is infrastructure.');
        // No warning: a source we recognise needs no investigation.
        $this->assertStringNotContainsString('Un outil oublié', $body);
    }

    /**
     * **The one thing this screen exists to prevent.** An unrecognised
     * source that AUTHENTICATES is almost always a forgotten tool of the
     * unit's own, and moving to `p=reject` without finding it rejects
     * precisely those messages. The warning is therefore tied to
     * « inconnue ET qui réussit », not to « inconnue ».
     */
    public function testAnUnknownSourceThatAuthenticatesRaisesTheWarningBeforeReject(): void
    {
        $this->recordDmarcReport('google.com', 'r-2', [['203.0.113.42', 40, true]]);

        $body = (string) $this->controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Un outil oublié', $body);
        $this->assertStringContainsString('p=reject', $body);
    }

    /**
     * And the other side of that same condition: an unknown source whose
     * messages all FAIL is a spoof being stopped, which is the system
     * working. A warning there would cry wolf on every page, and the
     * volunteer would stop reading the one that matters.
     */
    public function testAnUnknownSourceThatFailsRaisesNoWarning(): void
    {
        $this->recordDmarcReport('google.com', 'r-3', [['203.0.113.99', 40, false]]);

        $body = (string) $this->controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('203.0.113.99', $body, 'It is still listed.');
        $this->assertStringNotContainsString('Un outil oublié', $body);
    }

    /**
     * **The finding this whole round is about, as a test.**
     *
     * The table is capped at two hundred rows, and the warning used to be
     * read off those rows. So a forgotten tool sending forty messages,
     * sitting behind two hundred noisier senders, fell off the table and
     * took the only sentence naming it with it — on precisely the
     * installation busy enough to need the warning. The count now comes
     * from an uncapped query, and this pins that: the quiet unknown sender
     * is the LAST row by traffic, far outside anything drawn.
     *
     * The fixture is deliberately over the cap rather than at it: a test
     * built on exactly two hundred would pass against an off-by-one that
     * still loses the row.
     */
    public function testTheWarningSurvivesASourceListLongerThanTheTableShows(): void
    {
        // Two hundred and ten distinct senders, all noisy, none of whose
        // messages authenticate: spoofing being stopped, which raises
        // nothing and is exactly what fills a busy installation's table.
        for ($i = 0; $i < 210; $i++) {
            $this->recordDmarcReport('google.com', 'spam-' . $i, [['203.0.113.' . $i, 500 + $i, false]]);
        }
        // And one address nobody can place, quietly getting mail through.
        // Forty messages sorts it dead last, well outside the two hundred
        // rows the table draws.
        $this->recordDmarcReport('google.com', 'outil-oublie', [['198.51.100.200', 40, true]]);

        $body = (string) $this->controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Un outil oublié', $body);
        $this->assertStringContainsString('le tableau', $body, 'And it says it is showing fewer than there are.');
        $this->assertStringNotContainsString(
            '198.51.100.200',
            $body,
            'The row itself is off the table — which is the whole point: the warning outlives it.'
        );
    }

    /**
     * An installation that has never run the DNS check places nothing —
     * « autres » for every source, which is the safe side — and says so
     * rather than letting somebody read a page of « Autre » as a page of
     * strangers.
     */
    public function testAPageWithoutAResolvedRelayReadingSaysWhereToTakeOne(): void
    {
        $this->settings->setInternal(\Core\Mail\Feedback\Dmarc\KnownSenders::SETTING_KEY, '');
        // By name, not by the literal `19` this line used to carry: that
        // offset is exactly what `controllerWithout()` above explains
        // cannot be written down, and appending the SPF reading moved it.
        $controller = $this->controllerWith(
            'knownSenders',
            \Core\Mail\Feedback\Dmarc\KnownSenders::remembered($this->settings)
        );

        $this->recordDmarcReport('google.com', 'r-1', [['198.51.100.7', 120, true]]);

        $body = (string) $controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('n\'ont pas encore été résolus', $body);
        $this->assertStringNotContainsString('Brevo', $body, 'Nothing may be claimed from a reading never taken.');
    }

    // ── Nommer une source depuis le SPF de l'unité (issue #421) ───────

    /**
     * **The one case where this page had something important to say and
     * could not say it.** A source no declared relay places is an address
     * and nothing else; the unit's own SPF usually explains it, because
     * somebody had to put it there for that mail to pass.
     */
    public function testASourceNoRelayPlacesIsNamedByTheIncludeThatCoversIt(): void
    {
        $this->theSiteSendsFromTheFixturesDomain();
        $this->recordDmarcReport('google.com', 'r-spf', [['192.0.2.10', 60, true]]);

        $body = (string) $this->controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Déclarée dans votre SPF, via', $body);
        $this->assertStringContainsString('_spf.google.test', $body);
    }

    /**
     * **Named is not cleared**, which is the trap the issue names and a
     * trap because the truthful reading is the reassuring one. « Je l'ai
     * mis dans le SPF il y a trois ans » is the commonest way a forgotten
     * tool got there, so the warning and the badge both stay.
     */
    public function testNamingASourceFromTheSpfDoesNotClearIt(): void
    {
        $this->theSiteSendsFromTheFixturesDomain();
        $this->recordDmarcReport('google.com', 'r-spf', [['192.0.2.10', 60, true]]);

        $body = (string) $this->controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Déclarée dans votre SPF, via', $body);
        $this->assertStringContainsString('À identifier', $body);
        $this->assertStringContainsString('Un outil oublié', $body);
    }

    /**
     * A relay of the unit's own is in its SPF too — that is how its mail
     * passes. Saying both would put « Brevo » and « déclarée dans votre
     * SPF » on one row and leave the volunteer to work out that they are
     * the same fact.
     */
    public function testASourceOneOfTheUnitsRelaysPlacesIsNotAlsoLabelledFromTheSpf(): void
    {
        $this->theSiteSendsFromTheFixturesDomain();
        $controller = $this->controllerWith('spfCoverage', self::rememberedSpfCoverage(
            $this->settings,
            ['unite.be' => 'v=spf1 ip4:198.51.100.0/24 -all']
        ));

        $this->recordDmarcReport('google.com', 'r-relay', [['198.51.100.7', 120, true]]);

        $body = (string) $controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Brevo', $body);
        $this->assertStringNotContainsString('Déclarée dans votre SPF, via', $body);
        $this->assertStringNotContainsString('Déclarée directement', $body);
    }

    public function testARangeTheRecordStatesItselfIsShownAsDirectRatherThanViaSomething(): void
    {
        $this->theSiteSendsFromTheFixturesDomain();
        $controller = $this->controllerWith('spfCoverage', self::rememberedSpfCoverage(
            $this->settings,
            ['unite.be' => 'v=spf1 ip4:192.0.2.0/24 -all']
        ));

        $this->recordDmarcReport('google.com', 'r-direct', [['192.0.2.10', 60, true]]);

        $body = (string) $controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Déclarée directement dans votre enregistrement SPF', $body);
        $this->assertStringNotContainsString('Déclarée dans votre SPF, via', $body);
    }

    /**
     * A provider's published ranges move without anybody at the unit
     * touching anything, so an old reading would name an address that
     * provider may have handed back. It names nobody, and the page says
     * which of the three reasons it is.
     */
    public function testAnSpfReadingPastItsAgeNamesNobodyAndSaysWhy(): void
    {
        $this->theSiteSendsFromTheFixturesDomain();
        $long = \Core\Mail\Feedback\Dmarc\SpfCoverage::MAX_AGE_DAYS + 5;
        $controller = $this->controllerWith('spfCoverage', self::rememberedSpfCoverage(
            $this->settings,
            null,
            (new \DateTimeImmutable())->sub(new \DateInterval('P' . $long . 'D'))
        ));

        $this->recordDmarcReport('google.com', 'r-spf', [['192.0.2.10', 60, true]]);

        $body = (string) $controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('Déclarée dans votre SPF, via', $body);
        $this->assertStringContainsString('a plus de ' . \Core\Mail\Feedback\Dmarc\SpfCoverage::MAX_AGE_DAYS
            . ' jours', $body);
    }

    public function testAPageWithNoSpfReadingSaysThatRatherThanNothing(): void
    {
        $this->theSiteSendsFromTheFixturesDomain();
        $this->recordDmarcReport('google.com', 'r-spf', [['192.0.2.10', 60, true]]);

        $body = (string) $this->controllerWithout('spfCoverage')->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('Déclarée dans votre SPF, via', $body);
        $this->assertStringContainsString('n\'a pas encore été lu', $body);
    }

    /**
     * **Past ten lookups the record is the problem, not this page.** A
     * receiver abandons the chain there too, so the ranges hiding behind
     * it authorise nothing in practice — which is a different action from
     * « nous n'en conservons pas tant », and the page says which.
     */
    public function testAChainOverTheProtocolsLookupLimitIsReportedAsTheRecordsProblem(): void
    {
        $this->theSiteSendsFromTheFixturesDomain();
        $zone = ['unite.be' => 'v=spf1 include:h1.test -all'];
        for ($i = 1; $i <= 11; $i++) {
            $zone['h' . $i . '.test'] = 'v=spf1 include:h' . ($i + 1) . '.test -all';
        }
        $zone['h12.test'] = 'v=spf1 ip4:192.0.2.0/24 -all';

        $controller = $this->controllerWith('spfCoverage', self::rememberedSpfCoverage($this->settings, $zone));

        $this->recordDmarcReport('google.com', 'r-spf', [['192.0.2.10', 60, true]]);

        $body = (string) $controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('Déclarée dans votre SPF, via', $body);
        $this->assertStringContainsString('résolutions que le protocole accorde', $body);
        $this->assertStringNotContainsString('plus de plages que nous n\'en conservons', $body);
    }

    /**
     * **A reading outlives the address it was taken for** (found in review on
     * #571). The sending address moves, so the domain the site signs for
     * moves with it — and the stored ranges belong to the old one. For up to
     * thirty days the page would have gone on calling them « déclarée dans
     * votre SPF », and a range stated by the old record would even have read
     * as « directement dans votre enregistrement », for a domain that is no
     * longer the unit's.
     */
    public function testAReadingTakenForAnotherDomainNamesNobodyAndSaysWhy(): void
    {
        // The reading is about `unite.be` (see the fixture); the site now
        // sends from somewhere else entirely.
        $this->settings->set('mail_from_address', 'info@autre-unite.be');

        $this->recordDmarcReport('google.com', 'r-spf', [['192.0.2.10', 60, true]]);

        $body = (string) $this->controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('Déclarée dans votre SPF, via', $body);
        // **The needle must not span the template's own line break.** The
        // sentence reads « qui n'est plus votre domaine d'envoi » on screen
        // and carries a newline inside it in the source, so a needle written
        // across it matches nothing — which is how this assertion failed
        // first, on a page that was saying exactly the right thing.
        $this->assertStringContainsString('La dernière lecture porte sur', $body);
        $this->assertStringContainsString('<code>unite.be</code>', $body);

    }

    /**
     * A host in the chain that did not answer leaves the reading incomplete,
     * and the page says which of the reasons it is — « relancez » rather than
     * « raccourcissez votre enregistrement », which is a different job.
     */
    public function testAHostThatDidNotAnswerIsReportedAsSuchRatherThanAsATooLongChain(): void
    {
        $this->theSiteSendsFromTheFixturesDomain();

        \Core\Mail\Feedback\Dmarc\SpfCoverage::refresh(
            $this->settings,
            'unite.be',
            static fn(string $host): ?array => $host === 'unite.be'
                ? ['v=spf1 include:_spf.injoignable.test -all']
                : null
        );

        $controller = $this->controllerWith(
            'spfCoverage',
            \Core\Mail\Feedback\Dmarc\SpfCoverage::remembered($this->settings)
        );

        $this->recordDmarcReport('google.com', 'r-spf', [['192.0.2.10', 60, true]]);

        $body = (string) $controller->dmarc($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('n\'a pas répondu lors de la', $body);
        $this->assertStringNotContainsString('résolutions que le protocole accorde', $body);
    }

    public function testAnInstallationWithoutTheDmarcTablesSaysSoRatherThanShowingAnEmptyPage(): void
    {
        $body = (string) $this->controllerWithout('dmarc', 'knownSenders')
            ->dmarc($this->getRequest(), [])
            ->getBody();

        $this->assertStringContainsString('demande le module', $body);
        $this->assertStringNotContainsString('Aucun rapport reçu', $body, 'Silence must not read as « personne ».');
    }

    /**
     * An installation without `inbound_mail`: the page says so rather
     * than showing an empty list that promises a feature nothing fills.
     */
    public function testAnInstallationWithoutTheInboundModuleSaysSo(): void
    {
        $withoutBounces = $this->controllerWithout('bounces', 'bounceHistory');

        $body = (string) $withoutBounces->bounces($this->getRequest(), [])->getBody();
        $this->assertStringContainsString('Courrier entrant', $body);

        $response = $withoutBounces->unblockBounce($this->formRequest([]), ['id' => '1']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
    }

    // ── the probe, end to end (roadmap IT-04) ─────────────────────────
    //
    // The RBAC floor above pins who may reach these three routes; it
    // says nothing about what comes back, and AGENTS.md asks for both.
    // What follows walks the page as an operator does: open it, send
    // one, come back, answer where it landed — plus the three refusals
    // and the installation where the probe was never built at all.

    public function testTheProbePageOffersEveryRelayEveryLaneAndSaysWhyNothingRepeats(): void
    {
        $response = $this->controller->probe($this->getRequest(), []);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Envoyer une sonde', $body);
        $this->assertStringContainsString(MailProvider::LOCAL_NAME, $body);

        foreach (['Masse', 'Transactionnel', 'Authentification'] as $lane) {
            $this->assertStringContainsString($lane, $body, "the « {$lane} » lane must be offered.");
        }

        // The card that explains the absence of a schedule is part of
        // the answer, not decoration: without it the first thing an
        // operator looks for is the button to repeat this weekly, which
        // is the one thing the instrument must not do.
        $this->assertStringContainsString('La sonde ne se répète pas toute seule', $body);
        $this->assertStringContainsString('Aucune sonde envoyée pour', $body);
    }

    public function testSendingAProbeRecordsItAndHandsBackTheCodeToLookFor(): void
    {
        $response = $this->controller->sendProbe($this->formRequest([
            'destination' => 'vous@exemple.be',
            'provider_id' => (string) MailProvider::LOCAL_ID,
            'lane' => 'bulk',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(OutboundMailController::PROBE_URL, $response->getHeaders()['Location'] ?? null);

        $recent = $this->mailProbes->recent();
        $this->assertCount(1, $recent);
        $this->assertSame('vous@exemple.be', $recent[0]->destination);
        $this->assertSame(MailLane::Bulk, $recent[0]->lane);
        $this->assertNull($recent[0]->verdict);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        // The code, and not merely « envoyée » — it is the only thing
        // that lets somebody find the message in a mailbox they are
        // about to search, spam folder included.
        $this->assertStringContainsString($recent[0]->code, $flash['message'] ?? '');
        $this->assertStringContainsString(MailProvider::LOCAL_NAME, $flash['message'] ?? '');
    }

    public function testThePageThenAsksWhereThatMessageLandedAndOffersTheThreeAnswers(): void
    {
        $this->sendOneProbe();

        $body = (string) $this->controller->probe($this->getRequest(), [])->getBody();
        $code = $this->mailProbes->recent()[0]->code;

        $this->assertStringContainsString('Où sont-elles arrivées ?', $body);
        $this->assertStringContainsString($code, $body);

        foreach (\Core\Mail\Probe\MailProbeVerdict::ordered() as $verdict) {
            $this->assertStringContainsString(
                'value="' . $verdict->value . '"',
                $body,
                "« {$verdict->label()} » must be one of the answers offered."
            );
        }

        // Said next to the buttons rather than in the help topic,
        // because that is the second somebody is about to do it.
        $this->assertStringContainsString('Ne sortez pas le message des indésirables', $body);
    }

    public function testRecordingAVerdictWritesItAndTheHistoryThenShowsIt(): void
    {
        $id = $this->sendOneProbe();

        $response = $this->controller->recordProbeVerdict($this->formRequest([
            'probe_id' => (string) $id,
            'verdict' => 'spam',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertStringContainsString(
            \Core\Mail\Probe\MailProbeVerdict::Spam->guidance(),
            $flash['message'] ?? ''
        );

        $this->assertSame(\Core\Mail\Probe\MailProbeVerdict::Spam, $this->mailProbes->find($id)?->verdict);

        $body = (string) $this->controller->probe($this->getRequest(), [])->getBody();
        $this->assertStringContainsString(\Core\Mail\Probe\MailProbeVerdict::Spam->label(), $body);
        $this->assertStringContainsString(\Core\Mail\Probe\MailProbeVerdict::Spam->badge(), $body);
        // Answered, so it is no longer among the ones being asked about.
        $this->assertStringNotContainsString('Où sont-elles arrivées ?', $body);
    }

    /**
     * A double click, or a second tab. Neither is an error, and neither
     * may overwrite the answer that stood: the operator has to be told
     * which one is on file rather than left to assume it is the one they
     * just pressed.
     */
    public function testASecondVerdictDoesNotReplaceTheFirstAndSaysSo(): void
    {
        $id = $this->sendOneProbe();

        $this->controller->recordProbeVerdict($this->formRequest([
            'probe_id' => (string) $id,
            'verdict' => 'inbox',
        ]), []);
        \Core\Http\FlashMessage::get();

        $this->controller->recordProbeVerdict($this->formRequest([
            'probe_id' => (string) $id,
            'verdict' => 'never',
        ]), []);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('warning', $flash['type'] ?? null);
        $this->assertStringContainsString('avait déjà un verdict', $flash['message'] ?? '');
        $this->assertSame(\Core\Mail\Probe\MailProbeVerdict::Inbox, $this->mailProbes->find($id)?->verdict);
    }

    public function testAVerdictThatDoesNotExistIsRefusedAndLeavesTheProbeWaiting(): void
    {
        $id = $this->sendOneProbe();

        $this->controller->recordProbeVerdict($this->formRequest([
            'probe_id' => (string) $id,
            'verdict' => 'perdu',
        ]), []);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Ce verdict n’existe pas', $flash['message'] ?? '');
        $this->assertNull($this->mailProbes->find($id)?->verdict);
    }

    public function testAVerdictForAProbeThatIsGoneIsRefusedRatherThanCountedElsewhere(): void
    {
        $this->controller->recordProbeVerdict($this->formRequest([
            'probe_id' => '4242',
            'verdict' => 'inbox',
        ]), []);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Cette sonde n’existe plus', $flash['message'] ?? '');
    }

    /**
     * **The half of issue #419 that is the point of it: what the page says.**
     *
     * The consumer's own tests prove a bounce gets attached; none of them can
     * prove an operator ever sees it. This renders the real history table
     * from a real row and reads the sentence back.
     *
     * And it reads BOTH: the verdict the operator entered and the reason the
     * far end gave, on the same line. They are not alternatives — « jamais
     * reçu » is what a person saw in the mailbox, « Adresse inexistante » is
     * why there was nothing to see — and a page that replaced one with the
     * other would drop the half somebody came for.
     */
    public function testAProbeThatWasTracedToABounceShowsTheReasonNextToTheVerdict(): void
    {
        $probes = new \Core\Mail\Probe\MailProbeRepository(
            $this->pdo,
            // The same keys setUp() gave the controller: a different pair
            // would store a destination the page cannot decrypt.
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $id = $probes->record(
            'SM-7K2XPQ',
            'vous@exemple.be',
            null,
            'Relais principal',
            \Core\Mail\Transport\MailLane::Transactional,
            new \DateTimeImmutable('2026-09-15 07:00:00')
        );
        $probes->recordVerdict($id, \Core\Mail\Probe\MailProbeVerdict::Never, new \DateTimeImmutable('2026-09-15 09:00:00'));
        $probes->recordBounce(
            $id,
            \Core\Mail\Feedback\Bounce\BounceCategory::NoSuchAddress,
            '5.1.1',
            new \DateTimeImmutable('2026-09-15 08:00:00')
        );

        $body = (string) $this->controller->probe($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Adresse inexistante (5.1.1)', $body);
        $this->assertStringContainsString('Jamais reçu', $body);
        // **The date of the refusal, and not the date of the send.** The
        // probe left at 07:00 and the bounce came back at 08:00; a test
        // that only read the label passed while `bounce_at` was computed
        // in the view model and printed nowhere, which is what the review
        // of #562 found.
        $this->assertStringContainsString('Rebond le 15/09/2026 à 08:00', $body);
    }

    /**
     * The ordinary probe, and the assertion that keeps the one above from
     * passing on a page that says « Rebond » to everybody.
     *
     * Most bounces name no probe at all — a server that rejects before
     * quoting the message it rejected sends no code — so a row with nothing
     * traced to it is the common case, and it must stay silent rather than
     * show an empty reason.
     */
    public function testAProbeWithNothingTracedToItSaysNothingAboutARebound(): void
    {
        $probes = new \Core\Mail\Probe\MailProbeRepository(
            $this->pdo,
            // The same keys setUp() gave the controller: a different pair
            // would store a destination the page cannot decrypt.
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $probes->record(
            'SM-7K2XPQ',
            'vous@exemple.be',
            null,
            'Relais principal',
            \Core\Mail\Transport\MailLane::Transactional,
            new \DateTimeImmutable('2026-09-15 07:00:00')
        );

        $body = (string) $this->controller->probe($this->getRequest(), [])->getBody();

        // `Rebond le ` and not `Rebond`: the page's own navigation carries
        // a « Rebonds » tab, so the looser needle passed on that instead
        // and said nothing about the row under test.
        $this->assertStringNotContainsString('Rebond le ', $body);
    }

    /**
     * An installation that never built the probe — the two constructor
     * arguments are optional, so this is a real configuration and not a
     * decor invented for the test: it is `new OutboundMailController()`
     * with the last two left off, exactly as a composition root that
     * omits them produces.
     *
     * All three routes have to survive it, and say the same sentence:
     * the page may not offer a form that cannot send, and the two POSTs
     * may not reach a null.
     */
    public function testAnInstallationWithoutTheProbeSaysSoOnAllThreeRoutes(): void
    {
        $withoutProbe = $this->controllerWithout('probes', 'probeHistory');

        $body = (string) $withoutProbe->probe($this->getRequest(), [])->getBody();
        $this->assertStringContainsString('La sonde n’est pas disponible', $body);
        $this->assertStringNotContainsString('Envoyer une sonde', $body);

        foreach (['sendProbe', 'recordProbeVerdict'] as $action) {
            $response = $withoutProbe->{$action}($this->formRequest([
                'destination' => 'vous@exemple.be',
                'provider_id' => (string) MailProvider::LOCAL_ID,
                'probe_id' => '1',
                'verdict' => 'inbox',
            ]), []);

            $this->assertSame(302, $response->getStatusCode());

            $flash = \Core\Http\FlashMessage::get();
            $this->assertSame('error', $flash['type'] ?? null, "{$action} must refuse rather than crash.");
            $this->assertStringContainsString('La sonde n’est pas disponible', $flash['message'] ?? '');
        }

        $this->assertSame(0, $this->mailProbes->count());
    }

    public function testAnUnknownRelayIsRefusedWithoutSendingAnything(): void
    {
        $this->controller->sendProbe($this->formRequest([
            'destination' => 'vous@exemple.be',
            'provider_id' => '4242',
            'lane' => 'bulk',
        ]), []);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Ce fournisseur n’existe plus', $flash['message'] ?? '');
        $this->assertSame(0, $this->mailProbes->count());
    }

    /**
     * The one outcome that is neither a success nor a failure: the relay
     * took the message and the history could not be told. Here the table
     * is gone, which is as close to that window as a test can stand.
     *
     * **`warning`, and not `error`.** The distinction is the whole point
     * of `MailProbeNotRecordedException`: told it failed, the operator
     * presses the button again and a second message goes out — and two
     * messages by one road to one address is exactly the reading this
     * page cannot make sense of afterwards.
     */
    public function testAProbeThatLeftButCouldNotBeRecordedIsAWarningCarryingItsCode(): void
    {
        $this->pdo->exec('DROP TABLE mail_probes');

        $response = $this->controller->sendProbe($this->formRequest([
            'destination' => 'vous@exemple.be',
            'provider_id' => (string) MailProvider::LOCAL_ID,
            'lane' => 'bulk',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('warning', $flash['type'] ?? null, 'a message that LEFT is not an error.');
        $this->assertStringContainsString('ne relancez pas', $flash['message'] ?? '');
        // The code the message carries, handed over in the sentence
        // because the table that would have held it is what just failed.
        $this->assertMatchesRegularExpression('/\bSM-[A-Z0-9]{6}\b/', $flash['message'] ?? '');
    }

    /**
     * Same site-wide sentence as everywhere else, and nothing written:
     * `AbstractController::guardCsrf()` runs before the probe is even
     * looked at, on both write routes.
     */
    public function testAStaleTokenRefusesBothProbeWrites(): void
    {
        $id = $this->sendOneProbe();

        // Emptied, because guardCsrf() also looks in the superglobals:
        // a body carrying one token while $_POST carries another is not
        // an expired session, it is a state no request can produce — and
        // a refusal proved against it would be proved against nothing.
        $_POST = [];

        $stale = new Request(
            'POST',
            OutboundMailController::PROBE_SEND_URL,
            [],
            [
                'destination' => 'ailleurs@exemple.be',
                'provider_id' => (string) MailProvider::LOCAL_ID,
                'probe_id' => (string) $id,
                'verdict' => 'inbox',
                '_csrf_token' => 'not-the-token',
            ],
            [],
            []
        );

        foreach (['sendProbe', 'recordProbeVerdict'] as $action) {
            $response = $this->controller->{$action}($stale, []);

            $this->assertSame(302, $response->getStatusCode(), "{$action} must refuse a stale token.");
        }

        $this->assertSame(1, $this->mailProbes->count(), 'no second probe may be sent on a stale token.');
        $this->assertNull($this->mailProbes->find($id)?->verdict, 'no verdict may be written on a stale token.');
    }

    /**
     * Sends one through the local relay and returns its id. The
     * transport in setUp() is a capture double, so nothing reaches a
     * network — but everything up to the point of handing the message
     * over is the production path.
     */
    private function sendOneProbe(string $destination = 'vous@exemple.be'): int
    {
        $this->controller->sendProbe($this->formRequest([
            'destination' => $destination,
            'provider_id' => (string) MailProvider::LOCAL_ID,
            'lane' => 'bulk',
        ]), []);
        \Core\Http\FlashMessage::get();

        $recent = $this->mailProbes->recent();
        $this->assertNotSame([], $recent, 'the probe fixture must actually have sent one.');

        return $recent[0]->id;
    }

    /**
     * With no relay to look for, a published SPF authorises nothing this
     * page can name — and answering « en place » was a green light the
     * check could not earn. A site sending from the web server itself,
     * under a zone that delegates its mail elsewhere
     * (`v=spf1 include:… -all`), had every message hard-fail while this
     * line showed a tick.
     */
    public function testTheDashboardNeverCallsAnUnverifiableSpfGreen(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        // A key pair, so the DKIM branch does not answer first and the
        // SPF reading is what this test is actually looking at.
        $this->dkim->generateKey();
        $this->controller->checkDns($this->formRequest([]), []);

        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('impossible donc de dire s’il autorise ce site', $body);
        $this->assertStringContainsString('aucun relais n’est actif', $body);
        $this->assertStringNotContainsString('enregistrement en place', $body);
    }

    /**
     * And a relay list that could not be READ says so, rather than
     * borrowing the answer of a site that has no relay.
     *
     * The two used to be one empty array, which is the expensive kind of
     * confusion: `sendingHosts()` catches its own failures, so a provider
     * table that would not load left nothing to look for in the record —
     * and the dashboard's SPF line went green on the strength of a query
     * that had failed. A failure that reads as a success is worse than a
     * failure.
     */
    public function testARelayListThatCannotBeReadIsNotARelayListThatIsEmpty(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->dkim->generateKey();

        // The providers table goes away under the controller's feet —
        // what `sendingHosts()`'s own catch block exists for. Before the
        // lookup, because `MailProviderDirectory::all()` memoises its
        // answer: dropping the table after a first successful read would
        // test the cache, not the failure.
        $this->pdo->exec('DROP TABLE mail_providers');
        $this->controller->checkDns($this->formRequest([]), []);

        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('la liste des relais n’a pas pu être lue', $body);
        $this->assertStringNotContainsString('enregistrement en place', $body);
    }

    /**
     * A reading is about a domain and a selector, not about « the site ».
     * Verify `ancien.be`, get a green answer remembered, then move the
     * expédition address to `nouveau.be`: every verdict on file now
     * answers a question nobody is asking, and the dashboard used to keep
     * showing the green tick for a zone that has never been looked at.
     */
    public function testAReadingTakenOnAnotherDomainNoLongerVouchesForThisOne(): void
    {
        $this->settings->set('mail_from_address', 'info@ancien.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->controller->checkDns($this->formRequest([]), []);

        $this->settings->set('mail_from_address', 'info@nouveau.be');

        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Les adresses ont changé depuis la dernière vérification DNS', $body);
        $this->assertStringContainsString('ancien.be', $body, 'It says which domain the old reading was about.');
        $this->assertStringNotContainsString('enregistrement en place', $body);
    }

    /**
     * And the DMARC report address alone is enough too, because
     * `checkDmarc()`'s verdict is a direct function of it: it looks for
     * `rua=mailto:{cette adresse}`. A reading taken for one address says
     * « publié » about a record that names the other.
     */
    public function testChangingOnlyTheDmarcReportAddressAlsoRetiresTheReading(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->settings->set('dmarc_report_email', 'rapports@unite.be');
        $this->controller->checkDns($this->formRequest([]), []);

        $this->settings->set('dmarc_report_email', 'autre@unite.be');

        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Les adresses ont changé depuis la dernière vérification DNS', $body);
    }

    /**
     * The staleness key is a fingerprint, not a second copy of the
     * address.
     *
     * The blob does carry the address once, and must: the DMARC record's
     * `expected` is the value an operator copies into their registrar's
     * form, `rua=mailto:…` included. What this pins is that comparing
     * readings did not add a SECOND place to keep it — the comparison
     * needs « same or not », which a digest answers.
     */
    public function testTheStalenessKeyIsAFingerprintAndNotASecondCopyOfTheAddress(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->settings->set('dmarc_report_email', 'rapports@unite.be');
        $this->controller->checkDns($this->formRequest([]), []);

        $stored = (string) $this->settings->get(\Core\Mail\DnsCheckMemory::SETTING_KEY);
        $decoded = json_decode($stored, true);

        $this->assertIsArray($decoded);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $decoded['dmarc_target']);
        $this->assertStringNotContainsString('@', (string) $decoded['dmarc_target']);
    }

    /**
     * The selector alone is enough: the DKIM record lives at
     * `{selector}._domainkey`, so changing it moves the record that was
     * checked without touching a single address.
     */
    public function testChangingOnlyTheDkimSelectorAlsoRetiresTheReading(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->controller->checkDns($this->formRequest([]), []);

        $this->settings->set('dkim_selector', 's2027');

        $body = (string) $this->controller->dashboard($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Les adresses ont changé depuis la dernière vérification DNS', $body);
    }

    /**
     * And the Authentification sub-page stops offering the records too:
     * they are the previous domain's, and they are there to be copied
     * into a registrar's form.
     */
    public function testTheAuthenticationPageStopsOfferingRecordsForADomainThatMoved(): void
    {
        $this->settings->set('mail_from_address', 'info@ancien.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->controller->checkDns($this->formRequest([]), []);

        $before = (string) $this->controller->authentication($this->getRequest(), [])->getBody();
        $this->assertStringContainsString('Relevé du', $before);
        $this->assertStringContainsString('ancien.be', $before);

        $this->settings->set('mail_from_address', 'info@nouveau.be');

        $after = (string) $this->controller->authentication($this->getRequest(), [])->getBody();
        $this->assertStringNotContainsString('Relevé du', $after);
        $this->assertStringContainsString('n\'ont jamais été vérifiés depuis cette page', $after);
    }

    public function testTheAuthenticationPageNamesTheFourRolesAndTheSpfTrap(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('mail_from_name', 'Unité Test');

        $body = (string) $this->controller->authentication($this->getRequest(), [])->getBody();

        $this->assertStringContainsString('Expéditeur affiché', $body);
        $this->assertStringContainsString('Réponses', $body);
        $this->assertStringContainsString('Retour des rebonds', $body);
        $this->assertStringContainsString('Rapports DMARC', $body);
        // The one line the table exists for.
        $this->assertStringContainsString('domaine sur lequel le SPF est vérifié', $body);
    }

    public function testTheAuthenticationPageRunsNoDnsLookupUntilSomebodyAsks(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');

        $body = (string) $this->controller->authentication($this->getRequest(), [])->getBody();

        $this->assertStringNotContainsString('Nom complet', $body);
        $this->assertStringContainsString('Vérifier les enregistrements', $body);
        $this->assertStringContainsString('jamais été vérifiés depuis cette page', $body);
    }

    public function testSavingTheAddressesKeepsThemAndTheReplyAddressStaysOptional(): void
    {
        $response = $this->controller->saveAuthentication($this->formRequest([
            'mail_from_address' => 'info@unite.be',
            'mail_from_name' => 'Unité Test',
            'mail_reply_address' => '',
            'dmarc_report_email' => 'dmarc@unite.be',
            'dkim_selector' => 's2026',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('info@unite.be', $this->settings->get('mail_from_address'));
        $this->assertSame('', $this->settings->get('mail_reply_address'));
        $this->assertSame('dmarc@unite.be', $this->settings->get('dmarc_report_email'));
    }

    /**
     * The one field that cannot be cleared: PHPMailer refuses a send
     * outright without a From, so an empty one is not « pas d'adresse »,
     * it is « plus aucun e-mail, liens de connexion compris ».
     */
    public function testTheExpeditionAddressCannotBeEmptied(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');

        $this->controller->saveAuthentication($this->formRequest([
            'mail_from_address' => '',
            'mail_from_name' => 'Unité Test',
            'dkim_selector' => 's2026',
        ]), []);

        $this->assertSame('info@unite.be', $this->settings->get('mail_from_address'));
    }

    public function testAMalformedReplyAddressIsRefusedWithoutTouchingAnythingElse(): void
    {
        $this->settings->set('mail_from_name', 'Avant');

        $this->controller->saveAuthentication($this->formRequest([
            'mail_from_address' => 'info@unite.be',
            'mail_from_name' => 'Après',
            'mail_reply_address' => 'pas-une-adresse',
            'dkim_selector' => 's2026',
        ]), []);

        $this->assertSame('Avant', $this->settings->get('mail_from_name'));
    }

    public function testASelectorWithACapitalIsRefused(): void
    {
        $this->settings->set('dkim_selector', 's2026');

        $this->controller->saveAuthentication($this->formRequest([
            'mail_from_address' => 'info@unite.be',
            'mail_from_name' => 'Unité Test',
            'dkim_selector' => 'S2027',
        ]), []);

        $this->assertSame('s2026', $this->settings->get('dkim_selector'));
    }

    /**
     * Changing where the site's mail comes from is a security decision
     * even when it is made in perfect good faith — an expédition address
     * pointing somewhere else is every sign-in link pointing somewhere
     * else. And the entry never carries the address: the journal is read
     * on a screen and kept for a long time, so it says which role
     * changed, exactly as `member_email_added` says `member_id` alone.
     */
    public function testChangingAnAddressIsJournaledAsSecurityWithoutTheAddress(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');

        $this->controller->saveAuthentication($this->formRequest([
            'mail_from_address' => 'contact@unite.be',
            'mail_from_name' => 'Unité Test',
            'mail_reply_address' => 'secretariat@unite.be',
            'dkim_selector' => 's2026',
        ]), []);

        $entry = $this->lastJournalEntry('mail_identity_changed');
        $this->assertSame('security', $entry['level']);

        $context = json_decode((string) $entry['context'], true);
        $this->assertIsArray($context);
        // English role identifiers, not the French labels the screen
        // shows: this is stored data, printed as a raw JSON block.
        $this->assertSame('from, reply', $context['roles']);
        $this->assertStringNotContainsString('expédition', (string) $entry['context']);

        // The whole point of the entry's shape: it names the role, never
        // the value.
        $this->assertStringNotContainsString('contact@unite.be', (string) $entry['context']);
        $this->assertStringNotContainsString('secretariat@unite.be', (string) $entry['context']);
    }

    public function testSavingThePageWithoutChangingAnAddressWritesNothingToTheJournal(): void
    {
        $saved = [
            'mail_from_address' => 'info@unite.be',
            'mail_from_name' => 'Unité Test',
            'mail_reply_address' => '',
            'dmarc_report_email' => '',
            'dkim_selector' => 's2026',
        ];
        $this->controller->saveAuthentication($this->formRequest($saved), []);
        $this->pdo->exec('DELETE FROM event_log');

        // The same values again: a page saved twice is one decision.
        $this->controller->saveAuthentication($this->formRequest($saved), []);

        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'mail_identity_changed'"
        );
        $this->assertNotFalse($statement);
        $this->assertSame(0, (int) $statement->fetchColumn());
    }

    /**
     * @return array<string, mixed>
     */
    private function lastJournalEntry(string $type): array
    {
        $statement = $this->pdo->prepare(
            'SELECT level, context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$type]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'No journal entry of type ' . $type . '.');

        return $row;
    }

    /**
     * The lookup writes down what it saw so the dashboard can report a
     * state with a date, rather than putting a resolver on the critical
     * path of the page somebody opens when mail is already broken.
     */
    public function testAskingForTheLookupRemembersWhatItSaw(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');

        $response = $this->controller->checkDns($this->formRequest([]), []);

        // POST-redirect-GET: the lookup reaches the network and writes
        // down what came back, neither of which belongs on a GET.
        $this->assertSame(302, $response->getStatusCode());

        $memory = \Core\Mail\DnsCheckMemory::read($this->settings);
        $this->assertNotNull($memory);
        $this->assertSame('unite.be', $memory->spfDomain);
        $this->assertSame('s2026', $memory->selector);
        // No key pair on this installation, so the DKIM record has no
        // value to publish — « non vérifié », never « absent ».
        $this->assertNull($memory->state(\Core\Mail\DnsCheckMemory::DKIM));
        // And no report address was asked for.
        $this->assertNull($memory->state(\Core\Mail\DnsCheckMemory::DMARC));
    }

    /**
     * **The reading has to be taken somewhere, and this is the only
     * somewhere** (issue #421). Nothing else on the site resolves an
     * `include:` chain, so a wiring that forgot this call would leave the
     * « Rapports DMARC » page saying « votre enregistrement SPF n'a pas
     * encore été lu » for ever, with no way for anybody to change that and
     * nothing failing.
     *
     * What this asserts is that the reading was TAKEN and dated, which is
     * the wiring; what it found is `SpfCoverageTest`'s subject.
     *
     * **And it asks no real resolver**, which it used to. The action now
     * reads TXT records through `DnsVerifier` — the same fake this file
     * already installs with a canned zone — so there is one seam for the
     * whole screen. The first version leaned on `.test` being reserved by
     * RFC 6761, which is true and is not the same thing as not leaving the
     * machine. Review of #571 pointed at it.
     */
    public function testTheDnsCheckAlsoTakesTheSpfCoverageReading(): void
    {
        $this->settings->setInternal(\Core\Mail\Feedback\Dmarc\SpfCoverage::SETTING_KEY, '');
        $this->assertTrue(\Core\Mail\Feedback\Dmarc\SpfCoverage::remembered($this->settings)->isEmpty());

        $this->settings->set('mail_from_address', 'info@unite.test');
        $this->settings->set('dkim_selector', 's2026');
        $this->controller->checkDns($this->formRequest([]), []);

        $taken = \Core\Mail\Feedback\Dmarc\SpfCoverage::remembered($this->settings);

        $this->assertFalse($taken->isEmpty(), 'The DNS check must have taken the SPF chain reading.');
        $this->assertSame('unite.test', $taken->domain);
        $this->assertFalse($taken->isStale());
    }

    /**
     * **And the SPF reading goes through THIS screen's verifier**, which is
     * what makes the test above deterministic rather than merely fast.
     *
     * Asserting « a reading was taken » is not enough: with the resolver
     * un-injected the action still takes one, finds nothing, and every
     * assertion about its date and domain holds — the mutation that removes
     * the seam survived exactly that way. So this one asks the verifier to
     * record what it was asked for, and reads back a range only the canned
     * zone publishes.
     */
    public function testTheSpfReadingIsTakenThroughTheScreensOwnResolver(): void
    {
        $asked = [];
        $dkim = $this->dkim;
        $recording = new class ($dkim, $asked) extends \Core\Mail\DnsVerifier {
            /** @param list<string> $asked */
            public function __construct(private \Core\Mail\DkimManager $dkim, private array &$asked)
            {
            }

            protected function readTxtRecords(string $host): ?array
            {
                $this->asked[] = $host;

                return $host === 'unite.be' ? ['v=spf1 ip4:203.0.113.0/24 -all'] : [];
            }
        };

        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');

        $this->controllerWith('dns', $recording)->checkDns($this->formRequest([]), []);

        $taken = \Core\Mail\Feedback\Dmarc\SpfCoverage::remembered($this->settings);

        $this->assertContains('unite.be', $asked, 'the chain walk has to use this screen\'s resolver.');
        $this->assertSame(
            'unite.be',
            $taken->viaFor('203.0.113.7'),
            'the range read is the one the canned zone publishes, so the reading came from it.'
        );
    }

    /**
     * **A host that did not answer has to reach the page as « incomplete »,
     * through the real verifier** — and it did not (found in review on #571,
     * twice, by two different readings of the declared types).
     *
     * `SpfCoverage::walk()` branches on `null` to mark a reading incomplete,
     * and the action's closure was declared `: array` over a verifier that
     * mapped a failed `dns_get_record()` to `[]`. So in production a resolver
     * that fell over mid-chain read as a host publishing nothing: the reading
     * was stored as complete, and the « un des domaines n'a pas répondu »
     * warning could not render at all. Every test passed, because the unit
     * test injects a closure of its own typed `?array`.
     *
     * This one goes the whole way round — action, verifier, walk, stored
     * reading — which is the only path that could have caught it. It stops at
     * the stored reading on purpose:
     * `testAHostThatDidNotAnswerIsReportedAsSuchRatherThanAsATooLongChain()`
     * already pins the sentence the page shows for it, and asserting the same
     * rendering twice would make one of the two the ornament.
     */
    public function testAHostThatDidNotAnswerReachesThePageAsAnIncompleteReading(): void
    {
        $dkim = $this->dkim;
        $failing = new class ($dkim) extends \Core\Mail\DnsVerifier {
            public function __construct(private \Core\Mail\DkimManager $dkim)
            {
            }

            protected function readTxtRecords(string $host): ?array
            {
                // The unit's own record reads; the include's target is the
                // host nobody could answer for — null, the way a real
                // `dns_get_record()` failure now arrives.
                return $host === 'unite.be'
                    ? ['v=spf1 include:_spf.injoignable.test -all']
                    : null;
            }
        };

        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');

        $this->controllerWith('dns', $failing)->checkDns($this->formRequest([]), []);

        $taken = \Core\Mail\Feedback\Dmarc\SpfCoverage::remembered($this->settings);

        $this->assertSame(
            \Core\Mail\Feedback\Dmarc\SpfCoverage::PARTIAL_UNREADABLE,
            $taken->partial,
            'a resolver that could not be asked makes the stored reading incomplete, not complete.'
        );
    }

    public function testTheRememberedRecordsSurviveAReopeningOfThePage(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->controller->checkDns($this->formRequest([]), []);

        $body = (string) $this->controller->authentication($this->getRequest(), [])->getBody();

        // The records are what somebody is halfway through copying into
        // their registrar's form; losing them on the next page load is
        // exactly what keeping the reading is for.
        $this->assertStringContainsString('Relevé du', $body);
        $this->assertStringContainsString('v=spf1', $body);
    }

    public function testALookupNobodyCouldTakeRemembersNothingRatherThanAFalseNegative(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->controller->checkDns($this->formRequest([]), []);
        $this->assertNotNull(\Core\Mail\DnsCheckMemory::read($this->settings));

        // No address left: there is no domain to interrogate at all, and
        // a false negative is indistinguishable from a real one.
        $this->settings->set('mail_from_address', '');
        $this->controller->checkDns($this->formRequest([]), []);

        $this->assertNull(\Core\Mail\DnsCheckMemory::read($this->settings));
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

    /**
     * The mail identity settings, declared here exactly as
     * `public/index.php` declares them — `SettingService::set()` refuses
     * a key nothing registered, so a page that saves one is a page whose
     * test has to have it. {@see self::testEveryAddressThePageSavesIsDeclaredAtBoot()}
     * is what keeps the two lists from drifting.
     */
    private static function registerMailIdentitySettings(SettingService $settings): void
    {
        $settings->register('mail_from_address', '', 'email', 'Email d\'expédition', '', null, null, null, true, 40);
        $settings->register('mail_from_name', '', 'text', 'Nom d\'expédition', '', null, null, null, true, 50);
        $settings->register('mail_reply_address', '', 'email', 'Adresse de réponse', '', null, null, null, true, 55);
        $settings->register(
            \Core\Mail\DnsCheckMemory::SETTING_KEY,
            '',
            'text',
            'Dernière vérification DNS',
            '',
            null,
            null,
            null,
            false,
            56
        );
        $settings->register('dkim_selector', 's2026', 'text', 'Sélecteur DKIM', '', null, '^[a-z0-9]+$', null, true, 60);
        $settings->register('dmarc_report_email', '', 'email', 'Email rapports DMARC', '', null, null, null, true, 70);
    }

    /**
     * A setting the page writes and the boot never declares is a page
     * that throws the first time somebody presses Enregistrer — and
     * nothing else would say so, because this test builds its own
     * registrations.
     */
    public function testEveryAddressThePageSavesIsDeclaredAtBoot(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        $this->assertNotFalse($contents);

        // Matched on the key each call actually declares, whether it is
        // spelled as a literal or through the constant that owns it —
        // asserting one spelling would make a harmless rename of the
        // other read as a missing registration.
        $declared = self::settingKeysRegisteredIn($contents);

        foreach ([
            \Core\Mail\MailIdentity::SETTING_FROM_ADDRESS,
            \Core\Mail\MailIdentity::SETTING_FROM_NAME,
            \Core\Mail\MailIdentity::SETTING_REPLY_ADDRESS,
            \Core\Mail\MailIdentity::SETTING_DMARC_REPORT,
            \Core\Mail\DnsCheckMemory::SETTING_KEY,
            'dkim_selector',
        ] as $key) {
            $this->assertContains(
                $key,
                $declared,
                "public/index.php never registers « {$key} », so saving it would throw."
            );
        }
    }

    /**
     * Every setting key a composition root declares, resolved through the
     * constant when the call uses one.
     *
     * @return array<int, string>
     */
    private static function settingKeysRegisteredIn(string $source): array
    {
        $matched = preg_match_all(
            '/\$settingService->register\(\s*([^,]+),/',
            $source,
            $matches
        );
        self::assertNotFalse($matched);

        $keys = [];
        foreach ($matches[1] as $argument) {
            $argument = trim($argument);

            if (preg_match('/^\'([^\']+)\'$/', $argument, $literal) === 1) {
                $keys[] = $literal[1];
                continue;
            }

            if (preg_match('/^\\\\?[A-Za-z0-9_\\\\]+::[A-Z_]+$/', $argument) === 1 && defined($argument)) {
                $value = constant($argument);
                if (is_string($value)) {
                    $keys[] = $value;
                }
            }
        }

        return $keys;
    }

    /**
     * Canned TXT records, through `DnsVerifier::readTxtRecords()` — the one
     * seam that class documents as overridable, and the only place it reaches
     * a resolver. A fixture that replaced one of the two there used to be left
     * the other asking the network (issue #421, found in review).
     *
     * A host outside the canned zone answers `[]`, « publishes nothing »,
     * never `null`: null is the separate answer « the resolver could not be
     * asked », and handing it back here would make every reading in this
     * suite incomplete.
     */
    private static function fakeDnsVerifier(\Core\Mail\DkimManager $dkim): \Core\Mail\DnsVerifier
    {
        return new class ($dkim) extends \Core\Mail\DnsVerifier {
            public function __construct(private \Core\Mail\DkimManager $dkim)
            {
            }

            protected function readTxtRecords(string $host): ?array
            {
                if ($host === 'unite.be') {
                    return ['v=spf1 a mx ~all'];
                }

                // The zone publishes the key the site actually holds —
                // otherwise a test that needs a *valid* DKIM reading to
                // get past it can never have one, and the DKIM branch
                // answers for every question asked further down.
                if (str_contains($host, '._domainkey.') && $this->dkim->hasKey()) {
                    return ['v=DKIM1; k=rsa; p=' . $this->dkim->getPublicKey()];
                }

                return [];
            }
        };
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

    /**
     * The branch that says the round trip ran and nothing left.
     *
     * Three outcomes come back from `ReturnPathVerifier::launch()` and
     * the screen says a different thing for each: impossible (the module
     * or the mailbox is missing), nothing sent, or a count. The middle
     * one is the only one that means « the configuration is complete and
     * the transport still refused », which is why its message points at
     * Fournisseurs rather than at the module — and it was the one no
     * test exercised, found by SonarCloud when the surrounding lines
     * were reformatted.
     *
     * Built with a real verifier rather than a stub: what makes `sent`
     * zero without `impossible` is a mailbox that IS open to this
     * consumer and a send that throws, and a stub returning the triple
     * by hand would assert nothing about that combination being
     * reachable.
     */
    public function testAVerificationThatSendsNothingPointsAtTheProvidersPage(): void
    {
        $inbound = $this->createStub(\Modules\InboundMail\Api\InboundMailInterface::class);
        $inbound->method('probeAddressesFor')->willReturn(['retours@unite.be']);

        $refusing = $this->createStub(\Core\Mail\MailService::class);
        $refusing->method('withoutDeferral')->willReturnSelf();
        $refusing->method('send')->willThrowException(new \RuntimeException('relay refused'));

        $arguments = $this->controllerArguments;
        $index = array_search($this->returns, $arguments, true);
        self::assertIsInt($index, 'the verifier is no longer one of the controller arguments');
        $arguments[$index] = new \Core\Mail\Feedback\ReturnPathVerifier(
            new \Core\Mail\Feedback\ReturnProbeRepository(
                $this->pdo,
                new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
            ),
            $refusing,
            new JournalService(new JournalRepository($this->pdo)),
            $inbound
        );

        $response = (new OutboundMailController(...$arguments))
            ->verifyReturns($this->formRequest([]), []);

        $flash = \Core\Http\FlashMessage::get();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString(
            'Aucun message de vérification n’a pu partir',
            (string) ($flash['message'] ?? '')
        );
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

    // ————— Régénérer la clé DKIM (#336) —————

    /**
     * **The regeneration lives here now**, and this is the page that can
     * say what it costs.
     *
     * « Installation & serveur » carried a checkbox for it and could tell
     * the operator nothing about the DNS record having to follow, so a key
     * rotated there left the site failing DKIM with nothing on screen. This
     * page shows the public key, proposes the record and checks it live.
     */
    public function testRegeneratingTheDkimKeyMintsANewOne(): void
    {
        $this->dkim->generateKey();
        $before = $this->dkim->getPublicKey();
        $this->assertNotSame('', $before, 'the fixture needs a key for the rotation to be observable');

        $this->controller->regenerateDkimKey($this->formRequest([]), []);

        $after = $this->dkim->getPublicKey();
        $this->assertNotSame('', $after, 'the rotation left the site with no key at all');
        $this->assertNotSame($before, $after, 'the key was not rotated');
    }

    /**
     * And it forgets what the DNS last said — the one invalidation that
     * cannot be derived.
     *
     * A stored reading holds the key it was taken against, and nothing in
     * it can name the key in use NOW. Left behind, it goes on reporting the
     * previous key as published: a green tick over mail every receiver
     * rejects. `Tests\Architecture\DkimKeyChangeForgetsDnsTest` holds every
     * place that touches the key to doing this; this test is the behaviour
     * behind that rule.
     */
    public function testRegeneratingTheDkimKeyForgetsTheRememberedDnsReading(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->dkim->generateKey();
        $this->controller->checkDns($this->formRequest([]), []);

        $before = (string) $this->controller->authentication($this->getRequest(), [])->getBody();
        $this->assertStringContainsString(
            'Relevé du',
            $before,
            'the fixture needs a remembered reading for its loss to be observable'
        );

        $this->controller->regenerateDkimKey($this->formRequest([]), []);

        $after = (string) $this->controller->authentication($this->getRequest(), [])->getBody();
        $this->assertStringNotContainsString(
            'Relevé du',
            $after,
            'the reading survived the rotation, so the page reports the previous key as published'
        );
    }

    /**
     * **And when the generation fails, the old key is still signing.**
     *
     * This test used to assert the opposite, and it was right to: the
     * rotation was `deleteKey()` then `generateKey()`, so a failure of the
     * second left the installation with no key at all and the message had to
     * say so. Issue #547 removed that state instead of describing it —
     * `DkimManager::replaceKey()` writes the pair beside the live file and
     * `rename()`s it into place, so a failure changes nothing.
     *
     * Two assertions are the whole point, and neither existed before:
     *
     * 1. **the previous key is still there and still usable** — read back
     *    through `getPublicKey()`, which parses the file rather than
     *    trusting that it exists;
     * 2. **the remembered DNS reading survived**, because it still describes
     *    the key in service. Together with
     *    testRegeneratingTheDkimKeyForgetsTheRememberedDnsReading() above —
     *    where a rotation that SUCCEEDS loses the reading — this pair pins
     *    the ORDER of the invalidation, which
     *    `Tests\Architecture\DkimKeyChangeForgetsDnsTest` cannot: that guard
     *    reads the whole method and accepts the call anywhere in it.
     *
     * The double overrides `replaceKey()` and not `generateKey()`, which is
     * how this test caught its own staleness: left on `generateKey()`, the
     * fake stopped refusing anything and the rotation quietly succeeded.
     */
    public function testAFailedRegenerationLeavesTheOldKeySigningAndKeepsTheReading(): void
    {
        $this->settings->set('mail_from_address', 'info@unite.be');
        $this->settings->set('dkim_selector', 's2026');
        $this->dkim->generateKey();
        $survivor = $this->dkim->getPublicKey();
        $this->controller->checkDns($this->formRequest([]), []);
        $this->assertStringContainsString(
            'Relevé du',
            (string) $this->controller->authentication($this->getRequest(), [])->getBody(),
            'the fixture needs a remembered reading for its survival to be observable'
        );

        $refuses = new class ($this->secretsDirectory) extends \Core\Mail\DkimManager {
            public function replaceKey(): string
            {
                throw new \RuntimeException('Failed to generate DKIM key pair: openssl_pkey_new(): unavailable');
            }
        };
        $controller = new OutboundMailController(...array_replace(
            $this->controllerArguments,
            [10 => $refuses]
        ));

        $response = $controller->regenerateDkimKey($this->formRequest([]), []);

        $this->assertSame(302, $response->getStatusCode(), 'the operator is sent back to the page, not to a 500');

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString(
            'toujours signés',
            (string) ($flash['message'] ?? ''),
            'the message has to say what the failure LEFT, and what it left is a working key'
        );
        $this->assertStringNotContainsString(
            'openssl_pkey_new',
            (string) ($flash['message'] ?? ''),
            'whatever OpenSSL says is English and technical, and it reached a visitor'
        );

        $this->assertTrue($this->dkim->hasKey(), 'the failed rotation took the key away');
        $this->assertSame(
            $survivor,
            $this->dkim->getPublicKey(),
            'the key on disk is no longer the one that was signing before the failed rotation'
        );

        $this->assertStringContainsString(
            'Relevé du',
            (string) $this->controller->authentication($this->getRequest(), [])->getBody(),
            'a reading that still describes the key in service was thrown away'
        );

        $entries = $this->pdo->query(
            "SELECT event_type FROM event_log WHERE event_type = 'dkim_key_regeneration_failed'"
        )->fetchAll();
        $this->assertCount(1, $entries, 'nothing recorded that the rotation failed');
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
