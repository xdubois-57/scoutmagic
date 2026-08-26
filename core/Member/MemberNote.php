<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * One dated staff note about a member — already decrypted, assembled
 * only inside MemberNoteRepository (SECURITY.md §5: encryption confined
 * to the Repository layer).
 *
 * `$authorName` is resolved by the Repository from the account that
 * wrote it, and is null when that account is gone: losing the author
 * must never lose the note, which is a fact about the member rather than
 * about whoever typed it.
 */
final class MemberNote
{
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly string $body,
        public readonly ?int $createdBy,
        public readonly ?string $authorName,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt
    ) {
    }

    public function wasEdited(): bool
    {
        return $this->updatedAt !== null;
    }
}
