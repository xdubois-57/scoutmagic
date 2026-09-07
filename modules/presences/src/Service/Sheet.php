<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Modules\Presences\Value\PresenceStatus;

/**
 * One evening's sheet, assembled and ready to render: the event, the
 * section it belongs to, and one line per animé of that section at the
 * effective scout year.
 *
 * A sheet is built for a viewer who has already been allowed through
 * (Service\PresenceSheetService), so nothing here re-decides anything.
 */
final class Sheet
{
    /**
     * @param list<SheetLine> $lines
     */
    public function __construct(
        public readonly int $eventId,
        public readonly string $title,
        public readonly string $startDate,
        public readonly ?string $startTime,
        public readonly int $sectionId,
        public readonly string $sectionLabel,
        public readonly ?string $sectionColor,
        public readonly array $lines
    ) {
    }

    /**
     * How many animés stand in each state, every state present including
     * the ones nobody recorded — the counters are a filter bar, and a
     * missing key would draw three tiles one evening and four the next.
     *
     * « Non renseigné » is a subtraction rather than a stored value: it is
     * every animé the sheet offers minus those somebody decided about.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (PresenceStatus::ordered() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($this->lines as $line) {
            $counts[$line->status->value]++;
        }

        return $counts;
    }

    public function total(): int
    {
        return count($this->lines);
    }
}
