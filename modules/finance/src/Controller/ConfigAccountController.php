<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Finance\Repository\Account;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\FinanceService;

/**
 * GET+POST /config/finance/accounts — same auto-save-list pattern as the
 * calendar module's config page. A single POST route dispatches on
 * data['action'] since the module spec's route list only names one POST
 * endpoint for the whole section (create/update/activate/archive).
 */
class ConfigAccountController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private SectionService $sectionService,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->financeService->ensureDefaultAccountsForSections();

        $sectionsById = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $sectionsById[$section['id']] = $section;
        }

        return $this->render('@finance/config/accounts.html.twig', [
            'accounts' => $this->financeService->getAllAccountsForConfig(),
            'sections_by_id' => $sectionsById,
            'account_types' => [Account::TYPE_BANK => 'Compte bancaire', Account::TYPE_CASH => 'Caisse'],
            'role_min_options' => ['intendant' => 'Intendant', 'chief' => 'Chef', 'admin' => 'Admin'],
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
                    $account = $this->financeService->createAccount(
                        (string) ($data['name'] ?? ''),
                        (string) ($data['account_type'] ?? ''),
                        isset($data['section_id']) && $data['section_id'] !== '' ? (int) $data['section_id'] : null,
                        !empty($data['iban']) ? (string) $data['iban'] : null,
                        !empty($data['holder_name']) ? (string) $data['holder_name'] : null,
                        (string) ($data['role_min_view'] ?? 'intendant')
                    );
                    $this->journalService->log('finance', 'account_created', 'info', "Compte « {$account->name} » créé", ['account_id' => $account->id], AuthSession::getUserAccountId());
                    return $this->json(['success' => true, 'account_id' => $account->id]);

                case 'update':
                    $id = (int) ($data['id'] ?? 0);
                    $account = $this->financeService->updateAccount(
                        $id,
                        (string) ($data['name'] ?? ''),
                        (string) ($data['account_type'] ?? ''),
                        isset($data['section_id']) && $data['section_id'] !== '' ? (int) $data['section_id'] : null,
                        !empty($data['iban']) ? (string) $data['iban'] : null,
                        !empty($data['holder_name']) ? (string) $data['holder_name'] : null,
                        (string) ($data['role_min_view'] ?? 'intendant')
                    );
                    $this->journalService->log('finance', 'account_updated', 'info', "Compte « {$account->name} » modifié", ['account_id' => $account->id], AuthSession::getUserAccountId());
                    return $this->json(['success' => true]);

                case 'activate':
                    $id = (int) ($data['id'] ?? 0);
                    $this->financeService->activateAccount($id);
                    $this->journalService->log('finance', 'account_activated', 'info', 'Compte activé', ['account_id' => $id], AuthSession::getUserAccountId());
                    return $this->json(['success' => true]);

                case 'archive':
                    $id = (int) ($data['id'] ?? 0);
                    $this->financeService->archiveAccount($id);
                    $this->journalService->log('finance', 'account_archived', 'info', 'Compte archivé', ['account_id' => $id], AuthSession::getUserAccountId());
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
