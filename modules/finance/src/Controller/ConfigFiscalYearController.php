<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\FinanceService;

class ConfigFiscalYearController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@finance/config/fiscal_years.html.twig', [
            'fiscal_years' => $this->financeService->getFiscalYears(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $action = (string) ($data['action'] ?? 'create');

        try {
            switch ($action) {
                case 'create':
                    $fiscalYear = $this->financeService->createFiscalYear(
                        (string) ($data['label'] ?? ''),
                        (string) ($data['start_date'] ?? ''),
                        (string) ($data['end_date'] ?? '')
                    );
                    $this->journalService->log('finance', 'fiscal_year_created', 'info', "Exercice « {$fiscalYear->label} » créé", ['fiscal_year_id' => $fiscalYear->id], AuthSession::getUserAccountId());
                    return $this->json(['success' => true, 'fiscal_year_id' => $fiscalYear->id]);

                case 'set_current':
                    $id = (int) ($data['id'] ?? 0);
                    $this->financeService->setCurrentFiscalYear($id);
                    $this->journalService->log('finance', 'fiscal_year_set_current', 'info', 'Exercice courant modifié', ['fiscal_year_id' => $id], AuthSession::getUserAccountId());
                    return $this->json(['success' => true]);

                default:
                    return $this->json(['success' => false, 'error' => 'Action inconnue.'], 400);
            }
        } catch (FinanceException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function decodeAndAuthorize(Request $request): array|Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        return $data;
    }
}
