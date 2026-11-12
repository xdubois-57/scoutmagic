<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Archive\ArchiveLimits;
use Core\Import\MemberRepository;
use Core\Photo\ImportedMemberPhotoWriter;
use Modules\DataMigration\Service\MissingScoutYears;

/**
 * « Photos des membres » — format `scoutmagic-member-photos` 1.x, documented
 * for exporters in `docs/formats/data-migration/member-photos.md`.
 *
 * One line of `photos.jsonl` is one photo of one member for one scout
 * year: the member by their Desk id (merge aliases included, through
 * `Core\Import\MemberRepository::findByDeskId()`), the year by its label,
 * the image by its path `images/<sha256>.<extension>`. Member photos are a
 * core capability, so there is no `Api\` contract and no required module:
 * the photo goes through `Core\Photo\ImportedMemberPhotoWriter`, the
 * core's own door onto the photo pipeline.
 *
 * Never an overwrite:
 * - a member who already has a photo for that year keeps it — the line is
 *   « déjà présent », and the preview says how many;
 * - several lines for the same member and year: the first VALID one is
 *   taken and the later ones are ignored. « Valid » is decided by the
 *   image itself (its real type, its size, a decode), so a broken first
 *   photo does not hide a good second one. The analysis applies the same
 *   rule, so the preview counts what the execution will do;
 * - an unknown member is ignored, never created (spec §3.2).
 *
 * The year follows D5: a past year missing from the site is offered in the
 * preview, and the lines of a year not ticked are ignored by the module.
 */
final class MemberPhotosImporter implements Importer
{
    public const KEY = 'photos';
    public const FORMAT = 'scoutmagic-member-photos';
    public const MAJOR_VERSION = 1;
    public const KIND = 'photo';
    public const TARGET_TYPE = 'member_photo_file';
    public const LINES = 'photos.jsonl';

    /**
     * Images per archive. Bounded by the manifest, which lists every file
     * and is read whole up to 1 MiB: 4 000 lines of about 180 bytes leave
     * room for an indented one. A bigger collection is split into several
     * archives of the same installation, which deduplicate together.
     */
    public const MAX_IMAGES = 4000;

    /** One member of the archive, `photos.jsonl` included. Images over 10 MB are ignored one by one. */
    public const MAX_ENTRY_BYTES = 16 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 2 * 1024 * 1024 * 1024;

    public const EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /** A Desk id is at most 50 characters on this site (`members.desk_id`). */
    private const MAX_DESK_ID_BYTES = 100;

    public const REASON_UNKNOWN_MEMBER = 'Membre introuvable dans ScoutMagic';
    public const REASON_UNREADABLE_LINE = 'Ligne illisible';
    public const REASON_DESK_ID = 'desk_id absent ou qui n\'est pas une chaîne de caractères';
    public const REASON_SCOUT_YEAR = 'Année scoute absente ou illisible';
    public const REASON_SHA256 = 'Empreinte SHA-256 absente ou invalide';
    public const REASON_PATH = 'Chemin d\'image invalide';
    public const REASON_MISSING_IMAGE = 'Image absente de l\'archive';
    public const REASON_FINGERPRINT = 'Empreinte différente de celle du manifeste';
    public const REASON_EARLIER_LINE = 'Une photo précédente du fichier vise déjà ce membre et cette année';

    /** Tag of a line whose member already has a photo for its year. */
    public const TAG_SLOT_TAKEN = 'slot_taken';

    /**
     * Why an image cannot be used, by run and path — a path several lines
     * share, or the first line of a pair the next one asks about, is
     * decoded once per request.
     *
     * @var array<string, ?string>
     */
    private array $imageReasons = [];

