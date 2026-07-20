<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Filtering, sorting, and inline editing (module spec "itération 3") are
 * not built yet — list() shows every movement for the accounts visible to
 * the current role, and update() is a stub since
 * TransactionRepository::updateEditableFields() has no category/comment
 * validation wired to it yet.
 */
class MovementController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private TransactionRepository $transactionRepository
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function list(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $accounts = $this->financeService->getAccountsForUser($role);
        $accountIds = array_map(fn($account) => $account->id, $accounts);

        $movements = array_values(array_filter(
            $this->transactionRepository->findAll(),
            fn($transaction) => in_array($transaction->accountId, $accountIds, true)
        ));

        return $this->render('@finance/movements/list.html.twig', [
            'accounts' => $accounts,
            'movements' => $movements,
            'categories' => $this->financeService->getActiveCategories(),
        ]);
    }

    /**
     * PATCH /finance/movements/{id} — category/comment/fiscal_year_id
     * only (module spec: "Refuse toute modification de amount,
     * transaction_date, label, bank_reference"). Not implemented yet —
     * itération 3.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        return $this->json(['success' => false, 'error' => "La modification des mouvements n'est pas encore disponible."], 501);
    }
}
