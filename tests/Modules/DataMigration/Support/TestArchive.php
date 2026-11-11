<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Support;

/**
 * Builds migration archives for the tests: a valid manifest by default,
 * with whatever a test needs to break in it.
 */
final class TestArchive
{
    /**
     * @param array<string, string> $files member => bytes
     * @param array<string, mixed> $manifest fields replacing the valid defaults
     * @param array<string, string> $unlisted members added but not inventoried
     */
    public static function build(array $files, array $manifest = [], array $unlisted = []): string
    {
        $path = sys_get_temp_dir() . '/dm_test_' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $inventory = [];
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            $inventory[] = ['path' => $name, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        }
        foreach ($unlisted as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->addFromString('manifest.json', (string) json_encode(array_replace([
            'format' => TestImporter::FORMAT,
            'format_version' => '1.2.0',
            'source' => ['installation_id' => 'ancien-site'],
            'export_id' => 'export-1',
            'exported_at' => '2026-11-08T14:30:00+01:00',
            'files' => $inventory,
            'a_field_from_the_future' => true,
        ], $manifest)));
        $zip->close();

        return $path;
    }

    /**
     * @param list<array<string, mixed>|string> $lines objects, or raw lines
     */
    public static function items(array $lines): string
    {
        return self::build(['items.jsonl' => implode("\n", array_map(
            static fn (array|string $line): string => is_string($line) ? $line : (string) json_encode($line),
            $lines
        )) . "\n"]);
    }
}
