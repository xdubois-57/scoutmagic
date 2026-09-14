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
    private \Core\Mail\DkimManager $dkim;
    private OutboundMailController $controller;
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
            new JournalService(new JournalRepository($this->pdo))
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

        // Recursively, because `DkimManager::generateKey()` writes into a
        // `dkim/` subdirectory: the flat version left the temporary
        // directory behind on every test that generates a key, and said
        // so only as a PHP warning nobody reads.
        self::removeDirectory($this->secretsDirectory);
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
     * Canned TXT records, through the seam `DnsVerifier::getTxtRecords()`
     * documents as « overridable for testing ».
     */
    private static function fakeDnsVerifier(\Core\Mail\DkimManager $dkim): \Core\Mail\DnsVerifier
    {
        return new class ($dkim) extends \Core\Mail\DnsVerifier {
            public function __construct(private \Core\Mail\DkimManager $dkim)
            {
            }

            protected function getTxtRecords(string $host): array
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
