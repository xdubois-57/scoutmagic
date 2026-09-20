<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

/**
 * Answers "who may write THIS editable-content key", for the keys whose
 * answer is not simply the write endpoint's own `role_min`.
 *
 * ### Why this exists
 *
 * `POST /api/editable-content` is registered `role_min: admin`, and for
 * most of this site that is the whole answer: every historical
 * `editable()` call site sits on a page an `admin` may also READ — the
 * home page, contact, sections, a module's public view. Admin-writable
 * and admin-or-below-readable always matched, so one floor covered both.
 *
 * Free-text pages broke that coincidence (ARCHITECTURE.md §8.115). A page
 * filed in the Configuration menu is read at `superadmin`, while its text
 * is written through the same `admin` endpoint under the key
 * `page_content_{id}` — an id that is small, sequential and guessable. An
 * `admin` correctly refused `GET /pages/{slug}` could therefore still
 * POST that key and rewrite what a superadmin reads: **the first content
 * on this site whose read floor exceeds its write floor.**
 *
 * SECURITY.md §3 has the rule this restores — « `role_min` is a floor,
 * never the whole answer. Any resource with its own visibility rule must
 * re-check it in the controller or service, because the route only proves
 * the caller's role clears the minimum ». This interface is that
 * re-check, made explicit rather than left to each future caller to
 * remember.
 *
 * An implementation speaks only for the keys it recognises and answers
 * `null` for every other, so the endpoint's own floor stays the default
 * and adding one of these can only ever narrow, never widen.
 */
interface EditableContentAuthorizer
{
    /**
     * The role a caller must hold to write $key, or null when this
     * authorizer does not recognise the key.
     *
     * An implementation that recognises a key whose resource it cannot
     * find must answer the NARROWEST role it can rather than null:
     * "I know this shape of key and cannot vouch for this one" is a
     * refusal, not an abstention.
     */
    public function roleMinForKey(string $key): ?string;
}
