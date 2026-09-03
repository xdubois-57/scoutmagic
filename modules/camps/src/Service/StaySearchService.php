<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Service\TextNormalizerService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;

/**
 * « Quel séjour ? », answered by typing.
 *
 * The control this feeds replaced a `<select>` that held the CROSS PRODUCT
 * of every visible place and every stay it ever hosted. A unit in its
 * tenth year opens that list on two hundred lines, in an order nobody can
 * predict, to find the camp whose contract they are looking at — and the
 * page paid one query per place to build it.
 *
 * **Ranked, not merely filtered.** With nothing typed the answer is not
 * "the first twelve stays" but, in this order:
 *   1. the stays the caller says are likely — on the mail screen, the ones
 *      whose dates the message itself announces
 *      (`Mail\ExistingStayMatcher`), which is usually the whole answer
 *      before a single keystroke;
 *   2. the stays still to come, soonest first;
 *   3. the rest, most recent first.
 * A chief attaching a contract is almost always looking at 1 or 2, and a
 * picker that opens on the right line is the difference between a search
 * box and a search box people have to use.
 *
 * **Matching is done in PHP, over the whole set, on purpose.** What a
 * chief types is what they READ — « fresnaye », « septembre 2026 »,
 * « petit camp » — and the words on the screen are built by
 * `Service\CampLabels` out of columns that spell none of them: no `LIKE`
 * over `start_date` finds « septembre ». The set is a unit's stays, not a
 * public catalogue — a few hundred rows at the very outside, in one query
 * — so folding them and comparing is cheaper than the round trips a
 * cleverer scheme would need.
 */
class StaySearchService
{
    /**
     * How many suggestions a list may hold. Long enough that the answer is
     * usually in it, short enough to read without scrolling — past that a
     * chief types another word rather than hunting.
     */
    public const LIMIT = 12;

    public function __construct(private CampRepository $camps)
    {
    }

    /**
     * @param int[] $preferredIds stays the caller has reason to think this
     *   is about, in the order it believes them; they lead the list, and
     *   they are the only ones that carry a reason
     * @return list<array{id: int, label: string, detail: string, reason: string}>
     */
    public function search(string $query, array $preferredIds = [], int $limit = self::LIMIT): array
    {
        $terms = self::terms($query);
        $preferred = array_values(array_unique(array_map('intval', $preferredIds)));

        $rank = [];
        foreach ($preferred as $position => $campId) {
            $rank[$campId] = $position;
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $hits = [];
        foreach ($this->all() as $row) {
            $camp = $row['camp'];
            $label = self::labelFor($camp, $row['place_name']);
            if (!self::matches($terms, $camp, $row['place_name'], $label)) {
                continue;
            }

            $hits[] = [
                'row' => [
                    'id' => $camp->id,
                    'label' => $label,
                    'detail' => self::detailFor($camp),
                    'reason' => isset($rank[$camp->id]) ? self::REASON_PREFERRED : '',
                ],
                'order' => [
                    $rank[$camp->id] ?? PHP_INT_MAX,
                    self::endOf($camp) >= $today ? 0 : 1,
                    // Soonest first among what is still to come, most
                    // recent first among what is over: both are "nearest
                    // to today", counted in the only direction each has.
                    self::endOf($camp) >= $today ? self::endOf($camp) : self::invert(self::endOf($camp)),
                    $camp->id,
                ],
            ];
        }

        usort($hits, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(
            static fn(array $hit): array => $hit['row'],
            array_slice($hits, 0, max(1, $limit))
        );
    }

    /**
     * The unit's stays, read once per request.
     *
     * The mail screen asks this service once per message — each message
     * gets its own shortlist, because the stay its own dates name belongs
     * at the top of ITS list and nowhere else. Without this, a hundred
     * messages would be a hundred identical queries.
     *
     * @var list<array{camp: Camp, place_name: string}>|null
     */
    private ?array $cache = null;

    /**
     * @return list<array{camp: Camp, place_name: string}>
     */
    private function all(): array
    {
        return $this->cache ??= $this->camps->findAllWithPlaceName();
    }

    /** Why a stay leads the list, in the words the screen uses. */
    public const REASON_PREFERRED = 'Période annoncée par le message';

    /**
     * The same sentence the `<select>` used to show: a place and a period.
     * Two ways of writing a stay would drift within a season, and this
     * label is read next to the screens `Service\CampLabels` already
     * writes.
     */
    public static function labelFor(Camp $camp, string $placeName): string
    {
        $dates = CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly);

        return $dates === '' ? $placeName : $placeName . ' — ' . $dates;
    }

    /** What the label leaves out and a chief still needs to tell two apart. */
    private static function detailFor(Camp $camp): string
    {
        return CampLabels::stayType($camp->stayType) . ' · ' . CampLabels::status($camp->status);
    }

    /**
     * Every word typed has to appear somewhere. Words rather than one
     * substring, so « fresnaye 2026 » finds what neither « fresnaye2026 »
     * nor an ordered match would.
     *
     * @return string[]
     */
    private static function terms(string $query): array
    {
        $folded = TextNormalizerService::fold($query);

        return $folded === '' ? [] : array_values(array_filter(explode(' ', $folded), static fn($t) => $t !== ''));
    }

    /**
     * @param string[] $terms
     */
    private static function matches(array $terms, Camp $camp, string $placeName, string $label): bool
    {
        if ($terms === []) {
            return true;
        }

        // Everything a chief could reasonably type, folded once: what the
        // screen shows (place, French dates, kind of stay, status) AND the
        // stored dates, so « 2026-09 » and « 18/09/2026 » work for the
        // people who think in dates rather than in months.
        $haystack = TextNormalizerService::fold(implode(' ', array_filter([
            $placeName,
            $label,
            CampLabels::stayType($camp->stayType),
            CampLabels::status($camp->status),
            $camp->startDate,
            $camp->endDate,
            self::slashed($camp->startDate),
            self::slashed($camp->endDate),
            $camp->yearOnly !== null ? (string) $camp->yearOnly : null,
        ])));

        foreach ($terms as $term) {
            if (!str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    /** `2026-09-18` as `18/09/2026`, the way it is typed in Belgium. */
    private static function slashed(?string $date): ?string
    {
        if ($date === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return null;
        }

        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    /**
     * The day a stay is over, for ordering only. A year-only stay counts
     * as the last day of its year — the same reading
     * `Repository\Camp::isUpcoming()` uses, so the picker and the « À
     * venir » list cannot disagree about which side of today a stay is on.
     */
    private static function endOf(Camp $camp): string
    {
        if ($camp->endDate !== null) {
            return $camp->endDate;
        }
        if ($camp->startDate !== null) {
            return $camp->startDate;
        }

        return $camp->yearOnly !== null ? $camp->yearOnly . '-12-31' : '0000-00-00';
    }

    /**
     * A date that sorts backwards, so one ascending comparison can put the
     * soonest future stay and the most recent past one each at the top of
     * their own half.
     */
    private static function invert(string $date): string
    {
        $inverted = '';
        foreach (str_split($date) as $character) {
            $inverted .= ctype_digit($character) ? (string) (9 - (int) $character) : $character;
        }

        return $inverted;
    }
}
