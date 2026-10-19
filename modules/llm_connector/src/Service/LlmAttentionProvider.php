<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Modules\LlmConnector\Api\LlmException;

/**
 * The AI provider, not configured or failing (issue #718 § C point 9).
 *
 * - **Not configured**, while the module is on: no active provider with a
 *   model on any tier — what isAvailable() already answers. Attention:
 *   every feature that uses the AI simply hides itself, nothing breaks.
 * - **In trouble**: the last call that reached the provider failed, and
 *   none has succeeded since (LastFailureStore). Urgent, as the maintainer
 *   decided for a provider that is broken rather than absent.
 *
 * Read from stored facts only — the provider row and the last outcome —
 * never by calling the provider: the page must stay cheap, and must not
 * fail because the provider does.
 */
class LlmAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private LlmConnectorService $connector,
        private LastFailureStore $lastFailure
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Intelligence artificielle';
    }

    public function collect(int $scoutYearId): array
    {
        if (!$this->connector->isAvailable()) {
            return [new AttentionPoint(
                title: "L'intelligence artificielle n'est pas configurée",
                why: "Le module est activé, mais aucun fournisseur actif n'a de modèle utilisable : les fonctions "
                    . "qui s'appuient sur l'IA restent masquées, et l'assistant d'aide ne répond pas.",
                actionLabel: 'Configurer un fournisseur',
                actionUrl: '/config/llm',
                severity: AttentionPoint::SEVERITY_ATTENTION
            )];
        }

        $failure = $this->lastFailure->lastFailure();
        if ($failure === null) {
            return [];
        }

        return [new AttentionPoint(
            title: "Le dernier appel à l'intelligence artificielle a échoué",
            why: 'Le ' . $failure['at']->format('d/m/Y à H:i') . ', ' . self::cause($failure['code'])
                . ", et aucun appel n'a réussi depuis. Les fonctions qui s'appuient sur l'IA échouent tant que "
                . 'le fournisseur ne répond pas. Le journal du module donne sa réponse exacte.',
            actionLabel: 'Tester la connexion',
            actionUrl: '/config/llm',
            severity: AttentionPoint::SEVERITY_URGENT
        )];
    }

    private static function cause(int $code): string
    {
        return match ($code) {
            LlmException::TIMEOUT => "le fournisseur n'a pas répondu à temps",
            LlmException::RATE_LIMITED => 'le fournisseur a refusé la demande, quota atteint',
            default => 'le fournisseur a renvoyé une erreur (clé refusée ou service indisponible)',
        };
    }
}
