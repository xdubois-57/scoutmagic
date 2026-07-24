<?php

declare(strict_types=1);

namespace Modules\News\Service;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * Optional dependency on the llm_connector module (ARCHITECTURE.md §7.5)
 * — the "Générer avec l'IA" button (Controller\NewsController) is simply
 * hidden in the article editor whenever isAvailable() is false.
 */
class SeoKeywordService
{
    public function __construct(private ?LlmConnectorInterface $llmConnector = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * @throws NewsException when unavailable or the AI call fails
     */
    public function generateKeywords(string $title, string $bodyHtml): string
    {
        if ($this->llmConnector === null || !$this->llmConnector->isAvailable()) {
            throw new NewsException('Service IA non disponible.');
        }

        $plainText = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8'));
        $plainText = mb_substr($plainText, 0, 3000);

        $request = new LlmRequest(
            tier: LlmTier::CHEAP,
            prompt: "Titre : {$title}\n\nContenu :\n{$plainText}",
            systemPrompt: 'Tu es un assistant qui génère des mots-clés SEO pour un article de site web d\'une unité scoute. '
                . 'Réponds uniquement avec une liste de 5 à 10 mots-clés ou courtes expressions, séparés par des virgules, en français, '
                . 'sans phrase d\'introduction ni numérotation.'
        );

        try {
            $response = $this->llmConnector->complete($request);
        } catch (LlmException $e) {
            throw new NewsException('Échec de la génération des mots-clés : ' . $e->getMessage());
        }

        return trim($response->content);
    }
}
