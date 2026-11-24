<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Mail;

use Core\Config\ScoutYearService;
use Core\Member\SectionStaffAuthorizationService;
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
     * **Staff, stewards included, and in the year of the mail** (#1342).
     *
     * Staff here counts the chiefs AND the steward (`intendant`): the
     * steward is who most often buys for a section and sends the receipt.
     * Counted only for this question — whose account a receipt belongs to
     * — and never for what anybody may see
     * (`StaffedSectionRepository::staffedSectionIds()`).
     *
     * And the year is the one the mail was sent in: a receipt read late —
     * an old mail fetched again, a mailbox connected in October — belongs
     * to the section its sender staffed THEN, not to the one they moved to
     * since. A year the site has no record of resolves to nothing, and the
     * receipt goes to the sorting pile rather than onto a guess.
     *
     * `ownStaffedSectionIds()` and not `getStaffedSections()`: the latter
     * answers « what may this person see », which is every section for a
     * chef d'unité — a receipt they sent would match them all and fail the
     * « exactly one » test. The question here is what they staff.
     *
     * $scoutYearId is the year used when no date is given; $scoutYears,
     * the one that turns a date into its year. Without it, every date
     * counts as $scoutYearId's, as before.
     */
    public function __construct(
        private SectionStaffAuthorizationService $staffAuthorization,
        private AccountRepository $accounts,
        private int $scoutYearId,
        private ?ScoutYearService $scoutYears = null
    ) {
    }

    /**
     * The one account this address's staff owned on $sentAt, or null —
     * which covers "unknown address", "several staffs", "no usable account"
     * and "no record of that year" alike, because all mean the same thing
     * to the caller.
     */
    public function resolve(string $email, ?\DateTimeImmutable $sentAt = null): ?Account
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        $scoutYearId = $this->scoutYearOf($sentAt);
        if ($scoutYearId === null) {
            return null;
        }

        // Resolves the Desk address AND every confirmed secondary one
        // (`member_emails`), so an animateur who writes from their personal
        // address is still recognised — the same resolution the whole site
        // uses to decide which sections somebody staffs.
        $sectionIds = $this->staffAuthorization->ownStaffedSectionIds($email, $scoutYearId, includeStewards: true);
        if (count($sectionIds) !== 1) {
            return null;
        }

        return $this->theOneActiveAccountOf($sectionIds[0]);
    }

    private function scoutYearOf(?\DateTimeImmutable $sentAt): ?int
    {
        if ($sentAt === null || $this->scoutYears === null) {
            return $this->scoutYearId;
        }

        $year = $this->scoutYears->findByLabel(ScoutYearService::labelForDate($sentAt));

        return $year === null ? null : (int) $year['id'];
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
