<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Http\Controller\VersionController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Maintenance\VersionFile;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class VersionControllerTest extends TestCase
{
    private string $basePath;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/version_controller_test_' . uniqid();
        mkdir($this->basePath);
        $this->storagePath = $this->basePath . '/storage';
        mkdir($this->storagePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath . '/VERSION');
        @rmdir($this->storagePath);
        @rmdir($this->basePath);
    }

    private function buildController(): VersionController
    {
        return new VersionController(new Environment(new ArrayLoader([])), $this->storagePath);
    }

    public function testReturnsStableVersionWithNoCommit(): void
    {
        VersionFile::write($this->basePath, '1.0.24');

        $response = $this->buildController()->index(new Request('GET', '/api/version', [], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
        $this->assertSame('1.0.24', $data['version']);
        $this->assertArrayNotHasKey('commit', $data);
    }

    public function testReturnsBareDevWithoutDisclosingTheCommitSha(): void
    {
        VersionFile::write($this->basePath, 'dev-abc1234');

        $response = $this->buildController()->index(new Request('GET', '/api/version', [], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        // A dev build reports a bare "dev" — the exact commit sha is never
        // disclosed publicly (audit hardening).
        $this->assertSame('dev', $data['version']);
        $this->assertArrayNotHasKey('commit', $data);
        $this->assertStringNotContainsString('abc1234', $response->getBody());
    }

    // --- RBAC boundary ---

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/api/version', VersionController::class, 'index', 'public');

        $configFile = sys_get_temp_dir() . '/test_version_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, new Environment(new ArrayLoader([])), $config);
        $fc->registerController(VersionController::class, $this->buildController());

        return $fc;
    }

    public function testPublicRoleIsAllowedThroughWithoutASession(): void
    {
        VersionFile::write($this->basePath, '1.0.24');

        $response = $this->buildFrontController()->handle(new Request('GET', '/api/version', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }
}
