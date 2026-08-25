<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Modules\Camps\Repository\Place;
use Core\Service\TextNormalizerService;
use Modules\Camps\Repository\PlaceRepository;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * "Ce lieu semble peut-être déjà exister."
 *
 * A unit re-books the same field every few years, and the person encoding
 * it in 2031 was not the one who encoded it in 2026. "Domaine de Mozet",
 * "domaine de mozet", "Mozet (domaine)" and "Domaine de Mozet asbl" are
 * one plot of land and four rows, and once they are four rows nobody ever
 * merges them.
 *
 * Two levels, in this order:
 *
 * 1. TEXTUAL, always. Normalise through Core\Service\TextNormalizerService
 *    — the site's own normaliser, so "Éclaireurs" and "eclaireurs" fold
 *    the same way here as everywhere else — then compare, tolerating typos
 *    with PHP's native levenshtein(). No new dependency.
 * 2. AI, only when level 1 is INCONCLUSIVE and llm_connector is enabled.
 *    Optional and nullable: without it, level 1 stands alone.
 *
 * The AI never merges anything. It answers one question — do these two
 * names and addresses designate the same place — and a human accepts or
 * refuses. That boundary is the whole reason this is safe to run on
 * creation.
 */
class DuplicatePlaceDetector
{
    /**
     * Edit distance below which two normalised names are "the same, with
     * a typo". Deliberately generous on long names and strict on short
     * ones (a ratio, not a constant): two characters apart is a slip in
     * "Domaine de Mozet" and a different place entirely in "Ry".
     */
    private const NAME_DISTANCE_RATIO = 0.2;

    /** levenshtein() is O(n·m) and refuses strings past 255 bytes. */
    private const MAX_COMPARABLE = 200;

    /**
     * Below this, a name is too generic for containment to mean anything:
     * "Ferme" is inside half the camp sites in Wallonia.
     */
    private const MIN_CONTAINED_LENGTH = 8;

    public function __construct(
        private PlaceRepository $places,
        private ?LlmConnectorInterface $llm = null
    ) {
    }

