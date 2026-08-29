<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Modules\Gallery\Api\GalleryException;
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
        string $errorMessage,
        ?string $technicalError = null
    ): string {
        if ($this->llmConnector === null || !$this->llmConnector->isAvailable()) {
            throw new GalleryException('Service IA non disponible.');
        }

        // The SDK's own line is the diagnostic material — « The request
        // signature we calculated does not match » and « The specified
        // bucket does not exist » are different mistakes that both reach
        // the admin as « vérifiez vos identifiants ». Asking the model to
        // explain the French summary alone was asking it to guess.
        $prompt = "Fournisseur : {$provider}\n"
            . "Endpoint : {$endpoint}\n"
            . "Région : {$region}\n"
            . "Bucket : {$bucket}\n"
            . "Access key : {$accessKey}\n"
            . "Secret key : (non transmise — longueur : {$secretKeyLength} caractères)\n"
            . "Diagnostic affiché à l'administrateur :\n{$errorMessage}"
            . ($technicalError !== null && trim($technicalError) !== ''
                ? "\nMessage technique exact renvoyé par le fournisseur :\n{$technicalError}"
                : '');

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
            // Appending the connector's message used to quote the AI
            // provider's own HTTP body onto the configuration page — one
            // third party's error text explaining another's.
            throw new GalleryException(
                "L'explication n'a pas pu être générée par l'IA — réessayez dans quelques instants.",
                0,
                $e
            );
        }

        return trim($response->content);
    }
}
