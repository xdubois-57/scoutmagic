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
use Modules\Retro\Repository\Comment;

/**
 * Optional dependency on the llm_connector module (ARCHITECTURE.md §7.5) —
 * generates a short thematic synthesis once a board closes
 * (Service\BoardService::close()). Without it, chiefs simply see the raw
 * board (Controller renders nothing extra when isAvailable() is false).
 */
class SummaryService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'bullets' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => '3 à 5 points courts (une phrase chacun) résumant les thèmes qui ressortent des '
                    . 'commentaires.',
            ],
        ],
        'required' => ['bullets'],
    ];

    public function __construct(private ?LlmConnectorInterface $llmConnector = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * @param Comment[] $visibleComments already filtered to non-hidden comments
     * @throws RetroException when unavailable or the AI call fails
     */
    public function generate(array $visibleComments): string
    {
        if (!$this->isAvailable()) {
            throw new RetroException('Service IA non disponible.');
        }
        if ($visibleComments === []) {
            return '';
        }

        $byColumn = ['good' => [], 'improve' => [], 'suggestion' => []];
        foreach ($visibleComments as $comment) {
            $byColumn[$comment->columnKey][] = $comment->body;
        }

        $prompt = "Ce qui a bien marché :\n" . $this->bulletList($byColumn['good'])
            . "\n\nÀ améliorer :\n" . $this->bulletList($byColumn['improve'])
            . "\n\nAutres suggestions :\n" . $this->bulletList($byColumn['suggestion']);

        $request = new LlmRequest(
            tier: LlmTier::CHEAP,
            prompt: $prompt,
            systemPrompt: 'Tu résumes les commentaires anonymes d\'une rétrospective d\'unité scoute pour les '
                . 'animateurs. '
                . 'Identifie les thèmes qui reviennent, pas une liste exhaustive de chaque commentaire.',
            responseSchema: self::RESPONSE_SCHEMA
        );

        try {
            $response = $this->llmConnector->complete($request);
        } catch (LlmException $e) {
            // Appending $e->getMessage() used to quote the provider's own
            // HTTP body into the board page (Api\LlmException::apiError()).
            // The cause travels as $previous instead.
            throw new RetroException(
                'La synthèse n\'a pas pu être générée par l\'IA — réessayez dans quelques instants.',
                RetroException::TYPE_GENERIC,
                null,
                $e
            );
        }

        $bullets = $response->parsed['bullets'] ?? null;
        if (!is_array($bullets) || $bullets === []) {
            return trim($response->content);
        }

        return implode("\n", array_map(fn($b) => '- ' . trim((string) $b), $bullets));
    }

    /**
     * @param string[] $items
     */
    private function bulletList(array $items): string
    {
        return $items === [] ? '(aucun)' : implode("\n", array_map(fn(string $i) => '- ' . $i, $items));
    }
}
