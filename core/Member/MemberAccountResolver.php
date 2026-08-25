<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Import\MemberYearRepository;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;

/**
 * "Which logins can read a notification addressed to this member?"
 *
 * The answer is the two sources login itself resolves (SECURITY.md §2),
 * in the same order: the member's Desk address first, then every
 * confirmed secondary address they added themselves. For a young member
 * the Desk address is a parent's, which is exactly why a payment
 * reminder addressed to the child reaches the adult who pays — and why a
 * teenager who added their own address gets it too rather than instead.
 *
 * Extracted to core because three callers need the same list and a second
 * copy is a second answer waiting to disagree: Modules\Groups\Service\
 * GroupRecipientResolver (who could be sitting behind this membership)
 * and Modules\Finance\Api\FamilyPaymentService (who to tell about an
 * unpaid receivable) both delegate here.
 *
 * A member with no account at all yields an empty list — an animé with no
 * login of their own is the common case for a young member, not an error.
 */
class MemberAccountResolver
{
    public function __construct(
        private MemberYearRepository $memberYearRepository,
        private MemberEmailRepository $memberEmailRepository,
        private UserAccountRepository $userAccountRepository,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Every account that can log in as this member, Desk address first.
     *
     * @return int[]
     */
    public function accountIdsForMember(int $memberId): array
    {
        $ids = [];
        foreach ($this->blindIndexesForMember($memberId) as $blindIndex) {
            $account = $this->userAccountRepository->findByBlindIndex($blindIndex);
            if ($account !== null && !in_array($account->id, $ids, true)) {
                $ids[] = $account->id;
            }
        }

        return $ids;
    }

    /**
     * The one account their mail goes to, or null when they have none.
     */
    public function accountIdForMember(int $memberId): ?int
    {
        return $this->accountIdsForMember($memberId)[0] ?? null;
    }

    /**
     * The e-mail blind indexes that reach this member, Desk first.
     *
     * @return string[]
     */
    public function blindIndexesForMember(int $memberId): array
    {
        $indexes = [];

        $deskIndex = $this->memberYearRepository->findMostRecentEmailBlindIndexForMember($memberId);
        if ($deskIndex !== null && $deskIndex !== '') {
            $indexes[] = $deskIndex;
        }

        foreach ($this->memberEmailRepository->findValidByMember($memberId) as $secondary) {
            $indexes[] = $this->encryption->blindIndex(strtolower(trim($secondary->email)), 'email');
        }

        return array_values(array_unique($indexes));
    }
}
