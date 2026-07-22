<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Finance\Repository\CategoryRule;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\IbanNormalizer;

/**
 * POST-only — the config UI for rules lives on the same page as
 * categories (Controller\ConfigCategoryController::index(),
 * @finance/config/categories.html.twig), so this controller only ever
 * handles mutations, not rendering.
 */
class ConfigRuleController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private CategoryRuleRepository $ruleRepository,
        private CategoryRuleEngine $ruleEngine,
        private JournalService $journalService,
        private FinanceService $financeService,
        private BulkCategorizationService $bulkCategorizationService
    ) {
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
            case 'create': {
                $conditions = $this->extractConditions($data);
                if ($conditions instanceof Response) {
                    return $conditions;
                }
                [$keywordPattern, $counterpartyAccountPattern, $amountRange] = $conditions;

                $categoryId = (int) ($data['category_id'] ?? 0);
                $priority = count($this->ruleRepository->findAllOrderedByPriority());
                $id = $this->ruleRepository->create($categoryId, $priority, $keywordPattern, $counterpartyAccountPattern, $amountRange);
                $this->journalService->log('finance', 'rule_created', 'info', 'Règle de catégorisation créée', ['rule_id' => $id], AuthSession::getUserAccountId());
                return $this->json(['success' => true, 'rule_id' => $id]);
            }

            case 'update': {
                $ruleId = (int) ($data['id'] ?? 0);
                $blocked = $this->rejectIfSystem($ruleId);
                if ($blocked instanceof Response) {
                    return $blocked;
                }

                $conditions = $this->extractConditions($data);
                if ($conditions instanceof Response) {
                    return $conditions;
                }
                [$keywordPattern, $counterpartyAccountPattern, $amountRange] = $conditions;

                $this->ruleRepository->update($ruleId, (int) ($data['category_id'] ?? 0), $keywordPattern, $counterpartyAccountPattern, $amountRange);
                $this->journalService->log('finance', 'rule_updated', 'info', 'Règle de catégorisation modifiée', ['rule_id' => $ruleId], AuthSession::getUserAccountId());
                return $this->json(['success' => true]);
            }

            case 'activate': {
                $ruleId = (int) ($data['id'] ?? 0);
                $blocked = $this->rejectIfSystem($ruleId);
                if ($blocked instanceof Response) {
                    return $blocked;
                }
                $this->ruleRepository->setActive($ruleId, true);
                return $this->json(['success' => true]);
            }

            case 'deactivate': {
                $ruleId = (int) ($data['id'] ?? 0);
                $blocked = $this->rejectIfSystem($ruleId);
                if ($blocked instanceof Response) {
                    return $blocked;
                }
                $this->ruleRepository->setActive($ruleId, false);
                return $this->json(['success' => true]);
            }

            case 'delete': {
                $ruleId = (int) ($data['id'] ?? 0);
                $blocked = $this->rejectIfSystem($ruleId);
                if ($blocked instanceof Response) {
                    return $blocked;
                }
                $this->ruleRepository->delete($ruleId);
                $this->journalService->log('finance', 'rule_deleted', 'info', 'Règle de catégorisation supprimée', ['rule_id' => $ruleId], AuthSession::getUserAccountId());
                return $this->json(['success' => true]);
            }

            case 'reorder':
                // System rules are never included in the reorderable list
                // (the config UI hides them from it entirely) — dropping
                // any posted anyway keeps their own fixed, always-first
                // priority untouched rather than folding them into the
                // admin-authored ordering.
                $orderedIds = array_values(array_filter(
                    array_map('intval', (array) ($data['ordered_ids'] ?? [])),
                    fn(int $id) => $this->ruleRepository->findById($id)?->isSystem === false
                ));
                $this->ruleRepository->reorder($orderedIds);
                return $this->json(['success' => true]);

            case 'reset_defaults':
                $this->financeService->resetDefaultCategoryRules();
                $this->journalService->log('finance', 'rules_reset_to_defaults', 'info', 'Règles de catégorisation par défaut réinitialisées', [], AuthSession::getUserAccountId());
                return $this->json(['success' => true]);

            case 'set_ai_enabled':
                $this->bulkCategorizationService->setAiRuleEnabled((bool) ($data['enabled'] ?? false));
                return $this->json(['success' => true]);

            case 'run_on_uncategorized': {
                // Runs in the background (Service\BulkCategorizationService::
                // scheduleBackgroundRun(), picked up by the poor man's cron —
                // public/index.php) rather than inline here: the AI rule
                // makes one LLM call per uncategorized movement, which could
                // otherwise make this request hang for a very long time.
                if (!$this->bulkCategorizationService->scheduleBackgroundRun()) {
                    return $this->json(['success' => false, 'error' => 'Une exécution est déjà en cours.'], 400);
                }
                $this->journalService->log(
                    'finance', 'rules_run_on_uncategorized_started', 'info',
                    'Exécution des règles de catégorisation sur les mouvements non catégorisés lancée en arrière-plan',
                    [], AuthSession::getUserAccountId()
                );
                return $this->json(['success' => true]);
            }

            case 'run_status':
                return $this->json([
                    'success' => true,
                    'running' => $this->bulkCategorizationService->isRunning(),
                    'last_result' => $this->bulkCategorizationService->getLastResult(),
                ]);

            case 'test': {
                $conditions = $this->extractConditions($data);
                if ($conditions instanceof Response) {
                    return $conditions;
                }
                [$keywordPattern, $counterpartyAccountPattern, $amountRange] = $conditions;

                $transientRule = new CategoryRule(
                    id: 0,
                    categoryId: 0,
                    priority: 0,
                    keywordPattern: $keywordPattern,
                    counterpartyAccountPattern: $counterpartyAccountPattern,
                    amountRange: $amountRange,
                    isActive: true
                );
                return $this->json(['success' => true, 'count' => $this->ruleEngine->countMatches($transientRule)]);
            }

            default:
                return $this->json(['success' => false, 'error' => 'Action inconnue.'], 400);
        }
    }

    /**
     * Normalizes the three posted condition fields (blank → null) and
     * validates them: at least one must be set (a rule with none would
     * never match anything), and a set keyword_pattern must be a
     * well-formed regular expression — rejected here, at save time,
     * rather than saved and silently never matching anything at import
     * time. keyword_pattern is stored lowercased (CategoryRuleEngine
     * folds case at match time regardless, so this is purely a storage
     * convention — makes the saved pattern's own casing consistent no
     * matter how an admin typed it). counterparty_account_pattern is
     * always normalize()d (uppercase, no spaces/punctuation); it is
     * additionally checksum-validated as a real IBAN only when it's long
     * enough to plausibly be a complete one (Service\IbanNormalizer::
     * looksLikeFullIban()) — the config page's own help text explicitly
     * allows a shorter fragment (e.g. a bank sort-code prefix), which a
     * full IBAN checksum can never validate.
     *
     * @param array<string, mixed> $data
     * @return array{0: ?string, 1: ?string, 2: ?string}|Response
     */
    private function extractConditions(array $data): array|Response
    {
        $keywordPattern = $this->nullIfBlank((string) ($data['keyword_pattern'] ?? ''));
        $counterpartyAccountPattern = $this->nullIfBlank((string) ($data['counterparty_account_pattern'] ?? ''));
        $amountRange = $this->nullIfBlank((string) ($data['amount_range'] ?? ''));

        if ($keywordPattern === null && $counterpartyAccountPattern === null && $amountRange === null) {
            return $this->json(['success' => false, 'error' => 'Au moins une condition est requise.'], 400);
        }

        if ($keywordPattern !== null) {
            if (!CategoryRuleEngine::isValidKeywordPattern($keywordPattern)) {
                return $this->json(['success' => false, 'error' => 'Expression régulière invalide pour le mot-clé.'], 400);
            }
            $keywordPattern = mb_strtolower($keywordPattern);
        }

        if ($counterpartyAccountPattern !== null) {
            $counterpartyAccountPattern = IbanNormalizer::normalize($counterpartyAccountPattern);
            if (IbanNormalizer::looksLikeFullIban($counterpartyAccountPattern) && !IbanNormalizer::isValidFullIban($counterpartyAccountPattern)) {
                return $this->json(['success' => false, 'error' => "Le compte contrepartie n'est pas un IBAN valide."], 400);
            }
        }

        return [$keywordPattern, $counterpartyAccountPattern, $amountRange];
    }

    private function nullIfBlank(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    /**
     * A system rule (Service\AccountTransferCategoryService) is derived
     * from its account's own IBAN, not a standing admin decision — hand-
     * editing it would just be overwritten the next time that account is
     * saved, so mutating it is rejected outright rather than silently
     * accepted and then clobbered. The config UI already hides its edit/
     * delete/toggle controls; this is the server-side backstop for a
     * request crafted directly against the endpoint.
     */
    private function rejectIfSystem(int $ruleId): ?Response
    {
        $rule = $this->ruleRepository->findById($ruleId);
        if ($rule !== null && $rule->isSystem) {
            return $this->json(['success' => false, 'error' => 'Cette règle est gérée automatiquement et ne peut pas être modifiée.'], 400);
        }
        return null;
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
