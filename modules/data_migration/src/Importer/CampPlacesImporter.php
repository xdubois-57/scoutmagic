<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Archive\ArchiveLimits;
use Modules\Camps\Api\ImportedPlace;
use Modules\Camps\Api\PlaceImportWriterInterface;

/**
 * « Endroits de camp »: the camp sites of the old site, and nothing else —
 * no stays, contacts, reviews, prices, contracts or documents (lot G of
 * the chantier, spec §10).
 *
 * Format `scoutmagic-camp-places` 1.x, documented for exporters in
 * docs/formats/data-migration/camp-places.md: one `places.jsonl`, one
 * place per line, each with a string `id` (source kind `place`) and a
 * `name`.
 *
 * The correspondence table is asked first, by the module, before
 * {@see inspect()}: a place this source already brought is « déjà
 * présent ». Then the camps module's own duplicate rules, archived places
 * included and without the language model: a certain match is « déjà
 * présent » too and the existing place is not touched; a possible one is
 * ambiguous — neither created nor merged, and listed in the report with
 * the name of the place it resembles (D8 allows place names). Everything
 * else is created, through the camps module's `Api\` writer.
 */
final class CampPlacesImporter implements Importer
{
    public const KEY = 'camps';
    public const FORMAT = 'scoutmagic-camp-places';
    public const MEMBER = 'places.jsonl';
    public const SOURCE_KIND = 'place';
    public const TARGET_TYPE = 'camp_place';

    private const TAG_ARCHIVED = 'archived';
    private const TAG_UNRECOGNISED = 'unrecognised';

    /** The optional text fields, by source key. */
    private const TEXT_FIELDS = ['address', 'postal_code', 'city', 'country', 'website_url'];

    public function __construct(private ?PlaceImportWriterInterface $writer)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function requiredModule(): string
    {
        return 'camps';
    }

    public function title(): string
    {
        return 'Endroits de camp';
    }

    public function description(): string
    {
        return 'Les lieux de camp seuls, avec leurs coordonnées. Les anciens séjours ne sont pas repris.';
    }

    public function unitLabel(): string
    {
        return 'lieux';
    }

    public function archiveFormat(): ArchiveFormat
    {
        // A place is a line of a few hundred bytes: 16 Mio is tens of
        // thousands of them, far beyond any unit's history.
        return new ArchiveFormat(
            self::FORMAT,
            1,
            new ArchiveLimits(10, 16 * 1024 * 1024, 16 * 1024 * 1024, ['json', 'jsonl']),
            32 * 1024 * 1024
        );
    }

