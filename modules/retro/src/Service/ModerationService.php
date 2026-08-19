<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Service;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * Optional dependency on the llm_connector module (ARCHITECTURE.md §7.5).
 * Both features degrade to "simply unavailable" when llm_connector is
 * disabled or has no active provider — Controller\RetroBoardController
 * checks isAvailable() before offering either.
 */
class ModerationService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'flagged' => [
                'type' => 'boolean',
                'description' => "true si le texte contient une attaque personnelle, une insulte, ou un propos irrespectueux envers une personne nommée ou identifiable.",
            ],
            'reason' => [
                'type' => ['string', 'null'],
                'description' => 'Si flagged est true, une courte explication en français à afficher à l\'auteur. null sinon.',
            ],
            'suggestion' => [
                'type' => ['string', 'null'],
                'description' => 'Si flagged est true, une reformulation du même message en un ton plus respectueux, '
                    . 'conservant le sens, en français. null si flagged est false.',
            ],
        ],
        'required' => ['flagged', 'reason', 'suggestion'],
    ];

    public function __construct(private ?LlmConnectorInterface $llmConnector = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * Real contextual assessment (not a keyword blocklist) of a
     * retrospective comment, combined with a respectful-rewrite suggestion
     * in the SAME call when flagged (avoids a second round-trip, and keeps
     * the suggestion consistent with the exact reason that triggered it).
     *
     * Best-effort: any LLM failure returns null, treated by the caller as
     * "not flagged" rather than blocking the submission — moderation is a
     * courtesy check, not the only line of defense (a chief can still hide
     * a comment afterwards).
     *
     * @return array{flagged: bool, reason: ?string, suggestion: ?string}|null
     */
    public function moderate(string $text, int $maxLength): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $request = new LlmRequest(
            tier: LlmTier::CHEAP,
            prompt: $text,
            systemPrompt: 'Tu évalues un commentaire anonyme posté sur un tableau de rétrospective d\'une unité scoute '
                . '(colonnes "ce qui a bien marché", "à améliorer", "autres suggestions"). Un désaccord, une critique '
                . 'constructive ou un ton négatif envers une activité NE sont PAS à signaler — seule une attaque '
                . 'personnelle, une insulte, ou un propos irrespectueux envers une personne doit être signalé. '
                . "Si le message est signalé, propose aussi une reformulation du même message en un ton plus "
                . "respectueux, conservant le sens, en français, de moins de {$maxLength} caractères.",
            responseSchema: self::RESPONSE_SCHEMA
        );

        try {
            $response = $this->llmConnector->complete($request);
        } catch (LlmException) {
            return null;
        }

        $parsed = $response->parsed;
        if ($parsed === null) {
            return null;
        }

        // Structured output is prompt-instructed, not enforced by the API
        // (see Service\LlmConnectorService::extractJson()), so a model may
        // answer with the string "false" — which is truthy in PHP and used to
        // flag a perfectly civil comment. Only a real boolean true counts;
        // anything else means "not flagged", the safe direction for a
        // courtesy check that must never block a legitimate participant.
        if (($parsed['flagged'] ?? false) !== true) {
            return ['flagged' => false, 'reason' => null, 'suggestion' => null];
        }

        $reason = isset($parsed['reason']) && is_string($parsed['reason']) && trim($parsed['reason']) !== ''
            ? trim($parsed['reason'])
            : 'Ce message peut être perçu comme une attaque personnelle ou un propos irrespectueux. Merci de reformuler ta pensée.';

        $suggestionRaw = isset($parsed['suggestion']) && is_string($parsed['suggestion']) ? trim($parsed['suggestion']) : '';
        // Never trust the model's own character counting (same precedent
        // as shorten()'s caller and SeoKeywordService::generateSummary()'s
        // hard cap) — enforce $maxLength here regardless of what came back.
        $suggestion = $suggestionRaw !== '' ? mb_substr($suggestionRaw, 0, $maxLength) : null;

        return ['flagged' => true, 'reason' => $reason, 'suggestion' => $suggestion];
    }

    /**
     * Rewrites $text under $maxLength, preserving meaning and tone — a
     * genuine rewrite, never a blind truncation. The caller must show the
     * result to the author for confirmation before saving; this method
     * never saves anything itself.
     *
     * @throws RetroException when unavailable or the AI call fails
     */
    public function shorten(string $text, int $maxLength): string
    {
        if (!$this->isAvailable()) {
            throw new RetroException('Service IA non disponible.');
        }

        $request = new LlmRequest(
            tier: LlmTier::CHEAP,
            prompt: $text,
            systemPrompt: "Réécris ce commentaire pour qu'il tienne strictement en moins de {$maxLength} caractères, "
                . 'en gardant le même sens et le même ton. Réponds uniquement avec le texte réécrit, sans guillemets ni commentaire.'
        );

        try {
            $response = $this->llmConnector->complete($request);
        } catch (LlmException $e) {
            throw new RetroException('Échec du raccourcissement : ' . $e->getMessage());
        }

        return trim($response->content);
    }
}
