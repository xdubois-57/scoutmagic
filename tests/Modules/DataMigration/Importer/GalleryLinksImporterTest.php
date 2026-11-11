<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Core\Http\LinkPreview;
use Core\Member\SectionService;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\GalleryLinksImporter;
use Modules\DataMigration\Importer\ImportContext;
use Modules\DataMigration\Importer\ImportSource;
use Modules\DataMigration\Importer\ItemVerdict;
use Modules\DataMigration\Importer\PreviewNote;
use Modules\DataMigration\Importer\SourceItem;
use Modules\DataMigration\Importer\ThrottledImporter;
use Modules\DataMigration\Service\SectionByDeskCode;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\FakeExternalAlbumWriter;
use Tests\Modules\DataMigration\Support\FakeLinkPreviewFetcher;

/**
 * « Galeries photo », item by item, with the gallery and the network
 * replaced: what is imported, ignored or already present, when the
 * album's page is read, and what the gallery is asked to create.
 */
final class GalleryLinksImporterTest extends TestCase
{
    private const URL = 'https://photos.example.org/album/camp-2019';

    private FakeExternalAlbumWriter $albums;
    private FakeLinkPreviewFetcher $pages;
    private GalleryLinksImporter $importer;

    /** @var array<string, int> scout year label => id */
    private array $years = ['2019-2020' => 19, '2020-2021' => 20];

    protected function setUp(): void
    {
        $this->albums = new FakeExternalAlbumWriter(['https://photos.example.org/album/deja-la']);
        $this->pages = new FakeLinkPreviewFetcher([
            self::URL => new LinkPreview('Camp 2019 (page)', 'Description de la page', 'image-bytes'),
        ]);
        $sections = $this->createStub(SectionService::class);
        $sections->method('findByDeskCode')->willReturnCallback(
            static fn (string $code): ?array => $code === 'LOUV-A' ? ['id' => 7] : null
        );
        $this->importer = new GalleryLinksImporter($this->albums, new SectionByDeskCode($sections), $this->pages);
    }

    public function testItIsTheGalleryImportReadOneAlbumPerSlice(): void
    {
        $this->assertSame('galeries', $this->importer->key());
        $this->assertSame('gallery', $this->importer->requiredModule());
        $this->assertSame('scoutmagic-gallery-links', $this->importer->archiveFormat()->format);
        $this->assertSame(1, $this->importer->archiveFormat()->majorVersion);
        $this->assertInstanceOf(ThrottledImporter::class, $this->importer);
        $this->assertSame(1, $this->importer->inspectionsPerExecutionSlice());
        $this->assertContains('Aucune notification « Nouvel album ».', $this->importer->effects());
    }

    public function testACompleteAlbumIsImportedAndItsPageIsNotReadDuringTheAnalysis(): void
    {
        $verdict = $this->importer->inspect($this->album(), $this->context(false));

        $this->assertSame(ItemVerdict::IMPORT, $verdict->outcome);
        $this->assertSame('2019-2020', $verdict->scoutYearLabel);
        $this->assertSame([], $this->pages->fetched, 'The analysis read a remote page.');
    }

    public function testTheExecutionReadsThePageOnceForInspectAndWrite(): void
    {
        $item = $this->album(['section_desk_code' => 'LOUV-A', 'subtitle' => 'Sous-titre du fichier']);
        $context = $this->context(true);

        $this->assertSame(ItemVerdict::IMPORT, $this->importer->inspect($item, $context)->outcome);
        $target = $this->importer->write($item, $context);

        $this->assertSame([self::URL], $this->pages->fetched);
        $this->assertSame('gallery_album', $target->type);
        $created = $this->albums->created[0];
        $this->assertSame('Camp 2019', $created->title, 'The file\'s title wins over the page\'s.');
        $this->assertSame('Sous-titre du fichier', $created->subtitle);
        $this->assertSame('2019-10-12', $created->albumDate);
        $this->assertSame(7, $created->sectionId);
        $this->assertSame(19, $created->scoutYearId);
        $this->assertSame(42, $created->createdBy, 'The album belongs to the administrator who imports.');
        $this->assertSame('Camp 2019 (page)', $created->ogTitle);
        $this->assertSame('Description de la page', $created->ogDescription);
        $this->assertSame('image-bytes', $created->ogImageBytes);
    }

