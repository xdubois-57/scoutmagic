<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

/**
 * One attachment as it came off the wire — bytes included, nothing decided
 * yet. `Service\AttachmentPolicy` is what turns this into a stored file or
 * a discarded one.
 *
 * `$declaredMimeType` is named that way as a warning: it is what the
 * sender claimed, and it is never what decides anything (§7.8). The real
 * type is sniffed from the bytes.
 */
class FetchedAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $declaredMimeType,
        public readonly string $bytes,
        /**
         * True for `Content-Disposition: inline`. Together with a
         * Content-ID referenced from the HTML body, this is the single most
         * reliable signature-image tell — and it costs nothing to read,
         * unlike measuring an image (§7.8).
         */
        public readonly bool $isInline = false,
        public readonly ?string $contentId = null
    ) {
    }

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }

    public function contentHash(): string
    {
        return hash('sha256', $this->bytes);
    }
}
