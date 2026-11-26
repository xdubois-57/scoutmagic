<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Security\UserAccountRepository;

/**
 * Whether an address belongs to a superadmin of the site (issue #1355, D2).
 *
 * **Read from the account, never from a session.** Every decision of this
 * module about an address goes through it — the screen of the person
 * logged in, but also the mail sorted at night, the calendar feed fetched
 * without a session and a receivable described to somebody else. A session
 * role would answer for the visitor, not for the address being judged, and
 * there is no session at all in two of those three.
 *
 * A **deactivated** account is no superadmin here: it cannot log in, and a
 * feed or a filing that kept treating it as one would leave a door open on
 * the very account an operator closed.
 *
 * Answers are cached per address for the life of the object — one request,
 * or one sweep — since a page asks the same question once per asset.
 */
class SuperAdminRecognizer
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private UserAccountRepository $accounts)
    {
    }

    public function isSuperAdmin(?string $email): bool
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '') {
            return false;
        }

        if (!array_key_exists($normalized, $this->cache)) {
            $account = $this->accounts->findByEmail($normalized);
            $this->cache[$normalized] = $account !== null && $account->isSuperAdmin && $account->isActive;
        }

        return $this->cache[$normalized];
    }
}
