<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * Renders a PDF's first page as a JPEG — the fallback for a PDF with no
 * embedded text layer (PdfTextExtractor returned null: a scanned or
 * photographed receipt saved as PDF), so it can go through the same
 * image-based OCR path as a real photo upload. Requires the imagick
 * extension with PDF (Ghostscript) support; a missing/failed setup
 * degrades to null like any other extraction failure — a caller such as
 * Modules\Finance\Task\ExtractReceiptDataHandler never blocks a
 * receipt's normal (manual) use on this succeeding.
 */
class PdfRasterizer
{
    private const RESOLUTION_DPI = 150;
    private const JPEG_QUALITY = 80;

    public function firstPageToJpeg(string $pdfContent): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'pdf_rasterize_');
        if ($tmpPath === false) {
            return null;
        }

        try {
            if (file_put_contents($tmpPath, $pdfContent) === false) {
                return null;
            }

            $imagick = new \Imagick();

            // Bound what rasterizing an untrusted PDF may consume before the
            // page is ever read (audit M7/M8): a PDF can declare a huge page
            // size that, even at this low DPI, would rasterize to an enormous
            // bitmap and exhaust memory/disk. Exceeding any of these throws,
            // which the catch below turns into a graceful "no thumbnail".
            // These are size limits, so re-setting them per call is idempotent
            // — deliberately NOT RESOURCETYPE_TIME, which ImageMagick tracks
            // cumulatively for the whole process and would eventually abort
            // every rasterization in a long-running worker.
            $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
            $imagick->setResourceLimit(\Imagick::RESOURCETYPE_DISK, 1024 * 1024 * 1024);
            $imagick->setResourceLimit(\Imagick::RESOURCETYPE_WIDTH, 20000);
            $imagick->setResourceLimit(\Imagick::RESOURCETYPE_HEIGHT, 20000);

            $imagick->setResolution(self::RESOLUTION_DPI, self::RESOLUTION_DPI);
            // The [0] page selector is only honored when reading from a
            // file (not a blob) — it keeps Ghostscript from rasterizing
            // every page of a longer document just to use the first one.
            $imagick->readImage($tmpPath . '[0]');
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(self::JPEG_QUALITY);
            $imagick->setImageBackgroundColor('white');
            $imagick->flattenImages();

            return $imagick->getImageBlob();
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmpPath);
        }
    }
}
