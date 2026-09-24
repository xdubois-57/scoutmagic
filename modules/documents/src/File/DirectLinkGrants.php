<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\File;

use Core\Security\SessionStore;

/**
 * The unlisted documents this browser session reached through their
 * address.
 *
 * An unlisted (« Lien direct ») document's file cannot be `role_min:
 * admin` — anybody holding the address must get it, account or not — and
 * cannot be plain `public` either: /files/{id} ids are sequential, and a
 * public file is one anybody can reach by counting, which would make the
 * random segment in the address worthless. So the address itself is the
 * capability: /documents/{slug} records the document here before
 * redirecting, and DocumentFileOwnershipChecker lets /files/{id} serve
 * that document's file only to a session that holds the record.
 *
 * The session cookie is `SameSite=Lax`, so a link followed from an e-mail
 * carries it through the redirect. Bounded, oldest dropped first: a
 * session is not an archive of everything it ever opened.
 */
class DirectLinkGrants
{
    private const SESSION_KEY = 'documents_direct_link_grants';
    private const MAX_GRANTS = 50;

    public function grant(int $documentId): void
    {
        $grants = array_values(array_filter(
            $this->all(),
            static fn(int $id): bool => $id !== $documentId
        ));
        $grants[] = $documentId;
        SessionStore::set(self::SESSION_KEY, array_slice($grants, -self::MAX_GRANTS));
    }

    public function has(int $documentId): bool
    {
        return in_array($documentId, $this->all(), true);
    }

    /**
     * @return list<int>
     */
    private function all(): array
    {
        $stored = SessionStore::get(self::SESSION_KEY, []);
        if (!is_array($stored)) {
            return [];
        }
        return array_values(array_filter($stored, 'is_int'));
    }
}
