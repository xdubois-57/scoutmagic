<?php

declare(strict_types=1);

namespace Tests\Core\Contact\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Contact\Controller\ContactSyncConfigController;
use Core\Contact\Device\DeviceCredentialRepository;
use Core\Contact\Device\DeviceCredentialService;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Configuration > « Synchronisation des contacts »: the cut-out, and the
 * one place on the site where somebody else's device can be revoked.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ContactSyncConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private ContactSyncConfigController $controller;
    private DeviceCredentialService $service;
    private int $ownerAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

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

        $accounts = new UserAccountRepository($this->pdo, $enc);
        $this->ownerAccountId = $accounts->create('chef@example.org')->id;
        $superAdminId = $accounts->create('super@example.org', true)->id;

        $this->controller = new ContactSyncConfigController(
            new Environment(new ArrayLoader([
                'config/contact_sync.html.twig' =>
                    '{{ sync_enabled ? "active" : "coupee" }} {{ live_count }} {{ credentials|length }}',
                'errors/404.html.twig' => 'Introuvable',
            ])),
            $this->service,
            $accounts
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($superAdminId, 'super@example.org', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body = [], bool $withToken = true): Request
    {
        if ($withToken) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token'] = $token;
            $body['_csrf_token'] = $token;
        }

        return new Request('POST', '/config/synchronisation-contacts/switch', [], $body, [], []);
    }

    /**
     * Every device of every account, not only the superadmin's own — a
     * page that could not answer « qui synchronise ? » would be no
     * cut-out at all.
     */
    public function testThePageListsEverybodysDevices(): void
    {
        $this->service->create($this->ownerAccountId, 'Téléphone du chef', $this->ownerAccountId);

        $body = $this->controller->index(new Request('GET', '/config/synchronisation-contacts', [], [], [], []), [])
            ->getBody();

        $this->assertSame('active 1 1', $body);
    }

    public function testTheCutOutIsFlippedBothWaysAndSaysSoOnThePage(): void
    {
        $this->controller->toggle($this->post(['enabled' => '0']), []);
        $this->assertFalse($this->service->isSyncEnabled());

        $this->controller->toggle($this->post(['enabled' => '1']), []);
        $this->assertTrue($this->service->isSyncEnabled());
    }

    public function testTheCutOutCannotBeFlippedWithoutAValidCsrfToken(): void
    {
        $this->controller->toggle($this->post(['enabled' => '0'], withToken: false), []);

        $this->assertTrue($this->service->isSyncEnabled());
    }

    public function testASuperAdminRevokesSomebodyElsesDevice(): void
    {
        $theirs = $this->service->create($this->ownerAccountId, 'Téléphone du chef', $this->ownerAccountId);

        $response = $this->controller->revoke($this->post(), ['id' => (string) $theirs->credential->id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->service->listForAccount($this->ownerAccountId)[0]->isRevoked());
    }

    public function testTheRevocationSaysItWasASuperAdminAndNamesNobody(): void
    {
        $theirs = $this->service->create($this->ownerAccountId, 'iPhone de Camille', $this->ownerAccountId);
        $this->controller->revoke($this->post(), ['id' => (string) $theirs->credential->id]);

        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ?');
        $stmt->execute(['device_credential_revoked']);
        $entry = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($entry);
        $this->assertStringContainsString('superadministrateur', (string) $entry['description']);
        $this->assertStringNotContainsString('Camille', (string) $entry['description'] . (string) $entry['context']);
    }

    public function testAnUnknownCredentialIsNotFound(): void
    {
        $this->assertSame(404, $this->controller->revoke($this->post(), ['id' => '999999'])->getStatusCode());
        $this->assertSame(404, $this->controller->revoke($this->post(), ['id' => '0'])->getStatusCode());
    }
}
