<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Mail;

use Core\Member\SectionStaffAuthorizationService;
use Core\Security\Role;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;

/**
 * « Cette adresse anime-t-elle un seul staff, et ce staff a-t-il un seul
 * compte ? »
 *
 * An animateur photographs a receipt and sends it to the unit's treasury
 * address. Nothing in the message says which account it belongs to — but
 * the unit already knows who that person is and which section they staff,
 * and that is enough to file it without asking anybody.
 *
 * **Two "exactly one" in a row, and both are refusals to guess.** Exactly
 * one staffed section, then exactly one active account attached to it.
 * Anything else — an animateur who staffs two sections, a section whose
 * account is still a draft, a section carrying two accounts — resolves to
 * nothing and the receipt goes to the sorting pile. That is the same
 * discipline `FinanceMessageConsumer` already applies to a message quoting
 * two IBANs: a receipt filed on the wrong account is worse than one
 * waiting to be filed, because nobody reading the wrong account can tell
 * it is wrong.
 *
 * **The address is not authenticated**, and this class cannot make it so.
 * A `From:` header is forgeable and a forwarded body line more so. What
 * bounds it is that resolving buys the sender nothing but the filing of a
 * **document** — never an amount, never a movement — and that an invented
 * address resolves to no member at all.
 */
class SenderStaffAccountResolver
{
    /**
     * The role handed to `getStaffedSections()`, and it matters.
     *
     * That method short-circuits to **every section** for an admin role,
     * which is right for a chef d'unité browsing a screen and wrong here:
     * a receipt sent by the chef d'unité would resolve to every section at
     * once, fail the "exactly one" test, and land in the sorting pile
     * instead of on the section they actually animate. The question asked
     * here is never "what may this person see" — it is "what does this
     * person staff", and that question has no privileged answer.
     */
    private const NEUTRAL_ROLE = Role::IDENTIFIED;

    public function __construct(
        private SectionStaffAuthorizationService $staffAuthorization,
        private AccountRepository $accounts,
        private int $scoutYearId
    ) {
    }

    /**
     * The one account this address's staff owns, or null — which covers
     * "unknown address", "several staffs" and "no usable account" alike,
     * because all three mean the same thing to the caller.
     */
    public function resolve(string $email): ?Account
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        // Resolves the Desk address AND every confirmed secondary one
        // (`member_emails`), so an animateur who writes from their personal
        // address is still recognised — the same resolution the whole site
        // uses to decide which sections somebody staffs.
        $sections = $this->staffAuthorization->getStaffedSections(
            $email,
            self::NEUTRAL_ROLE->value,
            $this->scoutYearId
        );
        if (count($sections) !== 1) {
            return null;
        }

        return $this->theOneActiveAccountOf((int) $sections[0]['id']);
    }

    /**
     * Active only: a section's default account is created as a **draft**
     * (`FinanceService::ensureDefaultAccountsForSections()`), and every
     * picker in the module excludes a draft. Filing onto one here would
     * put a receipt somewhere no screen offers to look.
     */
    private function theOneActiveAccountOf(int $sectionId): ?Account
    {
        $found = [];

        foreach ($this->accounts->findAllOrdered() as $account) {
            if ($account->sectionId === $sectionId && $account->status === Account::STATUS_ACTIVE) {
                $found[] = $account;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
