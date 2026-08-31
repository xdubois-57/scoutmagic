<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Assistant;

use Core\Help\HelpException;
use Core\Help\HelpService;
use Core\Help\HelpTopic;
use Core\Journal\JournalService;
use Core\Security\Role;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * The help assistant: a question in French, an answer written only from
 * the help topics this reader may see.
 *
 * ---------------------------------------------------------------------
 * What it never does
 * ---------------------------------------------------------------------
 * **It never reads the database.** No member, no section, no amount, not
 * aggregated and not anonymised, ever enters a prompt. What it is given
 * is the shipped documentation and the question — which is why this whole
 * feature needed no RGPD change beyond naming the endpoint: nothing about
 * the unit leaves the installation that was not already in the release.
 * There is no tool-calling here and no SQL, deliberately, and adding
 * either would be a different feature with a different privacy answer.
 *
 * **It never journals the question.** It is free text a human typed and
 * can hold a name, an address, an amount. The journal gets counters and
 * the ids that were selected (SECURITY.md §11).
 *
 * ---------------------------------------------------------------------
 * Two calls, and why
 * ---------------------------------------------------------------------
 * 1. **Selection**, on the CHEAP tier: pick a few ids out of the
 *    role-filtered catalogue (~120 short lines). A cheap model is enough
 *    to match a question to a title and a `question:` line, and this is
 *    the call that runs on every request.
 * 2. **Answering**, on the CAPABLE tier, over the BODIES of the few
 *    topics selected. This is where the wording matters, and it is only
 *    reached once the corpus has something to say.
 *
 * Between the two, **every id the model returned is re-resolved through
 * HelpService::findById() at the caller's own role**. That is the gate,
 * not the catalogue: an id the model invented, or one it echoed from
 * somewhere it should not have, resolves to null and disappears in
 * silence. The catalogue makes the right answer easy; findById() makes
 * the wrong one impossible.
 */
class AssistantService
{
    /**
     * How many topics the selection may return. Enough for a question
     * that genuinely spans two or three screens, small enough that the
     * answering call carries bodies rather than a corpus.
     */
    public const MAX_SELECTED_TOPICS = 3;

    /** Longer than this and it is not a question, it is a paste. */
    public const MAX_QUESTION_LENGTH = 500;

    /** Questions one account may ask per window. */
    public const QUOTA_PER_WINDOW = 20;
    public const QUOTA_WINDOW_MINUTES = 60;

    /**
     * The answering call's ceiling. Five sentences of French fit well
     * inside it; a response that hits it comes back `truncated`, which
     * this service treats as a failure rather than as half an answer.
     */
    private const ANSWER_MAX_TOKENS = 500;

