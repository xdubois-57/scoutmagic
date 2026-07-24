<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;

class FinanceAccountService implements FinanceAccountInterface
{
    public function __construct(private AccountRepository $accountRepository)
    {
    }

    public function getConfiguredAccounts(): array
    {
        $accounts = array_filter(
            $this->accountRepository->findAllOrdered(),
            fn(Account $account) => $account->status === Account::STATUS_ACTIVE
        );

        return array_values(array_map(fn(Account $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'iban' => $account->iban,
            'holder_name' => $account->holderName,
            'section_id' => $account->sectionId,
        ], $accounts));
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
