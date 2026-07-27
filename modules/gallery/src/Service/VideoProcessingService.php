<?php

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * FFmpeg/FFprobe wrapper (Service\FfmpegAvailability guards every call
 * site — this class assumes the binaries exist). Operates on filesystem
 * paths, not in-memory strings: ffmpeg has no meaningful in-memory API
 * from PHP, so Task\ProcessVideoHandler writes the source to a temp file
 * first. Every argument reaching the shell goes through escapeshellarg().
 */
class VideoProcessingService
{
    /**
     * @return array{width: int, height: int, durationSeconds: int}
     * @throws GalleryException when ffprobe fails or returns no video stream
     */
    public function probe(string $sourcePath): array
    {
        $cmd = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height -show_entries format=duration -of json %s',
            escapeshellarg($sourcePath)
        );
        $json = shell_exec($cmd);
        $data = $json !== null ? json_decode($json, true) : null;

        $stream = $data['streams'][0] ?? null;
        if (!is_array($stream) || !isset($stream['width'], $stream['height'])) {
            throw new GalleryException('Impossible de lire les informations de cette vidéo.');
        }

        $duration = (float) ($data['format']['duration'] ?? 0);

        return [
            'width' => (int) $stream['width'],
            'height' => (int) $stream['height'],
            'durationSeconds' => (int) round($duration),
        ];
    }

    /**
     * Transcodes to H.264/AAC MP4, scaled so its height is $targetHeight
     * (width auto, even-number-safe for yuv420p). Bitrate follows the
     * module spec's rough targets (~2 Mbps@720p, ~5 Mbps@1080p).
     */
    public function transcode(string $sourcePath, string $outputPath, int $targetHeight): void
    {
        $bitrate = $targetHeight >= 1080 ? '5M' : '2M';
        $cmd = sprintf(
            'ffmpeg -y -i %s -vf %s -c:v libx264 -preset medium -b:v %s -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
            escapeshellarg($sourcePath),
            escapeshellarg("scale=-2:{$targetHeight}"),
            escapeshellarg($bitrate),
            escapeshellarg($outputPath)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($outputPath)) {
            throw new GalleryException('Échec du transcodage vidéo.');
        }
    }

    /**
     * Extracts a single JPEG frame at $atSecond into the poster thumbnail.
     */
    public function extractPoster(string $sourcePath, string $outputPath, float $atSecond = 1.0): void
    {
        $cmd = sprintf(
            'ffmpeg -y -ss %s -i %s -frames:v 1 -q:v 3 %s 2>&1',
            escapeshellarg((string) $atSecond),
            escapeshellarg($sourcePath),
            escapeshellarg($outputPath)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($outputPath)) {
            throw new GalleryException("Échec de l'extraction de la vignette vidéo.");
        }
    }
}
