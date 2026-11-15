<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Modules\DataMigration\Importer\EmailHistoryImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sample archives of `scoutmagic.email-history` are what the format
 * document shows an exporter: each ZIP holds exactly the files of the
 * directory beside it, its manifest inventories every one of them with the
 * right fingerprint, and it fits the limits the import declares.
 * `generate.php` writes both; this says they did not drift apart.
 */
final class EmailHistoryFixturesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../fixtures/data-migration/email-history/';

    /** @return array<string, array{string}> */
    public static function samples(): array
    {
        return ['exemple' => ['exemple'], 'cas-ignores' => ['cas-ignores']];
    }

    #[DataProvider('samples')]
    public function testTheArchiveHoldsExactlyItsDirectory(string $name): void
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(self::DIR . $name . '.zip'));
        $inZip = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $inZip[$entry] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        $onDisk = [];
        $root = realpath(self::DIR . $name);
        $this->assertNotFalse($root);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $onDisk[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
        ksort($inZip);
        ksort($onDisk);
        $this->assertSame($onDisk, $inZip);
    }

    #[DataProvider('samples')]
    public function testTheManifestInventoriesEveryFileWithItsFingerprint(string $name): void
    {
        $manifest = json_decode((string) file_get_contents(self::DIR . $name . '/manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertSame(EmailHistoryImporter::FORMAT, $manifest['format']);
        $this->assertSame('1.0.0', $manifest['format_version']);

        $importer = new EmailHistoryImporter(
            null,
            $this->createStub(\Core\Import\MemberRepository::class),
            new \Core\Security\HtmlSanitizer(),
        );
        $limits = $importer->archiveFormat()->limits;
        $listed = [];
        foreach ($manifest['files'] as $file) {
            $bytes = (string) file_get_contents(self::DIR . $name . '/' . $file['path']);
            $this->assertSame(hash('sha256', $bytes), $file['sha256'], $file['path']);
            $this->assertTrue($limits->allowsName($file['path']), $file['path']);
            $listed[] = $file['path'];
        }
        $this->assertNotContains('manifest.json', $listed);
    }
}
