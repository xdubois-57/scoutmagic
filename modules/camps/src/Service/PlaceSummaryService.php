<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\View\EditableContentService;
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
 * **What goes in is a closed list**: reviews, the stays' own free-text
 * notes, prices, statuses, dates, participant counts and sections — the
 * list `module.json` states to the administrator, and for a long time
 * larger than what this service actually sent. Notes and sections were
 * named there and read nowhere, so a staff who wrote three paragraphs
 * about a field in the note and pressed « Écrire le résumé » was told
 * their place had no qualitative review. Linked e-mails are deliberately
 * excluded — they are long, they are expensive, and they are third
 * parties' correspondence. Sending a farmer's letters to a subprocessor
 * so a model can add one adjective is not a trade this module makes.
 * Contacts are excluded for the same reason and more bluntly: their names
 * and numbers have no business leaving this database at all.
 *
 * A note is free text and a chief may well have written a person's name
 * in it ("demander Jean-Marie"). That is why the system prompt forbids
 * the model to return one — the same instruction Mail\StayFromMailService
 * relies on — and why the note is bounded: it is the one input here with
 * no natural size.
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

    /**
     * How much of ONE stay's note may travel, and how much material may
     * be built out of a whole place.
     *
     * A note is the only unbounded thing here — a chief can write four
     * screens about a field — and a place can hold twenty stays. Without
     * a bound, an old place would send a prompt costing more than the
     * summary is worth, on a schedule nobody watches. The place cap cuts
     * the OLDEST stays, because `CampRepository::findByPlace()` returns
     * them newest first and a place is what it was like last time.
     */
    private const MAX_NOTE_CHARS = 1200;
    private const MAX_MATERIAL_CHARS = 12000;

    public function __construct(
        private PlaceRepository $places,
        private CampRepository $camps,
        private ReviewRepository $reviews,
        private EditableContentService $notes,
        private SectionDescriber $sectionDescriber,
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
            $sections = $this->sectionNames($camp);
            if ($sections !== '') {
                $parts[] = $sections;
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

            $line = '- ' . implode(', ', $parts);

            // The note goes on its own line, and it is the line that
            // matters most: the review field is one box on one screen,
            // while the note is where a staff actually writes what the
            // next one needs — "la cuisine était petite mais bien", "pas
            // d'endroit pour un tabou", "penser aux couvertures". A
            // summary built without it describes a field nobody camped on.
            $note = $this->noteText($camp->id);
            if ($note !== '') {
                $line .= "\n  notes du staff : " . $note;
                $hasSomethingToSay = true;
            }

            $lines[] = $line;
        }

        if (!$hasSomethingToSay) {
            return null;
        }

        return "Lieu : {$place->name}\nSéjours :\n" . $this->bounded($lines);
    }

    /**
     * One stay's note, as plain text.
     *
     * The note is rich text (`partials/rich_text_form_field.html.twig`),
     * and markup is neither information for a model nor something worth
     * paying tokens for — so the tags come off and the entities come
     * back, the same way every other consumer of stored rich text on this
     * site does it.
     */
    private function noteText(int $campId): string
    {
        $stored = $this->notes->get(CampService::noteKey($campId), '') ?? '';
        if (trim($stored) === '') {
            return '';
        }

        // A block element with no space around it would run two sentences
        // together ("...bien.Par contre..."), which is exactly what the
        // reported note looked like once its tags were stripped.
        $spaced = (string) preg_replace('~<(?:/p|br\s*/?|/li|/h[1-6]|/div)\s*>~i', ' ', $stored);
        $text = trim((string) preg_replace(
            '~\s+~u',
            ' ',
            html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        return mb_strlen($text) > self::MAX_NOTE_CHARS
            ? mb_substr($text, 0, self::MAX_NOTE_CHARS) . '…'
            : $text;
    }

    /**
     * Which sections went, by name. Never an id: "3, 5" tells a model
     * nothing, and a section whose id no longer resolves is dropped by
     * Service\SectionDescriber rather than invented.
     */
    private function sectionNames(Camp $camp): string
    {
        $names = array_map(
            static fn(array $section): string => $section['name'],
            $this->sectionDescriber->describe($camp->sectionIds)
        );

        return $names !== [] ? implode(' et ', $names) : '';
    }

    /**
     * The stay lines, oldest dropped first if the whole thing is too long
     * to be worth sending.
     *
     * @param array<int, string> $lines newest first
     */
    private function bounded(array $lines): string
    {
        $material = implode("\n", $lines);
        while (mb_strlen($material) > self::MAX_MATERIAL_CHARS && count($lines) > 1) {
            array_pop($lines);
            $material = implode("\n", $lines);
        }

        return $material;
    }
}
