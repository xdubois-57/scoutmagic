<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ImportService;

class ImportController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private ImportService $importService,
        private BankStatementParserFactory $parserFactory
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());

        return $this->render('@finance/import/form.html.twig', [
            'accounts' => $this->financeService->getAccountsForUser($role),
            'bank_codes' => $this->parserFactory->getSupportedBankCodes(),
        ]);
    }

    /**
     * Not implemented yet — Service\ImportService::import() always throws
     * (module spec "itération 3").
     *
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        $account = $this->financeService->getAccount((int) $request->getBody('account_id', 0));
        $bankCode = (string) $request->getBody('bank_code', '');
        $file = $request->getFile('statement');

        $error = "L'import de relevés bancaires n'est pas encore disponible.";
        if ($account !== null && $file !== null) {
            try {
                $this->importService->import(
                    $account,
                    $bankCode,
                    (string) $file['tmp_name'],
                    (string) $file['name'],
                    null,
                    AuthSession::getUserAccountId()
                );
            } catch (FinanceException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('@finance/import/result.html.twig', ['error' => $error]);
    }
}
