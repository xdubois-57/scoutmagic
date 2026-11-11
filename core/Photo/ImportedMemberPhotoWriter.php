<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

use Core\Image\ImageDimensionGuard;

/**
 * Gives a member the photo of a scout year from bytes that did not come
 * from a browser — the member photos of another site, brought over by the
 * data migration module (chantier « Migration de données », lot E) — and
 * never replaces one.
 *
 * Member photos are a core capability, so this is the core's own small
 * door for it rather than an `Api\` contract of some module. It adds two
 * things to {@see PhotoIngestionService::ingest()}, which it goes through
 * for everything else (type check, size ceiling, disk budget, EXIF strip,
 * re-encoding, the `thumb` derivative, the `files` row with role_min
 * `identified`):
 *
 * - **a stricter check before anything is stored**
 *   ({@see rejectionReason()}). `UploadHandler` keeps a file GD cannot
 *   decode as it came, metadata included — right for a browser upload the
 *   visitor can retry, wrong for thousands of photos nobody looks at one by
 *   one. And the pixel ceiling is lower than the upload's 50 megapixels:
 *   the hosting gives a request 128 MiB and a decode costs four bytes a
 *   pixel, while a browser upload is downscaled before it is sent and an
 *   imported one is not.
 * - **no overwrite, ever.** `ingest()` links a member photo through
 *   `MemberPhotoService::setPhoto()`, an upsert. Here the file is stored
 *   unlinked (an empty key, which `ingest()` documents as "store, link
 *   nothing") and linked by {@see MemberPhotoRepository::insertIfAbsent()},
 *   which the UNIQUE index (member, year) decides.
 *
 * It sends nothing and notifies nobody: no photo upload does.
 */
class ImportedMemberPhotoWriter
{
    /** The same ceiling as a browser upload. */
    public const MAX_BYTES = PhotoIngestionService::MAX_SIZE_BYTES;

    /** 16 MP decode to 64 MB: what a 128 MiB request can afford, with the bytes beside it. */
    public const MAX_PIXELS = 16_000_000;

    /** Longest side, so a 16 MP strip of 1 × 16 000 000 is not « a photo ». */
    public const MAX_SIDE = 10_000;

    /** What a member photo may be: what GD decodes, minus GIF (no camera writes one). */
    public const ACCEPTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const REASON_TOO_HEAVY = 'Image trop lourde (plus de 10 Mo)';
    public const REASON_NOT_ACCEPTED = 'Type d\'image non accepté (JPEG, PNG ou WebP attendu)';
    public const REASON_TYPE_MISMATCH = 'Type réel de l\'image différent de son extension';
    public const REASON_TOO_LARGE = 'Image trop grande (plus de 16 mégapixels ou de 10 000 pixels de côté)';
    public const REASON_UNREADABLE = 'Image illisible';

    public function __construct(
        private PhotoIngestionService $ingestion,
        private MemberPhotoRepository $photos
    ) {
    }

    /**
     * Why these bytes cannot become a member photo — a short French
     * sentence naming nobody — or null when they can.
     *
     * Decodes the image: a header that parses is not yet a photo.
     *
     * @param string|null $expectedMime the type the sender announced (by an
     *        extension); a different real type is refused
     */
    public function rejectionReason(string $bytes, ?string $expectedMime = null): ?string
    {
        if ($bytes === '') {
            return self::REASON_UNREADABLE;
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return self::REASON_TOO_HEAVY;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mime) || !in_array($mime, self::ACCEPTED_MIMES, true)) {
            return self::REASON_NOT_ACCEPTED;
        }
        if ($expectedMime !== null && $mime !== $expectedMime) {
            return self::REASON_TYPE_MISMATCH;
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false || (int) $size[0] < 1 || (int) $size[1] < 1) {
            return self::REASON_UNREADABLE;
        }
        $width = (int) $size[0];
        $height = (int) $size[1];
        if ($width * $height > self::MAX_PIXELS || max($width, $height) > self::MAX_SIDE) {
            return self::REASON_TOO_LARGE;
        }

        try {
            // The site-wide guard too, so a change to its ceiling can only
            // make this one stricter.
            ImageDimensionGuard::assertWithinCeilingFromString($bytes);
        } catch (\Core\Image\ImageDimensionException) {
            return self::REASON_TOO_LARGE;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return self::REASON_UNREADABLE;
        }
        imagedestroy($image);

        return null;
    }

    /** Whether the member already has a photo for exactly that scout year. */
    public function hasPhoto(int $memberId, int $scoutYearId): bool
    {
        return $this->photos->hasPhotoForYear($memberId, $scoutYearId);
    }

    /**
     * Stores the bytes as the member's photo for that scout year, through
     * the photo pipeline, and returns the stored file's id — or null when
     * that year already has a photo, which is then left as it is.
     *
     * The caller has checked the bytes with {@see rejectionReason()}; the
     * pipeline checks type and size again and may still refuse.
     *
     * Runs inside the caller's transaction when there is one: the `files`
     * and `member_photos` rows go or stay together. The file on disk and
     * its derivative are written before the commit and are not removed by
     * a rollback — the same as any upload whose request fails late.
     *
     * @throws \Core\File\UploadException when the pipeline refuses the file
     *         (disk budget exhausted, among others)
     */
    public function write(int $memberId, int $scoutYearId, string $bytes, string $originalName, ?int $actorId): ?int
    {
        if ($this->photos->hasPhotoForYear($memberId, $scoutYearId)) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'member_photo_import_');
        if ($temporary === false || file_put_contents($temporary, $bytes) === false) {
            throw new \RuntimeException('Could not write a temporary file for an imported member photo.');
        }

        try {
            $result = $this->ingestion->ingest([
                'tmp_name' => $temporary,
                'name' => self::shownName($originalName),
                'size' => strlen($bytes),
                'error' => UPLOAD_ERR_OK,
            ], PhotoIngestionService::CONTEXT_MEMBER_PHOTO, '', $actorId);
        } finally {
            @unlink($temporary);
        }

        $fileId = $result->fileId ?? throw new \LogicException('A member photo is always stored as a file.');

        return $this->photos->insertIfAbsent($memberId, $scoutYearId, $fileId, $actorId) ? $fileId : null;
    }

    /** The name the `files` row keeps: a base name, printable, at most 255 bytes. */
    private static function shownName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = mb_strcut($name, 0, 255, 'UTF-8');

        return $name !== '' ? $name : 'photo';
    }
}
