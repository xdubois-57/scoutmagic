<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\Journal\JournalService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;

/**
 * The stay a message is ABOUT, when the module already has one.
 *
 * **Why this is not `Mail\StayFromMailService`.** That service creates —
 * it needs the `camps_auto_create_from_mail` setting, a place it may have
 * to write down, and a model to name it. Recognising a stay that already
 * exists needs none of the three, and gating it on them is precisely the
 * bug this class was written for: a unit with automatic creation OFF got
 * no association either, and a unit with it ON only ever got one as a
 * side effect of trying to create a duplicate first.
 *
 * **The evidence is the period, stated to the day.** A booking is a pair
 * of dates, and `Mail\MessageReader` already reads them with a
 * deliberately high bar — a RANGE, never a lone date, and never a guess
 * between day-first and month-first. When exactly one stay runs over
 * exactly those days, that is the stay the message is about, in the same
 * way a chief reading it would say so.
 *
 * It is not a certainty, and `Api\LinkOrigin::PERIOD` says so on the
 * screen: a second site quoting for the same weekend states the same two
 * dates truthfully. What makes it safe enough to act on is where it is
 * asked — a mailbox the unit declared to be the camps box, whose whole
 * contents are about camps.
 *
 * **Several stays over the same days produce propositions, never a
 * pick.** Two sections camping the same weekend is the ordinary reason,
 * and putting the farmer's mail on whichever sorted first is worse than
 * asking — the rule this module already applies to an ambiguous sender.
 */
class ExistingStayMatcher
{
    public function __construct(
        private CampRepository $camps,
        private MessageReader $reader,
        /**
         * Optional, like every journal in this module's mail path: without
         * it this class behaves identically and says nothing. With it, the
         * one question a chief asks — « pourquoi ce message n'est-il pas
         * sur mon camp ? » — has an answer that names the period read and
         * how many stays it found.
         */
        private ?JournalService $journal = null
    ) {
    }

    /**
     * The stays whose days this text states, in the order the repository
     * returns them.
     *
     * Empty is the ordinary answer and never an error: most mail states no
     * period at all, and a period matching nothing is a booking the module
     * has yet to hear about.
     *
     * @return Camp[]
     */
    public function matching(string $text): array
    {
        $range = $this->reader->readDateRange($text);
        if ($range === null) {
            return [];
        }

        return $this->camps->findByDateRange($range['start'], $range['end']);
    }

    /**
     * The same reading, narrowed to one place when the caller managed to
     * recognise one.
     *
     * A place is only ever a NARROWING here, never a widening: a message
     * whose venue resolves to a place holding none of the date-matching
     * stays leaves the list untouched rather than emptying it, because the
     * venue reading is the weaker of the two — it comes from a model, and
     * a covering note that mentions the unit's usual field while booking
     * somewhere else would otherwise silently veto a period read from a
     * contract.
     *
     * @param Camp[] $camps
     * @return Camp[]
     */
    public static function narrowedToPlace(array $camps, ?int $placeId): array
    {
        if ($placeId === null || count($camps) < 2) {
            return $camps;
        }

        $atPlace = array_values(array_filter(
            $camps,
            static fn(Camp $camp): bool => $camp->placeId === $placeId
        ));

        return $atPlace === [] ? $camps : $atPlace;
    }

    /**
     * What the period said, for the chief who wonders why.
     *
     * No personal data (§7.9): a message id, a number of stays and the two
     * dates the module read, which are facts about a booking and not about
     * a person.
     *
     * @param Camp[] $camps
     */
    public function journalMatch(int $messageId, string $text, array $camps, string $outcome): void
    {
        if ($this->journal === null) {
            return;
        }

        $range = $this->reader->readDateRange($text);

        $this->journal->log(
            'camps',
            'camps_stay_matched_by_period',
            'info',
            sprintf(
                'Message #%d : période %s, %d séjour(s) correspondant(s) — %s.',
                $messageId,
                $range === null ? 'illisible' : $range['start'] . ' → ' . $range['end'],
                count($camps),
                $outcome
            ),
            [
                'message_id' => $messageId,
                'start_date' => $range['start'] ?? null,
                'end_date' => $range['end'] ?? null,
                'camp_ids' => array_map(static fn(Camp $camp): int => $camp->id, $camps),
                'outcome' => $outcome,
            ]
        );
    }

    public const OUTCOME_LINKED = 'rattaché';
    public const OUTCOME_PROPOSED = 'proposé, aucun choisi';
}
