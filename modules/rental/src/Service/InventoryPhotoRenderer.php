<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\File\FileRepository;
use Core\Image\PhotoNormalizer;

/**
 * The copies of an inventory's photos its PDF embeds (#989): a JPEG of
 * 1 600 px at most on its longest side, made in memory from the stored
 * 2 400 px photo and **never stored** — the PDF is the only place it
 * lives, and the stored photo stays the original.
 *
 * Through `Core\Image\PhotoNormalizer` with a smaller bound, so the copy
 * gets the same pixel ceiling, the same fresh canvas and no metadata. A
 * JPEG because dompdf embeds one as it is, where a PNG or a WebP would be
 * decoded and re-encoded by GD, slower and heavier; at quality 80, ten
 * photos keep the PDF, and the e-mail it is attached to, within a few
 * megabytes.
 *
 * It is CPU work — ten decodes and resamples — so the validation calls it
 * before it writes anything (`RentalInventoryValidationService`).
 */
class InventoryPhotoRenderer
{
    public const MAX_LONG_SIDE = 1600;

    public const JPEG_QUALITY = 80;

    public function __construct(
        private FileRepository $fileRepository,
        private string $storagePath,
        private PhotoNormalizer $normalizer = new PhotoNormalizer()
    ) {
    }

    /**
     * The copies, by file id. A file that is gone, encrypted (no photo of
     * this module is) or that no longer decodes has no copy, and the
     * annex leaves it out rather than refusing the validation: nothing the
     * manager could do on the page would bring the bytes back.
     *
     * @param list<int> $fileIds
     * @return array<int, array{jpeg: string, width: int, height: int}>
     */
    public function render(array $fileIds): array
    {
        $images = [];
        foreach (array_unique($fileIds) as $fileId) {
            $image = $this->renderOne($fileId);
            if ($image !== null) {
                $images[$fileId] = $image;
            }
        }

        return $images;
    }

    /**
     * @return array{jpeg: string, width: int, height: int}|null
     */
    private function renderOne(int $fileId): ?array
    {
        $file = $this->fileRepository->findById($fileId);
        if ($file === null || $file->encrypted) {
            return null;
        }

        $path = $this->storagePath . '/' . $file->relativePath;
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false || $bytes === '') {
            return null;
        }

        try {
            $jpeg = $this->normalizer->normalize($bytes, self::MAX_LONG_SIDE, self::JPEG_QUALITY);
        } catch (\Throwable) {
            // A refusal of the normaliser, or whatever GD throws on bytes
            // that were a photo once: no copy, like a file gone — never a
            // validation refused over it.
            return null;
        }

        $size = getimagesizefromstring($jpeg);
        if ($size === false) {
            return null;
        }

        return ['jpeg' => $jpeg, 'width' => $size[0], 'height' => $size[1]];
    }
}