    public function testAnAlbumWithoutTitleTakesThePagesOne(): void
    {
        $item = $this->album(['title' => '  ']);

        $analysed = $this->importer->inspect($item, $this->context(false));
        $this->assertSame([GalleryLinksImporter::TAG_TITLE_FROM_PAGE], $analysed->tags);

        $context = $this->context(true);
        $this->assertSame(ItemVerdict::IMPORT, $this->importer->inspect($item, $context)->outcome);
        $this->importer->write($item, $context);

        $this->assertSame('Camp 2019 (page)', $this->albums->created[0]->title);
    }

    public function testAnUnreadablePageLeavesTheFilesDataAlone(): void
    {
        $this->pages->pages = [];
        $item = $this->album();
        $context = $this->context(true);

        $this->assertSame(ItemVerdict::IMPORT, $this->importer->inspect($item, $context)->outcome);
        $this->importer->write($item, $context);

        $created = $this->albums->created[0];
        $this->assertSame('Camp 2019', $created->title);
        $this->assertNull($created->ogTitle);
        $this->assertNull($created->ogImageBytes);
    }

    public function testAnAlbumWithNoTitleAnywhereIsIgnored(): void
    {
        $this->pages->pages = [self::URL => new LinkPreview(null, 'Une description seule', null)];

        $verdict = $this->importer->inspect($this->album(['title' => null]), $this->context(true));

        $this->assertSame(ItemVerdict::IGNORED, $verdict->outcome);
        $this->assertSame(GalleryLinksImporter::REASON_NO_TITLE, $verdict->reason);
    }

