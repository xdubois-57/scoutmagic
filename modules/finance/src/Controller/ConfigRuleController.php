<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRule;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Service\CategoryRuleEngine;

class ConfigRuleController extends AbstractController
{
    private const VALID_CONDITION_TYPES = [
        CategoryRule::CONDITION_KEYWORD,
        CategoryRule::CONDITION_COUNTERPARTY_ACCOUNT,
        CategoryRule::CONDITION_AMOUNT_RANGE,
    ];

    public function __construct(
        protected \Twig\Environment $twig,
        private CategoryRuleRepository $ruleRepository,
        private CategoryRepository $categoryRepository,
        private CategoryRuleEngine $ruleEngine,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $categoriesById = [];
        foreach ($this->categoryRepository->findAllOrdered() as $category) {
            $categoriesById[$category->id] = $category;
        }

        return $this->render('@finance/config/rules.html.twig', [
            'rules' => $this->ruleRepository->findAllOrderedByPriority(),
            'categories' => $this->categoryRepository->findActiveOrdered(),
            'categories_by_id' => $categoriesById,
            'condition_types' => [
                CategoryRule::CONDITION_KEYWORD => 'Mot-clé',
                CategoryRule::CONDITION_COUNTERPARTY_ACCOUNT => 'Compte contrepartie',
                CategoryRule::CONDITION_AMOUNT_RANGE => 'Montant (plage)',
            ],
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

        switch ($action) {
            case 'create':
                $conditionType = (string) ($data['condition_type'] ?? '');
                if (!in_array($conditionType, self::VALID_CONDITION_TYPES, true)) {
                    return $this->json(['success' => false, 'error' => 'Type de condition invalide.'], 400);
                }
                $categoryId = (int) ($data['category_id'] ?? 0);
                $priority = count($this->ruleRepository->findAllOrderedByPriority());
                $id = $this->ruleRepository->create($categoryId, $priority, $conditionType, (string) ($data['condition_value'] ?? ''));
                $this->journalService->log('finance', 'rule_created', 'info', 'Règle de catégorisation créée', ['rule_id' => $id], AuthSession::getUserAccountId());
                return $this->json(['success' => true, 'rule_id' => $id]);

            case 'update':
                $ruleId = (int) ($data['id'] ?? 0);
                $conditionType = (string) ($data['condition_type'] ?? '');
                if (!in_array($conditionType, self::VALID_CONDITION_TYPES, true)) {
                    return $this->json(['success' => false, 'error' => 'Type de condition invalide.'], 400);
                }
                $this->ruleRepository->update($ruleId, (int) ($data['category_id'] ?? 0), $conditionType, (string) ($data['condition_value'] ?? ''));
                $this->journalService->log('finance', 'rule_updated', 'info', 'Règle de catégorisation modifiée', ['rule_id' => $ruleId], AuthSession::getUserAccountId());
                return $this->json(['success' => true]);

            case 'activate':
                $this->ruleRepository->setActive((int) ($data['id'] ?? 0), true);
                return $this->json(['success' => true]);

            case 'deactivate':
                $this->ruleRepository->setActive((int) ($data['id'] ?? 0), false);
                return $this->json(['success' => true]);

            case 'delete':
                $ruleId = (int) ($data['id'] ?? 0);
                $this->ruleRepository->delete($ruleId);
                $this->journalService->log('finance', 'rule_deleted', 'info', 'Règle de catégorisation supprimée', ['rule_id' => $ruleId], AuthSession::getUserAccountId());
                return $this->json(['success' => true]);

            case 'reorder':
                $orderedIds = array_map('intval', (array) ($data['ordered_ids'] ?? []));
                $this->ruleRepository->reorder($orderedIds);
                return $this->json(['success' => true]);

            case 'test':
                $conditionType = (string) ($data['condition_type'] ?? '');
                if (!in_array($conditionType, self::VALID_CONDITION_TYPES, true)) {
                    return $this->json(['success' => false, 'error' => 'Type de condition invalide.'], 400);
                }
                $transientRule = new CategoryRule(
                    id: 0,
                    categoryId: 0,
                    priority: 0,
                    conditionType: $conditionType,
                    conditionValue: (string) ($data['condition_value'] ?? ''),
                    isActive: true
                );
                return $this->json(['success' => true, 'count' => $this->ruleEngine->countMatches($transientRule)]);

            default:
                return $this->json(['success' => false, 'error' => 'Action inconnue.'], 400);
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
