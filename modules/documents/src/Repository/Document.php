<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Repository;

use Core\Service\DateInput;
use Modules\Documents\Service\DocumentVisibility;

/**
 * One shared document, with what its screens show about the current
 * version's file (type, size) read in the same query.
 */
final class Document
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $description,
        public readonly DocumentVisibility $visibility,
        public readonly int $fileId,
        public readonly int $sortOrder,
        public readonly string $updatedAt,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $originalName,
        /** The slug carries the random segment (created unlisted). */
        public readonly bool $slugIsRandom = false,
        public readonly int $versionNumber = 1,
        /**
         * When the CURRENT file was uploaded (#731) — the file row's own
         * date, so editing the title, the description or the visibility
         * leaves it alone, and replacing the file moves it.
         */
        public readonly string $fileUpdatedAt = '',
        /** `Y-m-d`: shown « Expiré » once this day is past (#731). */
        public readonly string $expiresOn = ''
    ) {
    }

    /** How long a document is valid by default, and after a replaced file. */
    public const VALIDITY_YEARS = 2;

    /**
     * `Y-m-d`, {@see VALIDITY_YEARS} after the given day — or '' (no expiry)
     * when that day is not a date, rather than two years from today.
     */
    public static function defaultExpiry(string $fromDay): string
    {
        $day = DateInput::fromStorage($fromDay);

        return $day === null ? '' : $day->modify('+' . self::VALIDITY_YEARS . ' years')->format('Y-m-d');
    }

    /**
     * Past its expiry date — `$today` is `Y-m-d`. The document stays
     * online: this only marks it to be checked.
     */
    public function isExpired(string $today): bool
    {
        return $this->expiresOn !== '' && $this->expiresOn < $today;
    }

    /** The address that is shared: it survives a new version. */
    public function path(): string
    {
        return '/documents/' . $this->slug;
    }

    /**
     * What kind of file this is, in the words of somebody who is about to
     * open it — never a MIME type.
     */
    public function kindLabel(): string
    {
        return match (true) {
            $this->mimeType === 'application/pdf' => 'PDF',
            str_starts_with($this->mimeType, 'image/') => 'Image',
            in_array($this->mimeType, [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.spreadsheet',
                'text/csv',
            ], true) => 'Tableur',
            in_array($this->mimeType, [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.presentation',
            ], true) => 'Présentation',
            $this->mimeType === 'text/plain' => 'Texte',
            default => 'Document',
        };
    }
}
