<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Security\UserAccountRepository;

/**
 * How this module names a person: the ACCOUNT first — the human who
 * actually typed something — with the memberships that account carries in
 * parentheses.
 *
 *     Marie Dupont (Akéla, Baloo)
 *
 * One rule, one place, every surface: a message, a comment, "qui a
 * réagi", "vu par", the group's member list. It used to be the other way
 * round on messages (the totem in bold, the account in parentheses) and
 * member-only everywhere else, so the same person read differently
 * depending on where you met them.
 *
 * The parenthesised half is every member the account is linked to for the
 * scout year in question — not merely the one a given message was signed
 * as. A parent posting for one child is still the same human with the
 * same three memberships, and that is what the reader is being told.
 *
 * ---------------------------------------------------------------------
 * How an account and its members find each other
 * ---------------------------------------------------------------------
 * Through the email blind index, which is the join the whole
 * authentication path already uses (Core\Security\RoleResolver,
 * Service\GroupRecipientResolver). No address is decrypted anywhere here,
 * and there is no second matching mechanism.
 *
 * Everything is batched, and that is not an optimisation detail: this
 * runs for every author on a feed page, every reactor in a dialog and
 * every row of a member list, so a per-person lookup would be exactly the
 * N+1 Service\PostAuthorResolver was written to avoid. Whole-set in,
 * whole-set out — four queries for a page, whatever its size — plus a
 * per-request memo, since the same account usually appears several times
 * on one page.
 */
