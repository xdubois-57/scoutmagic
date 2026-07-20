<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;

class DashboardController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private BalanceService $balanceService
    ) {
    }

    /**
     * GET /finance — accounts visible to the current role with their
     * balance as of today. Statistics/graphs are a later iteration.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $today = new \DateTimeImmutable();

        $accounts = [];
        foreach ($this->financeService->getAccountsForUser($role) as $account) {
            $accounts[] = [
                'account' => $account,
                'balance' => $this->balanceService->getBalanceAt($account, $today),
            ];
        }

        return $this->render('@finance/dashboard.html.twig', [
            'accounts' => $accounts,
            'current_fiscal_year' => $this->financeService->getCurrentFiscalYear(),
        ]);
    }
}
