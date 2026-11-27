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
 * The total counts **each member once** (#1238), however many times it is
 * read through this handle — an importer that inspects a photo and then
 * reads it again to write it, or a line file read again from its start to
 * reach a later line, does not spend the archive's budget twice: the
 * ceiling bounds what the archive holds, and the per-member ceiling still
 * bounds every single read. Plus what the caller says earlier requests
 * already read. What bounds the WORK of a request is therefore the
 * per-member ceiling times the reads it makes, and a caller reading in
 * slices bounds those by its deadline (the data migration's execution
 * stops each slice after about five seconds).
 *
 * **A member read to its end must match its CRC-32** (#1238): libzip does
 * not check it, and a damaged member came out shorter — or as long but
 * wrong — without a word, so a line file lost its last lines and the
 * import believed the file had no more. The check is made where the end is
 * reached; a read the caller stops early checks nothing, having not read
 * what it would check, and the lines a line file yielded before its end
 * were handed over before it — so a format that must not act on a damaged
 * member reads it whole first (the data migration's verification digests
 * every member before any is imported).
 */
final class OpenedArchive
{
    private const CHUNK = 65536;

    private int $measured;

    /** @var array<string, int> member => decompressed bytes already counted against the total */
    private array $counted = [];

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
        // A member named « 12 » is an integer array key: cast back, or a
        // caller typed `string` gets a TypeError instead of a refusal.
        return array_map('strval', array_keys($this->files));
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
        $crc = hash_init('crc32b');

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
                $this->measure($name, $size, $ceiling);
                hash_update($crc, $chunk);

                $consumer($chunk);
            }
            $this->assertIntact($name, $crc);
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
        $crc = hash_init('crc32b');

        try {
            while (true) {
                $chunk = feof($handle) ? '' : @fread($handle, self::CHUNK);
                if ($chunk === false) {
                    throw self::unreadable($name);
                }

                $size += strlen($chunk);
                $this->measure($name, $size, $this->limits->maxEntryBytes);
                hash_update($crc, $chunk);

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
                    // Before a last line that has no newline of its own —
                    // the one a cut member most likely ends on. Every line
                    // that had one was handed over already (see the class
                    // comment: the verification is what reads it whole).
                    $this->assertIntact($name, $crc);
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

    /**
     * Counts what a read of `$name` has decompressed so far — only the part
     * of it no earlier read of that member counted — and stops the read at
     * either ceiling.
     *
     * @throws ArchiveRejectedException
     */
    private function measure(string $name, int $size, int $ceiling): void
    {
        // Refused before it is counted: a member left out for its size
        // (an image over a caller's lower ceiling) does not spend the
        // archive's budget on the chunk that crossed it.
        if ($size > $ceiling) {
            throw new ArchiveRejectedException(sprintf(
                'L\'élément « %s » dépasse, une fois décompressé, la taille acceptée.',
                BoundedZipReader::shown($name)
            ));
        }

        $counted = $this->counted[$name] ?? 0;
        if ($size > $counted) {
            $this->measured += $size - $counted;
            $this->counted[$name] = $size;
        }
        if ($this->measured > $this->limits->maxTotalBytes) {
            throw new ArchiveRejectedException(
                'Le contenu décompressé de l\'archive dépasse la taille acceptée pour ce format.'
            );
        }
    }

    /**
     * A member read to its end against the CRC-32 its archive declares.
     *
     * @throws ArchiveDamagedException
     */
    private function assertIntact(string $name, \HashContext $crc): void
    {
        $stat = $this->zip->statIndex($this->files[$name]);
        $declared = $stat === false ? null : sprintf('%08x', ((int) $stat['crc']) & 0xFFFFFFFF);
        if ($declared !== hash_final($crc)) {
            throw new ArchiveDamagedException(sprintf(
                'L\'élément « %s » de l\'archive est abîmé : son contenu ne correspond pas à son empreinte.',
                BoundedZipReader::shown($name)
            ));
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

    /** A member the decompressor gives up on is damaged, as one failing its CRC is. */
    private static function unreadable(string $name): ArchiveDamagedException
    {
        return new ArchiveDamagedException(sprintf(
            'L\'élément « %s » de l\'archive est illisible.',
            BoundedZipReader::shown($name)
        ));
    }
}