    public function items(ImportSource $source, int $from): iterable
    {
        foreach ($source->jsonLines(self::MEMBER, $from) as $position => $row) {
            if ($row === null) {
                yield $position => SourceItem::unreadable(self::SOURCE_KIND, 'ligne ' . ($position + 1), 'Ligne illisible');
                continue;
            }

            $id = SourceItem::idFrom($row['id'] ?? null);
            yield $position => new SourceItem(
                self::SOURCE_KIND,
                $id ?? '',
                $row,
                self::reference($id ?? 'ligne ' . ($position + 1), $row)
            );
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        $name = self::name($item->data);
        if ($name === null) {
            return ItemVerdict::ignored('Sans nom');
        }
        if (mb_strlen($name) > ImportedPlace::MAX_NAME_LENGTH) {
            return ItemVerdict::ignored('Nom de plus de ' . ImportedPlace::MAX_NAME_LENGTH . ' caractères');
        }

        [$place, $unreadable] = self::place($name, $item->data);
        $existing = $this->writer()->findExisting($place);
        if ($existing !== null && $existing->isCertain) {
            return ItemVerdict::present();
        }
        if ($existing !== null) {
            return ItemVerdict::ambiguous(
                'Ressemble à « ' . $existing->name . ' »' . ($existing->locality !== null ? ', ' . $existing->locality : '')
            );
        }

        $verdict = ItemVerdict::import();
        if ($unreadable || $this->writer()->unrecognisedFields($place) !== []) {
            $verdict = $verdict->tagged(self::TAG_UNRECOGNISED);
        }

        return $place->isArchived ? $verdict->tagged(self::TAG_ARCHIVED) : $verdict;
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        $name = self::name($item->data) ?? throw new \LogicException('A place without a name is never written.');
        [$place] = self::place($name, $item->data);

        return new WrittenTarget(self::TARGET_TYPE, (string) $this->writer()->create($place, $context->userAccountId));
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        $notes = [];
        if ($summary->ambiguous > 0) {
            $notes[] = PreviewNote::warning($summary->ambiguous === 1
                ? '1 lieu ressemble à un lieu déjà présent sans certitude. Il n\'est ni créé ni fusionné : vérifiez-le dans Camps.'
                : $summary->ambiguous . ' lieux ressemblent à un lieu déjà présent sans certitude. Ils ne sont ni créés ni '
                    . 'fusionnés : vérifiez-les dans Camps.');
        }
        $notes[] = PreviewNote::info('Les lieux archivés sont aussi comparés, pour ne pas les recréer.');

        $unrecognised = $summary->tag(self::TAG_UNRECOGNISED);
        if ($unrecognised > 0) {
            $notes[] = PreviewNote::warning($unrecognised === 1
                ? '1 lieu a des informations non reconnues (coordonnées incomplètes ou hors limites, site web invalide, '
                    . 'texte trop long). Il est créé sans elles.'
                : $unrecognised . ' lieux ont des informations non reconnues (coordonnées incomplètes ou hors limites, '
                    . 'site web invalide, texte trop long). Ils sont créés sans elles.');
        }

        $archived = $summary->tag(self::TAG_ARCHIVED);
        if ($archived > 0) {
            $notes[] = PreviewNote::info($archived === 1
                ? '1 lieu est archivé sur l\'ancien site. Il est créé archivé.'
                : $archived . ' lieux sont archivés sur l\'ancien site. Ils sont créés archivés.');
        }

        return $notes;
    }

    public function effects(): array
    {
        return [
            'Aucun lieu existant n\'est modifié ni fusionné.',
            'Les coordonnées du fichier sont gardées et ne seront pas recalculées.',
        ];
    }

    private function writer(): PlaceImportWriterInterface
    {
        // Only reachable with camps off, which the registry already
        // answers with a 404 everywhere.
        return $this->writer ?? throw new \LogicException('The camps module is not enabled.');
    }

    /**
     * What the screens show: « p-0043 · Ferme du Bois, Hamois ». A place's
     * name is a plot of land, never a person (D8).
     *
     * @param array<string, mixed> $row
     */
    private static function reference(string $id, array $row): string
    {
        $name = self::name($row);
        if ($name === null) {
            return $id;
        }
        $city = is_string($row['city'] ?? null) ? trim($row['city']) : '';

        return $id . ' · ' . $name . ($city !== '' ? ', ' . $city : '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function name(array $row): ?string
    {
        $name = $row['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }

    /**
     * The place as the camps module receives it, and whether a value had a
     * type the format does not allow (a number for a postal code, a text
     * for the archived flag) and was therefore left out here already.
     *
     * @param array<string, mixed> $row
     * @return array{ImportedPlace, bool}
     */
    private static function place(string $name, array $row): array
    {
        $unreadable = false;

        $texts = [];
        foreach (self::TEXT_FIELDS as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null && !is_string($value)) {
                $unreadable = true;
                $value = null;
            }
            $texts[$key] = $value;
        }

        // Numbers, or text the way a form carries them. Anything else
        // drops BOTH halves: half a point is no point.
        $latitude = self::coordinate($row['latitude'] ?? null);
        $longitude = self::coordinate($row['longitude'] ?? null);
        if ($latitude === false || $longitude === false) {
            $unreadable = true;
            $latitude = $longitude = null;
        }

        $archived = $row['is_archived'] ?? null;
        if ($archived !== null && !is_bool($archived)) {
            $unreadable = true;
            $archived = null;
        }

        return [new ImportedPlace(
            $name,
            $texts['address'],
            $texts['postal_code'],
            $texts['city'],
            $texts['country'],
            $texts['website_url'],
            $latitude,
            $longitude,
            $archived === true
        ), $unreadable];
    }

    /**
     * A coordinate as text, null when absent, false when of a type no
     * coordinate can have.
     */
    private static function coordinate(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) json_encode($value);
        }

        return is_string($value) ? $value : false;
    }
}
