<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\File;

use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;
use Modules\Documents\Repository\DocumentRepository;
use Modules\Documents\Service\DocumentVisibility;

/**
 * FileAccessGuard's second question for a document's file (owner_type
 * 'document', owner_id the document's id), asked AFTER role_min, which
 * DocumentService keeps equal to the visibility.
 *
 * - The Staff d'U reads every document file: they manage them.
 * - A listed document adds nothing to its role_min.
 * - An unlisted document's file is served only to a session that came
 *   through the document's address (DirectLinkGrants) — never to one that
 *   merely counted its way to the file's id.
 * - A file whose document is gone is refused (fail-closed, like the guard).
 */
class DocumentFileOwnershipChecker implements FileOwnershipCheckerInterface
{
    public const OWNER_TYPE = 'document';

    public function __construct(
        private DocumentRepository $documents,
        private DirectLinkGrants $grants
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        if ($currentRole->hasAccess(Role::ADMIN)) {
            return true;
        }

        $document = $this->documents->findById($ownerId);
        if ($document === null) {
            return false;
        }

        return $document->visibility !== DocumentVisibility::DIRECT_LINK
            || $this->grants->has($document->id);
    }
}
