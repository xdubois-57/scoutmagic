<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\SuperAdminAccountsController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\SuperAdminService;
use Core\Security\UserAccountRepository;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Configuration > Comptes superadmin, as rendered (issue #744): a switch
 * alone in the « Actif » column, the same switch disabled and unbound on
 * your own row, and an icon-only withdrawal button that keeps an explicit
 * accessible name and its confirmation.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SuperAdminAccountsPageTest extends TestCase
{
    private string $body;
    private int $selfId;
    private int $otherId;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];

        $pdo = DatabaseTestHelper::createTestDatabase();
        $users = new UserAccountRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $service = new SuperAdminService($users, new JournalService(new JournalRepository($pdo)));
        $this->selfId = $service->grant('moi@example.com', null)['account']->id;
        $this->otherId = $service->grant('autre@example.com', null)['account']->id;
        AuthSession::login($this->selfId, 'moi@example.com', 'superadmin');

        $twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', false);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');

        $this->body = (new SuperAdminAccountsController($twig, $users, $service))
            ->index(new Request('GET', '/config/superadmins', [], [], [], []), [])
            ->getBody();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testNoStateBadgeIsWrittenBesideTheSwitches(): void
    {
        $this->assertStringNotContainsString('super-admin-state-badge', $this->body);
        $this->assertDoesNotMatchRegularExpression(
            '~<span class="badge[^"]*">\s*(Actif|Désactivé)\s*</span>~',
            $this->body
        );
    }

    public function testAnotherAccountHasALiveSwitchWithItsAccessibleName(): void
    {
        $this->assertMatchesRegularExpression(
            '~class="form-check-input super-admin-active-toggle"\s+id="super-admin-active-' . $this->otherId
                . '"\s+data-account-id="' . $this->otherId . '"\s+checked\s+aria-checked="true">~',
            $this->body
        );
        $this->assertMatchesRegularExpression(
            '~for="super-admin-active-' . $this->otherId . '">\s*Compte actif\s*<~',
            $this->body
        );
    }

    /** Your own row: the same switch, checked, disabled — and unbound. */
    public function testYourOwnAccountShowsADisabledSwitchNothingIsSentFrom(): void
    {
        $this->assertMatchesRegularExpression(
            '~<input type="checkbox" role="switch" class="form-check-input"\s+id="super-admin-active-' . $this->selfId
                . '"\s+checked disabled\s+aria-checked="true">~',
            $this->body
        );
        $this->assertStringNotContainsString('data-account-id="' . $this->selfId . '"', $this->body);
        $this->assertMatchesRegularExpression(
            '~for="super-admin-active-' . $this->selfId . '">\s*État de votre propre compte, non modifiable~',
            $this->body
        );
    }

    public function testTheWithdrawalButtonIsAnIconWithAnExplicitNameAndKeepsItsConfirmation(): void
    {
        $this->assertStringContainsString('aria-label="Retirer le droit superadmin à autre@example.com"', $this->body);
        $this->assertStringContainsString('title="Retirer le droit superadmin"', $this->body);
        $this->assertStringContainsString(
            'data-confirm="Retirer le droit superadmin à autre@example.com ?',
            $this->body
        );
        $this->assertDoesNotMatchRegularExpression('~</i>\s*Retirer le droit\s*</button>~', $this->body);
    }
}
