<?php

declare(strict_types=1);

namespace Tests\Core\Cache;

use Core\Cache\SerializedFileCache;
use PHPUnit\Framework\TestCase;

/** A value the cache is allowed to carry — see $allowedClasses below. */
class SerializedFileCacheFixture
{
    public function __construct(public string $label)
    {
    }
}

/**
 * The contract both real users (the help index, the module-manifest
 * cache) lean on: a round trip gives the value back, and EVERY failure
 * mode — missing file, corrupt bytes, a class not on the allow-list, a
 * validator veto — is a miss (null), never an error.
 */
class SerializedFileCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sm-sfc-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach ((array) glob($this->directory . '/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->directory);
        }
    }

    private function cache(array $allowedClasses = [SerializedFileCacheFixture::class]): SerializedFileCache
    {
        return new SerializedFileCache($this->directory . '/value.cache', $allowedClasses);
    }

    public function testRoundTripGivesTheValueBackAndCreatesTheDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->directory);

        $this->cache()->write(['topics' => [new SerializedFileCacheFixture('aide')], 'n' => 3]);
        $read = $this->cache()->read();

        $this->assertIsArray($read);
        $this->assertSame(3, $read['n']);
        $this->assertInstanceOf(SerializedFileCacheFixture::class, $read['topics'][0]);
        $this->assertSame('aide', $read['topics'][0]->label);
        // No leftover tmp file from the atomic write.
        $this->assertCount(1, (array) glob($this->directory . '/*'));
    }

    public function testAMissingFileIsAMiss(): void
    {
        $this->assertNull($this->cache()->read());
    }

    public function testCorruptBytesAreAMiss(): void
    {
        mkdir($this->directory, 0755, true);
        file_put_contents($this->directory . '/value.cache', 'not serialized data');

        $this->assertNull($this->cache()->read());
    }

    public function testAClassOutsideTheAllowListIsAMissNotAnObject(): void
    {
        $this->cache()->write(new SerializedFileCacheFixture('aide'));

        // Same file read with an empty allow-list: the fixture comes back
        // as __PHP_Incomplete_Class, which unserialize() itself accepts —
        // the validator contract is what turns it into a miss. Mirrors how
        // HelpRegistry and ModuleManager always read with a shape check.
        $read = $this->cache([])->read(
            static fn(mixed $value): bool => $value instanceof SerializedFileCacheFixture
        );

        $this->assertNull($read);
    }

    public function testAValidatorVetoIsAMiss(): void
    {
        $this->cache()->write(['key' => 'v1']);

        $this->assertNull($this->cache()->read(static fn(mixed $value): bool => false));
        $this->assertIsArray($this->cache()->read(static fn(mixed $value): bool => true));
    }

    public function testFalseRoundTripsAsAMissByDesign(): void
    {
        // serialize(false) unserializes to false, indistinguishable from
        // failure — callers must not cache bare false, and the class
        // treats it as a miss rather than guessing.
        $this->cache()->write(false);

        $this->assertNull($this->cache()->read());
    }
}
