<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * One filter of a mail triage screen, as the shared template
 * `@inbound_mail/partials/triage.html.twig` renders it (issue #462, D9) —
 * for camps and rentals alike.
 *
 * Two screens that each built their own list drifted apart the day they
 * were written: which filters exist, what counts as « à trier », what is
 * folded away. Both now build it here, from rows the consumer read through
 * `InboundMailInterface::triageRows()` — so it invents no access: what is
 * in it is what the consumer was allowed to read.
 */
final class TriageScreen
{
    /**
     * @param list<array<string, mixed>> $messages the rows under this filter
     * @param array<string, int> $counts the number on each tab, by filter value
     */
    public function __construct(
        public readonly array $messages,
        public readonly TriageFilter $filter,
        public readonly bool $includeBulk,
        public readonly int $bulkCount,
        public readonly array $counts
    ) {
    }

    /**
     * The screen for one filter.
     *
     * Automatic mail — newsletters, bounces, acknowledgements — is out of
     * the work list unless asked for, exactly as it is on the chief's own
     * `/courrier`: a newsletter under « À trier » is not a decision anybody
     * has to make. The set-aside rows are the caller's own read, made only
     * when that tab is the one asked for; the set-aside count is passed on
     * every visit, because « rien d'écarté » and « le filtre cache tout »
     * look identical on an empty list and only a number tells them apart.
     *
     * @param list<array<string, mixed>> $everything the list, set-aside excluded
     * @param list<array<string, mixed>> $dismissedRows the set-aside list, when asked for
     */
    public static function of(
        array $everything,
        TriageFilter $filter,
        bool $includeBulk,
        array $dismissedRows,
        int $dismissedCount
    ): self {
        $human = array_values(array_filter(
            $everything,
            static fn(array $row): bool => !$row['message']->isBulk
        ));
        $all = $includeBulk ? $everything : $human;
        $count = static fn(TriageFilter $tab): int => count(array_filter($all, $tab->keeps(...)));

        return new self(
            $filter === TriageFilter::DISMISSED
                ? $dismissedRows
                : array_values(array_filter($all, $filter->keeps(...))),
            $filter,
            $includeBulk,
            count($everything) - count($human),
            [
                TriageFilter::UNLINKED->value => $count(TriageFilter::UNLINKED),
                TriageFilter::LINKED->value => $count(TriageFilter::LINKED),
                TriageFilter::ALL->value => count($all),
                TriageFilter::DISMISSED->value => $dismissedCount,
            ]
        );
    }

    /**
     * What the template reads.
     *
     * @return array{messages: list<array<string, mixed>>, status: string, include_bulk: bool,
     *     bulk_count: int, counts: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'messages' => $this->messages,
            'status' => $this->filter->value,
            'include_bulk' => $this->includeBulk,
            'bulk_count' => $this->bulkCount,
            'counts' => $this->counts,
        ];
    }
}
