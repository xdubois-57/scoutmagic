<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Core\Import\MemberRepository;
use Core\Photo\ImportedMemberPhotoWriter;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\ImporterRegistry;
use Modules\DataMigration\Importer\MemberPhotosImporter;
use Modules\DataMigration\Importer\PreviewNote;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What « Photos des membres » declares to the module: a core capability
 * (no module required), its format and its limits, and the preview's
 * wording. The import itself is driven end to end in
 * {@see MemberPhotosImportOnMysqlTest}.
 */
#[Group('database')]
final class MemberPhotosImporterTest extends TestCase
{
    private MemberPhotosImporter $importer;

    protected function setUp(): void
    {
        // Nothing here reaches either collaborator: they are built over a
        // connection no test statement uses.
        $pdo = new \PDO('sqlite::memory:');
        $this->importer = new MemberPhotosImporter(
            new MemberRepository($pdo),
            new ImportedMemberPhotoWriter(
                (new \ReflectionClass(\Core\Photo\PhotoIngestionService::class))->newInstanceWithoutConstructor(),
                new \Core\Photo\MemberPhotoRepository($pdo),
                new \Core\File\AttachedFileRemover(new \Core\File\FileRepository($pdo), '')
            )
        );
    }

    public function testItIsACoreImportOfferedWhateverModulesAreEnabled(): void
    {
        $this->assertSame('photos', $this->importer->key());
        $this->assertNull($this->importer->requiredModule());

        $registry = new ImporterRegistry(static fn (string $module): bool => false);
        $registry->add($this->importer);
        $this->assertSame($this->importer, $registry->find('photos'));
    }

    public function testItReadsVersionOneOfItsFormatWithinItsLimits(): void
    {
        $format = $this->importer->archiveFormat();

        $this->assertSame('scoutmagic-member-photos', $format->format);
        $this->assertSame(1, $format->majorVersion);
        $this->assertSame(MemberPhotosImporter::MAX_IMAGES + 3, $format->limits->maxEntries);
        $this->assertTrue($format->limits->allowsName('photos.jsonl'));
        $this->assertTrue($format->limits->allowsName('images/' . str_repeat('a', 64) . '.webp'));
        $this->assertTrue($format->limits->allowsName('images/' . str_repeat('a', 64) . '.JPEG'));
        $this->assertFalse($format->limits->allowsName('images/' . str_repeat('a', 64) . '.gif'));
        $this->assertFalse($format->limits->allowsName('images/portrait.heic'));
        // Over the browser upload's 10 MB, so one heavy image is ignored
        // with its reason rather than the whole archive refused.
        $this->assertGreaterThan(ImportedMemberPhotoWriter::MAX_BYTES, $format->limits->maxEntryBytes);
    }

    public function testThePreviewSaysHowManyMembersKeepTheirPhoto(): void
    {
        $this->assertSame([], $this->importer->previewNotes(self::summary(0)));

        $one = $this->importer->previewNotes(self::summary(1));
        $this->assertCount(1, $one);
        $this->assertSame(PreviewNote::INFO, $one[0]->level);
        $this->assertSame(
            '1 animé a déjà une photo pour l\'année visée. Elle est conservée, la photo du fichier est ignorée.',
            $one[0]->text
        );

        $this->assertSame(
            '12 animés ont déjà une photo pour l\'année visée. Elle est conservée, la photo du fichier est ignorée.',
            $this->importer->previewNotes(self::summary(12))[0]->text
        );
    }

    public function testItsWordingIsTheMockups(): void
    {
        $this->assertSame('Photos des membres', $this->importer->title());
        $this->assertSame('photos', $this->importer->unitLabel());
        $this->assertSame(
            ['Aucune photo existante n\'est remplacée.', 'Les photos de compte et de section ne sont pas touchées.'],
            $this->importer->effects()
        );
    }

    private static function summary(int $slotsTaken): AnalysisSummary
    {
        return new AnalysisSummary(
            20,
            10,
            $slotsTaken,
            0,
            [],
            $slotsTaken > 0 ? [MemberPhotosImporter::TAG_SLOT_TAKEN => $slotsTaken] : [],
            []
        );
    }
}
