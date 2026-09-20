<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

/**
 * Answers "who may write the content owned by THIS resource", for the
 * editable-content rows whose answer is not simply the write endpoint's
 * own `role_min`.
 *
 * ### Why this exists
 *
 * `POST /api/editable-content` is registered `role_min: admin`, and for
 * most of this site that is the whole answer: every historical
 * `editable()` call site sits on a page an `admin` may also READ — the
 * home page, contact, sections, a module's public view. Admin-writable
 * and admin-or-below-readable always matched, so one floor covered both.
 *
 * Free-text pages broke that coincidence (ARCHITECTURE.md §8.116). A page
 * filed in the Configuration menu is read at `superadmin` while its text
 * is written through the same `admin` endpoint: **the first content on
 * this site whose read floor exceeds its write floor.**
 *
 * SECURITY.md §3 has the rule this restores — « `role_min` is a floor,
 * never the whole answer. Any resource with its own visibility rule must
 * re-check it in the controller or service, because the route only proves
 * the caller's role clears the minimum ».
 *
 * ### Why it is asked about a ROW and not about a key
 *
 * An earlier version of this interface took the content key and worked
 * out from its spelling whether it named a page. That cannot be made
 * safe. `editable_contents.content_key` is compared with
 * `utf8mb4_unicode_ci`, which equates spellings differing in case,
 * accents, trailing spaces (PAD SPACE), fullwidth forms and every
 * primary-ignorable character — so any parser has to reproduce that
 * equivalence exactly, and four attempts each left a gap somebody could
 * write through.
 *
 * So the caller resolves the row first, with the same comparison the
 * write will use, and asks this about the **owner it found**. Whatever
 * the collation considers the key to be, the row that answered is the
 * row that will be written.
 */
interface EditableContentAuthorizer
{
    /**
     * The role a caller must hold to write content owned by
     * $ownerId of $ownerKind, or null when this authorizer does not
     * speak for that kind of owner.
     *
     * An implementation that recognises the kind but cannot find the
     * owner must answer the NARROWEST role it can rather than null:
     * "I own this kind and cannot vouch for this one" is a refusal, not
     * an abstention.
     */
    public function roleMinForOwner(string $ownerKind, int $ownerId): ?string;
}
