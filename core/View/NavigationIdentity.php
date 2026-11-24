<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Security\UserAccount;

/**
 * The name the navigation writes beside the photo of the signed-in account,
 * in the mobile menu and in the desktop header (issue #1360).
 *
 * It is the first name saved in the account's own profile (Mon compte →
 * Profil), as it was saved: a compound first name stays whole and nothing is
 * cut out of a longer name. It is deliberately NOT derived from the members
 * linked to the account's e-mail address. Those are other people — a parent
 * is linked to every child, and "the member with the highest role" says who
 * is the most senior, not who is logged in. The account's photo already
 * comes from the account (person_avatar()), so the name beside it has to as
 * well, or the menu shows a parent's picture under a child's name.
 *
 * A profile without a first name (an account that predates the mandatory
 * profile, or one seen before it is completed — ProfileCompletionGate sends
 * it to the form on its next request) falls back to the e-mail address, which
 * is the account's own identifier and never somebody else's.
 *
 * A pure function of the account, so the production wiring can be tested
 * without a page and no second policy about who is "the current person"
 * grows beside it.
 */
final class NavigationIdentity
{
    public static function displayName(?UserAccount $account, string $email): string
    {
        $firstName = $account === null ? '' : trim($account->firstName ?? '');

        return $firstName !== '' ? $firstName : $email;
    }
}
