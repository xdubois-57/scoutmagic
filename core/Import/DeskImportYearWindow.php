<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * What {@see DeskImportYearPolicy} allows today: the years a Desk import
 * may target, newest first, and the one the import page proposes.
 *
 * The import page renders exactly this and the server checks against
 * exactly this — the same object, computed the same way on both sides —
 * so the page can never offer a year the POST would refuse, nor the POST
 * accept one the page did not offer.
 */
final class DeskImportYearWindow
{
    /** Before the next year opens: the public year alone. */
    public const PERIOD_BEFORE = 'before';

    /** From the opening to the eve of the Desk-encoding date: both years, the next one proposed. */
    public const PERIOD_BETWEEN = 'between';

    /** From the Desk-encoding date until the public transition: the next year alone. */
    public const PERIOD_AFTER = 'after';

    /**
     * @param list<DeskImportYearOption> $options newest first
     * @param array{id: int, label: string, start_date: string, end_date: string} $publicYear
     * @param array{id: int, label: string, start_date: string, end_date: string}|null $nextYear null when it does not exist yet
     */
    public function __construct(
        public readonly array $options,
        public readonly ?int $preselectedId,
        public readonly string $period,
        public readonly bool $configurationMode,
        public readonly array $publicYear,
        public readonly ?array $nextYear,
        /** 'Y-m-d' — the first day the next year may be imported. */
        public readonly string $nextStartDate,
        /** 'Y-m-d' — the first day the public year may no longer be imported. */
        public readonly string $encodingDate
    ) {
    }

    public function option(int $scoutYearId): ?DeskImportYearOption
    {
        foreach ($this->options as $option) {
            if ($option->id === $scoutYearId) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The year to select on the page: $requestedId when the policy offers
     * it (the « Année scoute » page links here with `?annee=`), the
     * proposed one otherwise. A link never widens what may be imported.
     */
    public function selectedId(?int $requestedId): ?int
    {
        if ($requestedId !== null && $this->option($requestedId) !== null) {
            return $requestedId;
        }

        return $this->preselectedId;
    }
}
