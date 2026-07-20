<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ImportService;

class ImportController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private ImportService $importService,
        private BankStatementParserFactory $parserFactory,
        private BalanceCheckpointRepository $checkpointRepository
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $accounts = $this->financeService->getAccountsForUser($role);

        $firstImportByAccountId = [];
        foreach ($accounts as $account) {
            $firstImportByAccountId[$account->id] = !$this->checkpointRepository->hasAnyForAccount($account->id);
        }

        return $this->render('@finance/import/form.html.twig', [
            'accounts' => $accounts,
            'bank_codes' => $this->parserFactory->getSupportedBankCodes(),
            'first_import_by_account_id' => $firstImportByAccountId,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        $account = $this->financeService->getAccount((int) $request->getBody('account_id', 0));
        $bankCode = (string) $request->getBody('bank_code', '');
        $file = $request->getFile('statement');
        $balanceRaw = (string) $request->getBody('balance', '');
        $balance = $balanceRaw !== '' ? (float) str_replace(',', '.', $balanceRaw) : null;

        if ($account === null) {
            return $this->render('@finance/import/result.html.twig', ['error' => 'Compte introuvable.']);
        }
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->render('@finance/import/result.html.twig', ['error' => 'Aucun fichier fourni ou erreur lors du téléversement.']);
        }

        try {
            $result = $this->importService->import(
                $account,
                $bankCode,
                (string) $file['tmp_name'],
                (string) $file['name'],
                $balance,
                AuthSession::getUserAccountId()
            );
        } catch (FinanceException $e) {
            return $this->render('@finance/import/result.html.twig', ['error' => $e->getMessage()]);
        }

        return $this->render('@finance/import/result.html.twig', [
            'result' => $result->statementImport,
            'balance_discrepancy' => $result->balanceDiscrepancy,
            'account' => $account,
        ]);
    }
}
