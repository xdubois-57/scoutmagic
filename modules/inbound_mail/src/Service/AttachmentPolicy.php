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
     * No executables and no active types.
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
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
    ];

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

        return null;
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
