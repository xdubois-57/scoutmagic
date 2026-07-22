<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\FinanceService;

/**
 * GET /config/finance/categories — categories AND categorization rules
 * on one page (the module spec asked for them merged, since a rule is
 * meaningless without its target category right next to it). Rule
 * mutations still post to their own route (POST /config/finance/rules,
 * Controller\ConfigRuleController::save()) — only the page itself is shared.
 */
class ConfigCategoryController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private CategoryRuleRepository $ruleRepository,
        private JournalService $journalService,
        private AiCategorySuggestionRepository $aiSuggestionRepository,
        private BulkCategorizationService $bulkCategorizationService,
        private bool $aiModuleEnabled
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->financeService->ensureDefaultCategories();
        $categories = $this->financeService->getAllCategories();

        $categoriesById = [];
        foreach ($categories as $category) {
            $categoriesById[$category->id] = $category;
        }

        return $this->render('@finance/config/categories.html.twig', [
            'categories' => $categories,
            'active_categories' => $this->financeService->getActiveCategories(),
            'categories_by_id' => $categoriesById,
            'rules' => $this->ruleRepository->findAllOrderedByPriority(),
            'ai_module_enabled' => $this->aiModuleEnabled,
            'ai_rule_enabled' => $this->bulkCategorizationService->isAiRuleEnabled(),
            'recent_ai_suggestions' => $this->aiSuggestionRepository->findRecent(),
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
                    $category = $this->financeService->createCategory((string) ($data['name'] ?? ''));
                    $this->journalService->log('finance', 'category_created', 'info', "Catégorie « {$category->name} » créée", ['category_id' => $category->id], AuthSession::getUserAccountId());
                    return $this->json(['success' => true, 'category_id' => $category->id]);

                case 'update':
                    $id = (int) ($data['id'] ?? 0);
                    $this->financeService->updateCategoryName($id, (string) ($data['name'] ?? ''));
                    $this->journalService->log('finance', 'category_updated', 'info', 'Catégorie modifiée', ['category_id' => $id], AuthSession::getUserAccountId());
                    return $this->json(['success' => true]);

                case 'activate':
                    $id = (int) ($data['id'] ?? 0);
                    $this->financeService->setCategoryActive($id, true);
                    return $this->json(['success' => true]);

                case 'deactivate':
                    $id = (int) ($data['id'] ?? 0);
                    $this->financeService->setCategoryActive($id, false);
                    return $this->json(['success' => true]);

                case 'delete':
                    $id = (int) ($data['id'] ?? 0);
                    $this->financeService->deleteCategory($id);
                    $this->journalService->log('finance', 'category_deleted', 'info', 'Catégorie supprimée', ['category_id' => $id], AuthSession::getUserAccountId());
                    return $this->json(['success' => true]);

                case 'reset_defaults':
                    $this->financeService->resetDefaultCategories();
                    $this->journalService->log('finance', 'categories_reset_to_defaults', 'info', 'Catégories par défaut réinitialisées', [], AuthSession::getUserAccountId());
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
