<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Composer\Autoload\ClassLoader;
use Core\System\ComposerAutoloadSync;
use PHPUnit\Framework\TestCase;

/**
 * Uses a fresh, standalone ClassLoader (never registered with
 * spl_autoload_register) and its own findFile() lookup rather than the
 * real global autoloader, so these tests never touch the actual
 * autoloading state of the running test process.
 */
class ComposerAutoloadSyncTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/composer_autoload_sync_test_' . uniqid();
        mkdir($this->tmpDir . '/src/Widget', 0755, true);
        file_put_contents($this->tmpDir . '/src/Widget/Thing.php', "<?php\nnamespace Fixture\\Widget;\nclass Thing {}\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/src/Widget/Thing.php');
        @unlink($this->tmpDir . '/composer.json');
        @rmdir($this->tmpDir . '/src/Widget');
        @rmdir($this->tmpDir . '/src');
        @rmdir($this->tmpDir);
    }

    public function testRegistersAPsr4PrefixFromComposerJson(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
        ]));

        $loader = new ClassLoader();
        ComposerAutoloadSync::apply($loader, $this->tmpDir . '/composer.json');

        $this->assertSame(
            $this->tmpDir . '/src/Widget/Thing.php',
            $loader->findFile('Fixture\\Widget\\Thing')
        );
    }

    public function testDoesNothingWhenComposerJsonIsMissing(): void
    {
        $loader = new ClassLoader();

        ComposerAutoloadSync::apply($loader, $this->tmpDir . '/does-not-exist.json');

        $this->assertFalse($loader->findFile('Fixture\\Widget\\Thing'));
    }

    public function testDoesNothingWhenComposerJsonHasNoPsr4Section(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/test']));

        $loader = new ClassLoader();

        // Must not throw despite the missing autoload.psr4 key.
        ComposerAutoloadSync::apply($loader, $this->tmpDir . '/composer.json');

        $this->assertFalse($loader->findFile('Fixture\\Widget\\Thing'));
    }

    public function testDoesNothingOnMalformedJson(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', '{not valid json');

        $loader = new ClassLoader();

        ComposerAutoloadSync::apply($loader, $this->tmpDir . '/composer.json');

        $this->assertFalse($loader->findFile('Fixture\\Widget\\Thing'));
    }

    /**
     * The whole point: a prefix already registered from the compiled
     * autoload_psr4.php (simulated here by pre-registering it on the same
     * loader) must survive alongside whatever composer.json adds — never
     * overwritten, never erroring, since addPsr4() merges rather than
     * replacing an existing prefix.
     */
    public function testMergesWithAnAlreadyRegisteredPrefixRatherThanReplacingIt(): void
    {
        mkdir($this->tmpDir . '/other/Widget', 0755, true);
        file_put_contents($this->tmpDir . '/other/Widget/Existing.php', "<?php\nnamespace Fixture\\Widget;\nclass Existing {}\n");

        file_put_contents($this->tmpDir . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Fixture\\Widget\\' => 'src/Widget/']],
        ]));

        $loader = new ClassLoader();
        $loader->addPsr4('Fixture\\Widget\\', $this->tmpDir . '/other/Widget');

        ComposerAutoloadSync::apply($loader, $this->tmpDir . '/composer.json');

        $this->assertSame(
            $this->tmpDir . '/other/Widget/Existing.php',
            $loader->findFile('Fixture\\Widget\\Existing')
        );
        $this->assertSame(
            $this->tmpDir . '/src/Widget/Thing.php',
            $loader->findFile('Fixture\\Widget\\Thing')
        );

        @unlink($this->tmpDir . '/other/Widget/Existing.php');
        @rmdir($this->tmpDir . '/other/Widget');
        @rmdir($this->tmpDir . '/other');
    }
}
