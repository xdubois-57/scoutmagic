<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Http\Request;

/**
 * Whether an identified session may go anywhere at all before it has said
 * who it is.
 *
 * `user_accounts.first_name` and `last_name` are optional in the schema and
 * have to stay that way — every account created before this existed carries
 * neither, and there is nothing to migrate them from. They became mandatory
 * in the application instead: an account missing either one meets an
 * interstitial screen (`/account/complete-profile`) on its next request, and
 * goes no further until it fills them in.
 *
 * The name is what the site proposes as the signatory of the official
 * documents a parent prints (specifications.md §44), so a blank one there is
 * a blank line on a form the federation expects signed. That is the reason
 * this exists; the rule itself is the site's own, and applies to every
 * identified account whatever its role — there is no exemption for a chef
 * d'unité or a superadmin, and the very first account of every installation
 * is concerned, since the setup wizard collects an e-mail address and
 * nothing else.
 *
 * Three properties, and none of them is decoration:
 *
 * - **A public route is never intercepted.** Anything an anonymous visitor
 *   can already reach grants nothing new to an identified one, so the RGPD
 *   page, the cookie preferences, the manifest and the PWA icons the blocked
 *   page itself loads keep working — the screen has to be able to render.
 *   Deciding from the route's declared `role_min` rather than from a list of
 *   paths is what makes that hold for a route added tomorrow.
 * - **Logging out is always possible.** It is the only way out, and it is not
 *   negotiable: a validation that will not let go locks somebody out of their
 *   own site.
 * - **The verdict is fail-closed.** A `role_min` this enum does not know is
 *   treated as non-public and therefore blocked, rather than waved through.
 */
final class ProfileCompletionGate
{
    /** The interstitial screen itself — both its GET and its POST. */
    public const PATH = '/account/complete-profile';

    /**
     * The only paths a blocked session may still reach beyond the public
     * ones: the screen, and the way out.
     */
    private const ALWAYS_ALLOWED = [self::PATH, '/logout'];

    public function __construct(private readonly bool $profileIncomplete)
    {
    }

    /**
     * Built from whatever account the request is signed in as, or from null
     * for an anonymous visitor — who is never blocked, having no profile to
     * complete.
     */
    public static function forAccount(?UserAccount $account): self
    {
        return new self($account !== null && !self::isComplete($account));
    }

    /**
     * The one definition of « this profile is complete », shared by the
     * gate, the screen and the « Mon compte » form so none of the three can
     * drift into accepting what another refuses.
     */
    public static function isComplete(UserAccount $account): bool
    {
        return trim((string) $account->firstName) !== ''
            && trim((string) $account->lastName) !== '';
    }

    /**
     * Whether this request must be answered with the interstitial screen
     * instead of the route it asked for.
     *
     * $roleMin is the route's own declared minimum, read from the resolved
     * route rather than from the path: the question is whether an anonymous
     * visitor could have asked for this too.
     */
    public function blocks(Request $request, string $roleMin): bool
    {
        if (!$this->profileIncomplete) {
            return false;
        }

        if ($roleMin === Role::PUBLIC->value) {
            return false;
        }

        return !in_array($request->getPath(), self::ALWAYS_ALLOWED, true);
    }
}
