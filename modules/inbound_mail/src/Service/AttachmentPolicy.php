<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Api\AttachmentOmission;
use Modules\InboundMail\Client\FetchedAttachment;

/**
 * Which attachments are kept, and which are quietly dropped (§7.8).
 *
 * **The type is decided from the bytes, never from what the email said.**
 * An `.pdf` name and a `application/pdf` header cost a sender nothing to
 * write; `finfo` reading the file's own magic is the only claim worth
 * anything here.
 *
 * **Signature images are filtered in a deliberate order**, cheapest and
 * most reliable first:
 *
 * 1. `Content-Disposition: inline` with a Content-ID the HTML references —
 *    that is a signature logo by construction, and it removes most of the
 *    noise before anything is measured.
 * 2. A minimum pixel size, configurable. **Pixels, not bytes**: a logo is
 *    typically under 200 px on a side while a useful photo is over a
 *    thousand, and weight says nothing — a heavily compressed photograph
 *    can be smaller than an ornate signature banner.
 * 3. Content hashing, handled by the caller: the same image on ten messages
 *    is one stored file, or none if it was already rejected.
 */
class AttachmentPolicy
{
    /**
     * PDF, images and office documents — nothing else (§7.8). No archives:
     * a zip is a container whose contents nobody inspected, and it is the
     * standard way to smuggle everything on the other side of this list.
     * No executables and no active types — which is why the binary Office
     * formats of before 2007 (`.doc`, `.xls`) are not here (issue #1121):
     * they carry macros in the same file as the text, and nothing about a
     * `.doc` tells a reader whether it does. Their OOXML successors put
     * macros in a separate part (`vbaProject.bin`) of a macro-enabled
     * package, `.docm`/`.xlsm` — which finfo nevertheless reports as plain
     * `.docx`/`.xlsx`, so omissionFor() opens every OOXML package and
     * refuses one that carries such a part. ODF is not macro-free either: a
     * document can bind a macro to its open event, which LibreOffice runs
     * when the reader's macro-security settings allow it. It stays, as the
     * one editable format a unit without Microsoft Office sends.
     *
     * @var string[]
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /** How much of an OOXML package's `[Content_Types].xml` is read: a real one is a few kilobytes. */
    private const CONTENT_TYPES_READ = 262144;

    public function __construct(
        private int $maxSizeBytes = 15 * 1024 * 1024,
        private int $minImageEdgePixels = 200
    ) {
    }

    public function maxSizeBytes(): int
    {
        return $this->maxSizeBytes;
    }

