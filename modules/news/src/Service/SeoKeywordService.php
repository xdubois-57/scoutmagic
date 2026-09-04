<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * Optional dependency on the llm_connector module (ARCHITECTURE.md §7.5)
 * — every "Générer avec l'IA" button (Controller\NewsController) is simply
 * hidden in the article editor whenever isAvailable() is false. Covers
 * both SEO keyword generation and the social-sharing summary (usability
 * review addendum) — same connector, same availability gate, same
 * error-wrapping shape, not worth splitting into two services for.
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
            systemPrompt: 'Tu es un assistant qui génère des mots-clés SEO pour un article de site web d\'une unité '
                . 'scoute. '
                . 'Réponds uniquement avec une liste de 5 à 10 mots-clés ou courtes expressions, séparés par des '
                . 'virgules, en français, '
                . 'sans phrase d\'introduction ni numérotation.'
        );

        try {
            $response = $this->llmConnector->complete($request);
        } catch (LlmException $e) {
            // Never append the connector's message: Api\LlmException carries
            // the provider's HTTP failure, and this string is rendered in
            // the article editor.
            throw new NewsException(
                'Les mots-clés n\'ont pas pu être générés par l\'IA — réessayez, ou saisissez-les vous-même.',
                0,
                $e
            );
        }

        return trim($response->content);
    }

    /**
     * A punchy, one-sentence summary meant to make someone want to click
     * the link when it's shared (module usability review) — distinct
     * system prompt from generateKeywords() (which optimizes for search
     * engines, not human curiosity), same connector/error handling.
     * Hard-capped to 300 characters to match the `summary` column and
     * the editor's own maxlength, regardless of what the model returns.
     *
     * @throws NewsException when unavailable or the AI call fails
     */
    public function generateSummary(string $title, string $bodyHtml): string
    {
        if ($this->llmConnector === null || !$this->llmConnector->isAvailable()) {
            throw new NewsException('Service IA non disponible.');
        }

        $plainText = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8'));
        $plainText = mb_substr($plainText, 0, 3000);

        $request = new LlmRequest(
            tier: LlmTier::CHEAP,
            prompt: "Titre : {$title}\n\nContenu :\n{$plainText}",
            systemPrompt: 'Tu es un assistant qui rédige des résumés accrocheurs pour les actualités d\'un site web '
                . 'd\'une unité scoute, '
                . 'destinés à être partagés sur les réseaux sociaux (Facebook, Instagram...). '
                . 'Réponds uniquement avec UNE SEULE phrase, en français, engageante et donnant envie de cliquer sur '
                . 'le lien, '
                . 'sans guillemets, sans hashtag, sans emoji, sans phrase d\'introduction.'
        );

        try {
            $response = $this->llmConnector->complete($request);
        } catch (LlmException $e) {
            throw new NewsException(
                'Le résumé n\'a pas pu être généré par l\'IA — réessayez, ou rédigez-le vous-même.',
                0,
                $e
            );
        }

        return mb_substr(trim($response->content), 0, 300);
    }
}
