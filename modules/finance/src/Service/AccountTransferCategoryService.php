<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Keeps one auto-generated "Virement <compte>" category, and the one
 * system rule that feeds it, in sync with each active account — so a
 * movement anywhere in the ledger whose counterparty is one of the unit's
 * own accounts (an inter-account transfer, e.g. the Louveteaux account
 * paying the Scouts account) is recognized as such rather than counted as
 * ordinary external income/expense. Every account gets its own category —
 * never one shared "Virements internes" bucket — so the bilan shows which
 * of the unit's own accounts money moved to/from.
 *
 * The category is matched back to its account via Category::accountId
 * (Repository\CategoryRepository::findByAccountId()), never by name, so
 * an admin renaming it (e.g. "Virement Louveteaux" → "Transferts
 * Louveteaux") never breaks the link. The rule is flagged is_system so
 * the config UI hides its edit/delete controls — it's derived from the
 * account's own IBAN, not a standing admin decision, and gets corrected
 * automatically the next time sync() runs anyway.
 */
class AccountTransferCategoryService
{
    /**
     * Deliberately far below where Controller\ConfigRuleController's
     * ordinary rule creation starts numbering from (count of existing
     * rules, so effectively 0, 1, 2, ...) — an inter-account transfer
     * should always be recognized before any admin-authored keyword/
     * amount rule gets a chance to miscategorize it as ordinary income or
     * expense. Offset by account id so every account's system rule has
     * its own stable, deterministic priority without needing a query to
     * compute one.
     */
    private const SYSTEM_RULE_PRIORITY_BASE = -1_000_000;

    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryRuleRepository $categoryRuleRepository,
        private TransactionRepository $transactionRepository
    ) {
    }

    /**
     * Creates or updates the "Virement <compte>" category/rule for an
     * active account with an IBAN, or removes them (removeFor()) when the
     * account isn't eligible (draft/archived, or no IBAN yet) — call this
     * after every account create/update, not just on an explicit
     * activate/deactivate action, so an IBAN added or changed on an
     * already-active account is picked up too.
     */
    public function sync(Account $account): void
    {
        if ($account->status !== Account::STATUS_ACTIVE || $account->iban === null || $account->iban === '') {
            $this->removeFor($account->id);
            return;
        }

        $category = $this->categoryRepository->findByAccountId($account->id);
        $categoryId = $category?->id ?? $this->categoryRepository->create(
            "Virement {$account->name}",
            "Mouvements vers ou depuis le compte « {$account->name} » de l'unité — un virement entre deux comptes de l'unité, pas une dépense ou une recette externe.",
            $account->id
        );

        $rule = $this->categoryRuleRepository->findSystemRuleForCategory($categoryId);
        if ($rule === null) {
            $this->categoryRuleRepository->create(
                $categoryId,
                self::SYSTEM_RULE_PRIORITY_BASE + $account->id,
                keywordPattern: null,
                counterpartyAccountPattern: $account->iban,
                amountRange: null,
                isSystem: true
            );
        } elseif ($rule->counterpartyAccountPattern !== $account->iban) {
            $this->categoryRuleRepository->update($rule->id, $categoryId, null, $account->iban, null);
        }
    }

    /**
     * Removes the category (un-linking any transaction that referenced
     * it, same as any other category deletion — see Service\
     * FinanceService::deleteCategory(), duplicated here rather than
     * called directly to avoid a circular dependency between the two
     * services) and its system rule for an account that's no longer
     * eligible to have one. A no-op if it never had one.
     */
    public function removeFor(int $accountId): void
    {
        $category = $this->categoryRepository->findByAccountId($accountId);
        if ($category === null) {
            return;
        }

        $this->transactionRepository->clearCategory($category->id);
        $this->categoryRuleRepository->deleteAllForCategory($category->id);
        $this->categoryRepository->delete($category->id);
    }
}
