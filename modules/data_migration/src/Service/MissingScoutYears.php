<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Service;

use Core\Config\ScoutYearService;

/**
 * Scout years an archive refers to and this site does not have (D5).
 *
 * The rule is the maintainer's: the preview **lists the missing PAST
 * years** and the administrator **ticks those to create**. The current year
 * and the years to come are never created here, whatever the file says —
 * they belong to the site's own year workflow, and an import that invented
 * next year would open it to everything that reads « the latest year ».
 *
 * `ScoutYearService::ensureYear()` creates a year with no guard of its own,
 * so this class is the only caller of it in the module and asks the
 * question first, every time.
 *
 * « Current » is the calendar's (`ScoutYearService::labelForDate()`, the
 * year running on 1 September), not the site's public year: during a
 * transition the public year lags behind, and it exists anyway.
 */
class MissingScoutYears
{
    /** @var array<string, ?int> */
    private array $resolved = [];

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private ScoutYearService $years,
        private ?\Closure $clock = null
    ) {
    }

    /** The id of this year on this site, or null. Never creates. */
    public function resolve(string $label): ?int
    {
        if (!array_key_exists($label, $this->resolved)) {
            $this->resolved[$label] = self::isLabel($label)
                ? ($this->years->findByLabel($label)['id'] ?? null)
                : null;
        }

        return $this->resolved[$label];
    }

    /** `2019-2020`: two consecutive years, and nothing else. */
    public static function isLabel(string $label): bool
    {
        return preg_match('/^(\d{4})-(\d{4})$/', $label, $m) === 1 && (int) $m[2] === (int) $m[1] + 1;
    }

    /** Whether this year may be offered for creation: a valid label, strictly before the current year. */
    public function isCreatable(string $label): bool
    {
        if (!self::isLabel($label)) {
            return false;
        }

        $current = ScoutYearService::labelForDate($this->now());

        return (int) substr($label, 0, 4) < (int) substr($current, 0, 4) && (int) substr($label, 0, 4) >= 1907;
    }

    /**
     * Creates the ticked years — only those that are creatable and still
     * missing — and returns the labels it created.
     *
     * @param list<string> $labels
     * @return list<string>
     */
    public function create(array $labels): array
    {
        $created = [];
        foreach (array_unique($labels) as $label) {
            if (!$this->isCreatable($label) || $this->resolve($label) !== null) {
                continue;
            }
            $this->resolved[$label] = $this->years->ensureYear($label);
            $created[] = $label;
        }

        return $created;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
    }
}
