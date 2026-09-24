<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Service;

use Core\Security\Role;

/**
 * Who a shared document is for — the five choices of the news editor,
 * with its exact labels, so a chef d'unité setting the visibility of an
 * article and of a document sees the same thing.
 *
 * **Four rungs of the role ladder, and one value that is not a rung.**
 * `DIRECT_LINK` means "unlisted": it appears in no list, for nobody —
 * not even for the chef d'unité reading the public page — and it grants
 * anyone holding the address. That is why this is its own enumeration
 * rather than a `Role`, and why the database column is `visibility`, not
 * `role_min` (modules/documents/schema.sql, and the same distinction in
 * modules/news/schema.sql).
 *
 * `intendant` is absent on purpose: an intendant sees what is meant for
 * members, never what is reserved to the animateurs.
 */
enum DocumentVisibility: string
{
    case PUBLIC = 'public';
    case IDENTIFIED = 'identified';
    case CHIEF = 'chief';
    case ADMIN = 'admin';
    case DIRECT_LINK = 'direct_link';

    /** The news editor's wording, word for word (modules/news/views/editor.html.twig). */
    public function label(): string
    {
        return match ($this) {
            self::PUBLIC => 'Public',
            self::IDENTIFIED => 'Membres connectés',
            self::CHIEF => 'Animateurs',
            self::ADMIN => "Chefs d'Unité",
            self::DIRECT_LINK => 'Lien direct',
        };
    }

    /**
     * The `files.role_min` the current version's file carries.
     *
     * An unlisted document is reachable by anyone holding its address, so
     * its file is public: hiding it is the list's job, not the guard's.
     * Every other value is the rung it names.
     */
    public function fileRoleMin(): string
    {
        return $this === self::DIRECT_LINK ? Role::PUBLIC->value : $this->value;
    }

    /** Whether the document may appear in a list at all. */
    public function isListed(): bool
    {
        return $this !== self::DIRECT_LINK;
    }

    /**
     * Whether a reader holding $role sees this document in the public
     * list. An unlisted document is never listed, whatever the role.
     */
    public function isListedFor(Role $role): bool
    {
        return $this->isListed() && $role->hasAccess(Role::from($this->value));
    }

    /**
     * Whether search engines may index the document's address.
     *
     * Only a public document. `direct_link` and `identified` are the two
     * that ArticleService::enforceSeoRules() already refuses for an
     * article — an indexed address is offered to every search, forever,
     * and sends an anonymous visitor to a refusal — and `chief`/`admin`
     * would do the same, only more so.
     */
    public function isIndexable(): bool
    {
        return $this === self::PUBLIC;
    }

    /**
     * The value posted by the form, or null when it is not one of the five.
     */
    public static function fromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
