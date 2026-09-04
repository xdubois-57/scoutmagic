<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * MigrationRunner::isPending() runs on every request; with a cache
 * directory it must hash the schema files once and answer from the
 * files' mtime/size signature afterwards, and notice the first edit.
 */
final class SchemaHashCacheTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/scoutmagic-schema-hash-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/schema', 0777, true);
        mkdir($this->base . '/cache', 0777, true);
        file_put_contents($this->base . '/schema/core.sql', "CREATE TABLE a (id INT);\n");
    }

    protected function tearDown(): void
    {
        foreach (['/schema', '/cache'] as $dir) {
            foreach (glob($this->base . $dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->base . $dir);
        }
        @rmdir($this->base);
    }

    private function runner(?string $cacheDirectory): MigrationRunner
    {
        $connection = Connection::withPdo(DatabaseTestHelper::createTestDatabase());

        return new MigrationRunner(
            $connection,
            new SchemaIntrospector($connection->getPdo()),
            new SchemaComparator(),
            new SqlParser(),
            hashCacheDirectory: $cacheDirectory
        );
    }

    public function testTheHashIsWrittenOnceAndReusedWhileTheFilesAreUntouched(): void
    {
        $files = [$this->base . '/schema/core.sql'];
        $cacheFile = $this->base . '/cache/schema_hash.cache';

        $this->assertTrue($this->runner($this->base . '/cache')->isPending($files));
        $this->assertFileExists($cacheFile);
        $first = unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]);
        $this->assertSame(hash('sha256', "CREATE TABLE a (id INT);\n\x00\x00"), $first['hash']);

        $this->assertTrue($this->runner($this->base . '/cache')->isPending($files));
        $this->assertSame($first, unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]));
    }

    public function testAnEditedSchemaFileInvalidatesTheCachedHash(): void
    {
        $files = [$this->base . '/schema/core.sql'];
        $cacheFile = $this->base . '/cache/schema_hash.cache';
        $this->runner($this->base . '/cache')->isPending($files);
        $before = unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]);

        file_put_contents($this->base . '/schema/core.sql', "CREATE TABLE a (id INT, b INT);\n");
        $this->runner($this->base . '/cache')->isPending($files);
        $after = unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]);

        $this->assertNotSame($before['hash'], $after['hash']);
        $this->assertSame(hash('sha256', "CREATE TABLE a (id INT, b INT);\n\x00\x00"), $after['hash']);
    }

    public function testWithoutACacheDirectoryNothingIsWritten(): void
    {
        $this->runner(null)->isPending([$this->base . '/schema/core.sql']);

        $this->assertSame([], glob($this->base . '/cache/*') ?: []);
    }
}
