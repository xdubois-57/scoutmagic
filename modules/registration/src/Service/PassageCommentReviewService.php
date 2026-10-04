<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Service\TextNormalizerService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\Registration\Repository\PassageNoteRepository;
use Modules\Registration\Repository\ReenrollmentRepository;

/**
 * The AI re-reading of what families wrote in free text (roadmap IT-17,
 * spec §13), run as the first step of « Optimiser la répartition »
 * (issue #733).
 *
 * A family answers with a section and up to three names. Some of them also
 * write a sentence — « on aimerait qu'il reste avec les copains de sa
 * patrouille » — that says something the dedicated fields never captured.
 * This asks a model what the sentence asks for, in a SHAPE the optimiser
 * can use: a section and the names of friends, which are then resolved
 * here, locally, against ScoutMagic's own data.
 *
 * **Optional dependency, nullable, degrading silently** (ARCHITECTURE.md
 * §7.5). Without the `llm_connector` module, or with no active provider,
 * `isAvailable()` is false and the optimisation runs on what is already
 * known — no error, no mention of a feature the unit does not have.
 *
 * **A chief asks; the site never sends on its own.** The re-reading runs
 * only when a chief presses « Répartir », never on page load — a family
 * comment sent to an external provider is a TRANSMISSION of personal data
 * (`AGENTS.md`, RGPD section), and the optimisation dialog says so before
 * the button is pressed. And it is targeted: only the comments of the
 * people that run is about to place, so a child who is not changing
 * branch never has their family's words sent anywhere for it.
 *
 * **One call per comment.** The source hash below is compared before any
 * call: a comment already read is never sent again, and a family who
 * EDITED theirs is read again exactly once. A call that fails is not
 * recorded as read, so the next run tries again.
 *
 * **Nothing ambiguous becomes a choice.** A section name that matches no
 * section of the arrival branch, or more than one, is dropped; a name that
 * matches no member, or several, is dropped. The optimiser ranks what
 * remains after the staff's choice and the family's own fields, and
 * negative wishes (« surtout pas avec X ») stay out of scope: free text a
 * chief reads.
 */
class PassageCommentReviewService
{
    /**
     * Part of the hash, so a comment read before the reading had a
     * structured half (issue #733) is read once more and gets one.
     */
    private const READING_VERSION = 'v2';

    /** Below this, « contains » is a coincidence rather than a match. */
    private const MIN_PARTIAL_LENGTH = 4;

