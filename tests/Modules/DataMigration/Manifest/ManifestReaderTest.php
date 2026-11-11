<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Manifest;

use Core\Archive\ArchiveLimits;
use Core\Archive\BoundedZipReader;
use Core\Archive\OpenedArchive;
use Modules\DataMigration\Manifest\ManifestException;
use Modules\DataMigration\Manifest\ManifestReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\TestArchive;
use Tests\Modules\DataMigration\Support\TestImporter;

/**
 * The common manifest (docs/formats/data-migration/conventions.md): what it
 * must say, what it may add, and every way it can make the archive unusable.
 */
final class ManifestReaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testAValidManifestIsReadAndUnknownFieldsAreIgnored(): void
    {
        $archive = $this->open(TestArchive::build(['items.jsonl' => "{}\n"]));

        $manifest = (new ManifestReader())->read($archive, TestImporter::FORMAT, 1);

        $this->assertSame('1.2.0', $manifest->formatVersion);
        $this->assertSame(1, $manifest->majorVersion);
        $this->assertSame('ancien-site', $manifest->installationId);
        $this->assertSame('export-1', $manifest->exportId);
        $this->assertSame(['items.jsonl'], array_keys($manifest->files));
        $this->assertSame(3, (new ManifestReader())->verifyMember($archive, $manifest, 'items.jsonl'));
        $archive->close();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): array
    {
        return [
            'unknown major version' => [['format_version' => '2.0.0'], 'ne sait pas lire'],
            'two-part version' => [['format_version' => '1.0'], 'version complète'],
            'version as a number' => [['format_version' => 1], 'format_version'],
            'another format' => [['format' => 'scoutmagic-autre'], 'attend le format'],
            'numeric export id' => [['export_id' => 42], 'export_id'],
            'no source' => [['source' => null], 'source'],
            'date without time zone' => [['exported_at' => '2026-11-08 14:30:00'], 'ISO 8601'],
            'date that is not one' => [['exported_at' => 'hier'], 'ISO 8601'],
            'no inventory' => [['files' => null], 'inventaire'],
            'member listed twice' => [['files' => [
                ['path' => 'items.jsonl', 'sha256' => str_repeat('a', 64)],
                ['path' => 'items.jsonl', 'sha256' => str_repeat('a', 64)],
            ]], 'deux fois'],
            'uppercase fingerprint' => [['files' => [['path' => 'items.jsonl', 'sha256' => str_repeat('A', 64)]]], 'sha256'],
            'manifest listing itself' => [['files' => [
                ['path' => 'items.jsonl', 'sha256' => str_repeat('a', 64)],
                ['path' => 'manifest.json', 'sha256' => str_repeat('a', 64)],
            ]], 'lui-même'],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('refusals')]
    public function testAnUnusableManifestIsRefused(array $override, string $expected): void
    {
        $archive = $this->open(TestArchive::build(['items.jsonl' => "{}\n"], $override));

        try {
            (new ManifestReader())->read($archive, TestImporter::FORMAT, 1);
            $this->fail('The manifest was accepted.');
        } catch (ManifestException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        } finally {
            $archive->close();
        }
    }

    public function testAWrongFingerprintIsRefused(): void
    {
        $archive = $this->open(TestArchive::build(['items.jsonl' => "{}\n"], ['files' => [
            ['path' => 'items.jsonl', 'sha256' => str_repeat('0', 64)],
        ]]));
        $reader = new ManifestReader();
        $manifest = $reader->read($archive, TestImporter::FORMAT, 1);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('empreinte SHA-256');

        try {
            $reader->verifyMember($archive, $manifest, 'items.jsonl');
        } finally {
            $archive->close();
        }
    }

    public function testAWrongSizeIsRefused(): void
    {
        $archive = $this->open(TestArchive::build(['items.jsonl' => "{}\n"], ['files' => [
            ['path' => 'items.jsonl', 'sha256' => hash('sha256', "{}\n"), 'size' => 99],
        ]]));
        $reader = new ManifestReader();
        $manifest = $reader->read($archive, TestImporter::FORMAT, 1);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('taille');

        try {
            $reader->verifyMember($archive, $manifest, 'items.jsonl');
        } finally {
            $archive->close();
        }
    }

    private function open(string $path): OpenedArchive
    {
        $this->paths[] = $path;

        return (new BoundedZipReader(new ArchiveLimits(10, 1048576, 4194304, ['json', 'jsonl'])))->open($path);
    }
}
