<?php

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * Optional dependency on the llm_connector module (ARCHITECTURE.md §7.5)
 * — the "Expliquer avec l'IA" button (Controller\GalleryConfigController)
 * is hidden whenever isAvailable() is false. Never sends the secret key
 * itself to the model, only its length, so a diagnosis can still reason
 * about credential-shaped mistakes (e.g. a truncated or swapped key)
 * without ever exposing the actual secret to a third-party API.
 */
class S3ErrorExplainerService
{
    public function __construct(private ?LlmConnectorInterface $llmConnector = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * @throws GalleryException when unavailable or the AI call fails
     */
    public function explain(
        string $provider,
        string $endpoint,
        string $region,
        string $bucket,
        string $accessKey,
        int $secretKeyLength,
        string $errorMessage
    ): string {
        if ($this->llmConnector === null || !$this->llmConnector->isAvailable()) {
            throw new GalleryException('Service IA non disponible.');
        }

        $prompt = "Fournisseur : {$provider}\n"
            . "Endpoint : {$endpoint}\n"
            . "Région : {$region}\n"
            . "Bucket : {$bucket}\n"
            . "Access key : {$accessKey}\n"
            . "Secret key : (non transmise — longueur : {$secretKeyLength} caractères)\n"
            . "Message d'erreur reçu du fournisseur lors du test de connexion :\n{$errorMessage}";

        $request = new LlmRequest(
            tier: LlmTier::CHEAP,
            prompt: $prompt,
            systemPrompt: 'Tu es un assistant technique qui aide un administrateur bénévole, non expert, à configurer '
                . 'le stockage objet compatible S3 (Hetzner, Cloudflare R2, Scaleway, OVHcloud, ou un fournisseur personnalisé) '
                . 'd\'un site web associatif. On te donne la configuration saisie (sans la clé secrète, uniquement sa longueur) '
                . 'ainsi que le message d\'erreur technique reçu lors du test de connexion. '
                . 'Explique en français, en 3 à 5 phrases courtes et concrètes, la cause la plus probable pour CE fournisseur '
                . 'précis et l\'action exacte à réaliser pour la corriger (quel champ modifier, où le trouver dans la console '
                . 'du fournisseur). Ne donne pas de conseils génériques de sécurité, va droit à la cause probable.'
        );

        try {
            $response = $this->llmConnector->complete($request);
        } catch (LlmException $e) {
            throw new GalleryException('Échec de la génération de l\'explication : ' . $e->getMessage());
        }

        return trim($response->content);
    }
}
