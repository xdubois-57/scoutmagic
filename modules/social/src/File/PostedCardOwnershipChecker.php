<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\File;

use Core\File\FileIndexingPolicyInterface;
use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;

/**
 * Keeps the card the browser posted out of `/files/{id}` entirely
 * (issue #706, IT-02).
 *
 * **It answers no, to everyone, always** — and that is the rule, not a
 * placeholder. From IT-02 the composed card is kept as an ordinary
 * `files` row so that every destination published later receives the
 * image the first one did. Exactly one caller reads it,
 * {@see \Modules\Social\Service\ShareSourceResolver::frozenCard()},
 * through `EncryptedFileStorageService::read()` — never over HTTP. What
 * the outside world may fetch is the hour-long token route
 * (`Controller\CardController`, SECURITY.md § 6) and, from IT-04, the
 * communication's public page. The generic route is not one of them, so
 * it is closed here rather than left to a role floor.
 *
 * **Why a floor was not enough.** Stored with `role_min = 'chief'` and
 * no owner, the row fell through to that floor alone: ANY chief could
 * read ANY card by asking `/files/{id}` for a sequential id. A card is
 * the source photo with a veil and a title on it — and from IT-02 the
 * blur is the chief's choice, « Net » included, so the card of a gallery
 * photo can be that photo unblurred. The chief who composed it had been
 * granted the source album; the one guessing an id had not, which is the
 * whole point of {@see \Modules\Gallery\Api\DelegatedAlbumAccessChecker}.
 * Before IT-02 the composed card never outlived a request, so this row
 * is the new thing and this checker is why it is not an opening.
 *
 * Scoping to the source album instead would have closed the gallery case
 * and left the article's, the picked photo's and the upload's each
 * reasoning about a different owner — through two more fields on the
 * gallery's `Api` DTO, which is a contract this iteration has no reason
 * to widen (ARCHITECTURE.md § 7.5). Refusing the route says the same
 * thing once, for every kind of source.
 *
 * `owner_id` is the communication's id: the row says what it belongs to,
 * which a later iteration serving that page can rely on.
 *
 * Fail-closed twice over, like every checker here: the registry refuses
 * an owner type no checker claims, so these rows are also refused while
 * the module is disabled.
 */
final class PostedCardOwnershipChecker implements FileOwnershipCheckerInterface, FileIndexingPolicyInterface
{
    public const OWNER_TYPE = 'social_card';

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    /**
     * No. There is no reader of this file over `/files/{id}` — not a
     * chief, not the chief who composed it, not an administrator.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        return false;
    }

    /**
     * And nothing to index: a file no request is ever served cannot be
     * passed to a crawler. Stated rather than inferred, because the
     * guard's default for a claimed owner type with no opinion is yes.
     */
    public function isIndexable(int $ownerId): bool
    {
        return false;
    }
}