    public function __construct(
        private MemberRepository $members,
        private ImportedMemberPhotoWriter $photos
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function title(): string
    {
        return 'Photos des membres';
    }

    public function description(): string
    {
        return 'Une photo par animé et par année scoute, rattachée par son identifiant Desk. Le trombinoscope et '
            . 'les fiches les utilisent aussitôt.';
    }

    public function unitLabel(): string
    {
        return 'photos';
    }

    public function archiveFormat(): ArchiveFormat
    {
        return new ArchiveFormat(
            self::FORMAT,
            self::MAJOR_VERSION,
            new ArchiveLimits(
                // The images, the manifest, photos.jsonl and an `images/`
                // directory entry an archiver may add.
                self::MAX_IMAGES + 3,
                self::MAX_ENTRY_BYTES,
                self::MAX_TOTAL_BYTES,
                ['json', 'jsonl', ...array_keys(self::EXTENSIONS)]
            )
        );
    }

    /**
     * Reads `photos.jsonl` from its first line every time, and yields from
     * `$from`: whether a line is the first for its member and year depends
     * on the lines before it, which an earlier slice read. Re-reading a few
     * hundred kilobytes of text is cheaper than carrying that state.
     */
    public function items(ImportSource $source, int $from): iterable
    {
        /** @var array<string, list<string>> $earlier couple => paths of the well-formed lines before */
        $earlier = [];

        foreach ($source->jsonLines(self::LINES, 0) as $position => $row) {
            $parsed = $row === null ? self::REASON_UNREADABLE_LINE : self::parse($row, $source);

            if ($position >= $from) {
                yield $position => is_string($parsed)
                    ? SourceItem::unreadable(self::KIND, 'ligne ' . ($position + 1), $parsed)
                    : new SourceItem(
                        self::KIND,
                        $parsed['source_id'],
                        $parsed + ['earlier' => $earlier[$parsed['couple']] ?? []],
                        'desk ' . $parsed['desk_id'] . ' · ' . $parsed['scout_year']
                    );
            }

            if (is_array($parsed)) {
                $earlier[$parsed['couple']][] = $parsed['path'];
            }
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        $data = self::data($item);

        $member = $this->members->findByDeskId($data['desk_id']);
        if ($member === null) {
            return ItemVerdict::ignored(self::REASON_UNKNOWN_MEMBER);
        }

        // Before the slot: at the execution, the earlier line has just
        // filled it, and the analysis — which wrote nothing — must have
        // said the same thing about this one.
        foreach ($data['earlier'] as $earlierPath) {
            if ($this->imageReason($context, $earlierPath) === null) {
                return ItemVerdict::ignored(self::REASON_EARLIER_LINE);
            }
        }

        $yearId = $context->scoutYearId($data['scout_year']);
        if ($yearId !== null && $this->photos->hasPhoto($member['id'], $yearId)) {
            return ItemVerdict::present()->tagged(self::TAG_SLOT_TAKEN);
        }

        $reason = $this->imageReason($context, $data['path']);
        if ($reason !== null) {
            return ItemVerdict::ignored($reason);
        }

        return ItemVerdict::import()->needingScoutYear($data['scout_year']);
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        $data = self::data($item);
        $member = $this->members->findByDeskId($data['desk_id'])
            ?? throw new \RuntimeException('The member of an imported photo vanished since its inspection.');
        $yearId = $context->scoutYearId($data['scout_year'])
            ?? throw new \LogicException('An imported photo is written only once its scout year exists.');

        $fileId = $this->photos->write(
            $member['id'],
            $yearId,
            $context->source->archive()->read($data['path']),
            $data['original_name'],
            $context->userAccountId
        );
        if ($fileId === null) {
            // Taken between the inspection and this write: never replaced.
            // Counted as an error and rolled back; a re-run finds the slot
            // taken and counts the line « déjà présent ».
            throw new \RuntimeException('The member got a photo for this year while it was being imported.');
        }

        return new WrittenTarget(self::TARGET_TYPE, (string) $fileId);
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        $notes = [];

        $taken = $summary->tag(self::TAG_SLOT_TAKEN);
        if ($taken > 0) {
            $notes[] = PreviewNote::info($taken === 1
                ? '1 animé a déjà une photo pour l\'année visée. Elle est conservée, la photo du fichier est ignorée.'
                : $taken . ' animés ont déjà une photo pour l\'année visée. Elle est conservée, la photo du fichier '
                    . 'est ignorée.');
        }

        return $notes;
    }

    public function effects(): array
    {
        return [
            'Aucune photo existante n\'est remplacée.',
            'Les photos de compte et de section ne sont pas touchées.',
        ];
    }

    /**
     * One line, checked for what does not need the database: its fields,
     * the shape of its path and that the archive holds the image under the
     * fingerprint the line announces. A French reason when it falls short.
     *
     * @param array<string, mixed> $row
     * @return array{
     *     desk_id: string,
     *     scout_year: string,
     *     sha256: string,
     *     path: string,
     *     mime: string,
     *     original_name: string,
     *     couple: string,
     *     source_id: string
     * }|string
     */
    private static function parse(array $row, ImportSource $source): array|string
    {
        $deskId = SourceItem::idFrom($row['desk_id'] ?? null);
        if ($deskId === null) {
            return self::REASON_DESK_ID;
        }
        if (strlen($deskId) > self::MAX_DESK_ID_BYTES) {
            // No member of this site carries such an id.
            return self::REASON_UNKNOWN_MEMBER;
        }

        $year = $row['scout_year'] ?? null;
        if (!is_string($year) || !MissingScoutYears::isLabel($year)) {
            return self::REASON_SCOUT_YEAR;
        }

        $sha256 = $row['sha256'] ?? null;
        if (!is_string($sha256) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return self::REASON_SHA256;
        }

        $path = $row['path'] ?? null;
        if (
            !is_string($path)
            || preg_match('#^images/([0-9a-f]{64})\.([a-z]+)$#', $path, $match) !== 1
            || $match[1] !== $sha256
            || !isset(self::EXTENSIONS[$match[2]])
        ) {
            return self::REASON_PATH;
        }
        if (!$source->archive()->has($path)) {
            return self::REASON_MISSING_IMAGE;
        }
        // The manifest's fingerprint of this file was checked against its
        // bytes before the analysis began; the line must name the same.
        if (($source->manifest?->files[$path]['sha256'] ?? null) !== $sha256) {
            return self::REASON_FINGERPRINT;
        }

        $originalName = $row['original_name'] ?? null;

        return [
            'desk_id' => $deskId,
            'scout_year' => $year,
            'sha256' => $sha256,
            'path' => $path,
            'mime' => self::EXTENSIONS[$match[2]],
            'original_name' => is_string($originalName) && trim($originalName) !== '' ? $originalName : basename($path),
            // Raw Desk id and year: two aliases of one member are told
            // apart here and meet at the execution, on the slot itself.
            'couple' => $deskId . "\n" . $year,
            'source_id' => $deskId . '/' . $year . '/' . $sha256,
        ];
    }

    /**
     * @return array{desk_id: string, scout_year: string, path: string, original_name: string, earlier: list<string>}
     */
    private static function data(SourceItem $item): array
    {
        $data = $item->data;
        $earlier = is_array($data['earlier'] ?? null) ? array_values(array_filter($data['earlier'], 'is_string')) : [];

        return [
            'desk_id' => (string) ($data['desk_id'] ?? ''),
            'scout_year' => (string) ($data['scout_year'] ?? ''),
            'path' => (string) ($data['path'] ?? ''),
            'original_name' => (string) ($data['original_name'] ?? ''),
            'earlier' => $earlier,
        ];
    }

    /** Why the image at `$path` cannot be a member photo, or null. */
    private function imageReason(ImportContext $context, string $path): ?string
    {
        $key = $context->runId . "\n" . $path;
        if (!array_key_exists($key, $this->imageReasons)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $this->imageReasons[$key] = $this->photos->rejectionReason(
                $context->source->archive()->read($path),
                self::EXTENSIONS[$extension] ?? null
            );
        }

        return $this->imageReasons[$key];
    }
}
