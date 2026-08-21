<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Ai;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * The optional AI helpers (§6.36) — a nullable dependency, the §7.5 way.
 *
 * **Every method here returns a `Suggestion` and nothing else.** Not a
 * saved value, not a status change, not a decision: the rule §6.36 states
 * is that the AI never, on its own, accepts or refuses a booking, changes
 * an agreed price, blames a renter for damage, withholds a deposit,
 * triggers a refund or moves a final financial status. This class enforces
 * that structurally rather than by discipline — it has no repository, no
 * service and no write path of any kind. It cannot save anything, so it
 * cannot decide anything.
 *
 * **Failures are silence, never errors.** A model that is unavailable,
 * slow, or answering nonsense means the manager types the number
 * themselves, exactly as they did before the feature existed. Nothing in
 * this module has an AI-shaped hole in it.
 *
 * **What is sent leaves the site**, and the RGPD text says so. The methods
 * below therefore send the narrowest thing that can answer the question: a
 * photo of a dial, a filename and a subject line. Never a renter's name,
 * their address or a whole booking record.
 */
class RentalAiAssistant
{
    /**
     * Short, because every one of these is a person waiting at a screen and
     * the fallback — typing it in — costs seconds.
     */
    private const TIMEOUT_SECONDS = 20;

    public function __construct(private ?LlmConnectorInterface $connector = null)
    {
    }

    /**
     * Whether to offer the AI affordances at all. A button that always
     * fails is worse than no button.
     */
    public function isAvailable(): bool
    {
        return $this->connector !== null && $this->connector->isAvailable();
    }

    /**
     * Read a meter index off a photograph of the dial (§6.22, §6.36).
     *
     * The suggestion is placed in the input field for a human to check
     * against the photo they just took — never written to the reading.
     * A misread digit here is a bill that is wrong by a factor of ten.
     *
     * @param string $imageBytes the photograph, as uploaded
     */
    public function readMeter(string $imageBytes, string $mimeType): ?Suggestion
    {
        return $this->ask(
            LlmTier::OCR,
            "Lis l'index affiché sur ce compteur. Réponds uniquement par le nombre affiché, "
                . "avec ses décimales s'il y en a, sans unité et sans phrase. "
                . "Si l'index n'est pas lisible avec certitude, réponds exactement : ILLISIBLE.",
            [['mime_type' => $mimeType, 'data' => $imageBytes]]
        );
    }

    /**
     * Guess what a document is, from its filename and the subject of the
     * email it arrived on (§7.8, §6.36).
     *
     * Suggests a type for a `Non classé` attachment; the manager still
     * presses the button. Deliberately given the filename and subject only
     * — the document's own contents are not sent, because they routinely
     * contain the renter's address and signature.
     *
     * @param string[] $availableTypes the labels a manager may choose from
     */
    public function suggestDocumentType(string $filename, string $emailSubject, array $availableTypes): ?Suggestion
    {
        if ($availableTypes === []) {
            return null;
        }

        return $this->ask(
            LlmTier::CHEAP,
            "Voici le nom d'un fichier reçu par email et l'objet de cet email.\n"
                . "Nom du fichier : " . $filename . "\n"
                . "Objet : " . $emailSubject . "\n\n"
                . "Parmi ces catégories, laquelle correspond le mieux ? "
                . implode(', ', $availableTypes) . ".\n"
                . "Réponds uniquement par la catégorie, telle qu'écrite ci-dessus. "
                . "Si aucune ne convient clairement, réponds exactement : INCERTAIN."
        );
    }

    /**
     * Which of several candidate bookings an email is about (§7.6, level 4).
     *
     * **Only where levels 1 to 3 failed or came back ambiguous**, which is
     * the ordering §7.6 fixes: if the headers can settle it, the model is
     * never asked. And the answer is still a suggestion — an email attached
     * on a model's say-so carries `LinkOrigin::AI`, which the interface
     * labels as uncertain.
     *
     * @param string[] $candidateReferences
     */
    public function resolveEmailAmbiguity(
        string $subject,
        string $bodyExcerpt,
        array $candidateReferences
    ): ?Suggestion {
        if (count($candidateReferences) < 2) {
            // Nothing ambiguous to resolve. Asking anyway would spend a
            // call to be told what the headers already said.
            return null;
        }

        return $this->ask(
            LlmTier::CHEAP,
            "Un email est arrivé au sujet d'une location, sans référence explicite.\n"
                . "Objet : " . $subject . "\n"
                . "Extrait : " . mb_substr($bodyExcerpt, 0, 2000) . "\n\n"
                . "Il pourrait concerner l'une de ces réservations : "
                . implode(', ', $candidateReferences) . ".\n"
                . "Réponds uniquement par la référence la plus probable. "
                . "Si rien ne permet de trancher, réponds exactement : INCERTAIN."
        );
    }

    /**
     * A short summary of a thread, for a manager picking up somebody
     * else's file (§6.36).
     */
    public function summariseThread(string $conversation): ?Suggestion
    {
        if (trim($conversation) === '') {
            return null;
        }

        return $this->ask(
            LlmTier::CHEAP,
            "Résume en trois phrases maximum l'échange ci-dessous, du point de vue du gestionnaire "
                . "de la location. Ne propose aucune action et ne tire aucune conclusion : "
                . "résume ce qui a été dit.\n\n" . mb_substr($conversation, 0, 8000)
        );
    }

    /**
     * What a request does not say, so a manager can ask for it in one
     * message rather than three (§6.36).
     */
    public function missingRequestInformation(string $requestSummary): ?Suggestion
    {
        return $this->ask(
            LlmTier::CHEAP,
            "Voici une demande de location. Liste, en une phrase par point, les informations "
                . "manquantes qu'un gestionnaire devrait demander avant de confirmer. "
                . "N'invente rien et ne juge pas la demande.\n\n" . mb_substr($requestSummary, 0, 4000)
        );
    }

    /**
     * One call, one answer, and nothing thrown.
     *
     * `INCERTAIN` and `ILLISIBLE` come back as null rather than as text: a
     * model that says it does not know is more useful as an absent
     * suggestion than as a suggestion reading "INCERTAIN", which a hurried
     * manager could accept.
     *
     * @param array<int, array{mime_type: string, data: string}> $attachments
     */
    private function ask(LlmTier $tier, string $prompt, array $attachments = []): ?Suggestion
    {
        if ($this->connector === null || !$this->connector->isTierAvailable($tier)) {
            return null;
        }

        try {
            $response = $this->connector->complete(new LlmRequest(
                tier: $tier,
                prompt: $prompt,
                attachments: $attachments,
                systemPrompt: 'Tu assistes un gestionnaire de location d\'un local scout. '
                    . 'Tu proposes, tu ne décides jamais. Réponds de la manière la plus brève possible.',
                timeoutSeconds: self::TIMEOUT_SECONDS
            ));
        } catch (LlmException | \RuntimeException) {
            // Silence, not an error: the manager types it themselves, as
            // they did before this feature existed.
            return null;
        }

        $value = trim($response->content);
        if ($value === '' || self::saysItDoesNotKnow($value)) {
            return null;
        }

        return new Suggestion($value);
    }

    private static function saysItDoesNotKnow(string $value): bool
    {
        $upper = mb_strtoupper($value);

        return str_contains($upper, 'INCERTAIN') || str_contains($upper, 'ILLISIBLE');
    }
}
