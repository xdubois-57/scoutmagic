<?php

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * Checks for the ffmpeg/ffprobe system binaries (video transcoding is
 * entirely optional — this app has no other system-binary dependency).
 * Result is cached for the object's lifetime (effectively the request),
 * per module spec.
 */
class FfmpegAvailability
{
    private ?bool $cached = null;

    public function check(): bool
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $this->cached = $this->binaryExists('ffmpeg') && $this->binaryExists('ffprobe');
        return $this->cached;
    }

    private function binaryExists(string $binary): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which';
        exec(escapeshellcmd($which) . ' ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }
}
