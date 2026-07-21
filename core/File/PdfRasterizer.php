<?php

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
