<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact;

use Core\File\FileRepository;
use Core\Photo\ImageVariantService;
use Core\Photo\MemberPhotoService;
use Core\Photo\SquareJpegEncoder;

/**
 * A member's portrait as the small square JPEG a vCard's PHOTO property
 * carries, or null.
 *
 * A vCard cannot point at a URL an address book would have to be logged in
 * to fetch, so the portrait travels inside the card. That makes its size
 * the whole design: an original is a megabyte or two, and a contact card
 * is something somebody downloads on a phone. {@see self::SIDE} px at
 * {@see self::QUALITY} is a few kilobytes, roughly what the `thumb`
 * derivative already is.
 *
 * Null for a member with no photo, a photo that cannot be read, or one
 * that cannot be decoded — a card without a portrait is a perfectly good
 * card, and a stale file row must never break an export.
 *
 * It is never asked for the QR variant: a JPEG does not fit in a scannable
 * symbol ({@see VCardVariant}).
 */
class ContactPhotoResolver
{
    private const SIDE = 192;

    private const QUALITY = 75;

    public function __construct(
        private MemberPhotoService $memberPhotoService,
        private FileRepository $fileRepository,
        private ImageVariantService $imageVariantService,
        private string $storagePath
    ) {
    }

    /**
     * Resolve every portrait's file id in one query before jpegFor() is
     * asked member by member — what an address book of thirty staff needs
     * so it does not run thirty lookups.
     *
     * @param int[] $memberIds
     */
    public function prime(array $memberIds, int $scoutYearId): void
    {
        $this->memberPhotoService->primeFileIds($memberIds, $scoutYearId);
    }

    public function jpegFor(int $memberId, int $scoutYearId): ?string
    {
        $fileId = $this->memberPhotoService->resolveFileId($memberId, $scoutYearId);
        if ($fileId === null) {
            return null;
        }

        $file = $this->fileRepository->findById($fileId);
        // No core photo context is ever encrypted; refusing one rather than
        // reaching for the master key keeps this class out of that path.
        if ($file === null || $file->encrypted) {
            return null;
        }

        // Prefer the square 192px derivative — already cropped, already
        // small — and fall back to the original for a photo uploaded
        // before that pipeline existed (ImageVariantService never
        // generates on demand).
        $path = $this->imageVariantService->resolvePath($file->relativePath, 'thumb')
            ?? $this->storagePath . '/' . $file->relativePath;
        if (!is_file($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return SquareJpegEncoder::encode($bytes, self::SIDE, self::QUALITY);
    }
}
