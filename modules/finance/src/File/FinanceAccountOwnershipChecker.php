<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\File;

use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\TreasurerScope;
use Modules\Finance\Service\TreasurerScopeService;

/**
 * A receipt's FILE follows its account's rule, through
 * Core\File\FileAccessGuard's generic ownership registry
 * (docs/module-development.md, "Scoping a file to your module's own access
 * rule") — never a second file route, never a bypass of the guard.
 *
 * This exists because `files.role_min` is a **hierarchical floor** and
 * cannot express "the Louveteaux section". ReceiptService copies the
 * account's `role_min_view` into it, which was the whole story while
 * visibility was hierarchical too. Once §8.69 narrowed an account to its
 * section's treasurer, an intendant who no longer saw the Éclaireurs
 * account on any screen was still served its receipts by a direct
 * `/files/{id}`: the screen had been narrowed and the file had not.
 *
 * **The rule is not restated here.** It delegates to Service\
 * AccountVisibility, the same predicate every finance page and write route
 * consults, so the file and the screen cannot answer differently — the
 * failure mode this iteration exists to remove. That is also why
 * TreasurerScopeService was designed around `$linkedMemberIds` in the
 * first place: it is exactly what this interface hands over, and the rule
 * needed to be callable with no session, no e-mail address and no role
 * resolution of its own.
 *
 * A **pure decision function**: it journals nothing (FileController::
 * serve() already journals denials and owner-scoped accesses) and no
 * personal data passes through it — an account id in, a boolean out.
 */
class FinanceAccountOwnershipChecker implements FileOwnershipCheckerInterface
{
    /**
     * Prefixed with the module id, as the registry requires: the first
     * checker whose supports() answers true wins, so an unprefixed string
     * would make access depend on module registration order.
     */
    public const OWNER_TYPE = 'finance_account';

    /**
     * The owner id that means « aucun compte ne réclame ce reçu ».
     *
     * Zero rather than a second `owner_type`, because zero is already what
     * the ownership backfill wrote for a receipt whose `account_id` was
     * NULL (Repository\AttachmentRepository::backfillFileOwnership()) —
     * and until now that id resolved to no account and therefore denied
     * everybody, which was the right fail-safe for an orphan nobody could
     * sort and the wrong answer for a sorting pile the unit is meant to
     * work through. One owner type keeps one checker; a second would mean
     * two places deciding what a finance file is.
     */
    public const UNASSIGNED_OWNER_ID = 0;

    /**
     * The scout year is resolved by the composition root and passed in —
     * the effective one, never "the current year" hardcoded, because the
     * `Trésorier` badge is assigned per scout year.
     */
    public function __construct(
        private AccountRepository $accountRepository,
        private TreasurerScopeService $treasurerScopeService,
        private int $scoutYearId
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    /**
     * $ownerId is a `finance_accounts.id`.
     *
     * An id that resolves to no account answers false: isVisibleTo()
     * accepts null precisely so "no such account" and "not yours" are the
     * same answer.
     *
     * `UNASSIGNED_OWNER_ID` is the one id that is not an account and still
     * answers something: a receipt in the unit's sorting pile, ruled on by
     * Service\AccountVisibility::isUnassignedReceiptVisibleTo(). Everything
     * else that resolves to nothing is still denied.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        // Built from the ids the GUARD hands over rather than from a
        // scope resolved elsewhere: those are the ones authoritative for
        // this request, and taking them here is what makes the checker
        // usable without a session-shaped dependency.
        $visibility = new AccountVisibility(
            TreasurerScope::forSession($this->treasurerScopeService, $linkedMemberIds, $this->scoutYearId)
        );

        // A receipt no account claims: the sorting pile has a rule of its
        // own, and the file asks the same one the screen does.
        if ($ownerId === self::UNASSIGNED_OWNER_ID) {
            return $visibility->isUnassignedReceiptVisibleTo($currentRole);
        }

        return $visibility->isVisibleTo($this->accountRepository->findById($ownerId), $currentRole);
    }
}
