<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Core\Security\UserAccountRepository;

/**
 * How a person who signs a rental document is named on it (issue #825).
 *
 * **The account's own name, and nothing else.** A countersigned contract
 * and a validated inventory are read by a tenant from outside the unit.
 * The site's usual display name — a member's totem, else their first name —
 * is a courtesy between people who know each other, and means nothing to
 * someone who does not: « Contresigné pour la Troupe par Loutre » names
 * nobody. Nor can the roster stand in for the account. A login is matched
 * to the members who share its address, and on a family's address the
 * first of them may well be a child; the person who signs is the one who
 * logged in, and the name they gave their account is the name they signed
 * under.
 *
 * Null is a normal answer — an account whose owner never filled in a name
 * — and the caller then says « un gestionnaire » rather than guess. A
 * totem is never the fallback: a document that cannot name its signer says
 * so, which is better than naming the wrong person.
 */
final class SignerName
{
    public function __construct(private UserAccountRepository $accounts)
    {
    }

    public function forAccount(?int $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $names = $this->accounts->findNamesByIds([$accountId])[$accountId] ?? null;
        if ($names === null) {
            return null;
        }

        $full = trim(trim((string) $names['first_name']) . ' ' . trim((string) $names['last_name']));

        return $full === '' ? null : $full;
    }
}
