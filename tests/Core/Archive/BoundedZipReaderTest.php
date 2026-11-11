<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Archive;

use Core\Archive\ArchiveLimits;
use Core\Archive\ArchiveRejectedException;
use Core\Archive\BoundedZipReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reader every archive a stranger built goes through: names that
 * could escape a directory, symbolic links, members the format does not
 * allow, and sizes measured on the way out of the decompressor rather than
 * believed from the archive's own declarations.
 */
final class BoundedZipReaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        if (!BoundedZipReader::isAvailable()) {
            $this->markTestSkipped('ext-zip is not installed.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testAnHonestArchiveIsReadAndMeasured(): void
    {
        $archive = $this->reader()->open($this->zip([
            'manifest.json' => '{}',
            'dossier/' => null,
            'dossier/lignes.jsonl' => "un\ndeux\n\ntrois",
        ]));

        $this->assertSame(['manifest.json', 'dossier/lignes.jsonl'], $archive->names());
        $this->assertSame('{}', $archive->read('manifest.json'));
        $this->assertSame([0 => 'un', 1 => 'deux', 3 => 'trois'], iterator_to_array($archive->lines('dossier/lignes.jsonl')));
        $this->assertSame([3 => 'trois'], iterator_to_array($archive->lines('dossier/lignes.jsonl', 2)));
        $this->assertSame(
            ['sha256' => hash('sha256', '{}'), 'bytes' => 2],
            $archive->digest('manifest.json')
        );
        $this->assertGreaterThan(0, $archive->measuredTotal());
        $archive->close();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedNames(): array
    {
        return [
            'parent segment' => ['../evil.json'],
            'parent segment inside' => ['ok/../../evil.json'],
            'absolute' => ['/etc/evil.json'],
            'drive letter' => ['C:/evil.json'],
            'backslash' => ['dossier\\..\\evil.json'],
            'dot segment' => ['./evil.json'],
            'empty segment' => ['a//evil.json'],
        ];
    }

    #[DataProvider('refusedNames')]
    public function testANameThatCouldEscapeIsRefused(string $name): void
    {
        $this->expectException(ArchiveRejectedException::class);
        $this->expectExceptionMessage('chemin refusé');

        $this->reader()->open($this->zip([$name => '{}']));
    }

    public function testASymbolicLinkIsRefused(): void
    {
        $path = $this->zip(['lien.json' => '/etc/passwd'], static function (\ZipArchive $zip): void {
            $zip->setExternalAttributesName('lien.json', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });

        $this->expectException(ArchiveRejectedException::class);
        $this->expectExceptionMessage('lien symbolique');

        $this->reader()->open($path);
    }

    public function testAMemberWithAnExtensionTheFormatDoesNotAllowIsRefused(): void
    {
        $this->expectException(ArchiveRejectedException::class);
        $this->expectExceptionMessage('extension');

        $this->reader()->open($this->zip(['script.php' => '<?php']));
    }

    public function testTooManyMembersAreRefused(): void
    {
        $files = [];
        for ($i = 0; $i < 6; $i++) {
            $files['f' . $i . '.json'] = '{}';
        }

        $this->expectException(ArchiveRejectedException::class);
        $this->reader()->open($this->zip($files));
    }

    public function testADeclaredSizeOverTheCeilingIsRefusedAtOpening(): void
    {
        $this->expectException(ArchiveRejectedException::class);
        $this->reader()->open($this->zip(['gros.json' => str_repeat('a', 2048)]));
    }

    /**
     * The real bound: a member whose header was forged to declare 100 bytes
     * expands to far more, and the read stops at the ceiling — measured
     * output, never the archive's word.
     */
    public function testAForgedSizeIsCaughtByTheMeasuredRead(): void
    {
        $path = $this->zip(['bombe.json' => str_repeat("\0", 3 * 1048576)]);
        $raw = (string) file_get_contents($path);
        $raw = substr_replace($raw, pack('V', 100), (int) strpos($raw, "PK\x03\x04") + 22, 4);
        $raw = substr_replace($raw, pack('V', 100), (int) strpos($raw, "PK\x01\x02") + 24, 4);
        file_put_contents($path, $raw);

        $archive = (new BoundedZipReader(new ArchiveLimits(5, 1048576, 2 * 1048576, ['json'])))->open($path);

        try {
            $archive->read('bombe.json');
            $this->fail('A forged size was believed.');
        } catch (ArchiveRejectedException $e) {
            $this->assertStringContainsString('une fois décompressé', $e->getMessage());
            $this->assertLessThan(2 * 1048576, $archive->measuredTotal());
        } finally {
            $archive->close();
        }
    }

    public function testTheTotalCeilingCountsWhatEarlierRequestsAlreadyRead(): void
    {
        $archive = $this->reader()->open($this->zip(['a.json' => str_repeat('a', 900)]), 3500);

        $this->expectException(ArchiveRejectedException::class);
        $this->expectExceptionMessage('contenu décompressé');

        $archive->read('a.json');
    }

    public function testACallerMayLowerThePerMemberCeiling(): void
    {
        $archive = $this->reader()->open($this->zip(['a.json' => str_repeat('a', 900)]));

        $this->expectException(ArchiveRejectedException::class);
        $archive->read('a.json', 100);
    }

    public function testAFileThatIsNotAZipIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/bzr_' . bin2hex(random_bytes(6)) . '.zip';
        file_put_contents($path, 'ceci n\'est pas une archive');
        $this->paths[] = $path;

        $this->expectException(ArchiveRejectedException::class);
        $this->reader()->open($path);
    }

    /**
     * A member named « 12 » becomes an integer array key inside the reader;
     * it must come back as the string it is, or a caller typed `string`
     * meets a TypeError instead of a refusal.
     */
    public function testANumericMemberNameComesBackAsAString(): void
    {
        $archive = (new BoundedZipReader(new ArchiveLimits(5, 1024, 4096)))->open($this->zip(['12' => 'douze']));

        $this->assertSame(['12'], $archive->names());
        $this->assertSame('douze', $archive->read($archive->names()[0]));
        $archive->close();
    }

    public function testAMessageQuotesAMemberNameShortAndPrintable(): void
    {
        $this->assertSame('a?b', BoundedZipReader::shown("a\x01b"));
        $this->assertSame(78, mb_strlen(BoundedZipReader::shown(str_repeat('x', 200))));
    }

    private function reader(): BoundedZipReader
    {
        return new BoundedZipReader(new ArchiveLimits(5, 1024, 4096, ['json', 'jsonl']));
    }

    /**
     * @param array<string, ?string> $files null = a directory entry
     * @param (callable(\ZipArchive): void)|null $tweak
     */
    private function zip(array $files, ?callable $tweak = null): string
    {
        $path = sys_get_temp_dir() . '/bzr_' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $content === null ? $zip->addEmptyDir(rtrim((string) $name, "/")) : $zip->addFromString((string) $name, $content);
        }
        if ($tweak !== null) {
            $tweak($zip);
        }
        $zip->close();
        $this->paths[] = $path;

        return $path;
    }
}
