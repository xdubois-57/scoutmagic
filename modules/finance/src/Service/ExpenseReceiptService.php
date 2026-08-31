<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Security\Role;
use Modules\Finance\Api\ExpenseReceiptInterface;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;

/**
 * The concrete Api\ExpenseReceiptInterface: another module hands over a
 * document, this one keeps it as an ordinary receipt.
 *
 * It adds no storage path of its own. `ReceiptService::upload()` is
 * unchanged and does everything — the encryption at rest, the account's
 * `role_min_view` copied onto the file, the owner pair that makes
 * `/files/{id}` answer the same question the screens do (§8.70). What this
 * class contributes is the **authorization**, built here from the actor
 * the caller names rather than accepted from it (§8.69's predicate,
 * `Service\AccountVisibility`): a consumer able to supply the decision
 * could grant itself one.
 *
 * The scope is built per call rather than taken from the request, because
 * the caller is another module's controller and the two are not in the
 * same block of the composition root.
 */
class ExpenseReceiptService implements ExpenseReceiptInterface
{
    public function __construct(
        private AccountRepository $accountRepository,
        private TreasurerScopeService $treasurerScopeService,
        private ReceiptService $receiptService,
        private int $scoutYearId
    ) {
    }

    public function receiptAccounts(string $actorRole, array $actorLinkedMemberIds): array
    {
        $role = Role::fromString($actorRole);
        // One visibility object for the whole loop: TreasurerScope memoizes
        // the two queries the rule costs, and a fresh one per account would
        // pay them once per row.
        $visibility = $this->visibilityFor($actorLinkedMemberIds);

        $accounts = [];
        foreach ($this->accountRepository->findAllOrdered() as $account) {
            if ($account->status !== Account::STATUS_ACTIVE) {
                continue;
            }
            if (!$visibility->isVisibleTo($account, $role)) {
                continue;
            }
            $accounts[$account->id] = $account->name;
        }

        return $accounts;
    }

    public function storeReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        int $accountId,
        ?float $suggestedAmount,
        ?string $suggestedDate,
        string $actorRole,
        array $actorLinkedMemberIds,
        ?int $uploadedBy
    ): int {
        $account = $this->accountRepository->findById($accountId);
        // "No such account" and "not yours" answer the same thing, so a
        // caller never learns which accounts exist (SECURITY.md §3). Asked
        // again here and not only in receiptAccounts(): a filtered picker
        // is UI, never the boundary.
        if (!$this->visibilityFor($actorLinkedMemberIds)->isVisibleTo($account, Role::fromString($actorRole))) {
            throw new FinanceException('Compte introuvable.');
        }

        return $this->receiptService->upload(
            $content,
            $mimeType,
            $originalFilename,
            $accountId,
            $suggestedAmount,
            $suggestedDate,
            $uploadedBy
        )->fileId;
    }

    public function storeUnattendedReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        ?int $accountId
    ): int {
        // No actor to narrow against — see the interface for what stands
        // in their place. The account is still verified to EXIST and to be
        // active: a consumer resolving one from the unit's own data can
        // still hand over an id for an account that has since been
        // archived, and a receipt filed on one of those is filed where no
        // picker will ever offer to look.
        if ($accountId === null) {
            return $this->receiptService->uploadUnattributed($content, $mimeType, $originalFilename, null)->fileId;
        }

        $account = $this->accountRepository->findById($accountId);
        if ($account === null || $account->status !== Account::STATUS_ACTIVE) {
            throw new FinanceException('Compte introuvable.');
        }

        // uploadedBy stays null, and truthfully: nobody uploaded this.
        return $this->receiptService->upload(
            $content,
            $mimeType,
            $originalFilename,
            $account->id,
            null,
            null,
            null
        )->fileId;
    }

    /**
     * The same predicate the finance screens ask, narrowed against the
     * actor the CALLER named rather than against whatever session happens
     * to be open — this service is reached from another module's
     * controller, and the two are not in the same block of the composition
     * root.
     *
     * @param int[] $linkedMemberIds
     */
    private function visibilityFor(array $linkedMemberIds): AccountVisibility
    {
        return new AccountVisibility(
            TreasurerScope::forSession($this->treasurerScopeService, $linkedMemberIds, $this->scoutYearId)
        );
    }
}
