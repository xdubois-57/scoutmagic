<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * An attachment's **metadata**, never its bytes.
 *
 * A consumer that wants the file itself goes through the core file layer
 * with $fileId — which is what puts `FileAccessGuard` between the reader
 * and the disk. Handing the bytes out here would route around it.
 */
class InboundAttachment
{
    public function __construct(
        public readonly int $id,
        public readonly int $messageId,
        public readonly int $fileId,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        /**
         * The SHA-256 of the file's bytes. Two copies of the same signature
         * logo arriving on ten messages are one stored file, and an image
         * already rejected once is rejected without being written again.
         */
        public readonly string $contentHash
    ) {
    }
}