    /**
     * @return string[]
     */
    public function allowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }

    /**
     * Whether this part should become a stored document at all.
     *
     * @param string $bodyHtml the sanitised HTML, used to tell a referenced
     *   inline image from an inline part nothing points at
     */
    public function accepts(FetchedAttachment $attachment, string $bodyHtml): bool
    {
        return !$this->isDecoration($attachment, $bodyHtml)
            && $this->omissionFor($attachment) === null;
    }

    /**
     * A signature logo, a spacer, a decoration — something the sender's mail
     * client put there rather than something they attached.
     *
     * **Dropped silently, and never recorded as an omission.** Telling a
     * chief that « logo.png n'a pas été conservé » on every message from
     * every organisation with an email footer would bury the one omission
     * that actually matters under a hundred that never did.
     */
    public function isDecoration(FetchedAttachment $attachment, string $bodyHtml): bool
    {
        if ($this->isSignatureImage($attachment, $bodyHtml)) {
            return true;
        }

        $realType = $this->detectMimeType($attachment->bytes);

        // An image big enough in bytes can still be too small in PIXELS to
        // be a document — a blank 1200×800 PNG compresses smaller than an
        // ornate 100×100 banner, so weight says nothing.
        return $realType !== null
            && str_starts_with($realType, 'image/')
            && $this->isTooSmallToBeADocument($attachment->bytes);
    }

    /**
     * Why this attachment cannot be kept, or null when it can.
     *
     * Separate from `isDecoration()` because the two have opposite
     * consequences on screen: a decoration is dropped and never mentioned,
     * an omission is recorded so a reader is told the message arrived
     * whole and one file did not.
     */
    public function omissionFor(FetchedAttachment $attachment): ?AttachmentOmission
    {
        if ($attachment->bytes === '') {
            return AttachmentOmission::MIME_REJECTED;
        }

        if ($attachment->sizeBytes() > $this->maxSizeBytes) {
            return AttachmentOmission::TOO_LARGE;
        }

        $realType = $this->detectMimeType($attachment->bytes);
        if ($realType === null || !in_array($realType, self::ALLOWED_MIME_TYPES, true)) {
            return AttachmentOmission::MIME_REJECTED;
        }

        if (str_starts_with($realType, 'application/vnd.openxmlformats-officedocument.')
            && !self::isMacroFreePackage($attachment->bytes)
        ) {
            return AttachmentOmission::MIME_REJECTED;
        }

        return null;
    }

    /**
     * Whether an OOXML package carries no macro project (issue #1121): no
     * `vbaProject.bin` part anywhere, and no macro-enabled content type
     * declared — including a `vbaProject` content type, which is how a
     * renamed macro part still shows. A package that cannot be opened is
     * refused too — it is not
     * the `.docx` finfo took it for — and so is every OOXML file on a host
     * without the zip extension: unable to look is not the same as clean.
     *
     * Only the central directory and `[Content_Types].xml` are read, and
     * that part only when its declared size fits {@see CONTENT_TYPES_READ}:
     * a larger one is refused rather than read in part, so neither a
     * decompression bomb nor padding that pushes the declaration past the
     * window gets through.
     */
    private static function isMacroFreePackage(string $bytes): bool
    {
        $path = class_exists(\ZipArchive::class) ? tempnam(sys_get_temp_dir(), 'ooxml') : false;
        if ($path === false) {
            return false;
        }

        try {
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                return false;
            }
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($name), 'vbaproject.bin')) {
                        return false;
                    }
                }
                // The content types are the real backstop: a macro part can be
                // renamed (OPC finds it by relationship, not by file name),
                // but it must still be declared. Every OOXML package has
                // this part, so not finding it — under any case — or not
                // being able to read all of it is « unable to look ».
                $index = $zip->locateName('[Content_Types].xml', \ZipArchive::FL_NOCASE);
                $stat = $index === false ? false : $zip->statIndex($index);
                if ($index === false || !is_array($stat) || $stat['size'] > self::CONTENT_TYPES_READ) {
                    return false;
                }
                $stream = $zip->getStream((string) $zip->getNameIndex($index));
                if ($stream === false) {
                    return false;
                }
                $types = (string) stream_get_contents($stream, self::CONTENT_TYPES_READ);
                fclose($stream);
                // XML, not opaque text: `&#80;` is the `P` Word reads, so a
                // character reference must not hide the word from us.
                // Decoded rather than parsed — no XML parser, no external
                // entities to resolve.
                $types = html_entity_decode($types, ENT_QUOTES | ENT_XML1, 'UTF-8');

                return stripos($types, 'macroEnabled') === false && stripos($types, 'vbaProject') === false;
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * The type the bytes actually are, or null when nothing recognises them.
     */
    public function detectMimeType(string $bytes): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $type = $finfo->buffer($bytes);

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Rule 1: an inline part the HTML body references by Content-ID.
     *
     * Checked before anything is decoded or measured, because it is both
     * the cheapest test and the most reliable one — a client that puts a
     * logo in a signature says so in the headers.
     */
    public function isSignatureImage(FetchedAttachment $attachment, string $bodyHtml): bool
    {
        if (!$attachment->isInline || $attachment->contentId === null || $attachment->contentId === '') {
            return false;
        }

        // An inline part nothing points at is unusual but not a signature;
        // treating it as one would lose a real attachment some client
        // labelled oddly.
        return str_contains($bodyHtml, 'cid:' . $attachment->contentId)
            || str_contains($bodyHtml, $attachment->contentId);
    }

    /**
     * Rule 2, in pixels. An image whose dimensions cannot be read at all is
     * kept: failing to measure something is not evidence it is a logo.
     */
    private function isTooSmallToBeADocument(string $bytes): bool
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            return false;
        }

        return $size[0] < $this->minImageEdgePixels && $size[1] < $this->minImageEdgePixels;
    }
}
