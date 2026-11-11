<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Archive\ArchiveLimits;
use Core\Config\ScoutYearService;
use Core\Http\LinkPreview;
use Core\Http\LinkPreviewFetcher;
use Core\Service\DateInput;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\SectionByDeskCode;
use Modules\Gallery\Api\ExternalAlbumUrl;
use Modules\Gallery\Api\ExternalAlbumWriter;
use Modules\Gallery\Api\ImportedExternalAlbum;

/**
 * « Galeries photo »: the old site's albums hosted elsewhere (Google
 * Photos, Flickr…), brought in as the gallery's external albums. No photo
 * is copied. Format `scoutmagic-gallery-links` 1.x, documented for
 * exporters in `docs/formats/data-migration/gallery-links.md`.
 *
 * One line of `albums.jsonl` is one album, of kind `album`. What decides
 * its fate, in this order:
 * - an address that is not an absolute http(s) one, or too long, ignores
 *   it; an address the gallery already has — compared after the gallery's
 *   own safe normalisation — makes it « déjà présent »;
 * - its date (`album_date`) is required: the gallery files and sorts by
 *   it;
 * - its scout year is `scout_year`, or the one its date falls in; a past
 *   year the site lacks is offered for creation (D5), and an album of a
 *   year not created is ignored — never filed under the current year;
 * - a section, by Desk code (D10): absent means the whole unit, unknown
 *   ignores the album — it is never shown to the whole unit instead;
 * - the page's own Open Graph data, read **during the execution only**,
 *   one album per slice ({@see ThrottledImporter}), through the site's
 *   one SSRF-checked fetcher: the title, description and image. The
 *   file's title wins over the page's, the page's fills an empty one, and
 *   an album with neither is ignored.
 */
final class GalleryLinksImporter implements ThrottledImporter
{
    public const FORMAT = 'scoutmagic-gallery-links';
    public const KIND = 'album';
    public const MEMBER = 'albums.jsonl';

    public const TAG_TITLE_FROM_PAGE = 'title_from_page';

    public const REASON_URL = 'Adresse de l\'album absente ou invalide';
    public const REASON_URL_TOO_LONG = 'Adresse de l\'album trop longue (plus de 500 caractères)';
    public const REASON_EXTERNAL_DISABLED = 'Albums externes désactivés dans la configuration de la Galerie';
    public const REASON_DATE = 'Date de l\'album absente ou illisible';
    public const REASON_YEAR = 'Année scoute illisible';
    public const REASON_SECTION = 'Section sans correspondance';
    public const REASON_TITLE_TOO_LONG = 'Titre trop long (plus de 255 caractères)';
    public const REASON_SUBTITLE_TOO_LONG = 'Sous-titre trop long (plus de 255 caractères)';
    public const REASON_NO_TITLE = 'Sans titre, ni dans le fichier ni sur la page de l\'album';

    /**
     * What the page said, read by the last inspect() for this address and
     * used by the write() the module calls right after it — so one
     * album costs one fetch, not two.
     *
     * @var array{url: string, preview: ?LinkPreview}|null
     */
    private ?array $lastPreview = null;

    public function __construct(
        private ?ExternalAlbumWriter $albums,
        private SectionByDeskCode $sections,
        private ?LinkPreviewFetcher $previews
    ) {
    }

    public function key(): string
    {
        return 'galeries';
    }

    public function requiredModule(): string
    {
        return 'gallery';
    }

    public function title(): string
    {
        return 'Galeries photo';
    }

    public function description(): string
    {
        return 'Les albums hébergés ailleurs (Google Photos, Flickr…), ajoutés comme albums externes. Aucune photo n\'est copiée.';
    }

    public function unitLabel(): string
    {
        return 'albums';
    }

    public function archiveFormat(): ArchiveFormat
    {
        return new ArchiveFormat(
            self::FORMAT,
            1,
            new ArchiveLimits(10, 20 * 1024 * 1024, 21 * 1024 * 1024, ['json', 'jsonl']),
            25 * 1024 * 1024
        );
    }

    /** One remote page per slice: reading one can take several seconds. */
    public function inspectionsPerExecutionSlice(): int
    {
        return 1;
    }

