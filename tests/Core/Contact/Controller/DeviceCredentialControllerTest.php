<?php

declare(strict_types=1);

namespace Tests\Core\Contact\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Contact\CardDav\AddressBookService;
use Core\Contact\Controller\DeviceCredentialController;
use Core\Contact\Device\DeviceCredentialRepository;
use Core\Contact\Device\DeviceCredentialService;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * « Synchroniser mes contacts » under Mon compte: what it creates, what it
 * refuses, and whose credentials it will touch.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeviceCredentialControllerTest extends TestCase
{
    private \PDO $pdo;
    private DeviceCredentialController $controller;
    private DeviceCredentialService $service;
    private int $accountId = 1;
    private int $otherAccountId = 2;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $settings = new SettingService(new SettingRepository($this->pdo));
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

        $this->service = new DeviceCredentialService(
            new DeviceCredentialRepository($this->pdo),
            $settings,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->controller = new DeviceCredentialController(
            new Environment(new ArrayLoader([
                'account/devices.html.twig' => '{{ credentials|length }} appareils',
                'errors/404.html.twig' => 'Introuvable',
            ])),
            $this->service
        );

        foreach (['a', 'b'] as $marker) {
            $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
            $stmt->execute(['x', str_repeat($marker, 64)]);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->accountId, 'chef@example.org', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/api/account/devices', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn((string) json_encode($data));

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function formRequest(array $body): Request
    {
        return new Request('POST', '/account/devices/1/revoke', [], $body, [], []);
    }

    /**
     * The controller hands the page the full CardDAV address.
     *
     * iOS given the domain alone reaches `/.well-known/carddav` first, and
     * some hosts answer that with their own 403 page before PHP is ever
     * reached — so « Vérification » spins forever on correct credentials.
     * `https://…/carddav/staff/` connects. The page recommended the domain
     * and offered the full address only as a fallback, which is backwards
     * (#483).
     *
     * This half proves the VALUE reaches the template;
     * `Tests\Core\View\ContactSyncPageSaysWhatToTypeTest` proves the
     * template puts it where a phone is configured. The stub loader in
     * `setUp()` cannot do both, and one assertion pretending to would be
     * the weaker claim of the two.
     */
    public function testTheControllerGivesThePageTheFullServerAddress(): void
    {
        $controller = new DeviceCredentialController(
            new Environment(new ArrayLoader([
                'account/devices.html.twig' => '{{ site_url }}{{ carddav_collection_path }}',
                'errors/404.html.twig' => 'Introuvable',
            ])),
            $this->service
        );

        $page = $controller->index(new Request('GET', '/account/devices', [], [], [], []), [])->getBody();

        $this->assertStringContainsString(
            AddressBookService::COLLECTION_PATH,
            $page,
            'the full CardDAV address is what a phone is told to use'
        );
    }

    public function testCreatingReturnsTheSecretExactlyOnceAndInThisResponseOnly(): void
    {
        $response = $this->controller->create(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken(), 'label' => 'Téléphone']),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['secret']);

        // The listing that follows carries no secret of any kind.
        $listing = $this->controller->index(new Request('GET', '/account/devices', [], [], [], []), [])->getBody();
        $this->assertStringNotContainsString($payload['secret'], $listing);
    }

    public function testCreatingWithoutAValidCsrfTokenDoesNothing(): void
    {
        $response = $this->controller->create($this->jsonRequest(['label' => 'Téléphone']), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->service->listForAccount($this->accountId));
    }

    public function testCreatingPastTheCeilingIsRefusedWithoutCreatingAnything(): void
    {
        for ($i = 0; $i < DeviceCredentialService::MAX_PER_ACCOUNT; $i++) {
            $this->service->create($this->accountId, 'Appareil ' . $i, $this->accountId);
        }

        $response = $this->controller->create(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken(), 'label' => 'Un de trop']),
            []
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertCount(DeviceCredentialService::MAX_PER_ACCOUNT, $this->service->listForAccount($this->accountId));
    }

    public function testAnOwnerRevokesTheirOwnCredential(): void
    {
        $created = $this->service->create($this->accountId, 'Téléphone', $this->accountId);

        $response = $this->controller->revoke(
            $this->formRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $created->credential->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/account/devices', $response->getHeaders()['Location']);
        $this->assertTrue($this->service->listForAccount($this->accountId)[0]->isRevoked());
    }

    /**
     * The route's `admin` floor says who may open this page; it says
     * nothing about whose devices they may revoke. Somebody else's
     * credential answers the same 404 as one that does not exist.
     */
    public function testSomebodyElsesCredentialIsNotFoundRatherThanForbidden(): void
    {
        $theirs = $this->service->create($this->otherAccountId, 'Leur téléphone', $this->otherAccountId);

        $response = $this->controller->revoke(
            $this->formRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $theirs->credential->id]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->service->listForAccount($this->otherAccountId)[0]->isRevoked());

        $missing = $this->controller->revoke(
            $this->formRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => '999999']
        );
        $this->assertSame(404, $missing->getStatusCode());
    }

    public function testRevokingWithoutAValidCsrfTokenChangesNothing(): void
    {
        $created = $this->service->create($this->accountId, 'Téléphone', $this->accountId);

        $this->controller->revoke($this->formRequest([]), ['id' => (string) $created->credential->id]);

        $this->assertFalse($this->service->listForAccount($this->accountId)[0]->isRevoked());
    }
}