    public function testAnAddressAlreadyInTheGalleryIsPresentAfterNormalisation(): void
    {
        $verdict = $this->importer->inspect(
            $this->album(['url' => 'HTTPS://Photos.Example.org/album/deja-la#photo-4']),
            $this->context(true)
        );

        $this->assertSame(ItemVerdict::PRESENT, $verdict->outcome);
        $this->assertSame([], $this->pages->fetched, 'A known address is not read again.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function ignoredAlbums(): iterable
    {
        yield 'no address' => [['url' => null], GalleryLinksImporter::REASON_URL];
        yield 'not a web address' => [['url' => 'ftp://example.org/album'], GalleryLinksImporter::REASON_URL];
        yield 'address too long' => [['url' => 'https://example.org/' . str_repeat('a', 490)], GalleryLinksImporter::REASON_URL_TOO_LONG];
        yield 'no date' => [['album_date' => null], GalleryLinksImporter::REASON_DATE];
        yield 'unreadable date' => [['album_date' => '12/10/2019'], GalleryLinksImporter::REASON_DATE];
        yield 'unreadable year' => [['scout_year' => '2019'], GalleryLinksImporter::REASON_YEAR];
        yield 'unknown section' => [['section_desk_code' => 'INCONNU'], GalleryLinksImporter::REASON_SECTION];
        yield 'section code as a number' => [['section_desk_code' => 12], GalleryLinksImporter::REASON_SECTION];
        yield 'title too long' => [['title' => str_repeat('t', 256)], GalleryLinksImporter::REASON_TITLE_TOO_LONG];
        yield 'subtitle too long' => [['subtitle' => str_repeat('s', 256)], GalleryLinksImporter::REASON_SUBTITLE_TOO_LONG];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ignoredAlbums')]
    public function testAnAlbumMissingWhatItNeedsIsIgnoredWithItsReason(array $override, string $reason): void
    {
        $verdict = $this->importer->inspect($this->album($override), $this->context(true));

        $this->assertSame(ItemVerdict::IGNORED, $verdict->outcome);
        $this->assertSame($reason, $verdict->reason);
        $this->assertSame([], $this->pages->fetched);
    }

    public function testExternalAlbumsDisabledInTheGalleryIgnoreEveryAlbum(): void
    {
        $this->albums->allowed = false;

        $verdict = $this->importer->inspect($this->album(), $this->context(false));

        $this->assertSame(GalleryLinksImporter::REASON_EXTERNAL_DISABLED, $verdict->reason);
    }

    /**
     * D5: the year is the file's, or the one the date falls in — never the
     * current one. A year the site lacks is asked for, and the page of an
     * album whose year was not created is not read.
     */
    public function testTheScoutYearComesFromTheFileOrTheDate(): void
    {
        $fromDate = $this->importer->inspect($this->album(['scout_year' => null, 'album_date' => '2020-08-20']), $this->context(false));
        $this->assertSame('2019-2020', $fromDate->scoutYearLabel);

        $fromFile = $this->importer->inspect($this->album(['scout_year' => '2020-2021', 'album_date' => '2020-08-20']), $this->context(false));
        $this->assertSame('2020-2021', $fromFile->scoutYearLabel);

        $missing = $this->importer->inspect($this->album(['scout_year' => '2011-2012']), $this->context(true));
        $this->assertSame(ItemVerdict::IMPORT, $missing->outcome);
        $this->assertSame('2011-2012', $missing->scoutYearLabel);
        $this->assertSame([], $this->pages->fetched);
    }

    public function testTheItemsAreAlbumsShownByTheirOldReferenceNeverTheirTitle(): void
    {
        $archive = \Tests\Modules\DataMigration\Support\TestArchive::build(['albums.jsonl' => implode("\n", [
            (string) json_encode(['id' => 'album-1', 'url' => self::URL, 'title' => 'Anniversaire de Lucie']),
            'pas du JSON',
            (string) json_encode(['id' => 7, 'url' => self::URL]),
        ]) . "\n"], ['format' => GalleryLinksImporter::FORMAT]);
        $opened = (new \Core\Archive\BoundedZipReader($this->importer->archiveFormat()->limits))->open($archive);

        try {
            $items = iterator_to_array($this->importer->items(new ImportSource($opened, null), 0));
        } finally {
            $opened->close();
            @unlink($archive);
        }

        $this->assertCount(3, $items);
        $this->assertSame('album', $items[0]->kind);
        $this->assertSame('album-1', $items[0]->shownReference());
        $this->assertTrue($items[1]->isUnreadable());
        $this->assertSame('', $items[2]->sourceId);
        $this->assertSame('ligne 3', $items[2]->shownReference());
    }

    public function testThePreviewSaysItIsSlowAndWhatItLeavesOut(): void
    {
        $notes = $this->importer->previewNotes(new AnalysisSummary(
            324,
            312,
            7,
            0,
            [GalleryLinksImporter::REASON_SECTION => 5],
            [GalleryLinksImporter::TAG_TITLE_FROM_PAGE => 2],
            []
        ));

        $texts = array_map(static fn (PreviewNote $note): string => $note->text, $notes);
        $this->assertContains(
            'Le titre et la vignette sont relus sur chaque album pendant l\'import. Comptez environ deux secondes par album.',
            $texts
        );
        $this->assertContains('5 albums visent une section inconnue. Ils sont ignorés plutôt que montrés à toute l\'unité.', $texts);
        $this->assertSame(PreviewNote::WARNING, $notes[2]->level);
        $this->assertStringStartsWith('2 albums n\'ont pas de titre dans le fichier.', $texts[1]);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function album(array $override = []): SourceItem
    {
        $row = array_replace([
            'id' => 'album-0001',
            'url' => self::URL,
            'album_date' => '2019-10-12',
            'scout_year' => '2019-2020',
            'title' => 'Camp 2019',
        ], $override);

        return new SourceItem('album', 'album-0001', $row);
    }

    private function context(bool $executing): ImportContext
    {
        return new ImportContext(
            1,
            'ancien-site',
            GalleryLinksImporter::FORMAT,
            42,
            new ImportSource(null, null),
            fn (string $label): ?int => $this->years[$label] ?? null,
            $executing
        );
    }
}
