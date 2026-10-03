<?php

declare(strict_types=1);

namespace Tests\Security;

use Core\Http\RequestScheme;
use PHPUnit\Framework\TestCase;

/**
 * HTTPS is required by default (#751); every environment that runs over
 * plain http:// on purpose must declare `https_required => false` itself
 * — injected by its own bootstrap, never left to a sentence somebody has
 * to remember. Production must keep the policy, including a
 * config/app.php that predates the key.
 */
class HttpOnlyEnvironmentsDeclareTheExceptionTest extends TestCase
{
    private string $repoRoot;
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '') {
            @unlink($this->tempDir . '/config/app.php');
            @unlink($this->tempDir . '/config/app.php.dist');
            @rmdir($this->tempDir . '/config');
            @rmdir($this->tempDir);
        }
    }

    public function testANewProductionInstallRequiresHttps(): void
    {
        /** @var array<string, mixed> $dist */
        $dist = require $this->repoRoot . '/config/app.php.dist';

        $this->assertTrue($dist['https_required'] ?? null);
    }

    /**
     * A config/app.php written before the key existed must get the
     * production policy, never the development exception.
     */
    public function testTheFrontControllerDefaultsToRequiredWhenTheKeyIsMissing(): void
    {
        $index = (string) file_get_contents($this->repoRoot . '/public/index.php');

        $this->assertStringContainsString(
            "RequestScheme::setHttpsRequired(\$config->get('https_required', true) !== false);",
            $index
        );
    }

    public function testThePhpunitBootstrapDeclaresTheException(): void
    {
        $this->assertFalse(RequestScheme::httpsRequired(), 'tests/bootstrap.php must set https_required to false');
    }

    /**
     * scripts/e2e-support.php writes the instance's config/app.php for
     * every `npm run e2e` (CI's e2e job) and for scripts/dast.sh; only the
     * latter is served over TLS.
     */
    public function testTheEndToEndInstanceDeclaresTheExceptionUnlessServedOverTls(): void
    {
        $support = (string) file_get_contents($this->repoRoot . '/scripts/e2e-support.php');

        $this->assertStringContainsString(
            "'https_required' => \" . (e2eTrustForwardedProto() ? 'true' : 'false')",
            $support
        );
    }

    public function testTheDevelopmentConfigScriptWritesTheException(): void
    {
        $config = $this->runDevConfig();

        $this->assertFalse($config['https_required'] ?? null);
        $this->assertFalse($config['trust_forwarded_proto'] ?? null, 'the rest of the template is kept');
    }

    public function testTheDevelopmentConfigScriptNeverOverwritesAnExistingConfig(): void
    {
        $this->runDevConfig();
        file_put_contents($this->tempDir . '/config/app.php', "<?php\n\nreturn ['https_required' => true];\n");

        $this->runDevConfig();

        /** @var array<string, mixed> $config */
        $config = require $this->tempDir . '/config/app.php';
        $this->assertTrue($config['https_required']);
    }

    public function testComposerExposesTheDevelopmentConfigScript(): void
    {
        /** @var array{scripts: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents($this->repoRoot . '/composer.json'), true);

        $this->assertSame('php scripts/dev-config.php', $composer['scripts']['dev-config'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function runDevConfig(): array
    {
        if ($this->tempDir === '') {
            $this->tempDir = sys_get_temp_dir() . '/sm-dev-config-' . bin2hex(random_bytes(4));
            mkdir($this->tempDir . '/config', 0700, true);
            copy($this->repoRoot . '/config/app.php.dist', $this->tempDir . '/config/app.php.dist');
        }

        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->repoRoot . '/scripts/dev-config.php')
            . ' ' . escapeshellarg($this->tempDir) . ' 2>&1',
            $output,
            $exitCode
        );
        $this->assertSame(0, $exitCode, implode("\n", $output));

        /** @var array<string, mixed> $config */
        $config = require $this->tempDir . '/config/app.php';

        return $config;
    }
}
