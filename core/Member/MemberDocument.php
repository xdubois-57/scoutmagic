<?php

declare(strict_types=1);

namespace Core\Member;

/**
 * Metadata for a private per-member document (member page "Documents
 * privés" — e.g. a future fiscal attestation). The file itself is stored
 * encrypted-at-rest via Core\File\EncryptedFileStorageService, with
 * files.owner_member_id set to $memberId — that FK (enforced by
 * Core\File\FileAccessGuard) is what actually controls download access,
 * never this table.
 */
class MemberDocument
{
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly int $scoutYearId,
        public readonly string $title,
        public readonly int $fileId,
        public readonly string $createdAt,
        public readonly ?int $createdBy
    ) {
    }
}