    public function items(ImportSource $source, int $from): iterable
    {
        foreach ($source->jsonLines(self::MEMBER, $from) as $position => $row) {
            if ($row === null) {
                yield $position => SourceItem::unreadable(self::KIND, 'ligne ' . ($position + 1), 'Ligne illisible');
                continue;
            }
            $id = SourceItem::idFrom($row['id'] ?? null);
            // The old site's identifier is what the screens show — never
            // the album's title, which can name a child (D8).
            yield $position => new SourceItem(self::KIND, $id ?? '', $row, $id ?? 'ligne ' . ($position + 1));
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        $albums = $this->writer();
        $row = $item->data;

        $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';
        if (ExternalAlbumUrl::normalize($url) === null) {
            return ItemVerdict::ignored(self::REASON_URL);
        }
        if (mb_strlen($url) > ImportedExternalAlbum::MAX_URL_LENGTH) {
            return ItemVerdict::ignored(self::REASON_URL_TOO_LONG);
        }
        if (!$albums->externalAlbumsAllowed()) {
            return ItemVerdict::ignored(self::REASON_EXTERNAL_DISABLED);
        }
        if ($albums->findExternalAlbumByUrl($url) !== null) {
            return ItemVerdict::present();
        }

        $date = self::albumDate($row['album_date'] ?? null);
        if ($date === null) {
            return ItemVerdict::ignored(self::REASON_DATE);
        }
        $year = self::scoutYear($row['scout_year'] ?? null, $date);
        if ($year === null) {
            return ItemVerdict::ignored(self::REASON_YEAR);
        }
        if (self::hasSectionCode($row) && $this->sections->find($row['section_desk_code']) === null) {
            return ItemVerdict::ignored(self::REASON_SECTION);
        }

        $title = self::text($row['title'] ?? null);
        if ($title !== null && mb_strlen($title) > ImportedExternalAlbum::MAX_TITLE_LENGTH) {
            return ItemVerdict::ignored(self::REASON_TITLE_TOO_LONG);
        }
        $subtitle = self::text($row['subtitle'] ?? null);
        if ($subtitle !== null && mb_strlen($subtitle) > ImportedExternalAlbum::MAX_SUBTITLE_LENGTH) {
            return ItemVerdict::ignored(self::REASON_SUBTITLE_TOO_LONG);
        }

        $verdict = ItemVerdict::import()->needingScoutYear($year);
        if (!$context->executing) {
            // The analysis reads no page: it would make the preview as
            // slow as the import. Whether a title-less album finds one is
            // known at the execution.
            return $title === null ? $verdict->tagged(self::TAG_TITLE_FROM_PAGE) : $verdict;
        }
        if ($context->scoutYearId($year) === null) {
            // A year that was not created: the module ignores the album
            // right after this, and its page need not be read for that.
            return $verdict;
        }

        $preview = $this->preview($url);
        if ($title === null && self::text($preview?->title) === null) {
            return ItemVerdict::ignored(self::REASON_NO_TITLE);
        }

        return $verdict;
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        $row = $item->data;
        $url = trim((string) $row['url']);
        $date = self::albumDate($row['album_date'] ?? null) ?? throw new \LogicException('Inspected without a date.');
        $year = self::scoutYear($row['scout_year'] ?? null, $date) ?? throw new \LogicException('Inspected without a year.');
        $scoutYearId = $context->scoutYearId($year) ?? throw new \LogicException('The album\'s scout year does not exist.');
        $createdBy = $context->userAccountId ?? throw new \LogicException('An import is run by an account.');

        $preview = $this->preview($url);
        $pageTitle = self::text($preview?->title);
        $title = self::text($row['title'] ?? null)
            ?? ($pageTitle !== null ? mb_substr($pageTitle, 0, ImportedExternalAlbum::MAX_TITLE_LENGTH) : null)
            ?? throw new \LogicException('Inspected without a title.');

        $id = $this->writer()->createImportedExternalAlbum(new ImportedExternalAlbum(
            $url,
            $title,
            self::text($row['subtitle'] ?? null),
            $date->format('Y-m-d'),
            self::hasSectionCode($row) ? $this->sections->find($row['section_desk_code']) : null,
            $scoutYearId,
            $createdBy,
            $preview?->title,
            $preview?->description,
            $preview?->imageBytes
        ));

        return new WrittenTarget('gallery_album', (string) $id);
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        $notes = [];
        if ($summary->toImport > 0) {
            $notes[] = PreviewNote::info(
                'Le titre et la vignette sont relus sur chaque album pendant l\'import. Comptez environ deux secondes par album.'
            );
        }

        $withoutTitle = $summary->tag(self::TAG_TITLE_FROM_PAGE);
        if ($withoutTitle > 0) {
            $notes[] = PreviewNote::warning($withoutTitle === 1
                ? '1 album n\'a pas de titre dans le fichier. Il prendra celui de sa page, ou sera ignoré si elle n\'en donne pas.'
                : $withoutTitle . ' albums n\'ont pas de titre dans le fichier. Ils prendront celui de leur page, ou seront ignorés si elle n\'en donne pas.');
        }

        $unknownSection = $summary->ignoredReasons[self::REASON_SECTION] ?? 0;
        if ($unknownSection > 0) {
            $notes[] = PreviewNote::warning($unknownSection === 1
                ? '1 album vise une section inconnue. Il est ignoré plutôt que montré à toute l\'unité.'
                : $unknownSection . ' albums visent une section inconnue. Ils sont ignorés plutôt que montrés à toute l\'unité.');
        }

        return $notes;
    }

    public function effects(): array
    {
        return [
            'Aucune notification « Nouvel album ».',
            'Les albums suivent ensuite les règles normales de la Galerie.',
        ];
    }

    private function writer(): ExternalAlbumWriter
    {
        // The registry offers this import only while the gallery is on, and
        // the gallery's block builds the writer whenever it is.
        return $this->albums ?? throw new \LogicException('The gallery module is not enabled.');
    }

    /**
     * The page's Open Graph data, or null when it could not be read — the
     * file's data are then used alone. Read once per address and slice.
     */
    private function preview(string $url): ?LinkPreview
    {
        if ($this->lastPreview !== null && $this->lastPreview['url'] === $url) {
            return $this->lastPreview['preview'];
        }

        $preview = null;
        if ($this->previews !== null) {
            try {
                $preview = $this->previews->fetch($url);
            } catch (\Throwable) {
                // The contract says it never throws; an implementation that
                // does still costs this album its page, not its import.
                $preview = null;
            }
        }
        $this->lastPreview = ['url' => $url, 'preview' => $preview];

        return $preview;
    }

    private static function albumDate(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? DateInput::iso($value) : null;
    }

    /** The year the file names, or the one the album's date falls in; null when the file names an unreadable one. */
    private static function scoutYear(mixed $value, \DateTimeImmutable $date): ?string
    {
        if ($value === null || $value === '') {
            return ScoutYearService::labelForDate($date);
        }

        return is_string($value) && MissingScoutYears::isLabel($value) ? $value : null;
    }

    /** @param array<string, mixed> $row */
    private static function hasSectionCode(array $row): bool
    {
        $code = $row['section_desk_code'] ?? null;

        return $code !== null && !(is_string($code) && trim($code) === '');
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
