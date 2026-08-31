<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\Registration\Repository\PassageNoteRepository;
use Modules\Registration\Repository\ReenrollmentRepository;

/**
 * The optional AI re-reading of what families wrote in free text
 * (roadmap IT-17, spec §13).
 *
 * A family answers with a section and up to three names. Some of them also
 * write a sentence — « on aimerait qu'il reste avec les copains de sa
 * patrouille » — that says something the dedicated fields never captured.
 * This asks a model to point that out, and shows it to a chief marked
 * « à vérifier ».
 *
 * **Optional dependency, nullable, degrading silently** (ARCHITECTURE.md
 * §7.5). Without the `llm_connector` module, or with no active provider,
 * `isAvailable()` is false and the page renders exactly as it did before —
 * no block, no error, no mention of a feature the unit does not have.
 *
 * **A chief asks; the site never sends on its own.** The re-reading runs
 * on an explicit gesture, never on page load. Two reasons, and the second
 * is the one that settles it:
 *
 * - the roadmap's own pitfall: one call per comment, result persisted,
 *   never a call per page view — which the source hash below enforces
 *   whatever the trigger;
 * - a family comment sent to an external provider is a TRANSMISSION of
 *   personal data (`AGENTS.md`, RGPD section). Making it the consequence
 *   of a chief pressing a button, rather than of anybody opening a page,
 *   is the difference between a processing operation a unit performs and
 *   one that happens to it.
 *
 * **Nothing it produces is ever used by itself.** The suggestion is stored
 * unconfirmed and displayed as unverified; only a chief's confirmation
 * makes it usable by the optimiser (IT-18). A machine reading of a
 * parent's sentence is a hint to a human, never an input to a placement —
 * and negative wishes (« surtout pas avec X ») stay free text a chief
 * reads, whatever channel they arrive through.
 */
class PassageCommentReviewService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'has_wish' => [
                'type' => 'boolean',
                'description' => 'true si le commentaire exprime un souhait de placement '
                    . "(être avec quelqu'un, aller dans une section précise) qui n'est PAS déjà "
                    . 'couvert par les champs dédiés listés dans le contexte.',
            ],
            'summary' => [
                'type' => ['string', 'null'],
                'description' => 'Si has_wish est true, une phrase française courte disant ce que la famille '
                    . 'demande, sans interpréter au-delà du texte. null sinon.',
            ],
        ],
        'required' => ['has_wish', 'summary'],
    ];

    public function __construct(
        private ReenrollmentRepository $reenrollmentRepository,
        private PassageNoteRepository $passageNoteRepository,
        private ?LlmConnectorInterface $llmConnector = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * Re-read every family comment of `$targetYearId` that has not been
     * read yet, and return how many calls were actually made.
     *
     * « Not read yet » is a hash comparison, so pressing the button twice
     * costs one round of calls and then nothing: only a family who EDITED
     * their comment since is read again, and exactly once.
     */
    public function reviewPending(int $targetYearId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $notes = $this->passageNoteRepository->findForYear($targetYearId);
        $reviewed = 0;

        foreach ($this->reenrollmentRepository->findAnswersForYear($targetYearId) as $memberId => $answer) {
            $comment = $answer->familyComment;
            if ($comment === null || trim($comment) === '') {
                continue;
            }

            $hash = self::hashOf($comment);
            if (($notes[$memberId]['ai_source_hash'] ?? null) === $hash) {
                continue;
            }

            $this->passageNoteRepository->setAiSuggestion(
                $memberId,
                $targetYearId,
                $hash,
                $this->askAbout($comment)
            );
            $reviewed++;
        }

        return $reviewed;
    }

    /**
     * How many comments would be sent if the chief pressed the button now
     * — what the button says, so nobody starts a transmission blind.
     */
    public function pendingCount(int $targetYearId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $notes = $this->passageNoteRepository->findForYear($targetYearId);
        $pending = 0;

        foreach ($this->reenrollmentRepository->findAnswersForYear($targetYearId) as $memberId => $answer) {
            $comment = $answer->familyComment;
            if ($comment === null || trim($comment) === '') {
                continue;
            }
            if (($notes[$memberId]['ai_source_hash'] ?? null) !== self::hashOf($comment)) {
                $pending++;
            }
        }

        return $pending;
    }

    /**
     * The comment as an identity, never as something to look up by.
     *
     * A plain SHA-256 of the text: it is compared only against a value
     * this table already holds for this member and year, so it is an
     * equality test on our own bookkeeping and not a searchable copy of
     * what a parent wrote (which is what a blind index would be, and what
     * SECURITY.md §5 reserves for a real lookup).
     */
    private static function hashOf(string $comment): string
    {
        return hash('sha256', trim($comment));
    }

    /**
     * One call. Any failure is null — an unavailable model must cost a
     * chief nothing but the absence of a hint.
     *
     * The child is not named and the comment goes alone: the provider is
     * asked to read a sentence, not to know whose it is.
     */
    private function askAbout(string $comment): ?string
    {
        \assert($this->llmConnector !== null);

        try {
            $response = $this->llmConnector->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: $comment,
                systemPrompt: "Tu lis le commentaire libre écrit par une famille sur le formulaire de "
                    . "réinscription d'une unité scoute. Les champs dédiés du formulaire couvrent déjà : "
                    . "la section souhaitée, et jusqu'à trois prénoms d'amis avec qui l'enfant aimerait être. "
                    . "Signale UNIQUEMENT un souhait de placement que ces champs ne couvrent pas. "
                    . "N'interprète pas au-delà du texte, n'invente aucun prénom, et ne signale rien si le "
                    . "commentaire ne parle que de santé, d'horaires, de paiement ou de remerciements.",
                responseSchema: self::RESPONSE_SCHEMA,
            ));
        } catch (LlmException) {
            return null;
        }

        $parsed = $response->parsed;
        if (!is_array($parsed) || ($parsed['has_wish'] ?? false) !== true) {
            return null;
        }

        $summary = $parsed['summary'] ?? null;

        return is_string($summary) && trim($summary) !== '' ? trim($summary) : null;
    }
}