    /** More friends than a form offers would be the model inventing them. */
    private const MAX_FRIENDS = 5;

    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'has_wish' => [
                'type' => 'boolean',
                'description' => 'true si le commentaire exprime un souhait de placement '
                    . "(être avec quelqu'un, aller dans une section précise).",
            ],
            'summary' => [
                'type' => ['string', 'null'],
                'description' => 'Si has_wish est true, une phrase française courte disant ce que la famille '
                    . 'demande, sans interpréter au-delà du texte. null sinon.',
            ],
            'section' => [
                'type' => ['string', 'null'],
                'description' => 'La section demandée, recopiée parmi les sections possibles données dans le '
                    . "contexte. null si le commentaire n'en demande aucune ou si ce n'est pas clair.",
            ],
            'friends' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => "Les prénoms (et noms ou totems s'ils sont écrits) des personnes avec qui "
                    . "l'enfant souhaite être, tels qu'écrits dans le commentaire. Vide sinon.",
            ],
        ],
        'required' => ['has_wish', 'summary', 'section', 'friends'],
    ];

    public function __construct(
        private ReenrollmentRepository $reenrollmentRepository,
        private PassageNoteRepository $passageNoteRepository,
        private ReenrollmentService $reenrollmentService,
        private ?LlmConnectorInterface $llmConnector = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * Read the comments of the people about to be placed that have not
     * been read yet, and return how many were read.
     *
     * @param array<int, array{branch_id: ?int, sections: array<int, array<string, mixed>>}> $arrivals
     *        keyed by member id: the branch each one arrives in and the
     *        sections they may be placed in — what a section name and a
     *        friend's name are resolved against
     * @param int $currentYearId the year friends are looked for in
     */
    public function reviewArrivals(int $targetYearId, int $currentYearId, array $arrivals): int
    {
        if ($arrivals === []) {
            return 0;
        }

        $available = $this->isAvailable();
        $notes = $this->passageNoteRepository->findForYear($targetYearId);
        $reviewed = 0;

        foreach ($this->reenrollmentRepository->findAnswersForYear($targetYearId) as $memberId => $answer) {
            if (!isset($arrivals[$memberId])) {
                continue;
            }

            $comment = $answer->familyComment;
            if ($comment === null || trim($comment) === '') {
                // A comment the family took back takes its reading with it:
                // a withdrawn wish must stop steering the placement. No AI
                // is needed to forget, so this runs with the connector off.
                $storedHash = $notes[$memberId]['ai_source_hash'] ?? null;
                if ($storedHash !== null && $storedHash !== self::hashOf('')) {
                    $this->passageNoteRepository->setAiSuggestion($memberId, $targetYearId, self::hashOf(''), null);
                }
                continue;
            }
            if (!$available) {
                continue;
            }

            $hash = self::hashOf($comment);
            if (($notes[$memberId]['ai_source_hash'] ?? null) === $hash) {
                continue;
            }

            $arrival = $arrivals[$memberId];
            $reading = $this->askAbout($comment, $arrival['sections']);
            if ($reading === null) {
                // Not recorded as read: the next run asks again.
                continue;
            }

            $this->passageNoteRepository->setAiSuggestion(
                $memberId,
                $targetYearId,
                $hash,
                $reading['summary'],
                self::resolveSection($reading['section'], $arrival['sections']),
                $this->resolveFriends(
                    $reading['friends'],
                    $memberId,
                    $arrival['branch_id'],
                    $currentYearId,
                    $targetYearId
                )
            );
            $reviewed++;
        }

        return $reviewed;
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
        return hash('sha256', self::READING_VERSION . "\n" . trim($comment));
    }

    /**
     * The section the model named, if it is exactly one section of the
     * arrival branch. Exact name or code first; failing that, a section
     * name contained in what the model wrote, or the reverse — but only
     * when a single section fits.
     *
     * @param array<int, array<string, mixed>> $sections
     */
    private static function resolveSection(?string $named, array $sections): ?int
    {
        $needle = TextNormalizerService::fold($named ?? '');
        if ($needle === '') {
            return null;
        }

        $exact = [];
        $partial = [];
        foreach ($sections as $section) {
            $id = (int) ($section['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = TextNormalizerService::fold((string) ($section['name'] ?? ''));
            $code = TextNormalizerService::fold((string) ($section['desk_code'] ?? ''));

            if ($needle === $name || $needle === $code) {
                $exact[$id] = true;
            } elseif (
                // Names only, never the short Desk code: « LA » is inside
                // « éclaireurs », and that is no reason to place anybody.
                mb_strlen($name) >= self::MIN_PARTIAL_LENGTH
                && mb_strlen($needle) >= self::MIN_PARTIAL_LENGTH
                && (str_contains($needle, $name) || str_contains($name, $needle))
            ) {
                $partial[$id] = true;
            }
        }

        if (count($exact) === 1) {
            return (int) array_key_first($exact);
        }
        if ($exact === [] && count($partial) === 1) {
            return (int) array_key_first($partial);
        }

        return null;
    }

    /**
     * The names the model found, each kept only when the module's own
     * matcher finds exactly one member for it among the people of the
     * arrival branch — the same rule as the family's typed names.
     *
     * @param array<int, string> $names
     * @return array<int, int>
     */
    private function resolveFriends(
        array $names,
        int $memberId,
        ?int $branchId,
        int $currentYearId,
        int $targetYearId
    ): array {
        $ids = [];
        foreach (array_slice($names, 0, self::MAX_FRIENDS) as $name) {
            $candidates = $this->reenrollmentService->candidatesFor(
                $name,
                $currentYearId,
                $branchId,
                $targetYearId,
                $memberId
            );
            if (count($candidates) === 1) {
                $ids[] = $candidates[0]['member_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * One call. Any failure is null — an unavailable model must cost a
     * chief nothing but the absence of a hint.
     *
     * The child is not named and the comment goes alone: the provider is
     * asked to read a sentence, not to know whose it is. The section names
     * go with it — they are the unit's, not anybody's personal data, and
     * they are what lets the answer be resolved.
     *
     * @param array<int, array<string, mixed>> $sections
     * @return array{summary: ?string, section: ?string, friends: array<int, string>}|null
     */
    private function askAbout(string $comment, array $sections): ?array
    {
        \assert($this->llmConnector !== null);

        $sectionNames = [];
        foreach ($sections as $section) {
            $label = trim((string) ($section['name'] ?? $section['desk_code'] ?? ''));
            if ($label !== '') {
                $sectionNames[] = $label;
            }
        }

        try {
            $response = $this->llmConnector->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: $comment,
                systemPrompt: "Tu lis le commentaire libre écrit par une famille sur le formulaire de "
                    . "réinscription d'une unité scoute, pour aider à répartir l'enfant entre les sections. "
                    . "Relève uniquement un souhait de placement : une section demandée, ou des personnes avec "
                    . "qui l'enfant aimerait être. N'interprète pas au-delà du texte, n'invente aucun prénom, "
                    . "ignore les souhaits de ne PAS être avec quelqu'un, et ne relève rien si le commentaire "
                    . "ne parle que de santé, d'horaires, de paiement ou de remerciements."
                    . ($sectionNames === [] ? '' : ' Sections possibles : ' . implode(', ', $sectionNames) . '.'),
                responseSchema: self::RESPONSE_SCHEMA,
            ));
        } catch (LlmException) {
            return null;
        }

        $parsed = $response->parsed;
        if (!is_array($parsed)) {
            return null;
        }
        if (($parsed['has_wish'] ?? false) !== true) {
            return ['summary' => null, 'section' => null, 'friends' => []];
        }

        $summary = $parsed['summary'] ?? null;
        // No summary, no wish: what steers the placement must be what the
        // page shows under the line, and the page shows the summary.
        if (!is_string($summary) || trim($summary) === '') {
            return ['summary' => null, 'section' => null, 'friends' => []];
        }
        $section = $parsed['section'] ?? null;
        $friends = [];
        foreach (is_array($parsed['friends'] ?? null) ? $parsed['friends'] : [] as $name) {
            if (is_string($name) && trim($name) !== '') {
                $friends[] = trim($name);
            }
        }

        return [
            'summary' => trim($summary),
            'section' => is_string($section) && trim($section) !== '' ? trim($section) : null,
            'friends' => $friends,
        ];
    }
}
