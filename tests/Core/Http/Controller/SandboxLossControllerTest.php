<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Controller\SandboxLossController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\Security\SandboxLossState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * Configuration › Capture suspendue (issue #1469): what the superadmin
 * reads, the decision they take, and who may take it.
 */
#[Group('database')]
final class SandboxLossControllerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private JournalService $journal;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        SandboxLossState::register($this->settings);
        $this->journal = new JournalService(new JournalRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function state(): SandboxLossState
    {
        return new SandboxLossState(new SettingService(new SettingRepository($this->pdo)));
    }

    private function controller(?SandboxLossState $state = null): SandboxLossController
    {
        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');

        return new SandboxLossController($twig, $state ?? $this->state(), $this->journal);
    }

    private function loseMail(): void
    {
        $this->state()->record(
            SandboxLossState::CHANNEL_MAIL,
            SandboxLossState::REASON_PROFILE_MISSING,
            $this->journal
        );
    }

    private function postRelease(string $channel, bool $withToken): \Core\Http\Response
    {
        $body = ['channel' => $channel];
        if ($withToken) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token'] = $token;
            $body['_csrf_token'] = $token;
        }

        return $this->controller()->release(
            new Request('POST', SandboxLossController::RELEASE_URL, [], $body, [], []),
            []
        );
    }

    public function testTheBlockedChannelIsExplainedWithItsRemedyAndAConfirmedRelease(): void
    {
        $this->loseMail();

        $html = $this->controller()->index(new Request('GET', SandboxLossState::PAGE_URL, [], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('Capture suspendue', $html);
        $this->assertStringContainsString('Capture des e-mails', $html);
        $this->assertStringContainsString('le profil d&#039;installation est absent de config/app.php', $html);
        $this->assertStringContainsString('installation_profile dans config/app.php', $html);
        $this->assertMatchesRegularExpression(
            '~<form method="post" action="' . preg_quote(SandboxLossController::RELEASE_URL, '~')
                . '" data-confirm="Rouvrir l&#039;envoi réel \?[^"]+">~',
            $html
        );
        $this->assertStringContainsString('name="channel" value="mail"', $html);
        $this->assertStringContainsString('class="btn btn-outline-danger">Rouvrir l&#039;envoi réel</button>', $html);
        $this->assertStringNotContainsString('Téléphonie simulée', $html, 'Only the blocked channel is listed.');
    }

    public function testNothingBlockedSaysSo(): void
    {
        $html = $this->controller()->index(new Request('GET', SandboxLossState::PAGE_URL, [], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('Rien n\'est suspendu', $html);
        $this->assertStringNotContainsString('data-confirm', $html);
    }

    public function testWithoutATokenNothingIsReleased(): void
    {
        $this->loseMail();

        $response = $this->postRelease(SandboxLossState::CHANNEL_MAIL, false);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(AbstractController::SESSION_EXPIRED_MESSAGE, FlashMessage::get()['message'] ?? null);
        $this->assertTrue($this->state()->isBlocked(SandboxLossState::CHANNEL_MAIL));
    }

    public function testTheReleaseReopensSendingAndIsJournaledWithTheAccount(): void
    {
        $this->loseMail();
        AuthSession::login(5, 'super@example.be', 'superadmin');

        $response = $this->postRelease(SandboxLossState::CHANNEL_MAIL, true);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(SandboxLossState::PAGE_URL, $response->getHeaders()['Location'] ?? null);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
        $state = $this->state();
        $this->assertFalse($state->isBlocked(SandboxLossState::CHANNEL_MAIL));
        $this->assertTrue($state->isReleased(SandboxLossState::CHANNEL_MAIL));

        $row = $this->pdo->query(
            "SELECT level, user_account_id FROM event_log WHERE event_type = 'mail_capture_loss_released'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('security', $row['level']);
        $this->assertSame(5, (int) $row['user_account_id']);

        $html = $this->controller()->index(new Request('GET', SandboxLossState::PAGE_URL, [], [], [], []), [])
            ->getBody();
        $this->assertStringContainsString('Envoi réel rouvert', $html);
        $this->assertStringNotContainsString('data-confirm', $html, 'Nothing left to release.');
    }

    public function testAnUnknownChannelReleasesNothing(): void
    {
        $this->loseMail();

        $this->postRelease('fax', true);

        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
        $this->assertTrue($this->state()->isBlocked(SandboxLossState::CHANNEL_MAIL));
    }

    public function testNothingToReleaseIsSaid(): void
    {
        $this->postRelease(SandboxLossState::CHANNEL_TELEPHONY, true);

        $this->assertSame('warning', FlashMessage::get()['type'] ?? null);
    }

    // ── who may decide ────────────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return [
            'the page' => ['GET', SandboxLossState::PAGE_URL],
            'the decision' => ['POST', SandboxLossController::RELEASE_URL],
        ];
    }

    /**
     * Read out of public/index.php and fed to the real RbacGuard, as
     * LinkLabelRbacTest does: the route table lives in a bootstrap no unit
     * test loads.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testOnlyASuperadminReachesIt(string $method, string $path): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        $matched = preg_match(
            '/addRoute\(\s*\'' . $method . '\'\s*,\s*\'' . preg_quote($path, '/') . '\'\s*,'
                . '\s*\\\\Core\\\\Http\\\\Controller\\\\SandboxLossController::class\s*,\s*\'[a-z]+\'\s*,'
                . '\s*\'([a-z_]+)\'/',
            $source,
            $m
        );
        $this->assertSame(1, $matched, "No addRoute registration found for {$method} {$path}");
        $this->assertSame('superadmin', $m[1]);

        $roleMin = Role::fromString($m[1]);
        AuthSession::login(3, 'chef@example.be', 'admin');
        $this->assertNotNull((new RbacGuard())->enforce($roleMin), 'An admin may not decide.');

        AuthSession::login(4, 'super@example.be', 'superadmin');
        $this->assertNull((new RbacGuard())->enforce($roleMin));
    }
}
