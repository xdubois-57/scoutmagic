<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

/**
 * Opens an archive a stranger sent us, and refuses to be hurt by it
 * (roadmap IT-06).
 *
 * **Every other archive this site opens, it made itself.** A backup it
 * wrote, an update package an administrator uploaded — things whose shape
 * is known before they are read. A DMARC report is the first archive that
 * arrives unbidden from a third party, addressed to a mailbox anybody can
 * write to, and that changes what reading it has to survive.
 *
 * The attack is a decompression bomb: a few kilobytes that expand to
 * gigabytes and take the process, and the shared host it sits on, with
 * them. The defence is not to measure and then decompress — the declared
 * size in a ZIP header is written by whoever built the file — but to
 * **decompress with a ceiling and stop the moment it is crossed**. What is
 * read is bounded because the reading itself is bounded, not because the
 * archive was believed.
 *
 * Three limits, and they answer different lies: {@see MAX_ENTRIES} a
 * thousand tiny members, {@see MAX_ENTRY_BYTES} one enormous member, and
 * {@see MAX_TOTAL_BYTES} many members that are each acceptable and
 * together are not.
 */
final class BoundedArchive
{
    /**
     * A DMARC aggregate report is one XML file. More than a handful of
     * members means it is not what it claims to be, whatever it holds.
     */
    public const MAX_ENTRIES = 8;

    /**
     * Per member. A year of reports for a large unit is a few hundred
     * kilobytes of XML; four megabytes is already generous by two orders
     * of magnitude.
     */
    public const MAX_ENTRY_BYTES = 4 * 1024 * 1024;

    /** All members together. */
    public const MAX_TOTAL_BYTES = 8 * 1024 * 1024;

    /** Read in pieces so the ceiling can be checked before the next one. */
    private const CHUNK = 32768;

    /**
     * What this archive holds, or an empty list when it cannot be read —
     * and **that is not an error worth an exception**. A report that
     * cannot be opened is a report the unit does not get, exactly like one
     * that never arrived; throwing would let a malformed attachment from a
     * stranger interrupt a synchronisation carrying everybody else's mail.
     *
     * @return list<string> each member's bytes
     */
    public function membersOf(string $bytes, string $mimeType): array
    {
        if ($bytes === '') {
            return [];
        }

        return match ($mimeType) {
            'application/gzip', 'application/x-gzip' => $this->fromGzip($bytes),
            'application/zip' => $this->fromZip($bytes),
            // Some reporters send the XML uncompressed, and it is already
            // bounded by the caller's own payload cap.
            'application/xml', 'text/xml' => strlen($bytes) <= self::MAX_ENTRY_BYTES ? [$bytes] : [],
            default => [],
        };
    }

    /**
     * **Bounded on the way OUT, which is the only side that matters.**
     *
     * The first version of this fed the compressed bytes to
     * `inflate_add()` in thirty-two kilobyte pieces and checked the
     * ceiling after each one. That bounds the INPUT, and a bomb's whole
     * point is that the two sizes have no relation: thirty-two kilobytes
     * of compressed zeroes expand to some thirty megabytes inside a
     * single `inflate_add()` call, which has already returned them before
     * any ceiling is consulted. The test measured it — thirty-three
     * megabytes for a four megabyte ceiling.
     *
     * A read filter puts the bound where it belongs. `fread($h, N)`
     * returns at most N bytes of *output* whatever the input did, so the
     * ceiling is checked every N bytes of real expansion and the bomb
     * stops at the ceiling rather than after it.
     *
     * @return list<string>
     */
    private function fromGzip(string $bytes): array
    {
        $handle = fopen('php://memory', 'r+b');
        if ($handle === false) {
            return [];
        }

        try {
            if (@fwrite($handle, $bytes) === false) {
                return [];
            }

            rewind($handle);

            // 47 = 15 (largest window) + 32 (accept a gzip or zlib
            // header). A DMARC report is gzip; being lenient about the
            // wrapper costs nothing and spares a reporter's quirk.
            $filter = @stream_filter_append($handle, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 47]);
            if ($filter === false) {
                return [];
            }

            $out = '';

            while (!feof($handle)) {
                $piece = @fread($handle, self::CHUNK);
                if ($piece === false) {
                    return [];
                }

                $out .= $piece;
                if (strlen($out) > self::MAX_ENTRY_BYTES) {
                    // **What is bounded is the ACCUMULATED OUTPUT, not the
                    // peak.** `fread` caps each `$piece`, but the filter
                    // holds inflated bytes of its own between reads, so the
                    // process peaks somewhat above this ceiling rather than
                    // one chunk above it — a review measured roughly 12 MiB
                    // for a 40 MiB bomb against a 4 MiB ceiling. That is the
                    // property worth having and the one to state: bounded by
                    // a constant of the ceiling, and no longer a function of
                    // what the sender chose to compress.
                    return [];
                }
            }

            return $out === '' ? [] : [$out];
        } finally {
            @fclose($handle);
        }
    }

    /**
     * **Only when the extension is there.** `composer.json` requires no
     * `ext-*` at all, and `ext-zip` is genuinely absent from some shared
     * hosting — where `ext-zlib` effectively never is. A ZIP report on
     * such a server is simply not read, which is why the caller records
     * that a report was unreadable rather than assuming none arrived.
     *
     * @return list<string>
     */
    private function fromZip(string $bytes): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }

        // `ZipArchive` opens a path, never a string. The file is bounded
        // by the caller's payload cap and removed whatever happens below.
        $path = tempnam(sys_get_temp_dir(), 'dmarc_');
        if ($path === false) {
            return [];
        }

        try {
            if (@file_put_contents($path, $bytes) === false) {
                return [];
            }

            $zip = new \ZipArchive();
            if (@$zip->open($path) !== true) {
                return [];
            }

            try {
                return $this->membersOfOpenZip($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private function membersOfOpenZip(\ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return [];
        }

        $members = [];
        $total = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                return [];
            }

            // A directory entry carries no bytes and is not a member.
            if (str_ends_with((string) $stat['name'], '/')) {
                continue;
            }

            // The declared size is written by whoever built the archive,
            // so it is worth a cheap refusal and nothing more — the real
            // bound is the capped read below.
            if ((int) $stat['size'] > self::MAX_ENTRY_BYTES) {
                return [];
            }

            // Reading one byte past the ceiling is what makes « exactly at
            // the ceiling » distinguishable from « truncated here ».
            $content = @$zip->getFromIndex($index, self::MAX_ENTRY_BYTES + 1);
            if ($content === false) {
                return [];
            }

            if (strlen($content) > self::MAX_ENTRY_BYTES) {
                return [];
            }

            $total += strlen($content);
            if ($total > self::MAX_TOTAL_BYTES) {
                return [];
            }

            $members[] = $content;
        }

        return $members;
    }
}