    /**
     * Places that might already be the one being created.
     *
     * @param array<string, string|null> $fields
     * @return array<int, array{place: Place, certainty: string, reason: string}>
     *         certainty: 'certain' (same normalised name and locality) or
     *         'possible' (a typo away, or the AI thinks so)
     */
    public function findCandidates(array $fields): array
    {
        $name = $this->normaliseName((string) ($fields['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $city = $this->normaliseName((string) ($fields['city'] ?? ''));
        $postalCode = preg_replace('/\s+/', '', (string) ($fields['postal_code'] ?? '')) ?? '';
        $address = $this->normaliseAddress((string) ($fields['address'] ?? ''));

        $matches = [];
        $undecided = [];

        foreach ($this->places->findAllVisible() as $place) {
            $otherName = $this->normaliseName($place->name);
            $otherCity = $this->normaliseName($place->city ?? '');
            $otherPostal = preg_replace('/\s+/', '', $place->postalCode ?? '') ?? '';
            $otherAddress = $this->normaliseAddress($place->address ?? '');

            $sameLocality = ($city !== '' && $city === $otherCity)
                || ($postalCode !== '' && $postalCode === $otherPostal);

            if ($otherName === $name) {
                $matches[] = [
                    'place' => $place,
                    'certainty' => $sameLocality || $city === '' || $otherCity === '' ? 'certain' : 'possible',
                    'reason' => 'Même nom' . ($sameLocality ? ' et même localité' : ''),
                ];
                continue;
            }

            if ($address !== '' && $address === $otherAddress) {
                $matches[] = ['place' => $place, 'certainty' => 'certain', 'reason' => 'Même adresse'];
                continue;
            }

            // One name contained in the other at a word boundary:
            // "Domaine de Mozet" vs "Domaine de Mozet asbl", or "Ferme du
            // Moulin" vs "Ferme du Moulin Vielsalm". A very common way for
            // one plot of land to acquire two rows, and much too far apart
            // for the typo test below.
            if ($this->isOneInsideTheOther($name, $otherName)) {
                $matches[] = [
                    'place' => $place,
                    'certainty' => $sameLocality ? 'certain' : 'possible',
                    'reason' => 'Nom contenu dans l\'autre'
                        . ($sameLocality ? ', même localité' : ''),
                ];
                continue;
            }

            if ($this->isTypoAway($name, $otherName)) {
                $matches[] = [
                    'place' => $place,
                    'certainty' => 'possible',
                    'reason' => 'Nom très proche' . ($sameLocality ? ', même localité' : ''),
                ];
                continue;
            }

            // Level 1 could not decide: same town, different-looking
            // name. That is exactly the shape "Ferme du Moulin" vs
            // "Chez Delvaux" takes when they are the same farm.
            if ($sameLocality) {
                $undecided[] = $place;
            }
        }

        if ($matches === [] && $undecided !== []) {
            $matches = $this->askAi($fields, $undecided);
        }

        return $matches;
    }

    /**
     * Level 2. Returns an empty list — never an exception — when the
     * connector is absent, disabled, or fails: a duplicate hint that
     * breaks the creation form would be worse than no hint.
     *
     * @param array<string, string|null> $fields
     * @param Place[] $candidates
     * @return array<int, array{place: Place, certainty: string, reason: string}>
     */
    private function askAi(array $fields, array $candidates): array
    {
        // The tier this method actually asks for, not "is anything
        // configured": an install with a model on `capable` and none on
        // `cheap` passes isAvailable() and gets refused inside complete().
        if ($this->llm === null || !$this->llm->isTierAvailable(LlmTier::CHEAP)) {
            return [];
        }

        $subject = $this->describe(
            (string) ($fields['name'] ?? ''),
            (string) ($fields['address'] ?? ''),
            (string) ($fields['postal_code'] ?? ''),
            (string) ($fields['city'] ?? '')
        );

        $lines = [];
        foreach ($candidates as $index => $place) {
            $lines[] = $index . '. ' . $this->describe(
                $place->name,
                $place->address ?? '',
                $place->postalCode ?? '',
                $place->city ?? ''
            );
        }

        try {
            $response = $this->llm->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: "Lieu à créer :\n{$subject}\n\nLieux déjà connus :\n" . implode("\n", $lines),
                systemPrompt: 'Tu compares des terrains de camp scouts en Belgique. '
                    . 'Réponds uniquement avec les numéros des lieux déjà connus qui désignent '
                    . 'très probablement le MÊME terrain que le lieu à créer — même ferme, même pré, '
                    . 'même domaine, écrit autrement. Deux terrains différents dans la même commune '
                    . 'ne sont PAS le même lieu. Dans le doute, ne réponds rien.',
                responseSchema: [
                    'type' => 'object',
                    'properties' => [
                        'matches' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                    'required' => ['matches'],
                ],
            ));
        } catch (LlmException) {
            return [];
        }

        $indexes = $response->parsed['matches'] ?? [];
        if (!is_array($indexes)) {
            return [];
        }

        $matches = [];
        foreach ($indexes as $index) {
            if (!is_int($index) || !isset($candidates[$index])) {
                continue;
            }
            $matches[] = [
                'place' => $candidates[$index],
                // Never 'certain'. A suggestion a human accepts or
                // refuses is the only thing the AI is allowed to produce
                // here.
                'certainty' => 'possible',
                'reason' => 'Rapprochement suggéré automatiquement',
            ];
        }

        return $matches;
    }

    private function describe(string $name, string $address, string $postalCode, string $city): string
    {
        $parts = array_values(array_filter(
            [$name, $address, trim($postalCode . ' ' . $city)],
            static fn(string $p): bool => trim($p) !== ''
        ));

        return implode(' — ', $parts);
    }

    /**
     * Whether one folded name is the start of the other, at a word
     * boundary.
     *
     * The length floor matters: without it "Ry" would match every place
     * whose name happens to begin with those letters, and a suggestion
     * that fires on everything is a suggestion nobody reads.
     */
    private function isOneInsideTheOther(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || $a === $b) {
            return false;
        }

        $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $longer = $shorter === $a ? $b : $a;
        if (mb_strlen($shorter) < self::MIN_CONTAINED_LENGTH) {
            return false;
        }

        return str_starts_with($longer, $shorter . ' ');
    }

    /**
     * Two normalised names one small edit apart. Guarded on length
     * because levenshtein() refuses strings past 255 bytes and is O(n·m).
     */
    private function isTypoAway(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || strlen($a) > self::MAX_COMPARABLE || strlen($b) > self::MAX_COMPARABLE) {
            return false;
        }

        $allowed = (int) floor(min(mb_strlen($a), mb_strlen($b)) * self::NAME_DISTANCE_RATIO);
        if ($allowed < 1) {
            return false;
        }

        return levenshtein($a, $b) <= $allowed;
    }

    private function normaliseName(string $raw): string
    {
        return $this->fold(TextNormalizerService::normalizeName($raw));
    }

    private function normaliseAddress(string $raw): string
    {
        return $this->fold(TextNormalizerService::normalizeAddress($raw));
    }

    /**
     * Case, accents, punctuation and runs of whitespace, all flattened —
     * the shape two spellings of one place have in common.
     *
     * For an address, the site's own `normalizeAddress()` runs FIRST (it
     * settles casing, particles and the position of a house number, and
     * does it the same way here as in every member screen); `fold()` then
     * strips what is left. "Ferme-du-Moulin", "Ferme du Moulin" and "Ferme
     * du Moulin (asbl)" fold together, which is the whole point, and the
     * result is deliberately for COMPARISON, never for display.
     */
    private function fold(string $value): string
    {
        return TextNormalizerService::fold($value);
    }
}
