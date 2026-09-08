<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Service;

/**
 * One captured message, as the sandbox sees it: the metadata row with its
 * recipient already decrypted by the Repository. The message itself is not
 * here — it is fetched from encrypted storage only when a detail page
 * actually needs it.
 *
 * `$delivered` says whether this message ALSO left the server: false for
 * every capture, true only for a sign-in link the operator chose to let
 * through (ARCHITECTURE.md §8.63). A message the sandbox let out is still
 * filed here rather than disappearing, so the page can answer "what did
 * this feature actually send?" without the operator having to reason about
 * which half of the mail went where.
 *
 * @phpstan-type AttachmentRow array{id: int, file_name: string, mime_type: string, size_bytes: int, file_id: int|null}
 */
final class CapturedEmail
{
    /**
     * @param array<
     *     int,
     *     array{id: int, file_name: string, mime_type: string, size_bytes: int, file_id: int|null}
     * > $attachments
     */
    public function __construct(
        public readonly int $id,
        public readonly \DateTimeImmutable $capturedAt,
        public readonly bool $delivered,
        public readonly string $subject,
        public readonly string $recipient,
        public readonly string $fromAddress,
        public readonly ?string $replyTo,
        public readonly int $sizeBytes,
        public readonly bool $hasDkim,
        public readonly int $attachmentCount,
        public readonly ?int $mimeFileId,
        public readonly ?int $bodyHtmlFileId,
        public readonly ?int $bodyTextFileId,
        public readonly ?string $errorMessage,
        public readonly array $attachments = []
    ) {
    }

    public function hasFailed(): bool
    {
        return $this->errorMessage !== null;
    }
}
