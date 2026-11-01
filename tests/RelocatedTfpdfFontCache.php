<?php

declare(strict_types=1);

namespace Tests;

/** A real tFPDF cache copied from a different installation, without touching vendor/. */
final class RelocatedTfpdfFontCache
{
    /**
     * Run only in an isolated process: FPDF_FONTPATH is a global constant.
     *
     * @param callable(): string $render
     */
    public static function render(callable $render): string
    {
        $directory = sys_get_temp_dir() . '/scoutmagic-font-cache-' . bin2hex(random_bytes(8));
        $fonts = $directory . '/unifont';
        mkdir($fonts, 0700, true);

        $cleanup = static function () use ($fonts, $directory): void {
            if (!is_dir($fonts)) {
                return;
            }
            chmod($fonts, 0700);
            foreach (glob($fonts . '/*') ?: [] as $file) {
                chmod($file, 0600);
                unlink($file);
            }
            rmdir($fonts);
            rmdir($directory);
        };
        // Upstream uses die() on a missing font; it bypasses finally.
        register_shutdown_function($cleanup);

        try {
            $source = dirname((new \ReflectionClass(\tFPDF::class))->getFileName())
                . '/font/unifont/DejaVuSans.ttf';
            copy($source, $fonts . '/DejaVuSans.ttf');
            define('FPDF_FONTPATH', $directory . '/');

            // Generate genuine metrics, widths and a PDF before changing
            // only the old installation's path. The font remains intact.
            $render();
            $metricsFile = $fonts . '/dejavusans.mtx.php';
            $metrics = file_get_contents($metricsFile);
            $stale = preg_replace(
                '/^\$ttffile=.*;$/m',
                '\$ttffile=' . var_export($directory . '/previous-installation/DejaVuSans.ttf', true) . ';',
                $metrics,
                1,
                $replacements
            );
            if ($stale === null || $replacements !== 1) {
                throw new \RuntimeException('The real tFPDF metrics did not contain one font path.');
            }
            file_put_contents($metricsFile, $stale);
            foreach (glob($fonts . '/*') ?: [] as $file) {
                chmod($file, 0444);
            }
            chmod($fonts, 0555);

            return $render();
        } finally {
            $cleanup();
        }
    }
}
