<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Core\Archive\ArchiveRejectedException;
use Core\Archive\BoundedZipReader;
use Modules\Camps\Api\ExistingPlace;
use Modules\Camps\Api\ImportedPlace;
use Modules\Camps\Api\PlaceImportWriterInterface;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\CampPlacesImporter;
use Modules\DataMigration\Importer\ImportContext;
use Modules\DataMigration\Importer\ImportSource;
use Modules\DataMigration\Importer\ItemVerdict;
use Modules\DataMigration\Importer\PreviewNote;
use Modules\DataMigration\Importer\SourceItem;
use Modules\DataMigration\Manifest\ManifestException;
use Modules\DataMigration\Manifest\ManifestReader;
use PHPUnit\Framework\TestCase;

/**
 * « Endroits de camp » on its own, against a writer double: how each line
 * of `places.jsonl` is read, what the camps module is handed, and which
 * verdict comes back. The real writer and the whole run are covered by
 * CampPlacesImportOnMysqlTest.
 */
final class CampPlacesImporterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../fixtures/data-migration/scoutmagic-camp-places/';

    private CampPlacesImporter $importer;

    /** @var object{existing: ?ExistingPlace, unrecognised: list<string>, seen: list<ImportedPlace>, created: list<ImportedPlace>} */
    private object $writer;

    protected function setUp(): void
    {
        $this->writer = new class () implements PlaceImportWriterInterface {
            public ?ExistingPlace $existing = null;
            /** @var list<string> */
            public array $unrecognised = [];
            /** @var list<ImportedPlace> */
            public array $seen = [];
            /** @var list<ImportedPlace> */
            public array $created = [];

            public function findExisting(ImportedPlace $place): ?ExistingPlace
            {
                $this->seen[] = $place;

                return $this->existing;
            }

            public function unrecognisedFields(ImportedPlace $place): array
            {
                return $this->unrecognised;
            }

            public function create(ImportedPlace $place, ?int $actorUserAccountId): int
            {
                $this->created[] = $place;

                return 40 + count($this->created);
            }
        };
        $this->importer = new CampPlacesImporter($this->writer);
    }

    public function testItIsTheCampsImportOfTheMockup(): void
    {
        $this->assertSame('camps', $this->importer->key());
        $this->assertSame('camps', $this->importer->requiredModule());
        $this->assertSame('Endroits de camp', $this->importer->title());
        $this->assertSame('lieux', $this->importer->unitLabel());
        $this->assertSame('scoutmagic-camp-places', $this->importer->archiveFormat()->format);
        $this->assertSame(1, $this->importer->archiveFormat()->majorVersion);
        $this->assertSame([
            'Aucun lieu existant n\'est modifié ni fusionné.',
            'Les coordonnées du fichier sont gardées et ne seront pas recalculées.',
        ], $this->importer->effects());
    }

    public function testTheExampleArchiveIsReadLineByLineAsPlaces(): void
    {
        $items = $this->itemsOf('endroits-de-camp.zip');

        $this->assertCount(4, $items);
        foreach ($items as $item) {
            $this->assertSame('place', $item->kind);
        }
        $this->assertSame(['p-0001', 'p-0002', 'p-0003', 'p-0004'], array_map(static fn (SourceItem $i): string => $i->sourceId, $items));
        $this->assertSame('p-0001 · Ferme du Bois-Haut, Hamois', $items[0]->shownReference());
        $this->assertSame('p-0004 · Le pré du Moulin', $items[3]->shownReference());
    }

    public function testReadingResumesAtAPosition(): void
    {
        $items = $this->itemsOf('endroits-de-camp.zip', 2);

        $this->assertSame([2, 3], array_keys($items));
        $this->assertSame('p-0003', $items[2]->sourceId);
    }

    public function testAnUnreadableLineIsYieldedAtItsPosition(): void
    {
        $items = $this->itemsOf('elements-invalides.zip');

        $this->assertTrue($items[2]->isUnreadable());
        $this->assertSame('Ligne illisible', $items[2]->unreadableReason);
        $this->assertSame('ligne 3', $items[2]->shownReference());
        // A numeric id is left empty: the module ignores it with its own reason.
        $this->assertSame('', $items[1]->sourceId);
    }

    public function testAnArchiveOfAnotherMajorVersionIsRefused(): void
    {
        $this->expectException(ManifestException::class);
        $this->itemsOf('version-majeure-inconnue.zip');
    }

    public function testAnArchiveWithAForbiddenExtensionIsRefused(): void
    {
        $this->expectException(ArchiveRejectedException::class);
        $this->itemsOf('extension-refusee.zip');
    }

    public function testAnArchiveWithoutPlacesIsRefused(): void
    {
        $this->expectException(ArchiveRejectedException::class);
        $this->itemsOf('fichier-absent.zip');
    }

    public function testAPlaceWithoutANameIsIgnored(): void
    {
        foreach ([['id' => 'p-1'], ['id' => 'p-1', 'name' => '   '], ['id' => 'p-1', 'name' => 42]] as $row) {
            $verdict = $this->inspect($row);
            $this->assertSame(ItemVerdict::IGNORED, $verdict->outcome);
            $this->assertSame('Sans nom', $verdict->reason);
        }
        $this->assertSame([], $this->writer->seen, 'A nameless place was compared.');
    }

    public function testATooLongNameIsIgnored(): void
    {
        $verdict = $this->inspect(['id' => 'p-1', 'name' => str_repeat('é', 151)]);

        $this->assertSame(ItemVerdict::IGNORED, $verdict->outcome);
        $this->assertSame('Nom de plus de 150 caractères', $verdict->reason);
    }

    public function testACertainMatchIsAlreadyPresent(): void
    {
        $this->writer->existing = new ExistingPlace(3, true, 'Ferme du Bois-Haut', 'Hamois');

        $this->assertSame(ItemVerdict::PRESENT, $this->inspect(['id' => 'p-1', 'name' => 'Ferme du Bois-Haut'])->outcome);
    }

    public function testAPossibleMatchIsAmbiguousAndNamesTheExistingPlace(): void
    {
        $this->writer->existing = new ExistingPlace(3, false, 'Ferme du Bois-Haut', 'Hamois');

        $verdict = $this->inspect(['id' => 'p-0043', 'name' => 'Ferme du Bois', 'city' => 'Hamois']);

        $this->assertSame(ItemVerdict::AMBIGUOUS, $verdict->outcome);
        $this->assertSame('Ressemble à « Ferme du Bois-Haut », Hamois', $verdict->reason);
    }

    public function testAnAmbiguityWithoutLocalityNamesThePlaceAlone(): void
    {
        $this->writer->existing = new ExistingPlace(3, false, 'Prairie Lambert', null);

        $this->assertSame('Ressemble à « Prairie Lambert »', $this->inspect(['id' => 'p-1', 'name' => 'Prairie Lambrt'])->reason);
    }

    public function testAnUnknownPlaceIsImported(): void
    {
        $verdict = $this->inspect(['id' => 'p-1', 'name' => 'Le pré du Moulin']);

        $this->assertSame(ItemVerdict::IMPORT, $verdict->outcome);
        $this->assertSame([], $verdict->tags);
        $this->assertNull($verdict->scoutYearLabel, 'A place belongs to no scout year.');
    }

    public function testTheCampsModuleReceivesWhatTheLineSays(): void
    {
        $this->inspect([
            'id' => 'p-1', 'name' => ' Ferme du Bois-Haut ', 'address' => 'Chemin 12', 'postal_code' => '5360',
            'city' => 'Hamois', 'country' => 'Belgique', 'website_url' => 'https://exemple.be',
            'latitude' => 50.341205, 'longitude' => 5, 'is_archived' => true, 'unknown_field' => 'ignoré',
        ]);

        $place = $this->writer->seen[0];
        $this->assertSame('Ferme du Bois-Haut', $place->name);
        $this->assertSame('Chemin 12', $place->address);
        $this->assertSame('5360', $place->postalCode);
        $this->assertSame('Hamois', $place->city);
        $this->assertSame('Belgique', $place->country);
        $this->assertSame('https://exemple.be', $place->websiteUrl);
        $this->assertSame('50.341205', $place->latitude);
        $this->assertSame('5', $place->longitude);
        $this->assertTrue($place->isArchived);
    }

    public function testCoordinatesWrittenAsTextAreHandedOverAsTheyAre(): void
    {
        $this->inspect(['id' => 'p-1', 'name' => 'Pré', 'latitude' => '50,34', 'longitude' => '5.15']);

        $this->assertSame('50,34', $this->writer->seen[0]->latitude);
        $this->assertSame('5.15', $this->writer->seen[0]->longitude);
    }

    public function testValuesOfTheWrongTypeAreLeftOutAndCounted(): void
    {
        $verdict = $this->inspect([
            'id' => 'p-1', 'name' => 'Pré', 'postal_code' => 5360, 'latitude' => true, 'longitude' => 5.1,
            'is_archived' => 'oui',
        ]);

        $place = $this->writer->seen[0];
        $this->assertNull($place->postalCode);
        $this->assertNull($place->latitude, 'Half a point was kept.');
        $this->assertNull($place->longitude, 'Half a point was kept.');
        $this->assertFalse($place->isArchived);
        $this->assertSame(ItemVerdict::IMPORT, $verdict->outcome);
        $this->assertSame(['unrecognised'], $verdict->tags);
    }

    public function testWhatTheCampsModuleLeavesOutIsCounted(): void
    {
        $this->writer->unrecognised = ['coordinates'];

        $this->assertSame(['unrecognised'], $this->inspect(['id' => 'p-1', 'name' => 'Pré', 'latitude' => 50.1])->tags);
    }

    public function testAnArchivedPlaceIsCounted(): void
    {
        $this->assertSame(['archived'], $this->inspect(['id' => 'p-1', 'name' => 'Pré', 'is_archived' => true])->tags);
    }

    public function testWriteCreatesThePlaceAsTheAdministrator(): void
    {
        $item = new SourceItem('place', 'p-1', ['id' => 'p-1', 'name' => 'Pré', 'city' => 'Ciney']);

        $target = $this->importer->write($item, $this->context());

        $this->assertSame('camp_place', $target->type);
        $this->assertSame('41', $target->id);
        $this->assertSame('Ciney', $this->writer->created[0]->city);
    }

    public function testThePreviewFollowsTheMockup(): void
    {
        $notes = $this->importer->previewNotes(new AnalysisSummary(86, 41, 30, 15, [], ['unrecognised' => 2, 'archived' => 3], []));

        $this->assertSame([
            [PreviewNote::WARNING, '15 lieux ressemblent à un lieu déjà présent sans certitude. Ils ne sont ni créés ni fusionnés : vérifiez-les dans Camps.'],
            [PreviewNote::INFO, 'Les lieux archivés sont aussi comparés, pour ne pas les recréer.'],
            [PreviewNote::WARNING, '2 lieux ont des informations non reconnues (coordonnées incomplètes ou hors limites, site web invalide, texte trop long). Ils sont créés sans elles.'],
            [PreviewNote::INFO, '3 lieux sont archivés sur l\'ancien site. Ils sont créés archivés.'],
        ], array_map(static fn (PreviewNote $n): array => [$n->level, $n->text], $notes));
    }

    public function testThePreviewSpeaksOfOnePlaceInTheSingular(): void
    {
        $notes = $this->importer->previewNotes(new AnalysisSummary(1, 0, 0, 1, [], [], []));

        $this->assertSame(
            '1 lieu ressemble à un lieu déjà présent sans certitude. Il n\'est ni créé ni fusionné : vérifiez-le dans Camps.',
            $notes[0]->text
        );
        $this->assertCount(2, $notes);
    }

    public function testWithoutTheCampsModuleNothingCanBeInspected(): void
    {
        $this->expectException(\LogicException::class);
        (new CampPlacesImporter(null))->inspect(new SourceItem('place', 'p-1', ['name' => 'Pré']), $this->context());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function inspect(array $row): ItemVerdict
    {
        return $this->importer->inspect(new SourceItem('place', (string) $row['id'], $row), $this->context());
    }

    private function context(): ImportContext
    {
        return new ImportContext(1, 'ancien-site-exemple', 'scoutmagic-camp-places', 7, new ImportSource(null, null), static fn (string $label): ?int => null);
    }

    /**
     * @return array<int, SourceItem>
     */
    private function itemsOf(string $fixture, int $from = 0): array
    {
        $format = $this->importer->archiveFormat();
        $archive = (new BoundedZipReader($format->limits))->open(self::FIXTURES . $fixture);
        try {
            $manifest = (new ManifestReader())->read($archive, $format->format, $format->majorVersion);
            $items = [];
            foreach ($this->importer->items(new ImportSource($archive, $manifest), $from) as $position => $item) {
                $items[$position] = $item;
            }

            return $items;
        } finally {
            $archive->close();
        }
    }
}
