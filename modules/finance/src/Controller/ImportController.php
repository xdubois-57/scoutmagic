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
use Modules\Finance\Repository\Account;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ImportService;
use Modules\Finance\Service\StatementFormatNotRecognized;

/**
 * The bank statement import screen. There is no account to choose: the
 * file's own IBANs decide where each line goes (Service\ImportService), and
 * the result page reports what happened, account by account.
 */
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
        return $this->renderForm(null);
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->renderResult(['error' => self::SESSION_EXPIRED_MESSAGE]);
        }

        // Empty unless the list was offered, after detection failed.
        $bankCode = trim((string) $request->getBody('bank_code', ''));
        $file = $request->getFile('statement');
        $balanceRaw = trim((string) $request->getBody('balance', ''));
        $balance = $balanceRaw !== '' ? (float) str_replace(',', '.', $balanceRaw) : null;

        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->renderResult(['error' => 'Aucun fichier fourni ou erreur lors du téléversement.']);
        }

        // The route's own role_min ('intendant') is only the module floor —
        // each account carries its own role_min_view AND, since the
        // treasurer rule, its own section. A file naming an account this
        // session may not use has that account's lines set aside, exactly
        // as if its IBAN were unknown: nothing is written into it, not even
        // a balance checkpoint.
        $role = Role::fromString(AuthSession::getRole());

        try {
            $result = $this->importService->import(
                $bankCode !== '' ? $bankCode : null,
                (string) $file['tmp_name'],
                (string) $file['name'],
                $balance,
                AuthSession::getUserAccountId(),
                fn (Account $account): bool => $this->financeService->isAccountVisibleTo($account, $role)
            );
        } catch (StatementFormatNotRecognized $e) {
            return $this->renderForm($e->getMessage());
        } catch (FinanceException $e) {
            return $this->renderResult(['error' => $e->getMessage()]);
        }

        return $this->renderResult(['result' => $result]);
    }

    /**
     * The format list is only ever on screen after a failed detection
     * ($unrecognized set): a choice always offered would be taken out of
     * habit, including wrongly — BNP picked for a CODA file.
     */
    private function renderForm(?string $unrecognized): Response
    {
        return $this->render('@finance/import/form.html.twig', [
            'unrecognized' => $unrecognized,
            'formats' => $unrecognized !== null ? $this->parserFactory->getFormatLabels() : [],
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
        return $this->render(
            '@finance/import/result.html.twig',
            $context + [
                'breadcrumb_trail' => [
                    ['label' => 'Importer', 'url' => '/finance/import'],
                ],
            ]
        );
    }
}
