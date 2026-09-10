<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\SetupController;
use Core\Http\Request;
use Core\Mail\DkimManager;
use Core\Security\SecretManager;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * Who may reach the wizard's portable restore, and who may not.
 *
 * **This is the most dangerous pair of endpoints in the application**, and
 * saying so plainly is the point of this class. `POST /setup/restore-portable`
 * takes an uploaded file and replaces the database with it. It is reachable
 * without logging in — it has to be, because it exists for the moment when
 * there is no account to log into and no site to log into it with. What
 * stands in its place is the installation token, the same gate that guards
 * the wizard itself, plus the fact that the whole route block disappears the
 * instant the site is initialised.
 *
 * So the two refusals below are not paperwork. Each of them is the only
 * thing between a stranger who found `/setup` and a database of members'
 * addresses, dates of birth and telephone numbers.
 */
final class SetupPortableRestoreTest extends TestCase
{
    private string $tempDir;
    private SecretManager $secretManager;
    private DkimManager $dkimManager;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/setup_portable_test_' . uniqid();
        mkdir($this->tempDir . '/keys', 0700, true);
        mkdir($this->tempDir . '/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->tempDir . '/keys/master.key',
            $this->tempDir . '/config/secrets.enc'
        );
        $this->dkimManager = new DkimManager($this->tempDir . '/keys');
        $this->twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', true);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeDirectory($this->tempDir);
    }

    private function controller(): SetupController
    {
        return new SetupController(
            $this->twig,
            $this->secretManager,
            $this->dkimManager,
            dirname(__DIR__, 4) . '/schema/core.sql'
        );
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path): array
    {
        $response = $this->controller()->{$method}(new Request('POST', $path, [], [], [], []), []);
        $decoded = json_decode($response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /**
     * **Without the installation token, nothing.**
     *
     * The token file is what proves whoever is at `/setup` has filesystem
     * access to the server — the only credential that exists before the
     * site does. Without it this endpoint would let anybody who reached an
     * uninstalled ScoutMagic upload a database and own the result.
     */
    public function testTheRestoreIsRefusedWithoutAVerifiedInstallationToken(): void
    {
        unset($_SESSION['setup_token_verified']);

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Jeton', (string) ($body['message'] ?? ''));
    }

    /**
     * And the upload endpoint too, which is the half people forget.
     *
     * Gating only the restore would leave a route that writes attacker-chosen
     * bytes to the server's disk, in chunks, to a path derived from an id
     * they choose — protected by nothing at all.
     */
    public function testTheChunkedUploadIsRefusedWithoutAVerifiedInstallationTokenEither(): void
    {
        unset($_SESSION['setup_token_verified']);

        [$status, $body] = $this->call('restorePortableChunk', '/setup/restore-portable-chunk');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
    }

    /**
     * **Once the site is configured, this way in closes.**
     *
     * From that moment a restore belongs to Configuration > Maintenance,
     * behind a login and a role. The wizard's token is long gone, and
     * `denyUnlessTokenVerified()` deliberately stops refusing anything —
     * so if this second check were missing, the endpoint would go from
     * token-gated to completely open on the day the site started working.
     */
    public function testTheRestoreIsRefusedOnceTheSiteIsConfigured(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $this->initialiseTheSite();

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('déjà configuré', (string) ($body['message'] ?? ''));
    }

    public function testTheChunkedUploadIsRefusedOnceTheSiteIsConfiguredToo(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $this->initialiseTheSite();

        [$status, $body] = $this->call('restorePortableChunk', '/setup/restore-portable-chunk');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
    }

    /**
     * With the token but without a valid CSRF token, still nothing — and
     * this is what says the order of the two gates is deliberate.
     */
    public function testAValidTokenStillNeedsAValidCsrfToken(): void
    {
        $_SESSION['setup_token_verified'] = true;

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringNotContainsString('Jeton d\'installation', (string) ($body['message'] ?? ''));
    }

    /** The cheapest thing that makes SecretManager::isInitialized() true. */
    private function initialiseTheSite(): void
    {
        file_put_contents($this->tempDir . '/keys/master.key', random_bytes(32));
        $this->secretManager->writeSecrets(['db_host' => 'localhost']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
