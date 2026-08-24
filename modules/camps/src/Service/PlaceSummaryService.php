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
    /** Long enough to be worth reading, short enough that nobody skips it. */
    private const MAX_TOKENS = 400;

    public function __construct(
        private PlaceRepository $places,
        private CampRepository $camps,
        private ReviewRepository $reviews,
        private ?LlmConnectorInterface $llm = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->llm !== null && $this->llm->isAvailable();
    }

    /**
     * Regenerates one place's summary. Returns false when nothing was
     * written — no connector, nothing to summarise, or the model failed.
     */
    public function refresh(Place $place): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $material = $this->material($place);
        if ($material === null) {
            // A place with one undated stay and no review has nothing to
            // sum up, and a summary saying so is worse than none.
            $this->places->clearSummary($place->id);

            return false;
        }

        try {
            $response = $this->llm->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
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
            // yesterday's three sentences beat today's blank.
            return false;
        }

        $summary = trim($response->content);
        if ($summary === '') {
            return false;
        }

        $this->places->saveSummary($place->id, $summary);

        return true;
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
