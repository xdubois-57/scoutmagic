<?php

declare(strict_types=1);

namespace Tests\Core\Contact\CardDav\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Contact\CardDav\AddressBookRepository;
use Core\Contact\CardDav\AddressBookService;
use Core\Contact\CardDav\Controller\CardDavController;
use Core\Contact\CardDav\DavRequestParser;
use Core\Contact\CardDav\DavXml;
use Core\Contact\ContactCardService;
use Core\Contact\Device\DeviceAuthenticator;
use Core\Contact\Device\DeviceCredentialRepository;
use Core\Contact\Device\DeviceCredentialService;
use Core\Contact\Repository\ContactCardRepository;
use Core\Contact\VCardBuilder;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\ScoutYear\AuthorizationYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The protocol, end to end: what a real address-book client sends and
 * what comes back.
 *
 * The refusals are the load-bearing half. Every route in this controller
 * is `role_min: public` and none of them checks a CSRF token — the
 * second deliberate exception to `SECURITY.md` §4 — so the only thing
 * standing between a stranger and a unit's leaders is the credential
 * check at the top of each action.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CardDavControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private CardDavController $controller;
    private DeviceCredentialService $credentials;
    private int $yearId;
    private int $memberId;
    private int $accountId;
    private string $secret = '';
    private const EMAIL = 'chef@example.org';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register('site_name', '15e Unité Saint-Michel', 'text', 'Nom', 'Nom');
        $settings->register(
            DeviceCredentialService::SETTING_SYNC_ENABLED,
            '1',
            'boolean',
            'Synchronisation',
            'Le coupe-circuit.',
            null,
            null,
            null,
            false
        );
        $settings->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'P', 'P', null, '^[0-9]+$', null, false);
        $settings->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'S', 'S', null, '^[0-9]+$', null, false);

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $stmt = $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$label, $start, $end]);
        $this->yearId = (int) $this->pdo->lastInsertId();
        $settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearId);
        $settings->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearId);

        $deviceRepository = new DeviceCredentialRepository($this->pdo);
        $this->credentials = new DeviceCredentialService(
            $deviceRepository,
            $settings,
            new JournalService(new JournalRepository($this->pdo))
        );

        $scoutYearService = new ScoutYearService($this->pdo);
        $authenticator = new DeviceAuthenticator(
            $deviceRepository,
            $this->credentials,
            new UserAccountRepository($this->pdo, $this->enc),
            new RoleResolver(new MemberYearRepository($this->pdo), $this->enc, $this->pdo),
            new AuthorizationYearService($scoutYearService, $settings),
            $this->enc,
            new JournalService(new JournalRepository($this->pdo)),
            new HumanCheckRateLimitRepository($this->pdo)
        );

        $this->controller = new CardDavController(
            new Environment(new ArrayLoader([])),
            $authenticator,
            new AddressBookService(
                new AddressBookRepository($connection),
                new ContactCardRepository($connection),
                new ContactCardService(
                    new ContactCardRepository($connection),
                    $settings,
                    new MemberEmailRepository($this->pdo, $this->enc)
                ),
                new VCardBuilder(),
                new MemberService(new MemberYearRepository($this->pdo), $this->enc, $connection),
                $scoutYearService
            ),
            new DavRequestParser()
        );

        $this->memberId = $this->buildTheStaff();
        $this->accountId = (new UserAccountRepository($this->pdo, $this->enc))->create(self::EMAIL)->id;
        $this->secret = $this->credentials->create($this->accountId, 'Téléphone', $this->accountId)->secret;
    }

    /**
     * One chef d'unité, holding the account the device belongs to, so
     * the role resolves to admin exactly as it would for a session.
     */
    private function buildTheStaff(): int
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOUV', 'Louveteaux')");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (age_branch_id, desk_code, name, is_visible, is_active) VALUES (?, ?, ?, 1, 1)'
        );
        $stmt->execute([$branchId, 'LOUV1', 'Les Loups Gris']);
        $sectionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute(['CU', 'Chef d\'unité', 'admin']);
        $functionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['D-1']);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                email_encrypted, email_blind_index, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->yearId,
            $this->enc->encrypt('Camille', 'member_years.first_name'),
            $this->enc->encrypt('Dupont', 'member_years.last_name'),
            $this->enc->encrypt(self::EMAIL, 'member_years.email'),
            $this->enc->blindIndex(self::EMAIL, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return $memberId;
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $method, string $path, string $raw = '', array $server = []): Request
    {
        return new class ($method, $path, [], [], [], $server, $raw) extends Request {
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

    /** @return array<string, string> */
    private function signedIn(?string $secret = null): array
    {
        return ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(self::EMAIL . ':' . ($secret ?? $this->secret))];
    }

    // ---------------------------------------------------------------
    // What a stranger gets
    // ---------------------------------------------------------------

    public function testEveryRouteRefusesACallerCarryingNoCredential(): void
    {
        $paths = [
            ['propfind', 'PROPFIND', '/carddav/'],
            ['propfind', 'PROPFIND', '/carddav/staff/'],
            ['report', 'REPORT', '/carddav/staff/'],
            ['announce', 'OPTIONS', '/carddav/staff/'],
        ];

        foreach ($paths as [$action, $method, $path]) {
            $response = $this->controller->{$action}($this->request($method, $path));
            $this->assertSame(401, $response->getStatusCode(), $method . ' ' . $path);
            $this->assertSame('', $response->getBody(), $method . ' ' . $path);
        }

        $card = $this->controller->card(
            $this->request('GET', '/carddav/staff/' . $this->memberId . '.vcf'),
            ['member_id' => (string) $this->memberId]
        );
        $this->assertSame(401, $card->getStatusCode());
    }

    /**
     * A 401 without `WWW-Authenticate` is a dead end: the client has no
     * way of knowing it should ask its user for a password.
     */
    public function testTheRefusalSaysWhichSchemeToUse(): void
    {
        $response = $this->controller->propfind($this->request('PROPFIND', '/carddav/'));

        $this->assertSame(
            'Basic realm="ScoutMagic", charset="UTF-8"',
            $response->getHeaders()['WWW-Authenticate'] ?? null
        );
        $this->assertStringNotContainsString('Digest', (string) ($response->getHeaders()['WWW-Authenticate'] ?? ''));
    }

    /**
     * Wrong secret, unknown address, revoked credential, cut-out thrown:
     * one answer, so nothing here tells a caller which of them it was.
     */
    public function testEveryKindOfBadCredentialGetsTheSameAnswer(): void
    {
        $wrong = $this->controller->propfind(
            $this->request('PROPFIND', '/carddav/', '', $this->signedIn(str_repeat('f', 64)))
        );
        $unknown = $this->controller->propfind($this->request('PROPFIND', '/carddav/', '', [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('nobody@example.org:' . $this->secret),
        ]));

        $this->credentials->revoke($this->credentials->listForAccount($this->accountId)[0], $this->accountId);
        $revoked = $this->controller->propfind(
            $this->request('PROPFIND', '/carddav/', '', $this->signedIn())
        );

        foreach ([$wrong, $unknown, $revoked] as $response) {
            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame('', $response->getBody());
        }
    }

    /**
     * The site-wide cut-out reaches the protocol, not only the screens:
     * a superadmin who switches synchronisation off stops every client
     * at its next poll.
     */
    public function testTheSiteWideCutOutStopsTheProtocol(): void
    {
        $this->credentials->setSyncEnabled(false, $this->accountId);

        $response = $this->controller->propfind(
            $this->request('PROPFIND', '/carddav/', '', $this->signedIn())
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * The rule that does not bend, seen from the protocol: the role is
     * re-resolved on every request, so a chef who leaves the staff stops
     * synchronising without anybody revoking anything.
     */
    public function testAnAccountThatIsNoLongerAdminStopsSynchronising(): void
    {
        $this->pdo->exec("UPDATE functions SET role = 'identified'");

        $response = $this->controller->propfind(
            $this->request('PROPFIND', '/carddav/', '', $this->signedIn())
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // The protocol
    // ---------------------------------------------------------------

    public function testAutodiscoveryPointsAtTheRootAndNeedsNoCredential(): void
    {
        $response = $this->controller->wellKnown($this->request('GET', '/.well-known/carddav'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/carddav/', $response->getHeaders()['Location'] ?? null);
    }

    public function testOptionsAnnouncesAnAddressBookServer(): void
    {
        $response = $this->controller->announce(
            $this->request('OPTIONS', '/carddav/staff/', '', $this->signedIn())
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('1, 3, addressbook', $response->getHeaders()['DAV'] ?? null);
        // Locking is deliberately absent: nothing here is writable.
        $this->assertStringNotContainsString('2', (string) ($response->getHeaders()['DAV'] ?? ''));
        $this->assertStringContainsString('PROPFIND', (string) ($response->getHeaders()['Allow'] ?? ''));
        $this->assertStringContainsString('REPORT', (string) ($response->getHeaders()['Allow'] ?? ''));
        $this->assertStringNotContainsString('PUT', (string) ($response->getHeaders()['Allow'] ?? ''));
    }

    public function testTheRootResolvesThePrincipalAndTheHomeSet(): void
    {
        $response = $this->controller->propfind($this->request(
            'PROPFIND',
            '/carddav/',
            '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"><D:prop>'
            . '<D:current-user-principal /><C:addressbook-home-set /></D:prop></D:propfind>',
            $this->signedIn() + ['HTTP_DEPTH' => '0']
        ));

        $this->assertSame(207, $response->getStatusCode());
        $this->assertStringContainsString('application/xml', (string) ($response->getHeaders()['Content-Type'] ?? ''));

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($response->getBody()));
        $this->assertSame(1, $document->getElementsByTagNameNS(DavXml::NS_DAV, 'current-user-principal')->length);
        $this->assertSame(1, $document->getElementsByTagNameNS(DavXml::NS_CARDDAV, 'addressbook-home-set')->length);
    }

    public function testTheRootListsTheCollectionAtDepthOne(): void
    {
        $response = $this->controller->propfind($this->request(
            'PROPFIND',
            '/carddav/',
            '',
            $this->signedIn() + ['HTTP_DEPTH' => '1']
        ));

        $this->assertStringContainsString('<D:href>/carddav/staff/</D:href>', $response->getBody());
    }

    public function testTheCollectionAnnouncesItsTagAndItsReports(): void
    {
        $response = $this->controller->propfind($this->request(
            'PROPFIND',
            '/carddav/staff/',
            '',
            $this->signedIn() + ['HTTP_DEPTH' => '0']
        ));

        $body = $response->getBody();
        $this->assertStringContainsString('<CS:getctag>', $body);
        $this->assertStringContainsString('addressbook-multiget', $body);
        $this->assertStringContainsString('addressbook-query', $body);
        // Read, and nothing else — so a client stops offering its user an
        // « add contact » button for this address book.
        $this->assertStringContainsString('<D:read />', $body);
    }

    /**
     * The listing a client polls with: one entry per card, each with its
     * tag — and **no card bodies**, which is what makes the poll cheap
     * enough to answer every few minutes.
     */
    public function testTheListingCarriesATagPerCardAndNoCardBodies(): void
    {
        $response = $this->controller->propfind($this->request(
            'PROPFIND',
            '/carddav/staff/',
            '<D:propfind xmlns:D="DAV:"><D:prop><D:getetag /></D:prop></D:propfind>',
            $this->signedIn() + ['HTTP_DEPTH' => '1']
        ));

        $body = $response->getBody();
        $this->assertStringContainsString('<D:href>/carddav/staff/' . $this->memberId . '.vcf</D:href>', $body);
        $this->assertStringContainsString('<D:getetag>', $body);
        $this->assertStringNotContainsString('BEGIN:VCARD', $body);
    }

    public function testAMultigetReturnsTheCardsThatWereAskedFor(): void
    {
        $response = $this->controller->report($this->request(
            'REPORT',
            '/carddav/staff/',
            '<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:prop><D:getetag /><C:address-data /></D:prop>'
            . '<D:href>/carddav/staff/' . $this->memberId . '.vcf</D:href>'
            . '</C:addressbook-multiget>',
            $this->signedIn()
        ));

        $this->assertSame(207, $response->getStatusCode());
        $this->assertStringContainsString('BEGIN:VCARD', $response->getBody());
        $this->assertStringContainsString('Camille', $response->getBody());
    }

    /**
     * RFC 6352 §8.7: one href that cannot be resolved gets its own 404
     * response, and the others still come back. A report that failed
     * whole would make one stale path break every sync.
     */
    public function testAMultigetNamingAMissingCardStillReturnsTheOthers(): void
    {
        $response = $this->controller->report($this->request(
            'REPORT',
            '/carddav/staff/',
            '<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:prop><C:address-data /></D:prop>'
            . '<D:href>/carddav/staff/999999.vcf</D:href>'
            . '<D:href>/carddav/staff/' . $this->memberId . '.vcf</D:href>'
            . '</C:addressbook-multiget>',
            $this->signedIn()
        ));

        $this->assertSame(207, $response->getStatusCode());
        $this->assertStringContainsString('HTTP/1.1 404 Not Found', $response->getBody());
        $this->assertStringContainsString('BEGIN:VCARD', $response->getBody());
    }

    /**
     * RFC 4918 lets a client send an absolute URI where it was given a
     * path, and the 404 it gets back has to be matchable against the
     * href it asked for — echoing the value verbatim through an encoder
     * that works segment by segment would turn `http://` into
     * `http%3A//` and leave the client unable to correlate it.
     */
    public function testAMultigetHrefThatIsAnAbsoluteUriComesBackAsAUsablePath(): void
    {
        $response = $this->controller->report($this->request(
            'REPORT',
            '/carddav/staff/',
            '<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:prop><C:address-data /></D:prop>'
            . '<D:href>https://unite.example.org/carddav/staff/999999.vcf</D:href>'
            . '</C:addressbook-multiget>',
            $this->signedIn()
        ));

        $this->assertStringNotContainsString('http%3A', $response->getBody());
        $this->assertStringNotContainsString('https%3A', $response->getBody());
        $this->assertStringContainsString('<D:href>/carddav/staff/999999.vcf</D:href>', $response->getBody());
        $this->assertStringContainsString('HTTP/1.1 404 Not Found', $response->getBody());
    }

    /**
     * And an href pointing somewhere else entirely keeps its own path,
     * so the client can still see which of its requests this answers.
     */
    public function testAMultigetHrefOutsideTheCollectionKeepsItsPath(): void
    {
        $response = $this->controller->report($this->request(
            'REPORT',
            '/carddav/staff/',
            '<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:prop><C:address-data /></D:prop>'
            . '<D:href>https://unite.example.org/autre/collection/1.vcf</D:href>'
            . '</C:addressbook-multiget>',
            $this->signedIn()
        ));

        $this->assertStringContainsString('<D:href>/autre/collection/1.vcf</D:href>', $response->getBody());
        $this->assertStringContainsString('HTTP/1.1 404 Not Found', $response->getBody());

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($response->getBody()));
    }

    public function testAQueryReturnsTheWholeCollection(): void
    {
        $response = $this->controller->report($this->request(
            'REPORT',
            '/carddav/staff/',
            '<C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:prop><D:getetag /><C:address-data /></D:prop></C:addressbook-query>',
            $this->signedIn()
        ));

        $this->assertSame(207, $response->getStatusCode());
        $this->assertStringContainsString('BEGIN:VCARD', $response->getBody());
    }

    public function testAReportThisServerDoesNotImplementSaysSo(): void
    {
        $response = $this->controller->report($this->request(
            'REPORT',
            '/carddav/staff/',
            '<D:sync-collection xmlns:D="DAV:" />',
            $this->signedIn()
        ));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('supported-report', $response->getBody());
    }

    public function testOneCardComesBackAsVcard(): void
    {
        $response = $this->controller->card(
            $this->request('GET', '/carddav/staff/' . $this->memberId . '.vcf', '', $this->signedIn()),
            ['member_id' => (string) $this->memberId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/vcard; charset=utf-8', $response->getHeaders()['Content-Type'] ?? null);
        $this->assertStringContainsString('BEGIN:VCARD', $response->getBody());
        $this->assertNotNull($response->getHeaders()['ETag'] ?? null);
        // Somebody's home address: never in a shared cache.
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control'] ?? null);
    }

    public function testACardForSomebodyOutsideTheAddressBookIsNotFound(): void
    {
        $response = $this->controller->card(
            $this->request('GET', '/carddav/staff/999999.vcf', '', $this->signedIn()),
            ['member_id' => '999999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Desk is the source of truth. A 403 rather than a 405, so a client
     * shows its user a read-only address book instead of hunting for
     * somewhere else to write.
     */
    public function testWritingIsRefused(): void
    {
        foreach (['PUT', 'DELETE'] as $method) {
            $response = $this->controller->refuseWrite(
                $this->request($method, '/carddav/staff/' . $this->memberId . '.vcf', '', $this->signedIn()),
                ['member_id' => (string) $this->memberId]
            );

            $this->assertSame(403, $response->getStatusCode(), $method);
            $this->assertStringContainsString('need-privileges', $response->getBody(), $method);
        }
    }

    /**
     * A write attempt from a stranger is refused for being a stranger,
     * not for being a write: the credential check comes first, so an
     * unauthenticated caller cannot learn what this collection contains
     * by reading which refusal it gets.
     */
    public function testAStrangerIsRefusedBeforeTheWriteIsEvenConsidered(): void
    {
        $response = $this->controller->refuseWrite(
            $this->request('PUT', '/carddav/staff/' . $this->memberId . '.vcf'),
            ['member_id' => (string) $this->memberId]
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // The host this runs on
    // ---------------------------------------------------------------

    /**
     * Shared hosting is the target of this project, and under CGI and
     * FastCGI the `Authorization` header arrives — when the `.htaccess`
     * has copied it — spelled `REDIRECT_HTTP_AUTHORIZATION`. Reading only
     * the plain spelling would answer 401 forever on exactly the hosts
     * this feature is for.
     */
    public function testCredentialsAreFoundUnderTheRedirectSpelling(): void
    {
        $response = $this->controller->propfind($this->request('PROPFIND', '/carddav/', '', [
            'REDIRECT_HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(self::EMAIL . ':' . $this->secret),
        ]));

        $this->assertSame(207, $response->getStatusCode());
    }

    /**
     * And under mod_php there is no header at all — Apache hands PHP the
     * decoded pair instead.
     */
    public function testCredentialsAreFoundWhenApacheDecodedThemItself(): void
    {
        $response = $this->controller->propfind($this->request('PROPFIND', '/carddav/', '', [
            'PHP_AUTH_USER' => self::EMAIL,
            'PHP_AUTH_PW' => $this->secret,
        ]));

        $this->assertSame(207, $response->getStatusCode());
    }

    /**
     * A successful synchronisation moves the credential's « last seen »
     * and writes NOTHING to the journal: a client polls every few
     * minutes, and a line per poll would bury everything else in it.
     */
    public function testASuccessfulSyncIsRecordedOnTheDeviceAndNotInTheJournal(): void
    {
        $before = $this->journalCount();

        $this->controller->propfind($this->request('PROPFIND', '/carddav/staff/', '', $this->signedIn()));

        $this->assertSame($before, $this->journalCount());
        $stmt = $this->pdo->prepare('SELECT last_sync_at FROM device_credentials WHERE user_account_id = ?');
        $stmt->execute([$this->accountId]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    private function journalCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_log');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
