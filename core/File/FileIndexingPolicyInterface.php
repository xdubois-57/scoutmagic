<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * An optional second answer from a {@see FileOwnershipCheckerInterface}:
 * may a search engine keep this file?
 *
 * **A separate interface rather than a sixth method on the checker**, the
 * optional-companion shape this codebase already uses elsewhere. Almost
 * no owner type has an opinion — a section document, a member's tax
 * certificate and a kept Desk CSV are all already unreachable to an
 * anonymous crawler by `role_min` alone — and a method every checker had
 * to implement would be five `return true;` bodies saying nothing.
 *
 * **Why it exists at all** (#516): the Documents module serves a « Lien
 * direct » through `/documents/{slug}`, which redirects to `/files/{id}`.
 * The redirect carries `X-Robots-Tag: noindex`, and Google applies the
 * indexing rule to the response it ends on, not to the redirect that took
 * it there. The final response said nothing. A crawler that knew the
 * address and kept the session cookie across the hop could therefore
 * index a document nobody ever listed.
 *
 * **It cannot be answered by the core**, which is the whole reason this
 * is a registry question: `FileController` would have to read a module's
 * table to learn that a document is unlisted, and the generic
 * `owner_type`/`owner_id` mechanism exists precisely so that it never
 * does ({@see FileAccessGuard}).
 */
interface FileIndexingPolicyInterface
{
    /**
     * Whether a search engine may index the file owned by $ownerId.
     *
     * Asked only of the checker that {@see
     * FileOwnershipCheckerInterface::supports()} claimed the owner type,
     * and only once access has already been granted: this decides what a
     * reader who is allowed the file may pass on to a crawler, never
     * whether they are allowed it.
     */
    public function isIndexable(int $ownerId): bool;
}
