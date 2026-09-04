<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Pdf;

/**
 * Best-effort, server-side-only PDF compression — no external API, ever.
 * Detects an available system binary at runtime (Ghostscript first, then
 * qpdf, then pdftocairo — in the spirit of a pluggable-backend interface
 * like Modules\SosStaff\Provider\PhoneProviderInterface, though this
 * stays a single class since there's nothing to configure per backend).
 * Every process runs with a hard timeout and is killed if it overruns;
 * paths are always shell-escaped; temp files live under a random name in
 * the caller-provided temp directory and are removed in a finally block.
 */
class PdfCompressor
{
    public const BACKEND_GHOSTSCRIPT = 'ghostscript';
    public const BACKEND_QPDF = 'qpdf';
    public const BACKEND_PDFTOCAIRO = 'pdftocairo';
    public const BACKEND_NONE = 'none';

    public const QUALITY_MIN_SIZE = 'Taille minimale';
    public const QUALITY_BALANCED = 'Équilibré';
    public const QUALITY_HIGH = 'Haute qualité';

    private const TIMEOUT_SECONDS = 60;

    public function __construct(private string $tempDirectory)
    {
    }

    /**
     * Which backend (if any) is usable on this server right now — checks
     * proc_open is callable and not disabled, then that the binary
     * actually answers, never assumes from PATH alone.
     */
    public function detectBackend(): string
    {
        if (!$this->canUseProcOpen()) {
            return self::BACKEND_NONE;
        }
        if ($this->binaryAnswers('gs', ['-version'])) {
            return self::BACKEND_GHOSTSCRIPT;
        }
        if ($this->binaryAnswers('qpdf', ['--version'])) {
            return self::BACKEND_QPDF;
        }
        if ($this->binaryAnswers('pdftocairo', ['-v'])) {
            return self::BACKEND_PDFTOCAIRO;
        }

        return self::BACKEND_NONE;
    }

    /**
     * Returns the compressed content, or null when compression isn't
     * possible/beneficial: no backend, the process failed or timed out,
     * the output isn't a valid non-empty PDF, or it isn't actually
     * smaller than the input (Ghostscript can grow a text-heavy PDF) —
     * callers must keep the original in every null case.
     */
    public function compress(string $content, string $backend, string $quality): ?string
    {
        if ($backend === self::BACKEND_NONE) {
            return null;
        }

        if (!is_dir($this->tempDirectory)) {
            mkdir($this->tempDirectory, 0755, true);
        }
        $inputPath = $this->tempDirectory . '/' . bin2hex(random_bytes(16)) . '.pdf';
        $outputPath = $this->tempDirectory . '/' . bin2hex(random_bytes(16)) . '.pdf';

        try {
            if (file_put_contents($inputPath, $content) === false) {
                return null;
            }

            $success = $this->executeBackend($backend, $inputPath, $outputPath, $quality);

            if (!$success || !is_file($outputPath)) {
                return null;
            }

            $output = file_get_contents($outputPath);
            if ($output === false || $output === '' || !str_starts_with($output, '%PDF-')) {
                return null;
            }
            if (strlen($output) >= strlen($content)) {
                return null;
            }

            return $output;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    /**
     * Dispatches to the concrete backend command — a single overridable
     * seam (rather than each run*() method individually) so a test can
     * inject a fake outcome (e.g. "produced a bigger file", "produced
     * nothing") without a real Ghostscript/qpdf/pdftocairo binary,
     * exactly like this codebase's other "inject a fake instead of a
     * real network/process call" test doubles.
     */
    protected function executeBackend(string $backend, string $inputPath, string $outputPath, string $quality): bool
    {
        return match ($backend) {
            self::BACKEND_GHOSTSCRIPT => $this->runGhostscript($inputPath, $outputPath, $quality),
            self::BACKEND_QPDF => $this->runQpdf($inputPath, $outputPath),
            self::BACKEND_PDFTOCAIRO => $this->runPdftocairo($inputPath, $outputPath),
            default => false,
        };
    }

    private function runGhostscript(string $input, string $output, string $quality): bool
    {
        $pdfSettings = match ($quality) {
            self::QUALITY_MIN_SIZE => '/screen',
            self::QUALITY_HIGH => '/printer',
            default => '/ebook',
        };

        $cmd = sprintf(
            'gs -dSAFER -dBATCH -dNOPAUSE -dQUIET -sDEVICE=pdfwrite -dCompatibilityLevel=1.5 -dPDFSETTINGS=%s '
                . '-sOutputFile=%s %s',
            escapeshellarg($pdfSettings), escapeshellarg($output), escapeshellarg($input)
        );

        return $this->runWithTimeout($cmd);
    }

    private function runQpdf(string $input, string $output): bool
    {
        $cmd = sprintf(
            'qpdf --compress-streams=y --recompress-flate --optimize-images %s %s',
            escapeshellarg($input), escapeshellarg($output)
        );

        return $this->runWithTimeout($cmd);
    }

    private function runPdftocairo(string $input, string $output): bool
    {
        // pdftocairo writes to {output}.pdf's basename minus extension by
        // convention when given -pdf — pass the full desired path minus
        // '.pdf' via a wrapper name, then confirm the real file exists.
        $cmd = sprintf('pdftocairo -pdf %s %s', escapeshellarg($input), escapeshellarg($output));

        return $this->runWithTimeout($cmd);
    }

    private function canUseProcOpen(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }

    /**
     * @param string[] $args
     */
    private function binaryAnswers(string $binary, array $args): bool
    {
        $cmd = escapeshellcmd($binary) . ' ' . implode(' ', array_map('escapeshellarg', $args));

        return $this->runWithTimeout($cmd, 5);
    }

    private function runWithTimeout(string $cmd, int $timeoutSeconds = self::TIMEOUT_SECONDS): bool
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);
                return $status['exitcode'] === 0;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        return false;
    }
}
