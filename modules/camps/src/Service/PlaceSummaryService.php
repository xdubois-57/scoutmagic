<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * A few sentences summing up what a place's stays and reviews add up to.
 *
 * Optional `llm_connector` consumer: absent, disabled or failing, a place
 * simply has no summary and every other screen is unaffected.
 *
 * **What goes in is a closed list**: reviews, notes, prices, statuses,
 * dates, participant counts and sections. Linked e-mails are deliberately
 * excluded — they are long, they are expensive, and they are third
 * parties' correspondence. Sending a farmer's letters to a subprocessor
 * so a model can add one adjective is not a trade this module makes.
 * Contacts are excluded for the same reason and more bluntly: their names
 * and numbers have no business leaving this database at all.
 */
class PlaceSummaryService
{
    /**
     * The budget for the whole completion, thinking included — which is
     * why it is far larger than the three sentences it has to pay for.
     *
     * It was 400, sized for the answer alone, and that is exactly how
     * this feature broke on an installation whose `cheap` model was a
     * hybrid reasoning model (glm-5.2): the cap covers the model's
     * reasoning tokens too, so the whole 400 went on thinking, the answer
     * came back EMPTY with finish_reason "length", and the place sheet
     * reported it as "il n'y a pas assez à raconter" — about a stay
     * carrying four stars and a comment.
     *
     * The journal entry that proved it, and what sized this number:
     * `input_tokens: 176, output_tokens: 400` — the model produced
     * exactly the cap and not one sentence of it was the answer. How far
     * past 400 that model's reasoning would have run is unknowable from
     * here, so the cap is set well clear of it rather than one guess
     * above.
     *
     * A model that finishes stops billing, so a generous cap costs
     * nothing on a model that does not think, and is the difference
     * between working and not on one that does. Providers that let a
     * caller ask for less reasoning do it with their own parameter
     * (Scaleway: `reasoning_effort`), which this module does not send:
     * it is not in Api\LlmRequest, the accepted values differ per
     * provider, and an unknown one is a rejected request — a worse
     * failure than a large cap.
     */
    private const MAX_TOKENS = 3000;

    /**
     * The only tier this service ever asks for — three sentences off a
     * dozen lines of material is not work for an expensive model.
     *
     * Named once, because `isAvailable()` and `refresh()` must ask the
     * connector about the SAME tier: asking "is anything configured"
     * while calling for `cheap` is what let the place sheet offer a
     * button whose click could only ever fail.
     */
    private const TIER = LlmTier::CHEAP;

    public function __construct(
        private PlaceRepository $places,
        private CampRepository $camps,
        private ReviewRepository $reviews,
        private ?LlmConnectorInterface $llm = null
    ) {
    }

    /**
     * Whether a summary can be written at all — the check that decides
     * whether the place sheet offers the button.
     *
     * `isTierAvailable()`, never `isAvailable()`: the latter answers "is
     * anything configured at all", so an installation with a model on
     * `capable` and none on `cheap` passed it, offered « Écrire le résumé
     * maintenant », and had `complete()` refuse the tier before it
     * reached a provider — no summary, nothing in the journal, and a
     * message blaming the chief's material.
     */
    public function isAvailable(): bool
    {
        return $this->llm !== null && $this->llm->isTierAvailable(self::TIER);
    }

    /**
     * Regenerates one place's summary, and says what happened: five
     * different situations end in no summary, and the person reading the
     * answer can only act on the right one (see Service\SummaryOutcome).
     */
    public function refresh(Place $place): SummaryOutcome
    {
        if (!$this->isAvailable()) {
            return SummaryOutcome::Unavailable;
        }

        $material = $this->material($place);
        if ($material === null) {
            // A place with one undated stay and no review has nothing to
            // sum up, and a summary saying so is worse than none.
            $this->places->clearSummary($place->id);

            return SummaryOutcome::NothingToSummarise;
        }

        try {
            $response = $this->llm->complete(new LlmRequest(
                tier: self::TIER,
                prompt: $material,
                systemPrompt: 'Tu résumes, pour un staff scout qui cherche un terrain de camp, '
                    . 'ce que les séjours précédents sur ce terrain racontent. Trois phrases au maximum, '
                    . 'en français, factuelles. Dis ce qui revient d\'un séjour à l\'autre : la qualité de '
                    . 'l\'accueil, l\'évolution du prix, les difficultés signalées. '
                    . 'N\'invente rien qui ne soit pas dans les données. '
                    . 'Ne cite jamais de nom de personne. '
                    . 'Si les données ne disent pas grand-chose, dis-le en une phrase plutôt que de broder.',
                maxTokens: self::MAX_TOKENS,
            ));
        } catch (LlmException) {
            // A failed summary must never cost the place its old one:
            // yesterday's three sentences beat today's blank. The reason
            // is not re-told here — Service\LlmConnectorService journals
            // every refusal, with the provider's own words.
            return SummaryOutcome::ModelRefused;
        }

        $summary = trim($response->content);

        // Cut off mid-thought: three sentences that stop in the middle of
        // the second are not a summary, and storing them would replace a
        // good one from yesterday with a fragment. Said separately from
        // "nothing came back" because the two are fixed differently —
        // this one by MAX_TOKENS above, and a chief reading the message
        // should know which one they are looking at.
        if ($response->truncated) {
            return SummaryOutcome::AnswerCutOff;
        }
        if ($summary === '') {
            return SummaryOutcome::EmptyAnswer;
        }

        $this->places->saveSummary($place->id, $summary);

        return SummaryOutcome::Written;
    }

    /**
     * Everything the model is allowed to see about this place, as text.
     * Null when there is nothing worth summarising.
     */
    public function material(Place $place): ?string
    {
        $camps = $this->camps->findByPlace($place->id);
        if ($camps === []) {
            return null;
        }

        $reviews = $this->reviews->findByCamps(array_map(static fn(Camp $c): int => $c->id, $camps));

        $lines = [];
        $hasSomethingToSay = false;
        foreach ($camps as $camp) {
            $parts = [CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly)];
            $parts[] = CampLabels::stayType($camp->stayType);
            $parts[] = CampLabels::status($camp->status);
            if ($camp->priceCents !== null) {
                $parts[] = 'prix ' . CampLabels::money($camp->priceCents);
                $hasSomethingToSay = true;
            }
            if ($camp->participantCount !== null) {
                $parts[] = $camp->participantCount . ' participants';
            }

            $review = $reviews[$camp->id] ?? null;
            if ($review !== null) {
                if ($review->rating !== null) {
                    $parts[] = 'note ' . $review->rating . '/5';
                }
                if ($review->comment !== null) {
                    $parts[] = 'avis : ' . $review->comment;
                }
                $hasSomethingToSay = true;
            }

            $lines[] = '- ' . implode(', ', $parts);
        }

        if (!$hasSomethingToSay) {
            return null;
        }

        return "Lieu : {$place->name}\nSéjours :\n" . implode("\n", $lines);
    }
}