    private const SELECTION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'ids' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => "Les identifiants des sujets d'aide qui répondent à la question, du plus "
                    . "pertinent au moins pertinent, au maximum " . self::MAX_SELECTED_TOPICS . ". "
                    . "Tableau vide si aucun sujet de la liste ne répond à la question.",
            ],
        ],
        'required' => ['ids'],
    ];

    public function __construct(
        private readonly HelpService $helpService,
        private readonly AssistantCatalog $catalog,
        private readonly AssistantRateLimitRepository $rateLimits,
        private readonly AssistantCacheRepository $cache,
        private readonly JournalService $journal,
        private readonly string $appVersion,
        // The connector is a MODULE capability consumed optionally
        // (ARCHITECTURE.md §7.5): with llm_connector disabled this is
        // null, the only name core ever autoloads is the Api\ interface,
        // and isAvailable() below answers false instead of throwing.
        private readonly ?LlmConnectorInterface $llmConnector = null,
    ) {
    }

    /** Memoised isAvailable() answer for this request — see below. */
    private ?bool $available = null;

    /**
     * Whether the assistant can be offered at all — the connector is
     * wired AND both tiers it needs have a model.
     *
     * There is deliberately no setting behind this (locked decision D7):
     * a unit that configured an AI provider has said yes, and a second
     * switch would only ever be a way to have configured one and still
     * not have the feature. Never throws: callers ask this while
     * rendering a page.
     */
    public function isAvailable(): bool
    {
        // Memoised for the request: the help panel ships on every page and
        // asks this once per render, and each tier check is a provider row
        // plus a model row. Nothing can enable a provider mid-request, so
        // the answer cannot change between two calls in the same one.
        if ($this->available !== null) {
            return $this->available;
        }

        return $this->available = $this->llmConnector !== null
            && $this->llmConnector->isTierAvailable(LlmTier::CHEAP)
            && $this->llmConnector->isTierAvailable(LlmTier::CAPABLE);
    }

    /**
     * Answer one question.
     *
     * @param string $question the reader's own words
     * @param Role $role the reader's CURRENT role — the catalogue and
     *        every id check are made against it, never against a role
     *        carried in the request
     * @param int $userAccountId whose quota this spends
     * @param array<int, array{question: string, answer: string}> $history
     *        the last few exchanges of this conversation, for follow-up
     *        questions ("et pour une autre section ?"). Session-only,
     *        never stored (locked decision D5).
     * @param string|null $currentPath the page the reader is on, and
     * @param string[] $routeTopicIds the topics covering it — the anchor
     *        that makes "comment je fais ça ?" answerable at all.
     *
     * @throws AssistantException when the quota is spent or the question
     *         is unusable — a French sentence, showable as it comes.
     * @throws LlmException when the model or the connector fails; its
     *         message is already user-facing and must NOT be re-wrapped
     *         (AGENTS.md § Exception messages).
     */
    public function ask(
        string $question,
        Role $role,
        int $userAccountId,
        array $history = [],
        ?string $currentPath = null,
        array $routeTopicIds = [],
    ): AssistantAnswer {
        $question = trim($question);
        if ($question === '') {
            throw new AssistantException('Posez une question pour que l\'assistant puisse chercher.');
        }
        if (mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            throw new AssistantException(
                'Votre question est trop longue. Reformulez-la en une ou deux phrases.'
            );
        }
        if ($this->llmConnector === null) {
            throw new AssistantException(
                "L'assistant n'est pas disponible sur ce site. La recherche dans l'aide, elle, fonctionne toujours."
            );
        }

        // The cache is consulted BEFORE the quota is charged: serving an
        // answer that cost nothing must not spend somebody's allowance.
        $fingerprint = AssistantCacheRepository::fingerprint($question, $role->value, $this->appVersion);
        $cached = $this->cache->find($fingerprint);
        if ($cached !== null) {
            $topics = $this->resolve($cached['topic_ids'], $role);
            $this->log($role, $topics, 0, 0, true);

            return new AssistantAnswer($cached['answer'], $topics, $cached['answer'] === '', true);
        }

        $this->enforceQuota($userAccountId);

        [$selection, $selectionTokens] = $this->select($question, $role, $history, $currentPath, $routeTopicIds);
        $topics = $this->resolve($selection, $role);

        if ($topics === []) {
            // A real outcome, not an error: the corpus does not cover
            // this. Cached like any other answer — asking twice must not
            // cost twice just because the answer was "nothing".
            $this->cache->store($fingerprint, '', []);
            $this->log($role, [], $selectionTokens, 0, false);

            return new AssistantAnswer('', [], true);
        }

        [$text, $answerTokens] = $this->answer($question, $topics, $history);

        $this->cache->store($fingerprint, $text, array_map(static fn (HelpTopic $t): string => $t->id, $topics));
        $this->log($role, $topics, $selectionTokens, $answerTokens, false);

        return new AssistantAnswer($text, $topics, false);
    }

    /**
     * @throws AssistantException when the window is full
     */
    private function enforceQuota(int $userAccountId): void
    {
        $since = (new \DateTimeImmutable('-' . self::QUOTA_WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
        if ($this->rateLimits->countSince($userAccountId, $since) >= self::QUOTA_PER_WINDOW) {
            throw new AssistantException(
                "Vous avez posé beaucoup de questions à l'assistant en peu de temps. Réessayez dans une heure — "
                . "la recherche dans l'aide reste disponible entre-temps."
            );
        }

        // Recorded before the call, not after: a request that fails at
        // the provider still consumed one, and a failing provider must
        // not become an unlimited retry loop.
        $this->rateLimits->record($userAccountId);
    }

    /**
     * The selection call.
     *
     * @param array<int, array{question: string, answer: string}> $history
     * @param string[] $routeTopicIds
     * @return array{0: string[], 1: int} the ids the model returned, and the tokens spent
     */
    private function select(
        string $question,
        Role $role,
        array $history,
        ?string $currentPath,
        array $routeTopicIds,
    ): array {
        $catalogue = $this->catalog->forRole($role);
        if (trim($catalogue) === '') {
            return [[], 0];
        }

        $prompt = "Voici la liste des sujets d'aide disponibles, un par ligne, "
            . "au format `identifiant | titre | résumé | questions auxquelles il répond` :\n\n"
            . $catalogue . "\n\n";

        // The anchor: where the reader is standing. « Comment je fais
        // ça ? » is answerable on the page that documents « ça » and
        // nowhere else, and this is what tells the model which page that
        // is. It is a hint, never a constraint — a reader on the calendar
        // may perfectly well ask about payments.
        if ($currentPath !== null && $currentPath !== '') {
            $prompt .= "La personne consulte actuellement la page `{$currentPath}`";
            if ($routeTopicIds !== []) {
                $prompt .= ", que documentent les sujets : " . implode(', ', $routeTopicIds);
            }
            $prompt .= ". Tenez-en compte si la question est ambiguë, sans vous y limiter.\n\n";
        }

        if ($history !== []) {
            $prompt .= "Échanges précédents de cette conversation :\n" . self::formatHistory($history) . "\n\n";
        }

        $prompt .= "Question : " . $question . "\n\n"
            . "Répondez uniquement par les identifiants des sujets de la liste qui permettent d'y répondre, "
            . "au maximum " . self::MAX_SELECTED_TOPICS . ", du plus pertinent au moins pertinent. "
            . "N'inventez aucun identifiant. Si aucun sujet de la liste ne répond, renvoyez une liste vide.";

        $response = $this->llmConnector?->complete(new LlmRequest(
            tier: LlmTier::CHEAP,
            prompt: $prompt,
            systemPrompt: "Vous aidez à retrouver le bon sujet d'aide dans la documentation d'un site scout. "
                . "Vous ne choisissez que parmi les identifiants de la liste fournie.",
            responseSchema: self::SELECTION_SCHEMA,
        ));
        if ($response === null) {
            return [[], 0];
        }

        $ids = [];
        $parsed = $response->parsed;
        if (is_array($parsed) && isset($parsed['ids']) && is_array($parsed['ids'])) {
            foreach ($parsed['ids'] as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return [array_slice($ids, 0, self::MAX_SELECTED_TOPICS), $response->inputTokens + $response->outputTokens];
    }

    /**
     * Every id, re-resolved at this reader's role. This is the gate.
     *
     * @param string[] $ids
     * @return HelpTopic[]
     */
    private function resolve(array $ids, Role $role): array
    {
        $topics = [];
        foreach ($ids as $id) {
            $topic = $this->helpService->findById($id, $role);
            // Null covers both "no such topic" and "above this role", and
            // they are deliberately indistinguishable here as everywhere
            // else in Core\Help. Either way it silently does not exist.
            if ($topic !== null && !isset($topics[$topic->id])) {
                $topics[$topic->id] = $topic;
            }
        }

        return array_values($topics);
    }

    /**
     * The answering call, over the bodies of the selected topics.
     *
     * @param HelpTopic[] $topics
     * @param array<int, array{question: string, answer: string}> $history
     * @return array{0: string, 1: int} the answer, and the tokens spent
     * @throws AssistantException when the model ran into the length cap
     */
    private function answer(string $question, array $topics, array $history): array
    {
        $sections = [];
        foreach ($topics as $topic) {
            try {
                $body = $topic->body();
            } catch (HelpException $e) {
                // The file parsed at the start of the request and is gone
                // or truncated now — an update copying over the live
                // install is exactly that race (HelpRegistry::load()).
                // One unreadable topic costs itself, not the answer.
                continue;
            }
            $sections[] = "## " . $topic->title . "\n\n" . $body;
        }
        if ($sections === []) {
            return ['', 0];
        }

        $prompt = "Voici les sujets d'aide retenus :\n\n" . implode("\n\n---\n\n", $sections) . "\n\n";
        if ($history !== []) {
            $prompt .= "Échanges précédents de cette conversation :\n" . self::formatHistory($history) . "\n\n";
        }
        $prompt .= "Question : " . $question;

        $response = $this->llmConnector?->complete(new LlmRequest(
            tier: LlmTier::CAPABLE,
            prompt: $prompt,
            systemPrompt: self::answerSystemPrompt(),
            maxTokens: self::ANSWER_MAX_TOKENS,
        ));
        if ($response === null) {
            return ['', 0];
        }

        // A response that ran into the ceiling is half a sentence, not a
        // short answer, and half a sentence about a procedure is worse
        // than none. An AssistantException rather than an LlmException:
        // the connector did its job, the answer was simply too long for
        // the cap this service set, and core does not throw a module's
        // contract exception on the module's behalf.
        if ($response->truncated) {
            throw new AssistantException(
                "L'assistant n'a pas réussi à formuler une réponse complète. Reformulez votre question, "
                . "ou ouvrez directement les sujets d'aide proposés."
            );
        }

        return [trim($response->content), $response->inputTokens + $response->outputTokens];
    }

    /**
     * The rules the answering model writes under.
     *
     * The formatting half is not style: `Core\View\MarkdownRenderer`
     * understands headings, paragraphs, bullet and numbered lists, bold,
     * italic and inline code — and nothing else. A table, a code fence or
     * a link comes out as its own raw source text on the page, so the
     * model is told not to produce one rather than left to discover it.
     */
    private static function answerSystemPrompt(): string
    {
        return implode(' ', [
            "Vous répondez à un animateur ou un chef d'unité scoute, en français, à partir des sujets d'aide fournis",
            "et d'eux seuls.",
            "N'inventez jamais un libellé de bouton, un nom de page ni une étape : si les sujets fournis ne suffisent",
            "pas, dites-le franchement et invitez la personne à consulter les sujets proposés.",
            "Vouvoiement, phrases courtes, voix active, le ton d'un collègue qui explique.",
            "Dites « animé », « animateur », « chef d'unité », « Staff d'Unité » — jamais « chef » seul,",
            "jamais « utilisateur ».",
            "Cinq phrases au maximum.",
            "Pas de tableau, pas de bloc de code, aucun lien : uniquement du texte, des listes à puces ou numérotées,",
            "et du gras si nécessaire.",
        ]);
    }

    /**
     * @param array<int, array{question: string, answer: string}> $history
     */
    private static function formatHistory(array $history): string
    {
        $lines = [];
        foreach ($history as $exchange) {
            $lines[] = "Q : " . trim($exchange['question']);
            $lines[] = "R : " . trim($exchange['answer']);
        }

        return implode("\n", $lines);
    }

    /**
     * Counters and ids, never the question.
     *
     * @param HelpTopic[] $topics
     */
    private function log(Role $role, array $topics, int $selectionTokens, int $answerTokens, bool $fromCache): void
    {
        $this->journal->log(
            'core',
            'help_assistant_query',
            'info',
            "Question posée à l'assistant d'aide",
            [
                'role' => $role->value,
                'topic_ids' => array_map(static fn (HelpTopic $t): string => $t->id, $topics),
                'selection_tokens' => $selectionTokens,
                'answer_tokens' => $answerTokens,
                'cache' => $fromCache ? 'hit' : 'miss',
            ],
            null
        );
    }
}
