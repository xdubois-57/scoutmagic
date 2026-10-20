<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * One block of a custom list (mass_mail_list_blocks) — a list is the
 * union of its blocks.
 *
 * A member group carries its three criteria axes here, read with it; a
 * contact group carries a name and the date its file was last applied,
 * and its three axes are always empty. The contacts themselves are not
 * loaded with the block: they are encrypted, and a page that only counts
 * them must not pay for decrypting them (Repository\ContactRepository).
 */
final class ListBlock
{
    public const KIND_MEMBER_GROUP = 'member_group';
    public const KIND_CONTACT_GROUP = 'contact_group';

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $listId,
        public readonly string $kind,
        /** Mandatory for a contact group, always null for a member group. */
        public readonly ?string $name,
        /** When a file last replaced a contact group's contacts; null before any. */
        public readonly ?string $fileReplacedAt,
        public readonly string $createdAt,
        public readonly ?int $createdBy,
        public readonly array $functionIds = [],
        public readonly array $sectionIds = [],
        public readonly array $badgeIds = []
    ) {
    }

    public function isMemberGroup(): bool
    {
        return $this->kind === self::KIND_MEMBER_GROUP;
    }

    public function isContactGroup(): bool
    {
        return $this->kind === self::KIND_CONTACT_GROUP;
    }
}
