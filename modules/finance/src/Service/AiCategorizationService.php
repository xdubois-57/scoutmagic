<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Journal\JournalService;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\Category;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\Transaction;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * The "AI rule" the config page can enable at the end of the
 * categorization rule list (module spec follow-up) — a CHEAP-tier LLM
 * call, only ever reached for a movement no regular rule already
 * matched (Service\BulkCategorizationService), asking in one prompt
 * both "does one of the existing categories fit?" and, if not, "what
 * would you call a new one for this?". A picked existing category name
 * is resolved back to its id case/accent-insensitively (the model isn't
 * guaranteed to echo it byte-for-byte); a new-category suggestion is
 * recorded (Repository\AiCategorySuggestionRepository) but never creates
 * the category itself — only an admin does that, from the config page's
 * "new category" dialog, which surfaces the 10 most recent suggestions
 * as one-click starting points.
 */
class AiCategorizationService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'category' => [
                'type' => ['string', 'null'],
                'description' => "Le nom exact d'une des catégories existantes listées qui convient le mieux à ce "
                    . "mouvement, ou null si aucune ne convient vraiment.",
            ],
            'new_category_suggestion' => [
                'type' => ['string', 'null'],
                'description' => "Uniquement si aucune catégorie existante ne convient : un nom court (2-4 mots) "
                    . "de nouvelle catégorie à proposer pour ce type de mouvement à l'avenir. null sinon.",
            ],
        ],
        'required' => ['category', 'new_category_suggestion'],
    ];

    public function __construct(
        private ?LlmConnectorInterface $llmConnector,
        private CategoryRepository $categoryRepository,
        private AiCategorySuggestionRepository $suggestionRepository,
        private JournalService $journalService
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * The matched existing category's id, or null — either the model
     * found no good fit (a new-category suggestion may still have been
     * recorded as a side effect) or the request itself failed, journaled
     * either way rather than thrown, matching Task\
     * ExtractReceiptDataHandler's "a failed AI call never blocks the
     * caller" convention.
     */
    public function categorize(Transaction $transaction): ?int
    {
        if ($this->llmConnector === null) {
            return null;
        }

        $categories = $this->categoryRepository->findActiveOrdered();

        try {
            $response = $this->llmConnector->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: $this->buildPrompt($transaction, $categories),
                responseSchema: self::RESPONSE_SCHEMA
            ));
        } catch (LlmException $e) {
            $this->journalService->log(
                'finance', 'ai_categorization_failed', 'info',
                "Catégorisation IA échouée : {$e->getMessage()}", ['transaction_id' => $transaction->id], null
            );
            return null;
        }

        $parsed = $response->parsed;
        if ($parsed === null) {
            return null;
        }

        $categoryName = isset($parsed['category']) && is_string($parsed['category']) && trim($parsed['category']) !== ''
            ? trim($parsed['category'])
            : null;
        if ($categoryName !== null) {
            $matched = $this->findByNameFold($categories, $categoryName);
            if ($matched !== null) {
                return $matched->id;
            }
        }

        $suggestion = isset($parsed['new_category_suggestion']) && is_string($parsed['new_category_suggestion']) && trim($parsed['new_category_suggestion']) !== ''
            ? mb_substr(trim($parsed['new_category_suggestion']), 0, 100)
            : null;
        if ($suggestion !== null) {
            $this->suggestionRepository->create($suggestion);
        }

        return null;
    }

    /**
     * @param Category[] $categories
     */
    private function findByNameFold(array $categories, string $name): ?Category
    {
        $normalized = mb_strtolower(trim($name));
        foreach ($categories as $category) {
            if (mb_strtolower(trim($category->name)) === $normalized) {
                return $category;
            }
        }
        return null;
    }

    /**
     * @param Category[] $categories
     */
    private function buildPrompt(Transaction $transaction, array $categories): string
    {
        $categoryNames = array_map(fn(Category $c) => $c->name, $categories);

        $details = ["Libellé : {$transaction->label}", 'Montant : ' . number_format($transaction->amount, 2, ',', ' ') . ' €'];
        if ($transaction->counterpartyName !== null) {
            $details[] = "Contrepartie : {$transaction->counterpartyName}";
        }
        if ($transaction->comment !== null) {
            $details[] = "Commentaire : {$transaction->comment}";
        }
        if ($transaction->extraDetails !== null) {
            $details[] = "Autres informations : {$transaction->extraDetails}";
        }

        return "Voici un mouvement financier d'une unité scoute :\n"
            . implode("\n", $details)
            . "\n\nCatégories existantes disponibles :\n"
            . ($categoryNames !== [] ? implode(', ', $categoryNames) : '(aucune catégorie existante)')
            . "\n\nQuelle catégorie existante correspond le mieux à ce mouvement ? Si aucune ne convient vraiment, "
            . "ne force pas une correspondance approximative — indique plutôt un nom de nouvelle catégorie à créer.";
    }
}
