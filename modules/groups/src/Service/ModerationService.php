<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Config\SettingService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * A-priori check of a post or reply, before it is stored — an optional
 * llm_connector consumer (ARCHITECTURE.md §7.5), degrading to "simply
 * unavailable" when that module is disabled or has no active provider.
 *
 * This is deliberately its OWN service rather than a reuse of
 * Modules\Retro\Service\ModerationService, whose shape it copies closely.
 * Retro's system prompt is written for an anonymous retrospective board
 * ("ce qui a bien marché" / "à améliorer"), which is a different context
 * with different norms; and making `groups` depend on `retro` would turn
 * an optional capability into a coupling between two modules that have
 * nothing to do with each other, breaking the moment an admin disables
 * retro. Duplicating the shape is right here — duplicating the prompt
 * would not be.
 *
 * FAIL OPEN, always. No provider, provider disabled, timeout, malformed
 * response, exception: every one of them means "posted without
 * moderation", exactly like retro. Only an explicit flagged:true blocks a
 * submission. This is a courtesy check that catches something said in
 * anger before anyone reads it — never the line of defence. That is
 * member reporting plus a moderator's own judgement
 * (Service\ReportService), which keep working whatever the AI does.
 */
class ModerationService
{
    /**
     * The LLM sits in the member's publish request, so this is
     * deliberately tighter than the provider's own default: a slow
     * provider must degrade to "posted without moderation" quickly rather
     * than hold the request open while someone waits on a spinner.
     */
    private const TIMEOUT_SECONDS = 8;

    /**
     * Bounds the suggestion the model may return. Not the post's own
     * ceiling (Service\PostService::MAX_BODY_LENGTH, 5000): a suggested
     * rewording is shown inline in an error panel, and a multi-thousand
     * character one would be unusable there.
     */
    private const MAX_SUGGESTION_LENGTH = 1000;

    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'flagged' => [
                'type' => 'boolean',
                'description' => 'true si le texte contient une attaque personnelle, une insulte, ou un propos '
                    . 'irrespectueux envers une personne nommée ou identifiable.',
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

    public const SETTING_ENABLED = 'groups_ai_moderation_enabled';

    public function __construct(
        private SettingService $settingService,
        private ?LlmConnectorInterface $llmConnector = null
    ) {
    }

    public function isAvailable(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * The admin switch alone, without touching the provider — separate
     * from isAvailable() so a settings screen can say "turned off" rather
     * than "no provider configured", which are different problems.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->settingService->get(self::SETTING_ENABLED, 'groups', true);
    }

    /**
     * Contextual assessment of one post or reply, with a respectful
     * rewrite in the SAME call when flagged — one round-trip, and a
     * suggestion consistent with the exact reason that triggered it.
     *
     * @return array{flagged: bool, reason: ?string, suggestion: ?string}|null
     *         null means "could not check" and the caller must post the
     *         text anyway (fail open, see the class docblock).
     */
    public function moderate(string $text): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $request = new LlmRequest(
            tier: LlmTier::CHEAP,
            prompt: $text,
            systemPrompt: 'Tu évalues un message publié dans un groupe de discussion privé d\'une unité scoute, '
                . 'entre animateurs, parents et responsables qui se connaissent. Le ton y est familier et direct. '
                . 'Un désaccord, une critique d\'une activité, d\'une décision ou d\'une organisation, un reproche sur '
                . 'un fait précis, une plainte, un agacement ou un humour taquin entre personnes qui se connaissent '
                . 'NE sont PAS à signaler : ce sont des échanges légitimes et nécessaires dans un groupe. '
                . 'Ne signale QUE ce qui vise une personne plutôt qu\'un fait : une attaque personnelle, une insulte, '
                . 'une moquerie humiliante, un propos discriminatoire, une menace, ou un propos manifestement '
                . 'irrespectueux envers quelqu\'un. Dans le doute, ne signale pas. '
                . 'Si le message est signalé, propose aussi une reformulation du même message, en français, gardant '
                . 'le même fond et le même reproche mais adressé au fait plutôt qu\'à la personne, en moins de '
                . self::MAX_SUGGESTION_LENGTH . ' caractères.',
            responseSchema: self::RESPONSE_SCHEMA,
            timeoutSeconds: self::TIMEOUT_SECONDS
        );

        try {
            $response = $this->llmConnector->complete($request);
        } catch (LlmException) {
            // Includes the timeout: a provider that did not answer in
            // time is a technical failure like any other, never a reason
            // to refuse someone's message.
            return null;
        }

        $parsed = $response->parsed;
        if ($parsed === null || !array_key_exists('flagged', $parsed) || !is_bool($parsed['flagged'])) {
            // A malformed or schema-violating answer is "could not
            // check", not "suspicious" — anything else would fail closed
            // on the provider's bad day.
            return null;
        }

        if ($parsed['flagged'] === false) {
            return ['flagged' => false, 'reason' => null, 'suggestion' => null];
        }

        $reason = isset($parsed['reason']) && is_string($parsed['reason']) && trim($parsed['reason']) !== ''
            ? trim($parsed['reason'])
            : 'Ce message peut être perçu comme une attaque personnelle ou un propos irrespectueux. Merci de le reformuler.';

        $suggestionRaw = isset($parsed['suggestion']) && is_string($parsed['suggestion']) ? trim($parsed['suggestion']) : '';
        // Never trust the model's own character counting — the cap is
        // enforced here regardless of what came back, same precedent as
        // retro's own service.
        $suggestion = $suggestionRaw !== '' ? mb_substr($suggestionRaw, 0, self::MAX_SUGGESTION_LENGTH) : null;

        return ['flagged' => true, 'reason' => $reason, 'suggestion' => $suggestion];
    }
}
