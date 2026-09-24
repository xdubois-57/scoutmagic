<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

/**
 * One row of a search endpoint answering `partials/search_picker.html.twig`
 * (ARCHITECTURE.md §8.30bis) — the server half of that component's contract.
 *
 * The four search-as-you-type pickers written before it each invented their
 * own reply: `{stays: [{id, label, detail, reason}]}`,
 * `{receivables: [{id, label, communication, remaining_cents}]}`, and so on,
 * with the script that reads each one knowing its field names by heart. That
 * is how a fifth copy happens — nothing generic can read a shape nobody
 * wrote down. So the shape is written down here, once, and an endpoint that
 * feeds the shared picker builds these rather than an array literal.
 *
 * Every optional field is a line of text the picker draws under or beside the
 * label, and nothing else: it never interprets them. A picker for events uses
 * the subtitle for the calendar, the badge for the section and the warning
 * for « a carpool already exists for this one »; none of that is known here.
 *
 * Everything is plain text. The script writes it with `textContent`, never as
 * markup, so an event title a chief typed cannot become HTML on somebody
 * else's screen.
 */
final class SearchPickerResult
{
    public function __construct(
        /** What the form submits for this row. */
        public readonly int|string $id,
        /** The line the reader recognises the row by. */
        public readonly string $label,
        /** A second, quieter line — where it comes from, when it is. */
        public readonly ?string $subtitle = null,
        /** A short tag drawn at the end of the row — a section, a status. */
        public readonly ?string $badge = null,
        /** A caution about choosing this row; it may still be chosen. */
        public readonly ?string $warning = null
    ) {
    }

    /**
     * The row as the picker reads it. Absent fields are omitted rather than
     * sent as null, so a reply stays readable in a network panel.
     *
     * @return array{id: int|string, label: string, subtitle?: string, badge?: string, warning?: string}
     */
    public function toArray(): array
    {
        $row = ['id' => $this->id, 'label' => $this->label];
        foreach (['subtitle' => $this->subtitle, 'badge' => $this->badge, 'warning' => $this->warning] as $key => $value) {
            if ($value !== null && $value !== '') {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    /**
     * The whole JSON body a search endpoint returns — `{success, results}`,
     * the envelope `ScoutMagicApi.getJson()` callers already test for.
     *
     * An empty list is a successful answer, not an error: the picker says
     * « nothing matches » itself, and an endpoint that failed on no match
     * would read as a broken field.
     *
     * @param iterable<self> $results
     * @return array{success: true, results: list<array<string, int|string>>}
     */
    public static function payload(iterable $results): array
    {
        $rows = [];
        foreach ($results as $result) {
            $rows[] = $result->toArray();
        }

        return ['success' => true, 'results' => $rows];
    }
}