class MemberIdentityService
{
    /**
     * Resolved identities for this request, keyed "accountId:scoutYearId".
     *
     * @var array<string, array{account_name: string, member_names: string[]}>
     */
    private array $memo = [];

    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private MemberYearRepository $memberYearRepository,
        private MemberService $memberService
    ) {
    }

    /**
     * The identity behind each of these accounts.
     *
     * An account with no first/last name recorded falls back to its own
     * members' names rather than rendering as an empty parenthesis with
     * nothing in front of it — an account without a name still belongs to
     * somebody, and the memberships are what identifies them until the
     * name is filled in ("Mon compte").
     *
     * @param int[] $accountIds
     * @param int $scoutYearId which year's memberships to name — the
     *        group's own, so an archived group keeps naming the people who
     *        were in it that year
     * @return array<int, array{account_name: string, member_names: string[]}> keyed by account id
     */
    public function forAccounts(array $accountIds, int $scoutYearId): array
    {
        $accountIds = array_values(array_unique(array_filter(
            array_map('intval', $accountIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($accountIds === []) {
            return [];
        }

        $resolved = [];
        $missing = [];
        foreach ($accountIds as $accountId) {
            $key = $accountId . ':' . $scoutYearId;
            if (isset($this->memo[$key])) {
                $resolved[$accountId] = $this->memo[$key];
                continue;
            }
            $missing[] = $accountId;
        }

        if ($missing === []) {
            return $resolved;
        }

        $accountNames = $this->userAccountRepository->findNamesByIds($missing);
        $blindIndexes = $this->userAccountRepository->findEmailBlindIndexesByIds($missing);
        $memberIdsByIndex = $this->memberYearRepository->findMemberIdsByEmailBlindIndexes(
            array_values($blindIndexes),
            $scoutYearId
        );
        $allMemberIds = [];
        foreach ($memberIdsByIndex as $ids) {
            $allMemberIds = array_merge($allMemberIds, $ids);
        }
        $displayNames = $this->memberService->findDisplayNamesByMemberIds($allMemberIds, $scoutYearId);

        foreach ($missing as $accountId) {
            $account = $accountNames[$accountId] ?? ['first_name' => null, 'last_name' => null];
            $accountName = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''));

            $memberNames = [];
            foreach ($memberIdsByIndex[$blindIndexes[$accountId] ?? ''] ?? [] as $memberId) {
                if (isset($displayNames[$memberId])) {
                    $memberNames[] = $displayNames[$memberId];
                }
            }

            $identity = $accountName !== ''
                ? ['account_name' => $accountName, 'member_names' => $memberNames]
                // No name on the account: its memberships become the name
                // rather than the aside, so the person is still named.
                : ['account_name' => implode(', ', $memberNames), 'member_names' => []];

            $this->memo[$accountId . ':' . $scoutYearId] = $identity;
            $resolved[$accountId] = $identity;
        }

        return $resolved;
    }

    /**
     * The identity behind each of these MEMBERS — the same answer as
     * forAccounts(), reached from the other end, for the surfaces that
     * start from a membership rather than from an author: "qui a réagi",
     * "vu par", the group's member list.
     *
     * A member with no account at all (most animés have none) is named by
     * their own display name and nothing else. That is not a fallback so
     * much as the truth: there is no human account behind them to name.
     *
     * @param int[] $memberIds
     * @return array<int, array{account_name: string, member_names: string[]}> keyed by member id
     */
    public function forMembers(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique(array_filter(
            array_map('intval', $memberIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($memberIds === []) {
            return [];
        }

        $blindIndexes = $this->memberYearRepository->findMostRecentEmailBlindIndexesForMembers($memberIds);
        $accountIds = $this->userAccountRepository->findIdsByBlindIndexes(array_values($blindIndexes));
        $identitiesByAccount = $this->forAccounts(array_values($accountIds), $scoutYearId);
        $ownNames = $this->memberService->findDisplayNamesByMemberIds($memberIds, $scoutYearId);

        $identities = [];
        foreach ($memberIds as $memberId) {
            $accountId = $accountIds[$blindIndexes[$memberId] ?? ''] ?? null;
            $identity = $accountId !== null ? ($identitiesByAccount[$accountId] ?? null) : null;

            $identities[$memberId] = $identity ?? [
                'account_name' => $ownNames[$memberId] ?? '',
                'member_names' => [],
            ];
        }

        return $identities;
    }

    /**
     * The account behind each of these memberships, named account-first
     * but with the parentheses narrowed to THAT membership alone:
     *
     *     Marie Dupont (Akéla)
     *
     * For the two surfaces where the membership is what is being picked
     * rather than who is speaking — "Publier en tant que" and the
     * invite-candidate search. forMembers() would put every membership
     * the account carries in the parentheses, which is right when naming
     * an author (a parent is one human with three children) and wrong
     * here: three options reading "Marie Dupont (Akéla, Baloo, Chil)"
     * are three identical options, and a chooser that cannot tell its
     * choices apart is not a chooser.
     *
     * Returns an EMPTY string for a membership with no account behind it,
     * or whose account has no name of its own — deliberately, rather than
     * a fallback of this class's choosing. There is no human name to put
     * in front of those, and each caller's own fallback is better than a
     * shared guess: the author selector wants the totem it already has,
     * the invite search wants the full name it is searching by.
     *
     * @param int[] $memberIds
     * @return array<int, string> member id => label, '' when no named account
     */
    public function accountLabelForMembers(array $memberIds, int $scoutYearId): array
    {
        $memberIds = array_values(array_unique(array_filter(
            array_map('intval', $memberIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($memberIds === []) {
            return [];
        }

        // The same blind-index join as forMembers(), read for the
        // account's OWN name rather than through forAccounts() — whose
        // no-name fallback (the memberships become the name) would make
        // this render "Akéla, Baloo (Akéla)".
        $blindIndexes = $this->memberYearRepository->findMostRecentEmailBlindIndexesForMembers($memberIds);
        $accountIds = $this->userAccountRepository->findIdsByBlindIndexes(array_values($blindIndexes));
        $accountNames = $this->userAccountRepository->findNamesByIds(array_values($accountIds));
        $ownNames = $this->memberService->findDisplayNamesByMemberIds($memberIds, $scoutYearId);

        $labels = [];
        foreach ($memberIds as $memberId) {
            $accountId = $accountIds[$blindIndexes[$memberId] ?? ''] ?? null;
            $account = $accountId !== null ? ($accountNames[$accountId] ?? null) : null;
            $accountName = $account !== null
                ? trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''))
                : '';

            if ($accountName === '') {
                $labels[$memberId] = '';
                continue;
            }

            // An account named exactly like the membership it is being
            // shown next to says it twice; once is enough.
            $ownName = $ownNames[$memberId] ?? '';
            $labels[$memberId] = self::label([
                'account_name' => $accountName,
                'member_names' => ($ownName === '' || $ownName === $accountName) ? [] : [$ownName],
            ]);
        }

        return $labels;
    }

    /**
     * The identity as one line — for the few places that can only carry a
     * string (a <select>'s option, a joined list). Everywhere with room
     * for markup renders partials/identity.html.twig instead, so the two
     * halves keep their own weight.
     *
     * @param array{account_name: string, member_names: string[]} $identity
     */
    public static function label(array $identity): string
    {
        if ($identity['member_names'] === []) {
            return $identity['account_name'];
        }

        return $identity['account_name'] . ' (' . implode(', ', $identity['member_names']) . ')';
    }
}
