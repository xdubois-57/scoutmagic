<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;

class FinanceAccountService implements FinanceAccountInterface
{
    /**
     * @param (\Closure(): void)|null $seedDefaultCategories run before the
     *     categories are listed — FinanceService::ensureDefaultCategories(),
     *     so another module's picker is never empty on a fresh installation
     */
    public function __construct(
        private AccountRepository $accountRepository,
        private ?\Modules\Finance\Repository\CategoryRepository $categoryRepository = null,
        private ?\Closure $seedDefaultCategories = null
    ) {
    }

    public function getCategories(): array
    {
        if ($this->categoryRepository === null) {
            return [];
        }
        if ($this->seedDefaultCategories !== null) {
            ($this->seedDefaultCategories)();
        }

        return array_values(array_map(
            static fn(\Modules\Finance\Repository\Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            $this->categoryRepository->findActiveOrdered()
        ));
    }

    public function getConfiguredAccounts(): array
    {
        $accounts = array_filter(
            $this->accountRepository->findAllOrdered(),
            fn(Account $account) => $account->status === Account::STATUS_ACTIVE
        );

        return array_values(array_map(
            fn(Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'iban' => $account->iban,
                'holder_name' => $account->holderName,
                'section_id' => $account->sectionId,
            ],
            $accounts
        ));
    }

    public function getDefaultAccountForSection(int $sectionId): ?int
    {
        $accounts = array_filter(
            $this->accountRepository->findAllOrdered(),
            fn(Account $account) => $account->status === Account::STATUS_ACTIVE && $account->sectionId === $sectionId
        );

        if ($accounts === []) {
            return null;
        }

        foreach ($accounts as $account) {
            if ($account->isDefault) {
                return $account->id;
            }
        }

        return array_values($accounts)[0]->id;
    }
}
