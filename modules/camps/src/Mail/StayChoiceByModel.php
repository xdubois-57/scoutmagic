<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * The model as a last resort, when the deterministic rules leave several
 * stays standing.
 *
 * **It never associates.** Whatever it answers is a proposition, marked
 * as the model's, and the other stays stay on the list: a wrong pick
 * that hid the right stay would be exactly the failure a proposition
 * exists to avoid. What it buys is the order and one sentence — the
 * chief reads « le modèle suggère celui-ci » first instead of comparing
 * five stays cold.
 *
 * Optional everywhere (§7.5): without the connector, or without a model
 * on the cheap tier, nothing is called and the list is what the rules
 * produced. The same shape as `Mail\StayFromMailService`'s venue
 * reading, and the same token lesson (§8.67).
 */
class StayChoiceByModel
{
    /** How much of the message the model reads. */
    public const MAX_PROMPT_CHARS = 4000;

    /** Pays for the model's thinking too (§8.67). */
    public const MAX_TOKENS = 1500;

    private const SYSTEM_PROMPT = 'Tu aides une unité scoute à classer un e-mail reçu. '
        . 'On te donne le message et une liste de séjours (camps) possibles, chacun avec un identifiant. '
        . 'Réponds uniquement avec l\'identifiant du séjour dont le message parle le plus probablement, '
        . 'd\'après les dates, le lieu, les personnes ou le sujet qu\'il mentionne. '
        . 'Si rien dans le message ne permet de trancher, réponds une chaîne vide. '
        . 'Ne réponds jamais un identifiant absent de la liste.';

    public function __construct(private ?LlmConnectorInterface $llm = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->llm !== null && $this->llm->isTierAvailable(LlmTier::CHEAP);
    }

    /**
     * The option the model picks, or null when it declines, errs or is
     * absent.
     *
     * @param array<string, string> $options reference => how a chief names it
     */
    public function choose(string $text, array $options): ?string
    {
        if (!$this->isAvailable() || $this->llm === null || count($options) < 2 || trim($text) === '') {
            return null;
        }

        $list = '';
        foreach ($options as $reference => $label) {
            $list .= '- ' . $reference . ' : ' . $label . "\n";
        }

        try {
            $response = $this->llm->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: "Séjours possibles :\n" . $list . "\nMessage :\n" . mb_substr($text, 0, self::MAX_PROMPT_CHARS),
                systemPrompt: self::SYSTEM_PROMPT,
                responseSchema: [
                    'type' => 'object',
                    'properties' => ['choice' => ['type' => 'string']],
                    'required' => ['choice'],
                ],
                maxTokens: self::MAX_TOKENS,
            ));
        } catch (LlmException) {
            return null;
        }

        $choice = $response->parsed['choice'] ?? null;
        $choice = is_string($choice) ? trim($choice) : '';

        return $choice !== '' && array_key_exists($choice, $options) ? $choice : null;
    }
}
