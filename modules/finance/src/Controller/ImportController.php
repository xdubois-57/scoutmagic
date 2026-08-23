<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
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
        $selectedAccount = $this->financeService->resolveSelectedAccount($role, $request->getQuery('account_id'));

        $firstImportByAccountId = [];
        foreach ($accounts as $account) {
            $firstImportByAccountId[$account->id] = !$this->checkpointRepository->hasAnyForAccount($account->id);
        }

        return $this->render('@finance/import/form.html.twig', [
            'accounts' => $accounts,
            'selected_account' => $selectedAccount,
            'bank_codes' => $this->parserFactory->getSupportedBankCodes(),
            'first_import_by_account_id' => $firstImportByAccountId,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->renderResult(['error' => self::SESSION_EXPIRED_MESSAGE]);
        }

        $account = $this->financeService->getAccount((int) $request->getBody('account_id', 0));
        $bankCode = (string) $request->getBody('bank_code', '');
        $file = $request->getFile('statement');
        $balanceRaw = (string) $request->getBody('balance', '');
        $balance = $balanceRaw !== '' ? (float) str_replace(',', '.', $balanceRaw) : null;

        if ($account === null) {
            return $this->renderResult(['error' => 'Compte introuvable.']);
        }
        // The route's own role_min ('intendant') is only the module floor —
        // each account carries its own role_min_view on top of it, and
        // form() above only ever *renders* the visible ones. Without this
        // check a request crafted directly against the endpoint could
        // import movements (and a balance checkpoint) into an account the
        // caller is not allowed to see at all.
        $role = Role::fromString(AuthSession::getRole());
        if (!$role->hasAccess(Role::fromString($account->roleMinView))) {
            return $this->renderResult(['error' => 'Accès refusé.']);
        }
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->renderResult(['error' => 'Aucun fichier fourni ou erreur lors du téléversement.']);
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
            return $this->renderResult(['error' => $e->getMessage()]);
        }

        return $this->renderResult([
            'result' => $result->statementImport,
            'balance_discrepancy' => $result->balanceDiscrepancy,
            'account' => $account,
        ]);
    }
    /**
     * Every outcome of upload() — error or success — renders the same
     * result page; the breadcrumb trail back to the import form is added
     * here once so no branch can forget it (design.md §7.3 — the trail
     * replaced the page's own « Retour » button).
     *
     * @param array<string, mixed> $context
     */
    private function renderResult(array $context): Response
    {
        return $this->render('@finance/import/result.html.twig', $context + [
            'breadcrumb_trail' => [
                ['label' => 'Importer', 'url' => '/finance/import'],
            ],
        ]);
    }
}
