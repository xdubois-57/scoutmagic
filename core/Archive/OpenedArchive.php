<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Archive;

/**
 * An archive {@see BoundedZipReader} accepted, read under its limits.
 *
 * **Every read is measured on the way OUT of the decompressor**, which is
 * the only side that matters: a few kilobytes of compressed zeroes expand
 * to megabytes inside a single call, so a ceiling checked against the
 * input bounds nothing (`Core\Mail\Feedback\Dmarc\BoundedArchive` learnt
 * it with a test that measured thirty-three megabytes for a four megabyte
 * ceiling). `fread()` on the member's stream returns at most one chunk of
 * OUTPUT whatever the input did, so the per-member and total ceilings are
 * checked every chunk of real expansion and a bomb stops at the ceiling.
 *
 * The total is the sum of everything read through this handle, plus what
 * the caller says earlier requests already read.
 */
final class OpenedArchive
{
    private const CHUNK = 65536;

    private int $measured;

    /**
     * @param array<string, int> $files name => index, in archive order
     */
    public function __construct(
        private \ZipArchive $zip,
        private array $files,
        private ArchiveLimits $limits,
        int $alreadyMeasured = 0
    ) {
        $this->measured = max(0, $alreadyMeasured);
    }

    /**
     * The archive's files, directories left out, in archive order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->files);
    }

    public function has(string $name): bool
    {
        return isset($this->files[$name]);
    }

    /** Decompressed bytes counted so far against the total ceiling. */
    public function measuredTotal(): int
    {
        return $this->measured;
    }

    /**
     * The whole member, in memory. `$maxBytes` lowers the per-member
     * ceiling for a member the caller keeps whole (a manifest, a line
     * file), never raises it.
     *
     * @throws ArchiveRejectedException
     */
    public function read(string $name, ?int $maxBytes = null): string
    {
        $content = '';
        $this->stream($name, static function (string $chunk) use (&$content): void {
            $content .= $chunk;
        }, $maxBytes);

        return $content;
    }

    /**
     * Hands the member to `$consumer` one decompressed chunk at a time.
     *
     * @param callable(string): void $consumer
     * @return int the member's measured size
     *
     * @throws ArchiveRejectedException
     */
    public function stream(string $name, callable $consumer, ?int $maxBytes = null): int
    {
        $ceiling = $maxBytes === null
            ? $this->limits->maxEntryBytes
            : min($maxBytes, $this->limits->maxEntryBytes);

        $handle = $this->open($name);
        $size = 0;

        try {
            while (!feof($handle)) {
                $chunk = @fread($handle, self::CHUNK);
                if ($chunk === false) {
                    throw self::unreadable($name);
                }
                if ($chunk === '') {
                    continue;
                }

                $size += strlen($chunk);
                $this->measured += strlen($chunk);

                if ($size > $ceiling) {
                    throw new ArchiveRejectedException(sprintf(
                        'L\'élément « %s » dépasse, une fois décompressé, la taille acceptée.',
                        BoundedZipReader::shown($name)
                    ));
                }
                if ($this->measured > $this->limits->maxTotalBytes) {
                    throw new ArchiveRejectedException(
                        'Le contenu décompressé de l\'archive dépasse la taille acceptée pour ce format.'
                    );
                }

                $consumer($chunk);
            }
        } finally {
            fclose($handle);
        }

        return $size;
    }

    /**
     * SHA-256 of the member's decompressed bytes, and their measured count.
     *
     * @return array{sha256: string, bytes: int}
     *
     * @throws ArchiveRejectedException
     */
    public function digest(string $name): array
    {
        $context = hash_init('sha256');
        $bytes = $this->stream($name, static function (string $chunk) use ($context): void {
            hash_update($context, $chunk);
        });

        return ['sha256' => hash_final($context), 'bytes' => $bytes];
    }

    /**
     * The lines of a text member (JSON Lines), numbered from 0, starting at
     * `$fromLine`. A final line without its newline is still a line; an
     * empty line is skipped and keeps its number. A single line longer than
     * `$maxLineBytes` stops the reading.
     *
     * Lines before `$fromLine` are decompressed and discarded: a ZIP member
     * cannot be seeked into, and re-reading a few megabytes of text is cheaper
     * than any bookkeeping that would avoid it.
     *
     * @return \Generator<int, string>
     *
     * @throws ArchiveRejectedException
     */
    public function lines(string $name, int $fromLine = 0, int $maxLineBytes = 1048576): \Generator
    {
        $handle = $this->open($name);
        $buffer = '';
        $number = 0;
        $size = 0;

        try {
            while (true) {
                $chunk = feof($handle) ? '' : @fread($handle, self::CHUNK);
                if ($chunk === false) {
                    throw self::unreadable($name);
                }

                $size += strlen($chunk);
                $this->measured += strlen($chunk);
                if ($size > $this->limits->maxEntryBytes) {
                    throw new ArchiveRejectedException(sprintf(
                        'L\'élément « %s » dépasse, une fois décompressé, la taille acceptée.',
                        BoundedZipReader::shown($name)
                    ));
                }
                if ($this->measured > $this->limits->maxTotalBytes) {
                    throw new ArchiveRejectedException(
                        'Le contenu décompressé de l\'archive dépasse la taille acceptée pour ce format.'
                    );
                }

                $buffer .= $chunk;
                $finished = $chunk === '' && feof($handle);

                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $newline), "\r");
                    $buffer = substr($buffer, $newline + 1);
                    if ($number >= $fromLine && trim($line) !== '') {
                        yield $number => $line;
                    }
                    $number++;
                }

                if (strlen($buffer) > $maxLineBytes) {
                    throw new ArchiveRejectedException(sprintf(
                        'Une ligne de l\'élément « %s » est trop longue.',
                        BoundedZipReader::shown($name)
                    ));
                }

                if ($finished) {
                    if ($number >= $fromLine && trim($buffer) !== '') {
                        yield $number => rtrim($buffer, "\r");
                    }

                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function close(): void
    {
        @$this->zip->close();
    }

    /**
     * @return resource
     *
     * @throws ArchiveRejectedException
     */
    private function open(string $name)
    {
        if (!isset($this->files[$name])) {
            throw new ArchiveRejectedException(sprintf(
                'L\'élément « %s » est absent de l\'archive.',
                BoundedZipReader::shown($name)
            ));
        }

        $handle = @$this->zip->getStreamIndex($this->files[$name]);
        if ($handle === false) {
            throw self::unreadable($name);
        }

        return $handle;
    }

    private static function unreadable(string $name): ArchiveRejectedException
    {
        return new ArchiveRejectedException(sprintf(
            'L\'élément « %s » de l\'archive est illisible.',
            BoundedZipReader::shown($name)
        ));
    }
}
