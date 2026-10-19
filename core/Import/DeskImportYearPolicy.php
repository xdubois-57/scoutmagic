<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Config\AppClock;
use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\ScoutYear\ScoutYearResolver;
use Core\View\ConfigurationMode;

/**
 * Which scout years a Desk import may target today — the one source of
 * truth for the import page (what it offers, what it proposes, when it
 * asks) and for the server (what it accepts).
 *
 * The rule is anchored on the public year the site is really on, never on
 * the calendar alone. The two days of {@see DeskImportYearDates} are placed
 * in the calendar year that public year ends in:
 *
 *  - before the opening (07-01 by default): the public year only;
 *  - from the opening to the eve of the Desk-encoding date (08-15): the
 *    public year AND the next one, the next one proposed, and the public
 *    year asked for confirmation — it is the year the families see;
 *  - from the Desk-encoding date until the public transition: the next
 *    year only.
 *
 * After the transition the rule starts again from the new public year —
 * which, the day after an early switch, means the new public year alone
 * until the next summer. A second year ahead is never opened because the
 * calendar says so.
 *
 * **Configuration mode** (ConfigurationMode::isActive(), read on the
 * server, never a value from the browser) opens every existing year; one
 * outside the usual rule asks the same confirmation. Every check of the
 * file and the roster barrier still apply.
 *
 * **Why the service enforces it, not only the page.** A select that lists
 * two years is a convenience; a POST can name any id. DeskImportService::
 * import() asks this policy before reading the file, so no route — the
 * form, a replayed request, a future caller — can write into a year the
 * page would not have offered. The reference-dataset replay and the tests
 * pass {@see self::unrestricted()} explicitly, since they import into past
 * years on purpose; the web path is wired with {@see self::forSite()} in
 * the composition root and has no way to ask for the other one.
 */
final class DeskImportYearPolicy
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /** @var \Closure(): bool */
    private \Closure $configurationModeActive;

    private function __construct(
        private ?ScoutYearResolver $resolver,
        private ?ScoutYearService $scoutYears,
        private ?SettingService $settings,
        ?\Closure $clock,
        ?\Closure $configurationModeActive
    ) {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => AppClock::now();
        $this->configurationModeActive = $configurationModeActive
            ?? static fn(): bool => ConfigurationMode::isActive();
    }

    /**
     * The policy of the web import.
     *
     * @param (\Closure(): \DateTimeImmutable)|null $clock today, on the application clock by default
     * @param (\Closure(): bool)|null $configurationModeActive ConfigurationMode::isActive() by default
     */
    public static function forSite(
        ScoutYearResolver $resolver,
        ScoutYearService $scoutYears,
        SettingService $settings,
        ?\Closure $clock = null,
        ?\Closure $configurationModeActive = null
    ): self {
        return new self($resolver, $scoutYears, $settings, $clock, $configurationModeActive);
    }

    /**
     * Any existing year, no confirmation — for the reference-dataset
     * replay and the tests, which import into historical years on
     * purpose. Never wired on a web path.
     */
    public static function unrestricted(): self
    {
        return new self(null, null, null, null, static fn(): bool => false);
    }

    public function isUnrestricted(): bool
    {
        return $this->resolver === null;
    }

    /**
     * What may be imported today.
     *
     * Reads only: the next year is looked up by label, never created —
     * ScoutYearResolver::listYears() creates it as a side effect, which is
     * the import page's business, not this decision's.
     *
     * @throws \LogicException on the unrestricted policy, which offers no page
     */
    public function window(): DeskImportYearWindow
    {
        if ($this->resolver === null || $this->scoutYears === null || $this->settings === null) {
            throw new \LogicException('The unrestricted Desk import policy has no window to offer.');
        }

        $public = $this->resolver->getCurrentPublicYear();
        $next = $this->scoutYears->findByLabel(ScoutYearService::nextLabel($public['label']));

        $dates = DeskImportYearDates::fromSettings($this->settings);
        $endCalendarYear = substr($public['end_date'], 0, 4);
        // Compared as 'Y-m-d' strings rather than as dates: 02-29 in a
        // year without one stays the 29th instead of rolling over to
        // 1 March, and ISO strings order exactly as the days they name.
        $nextStart = $endCalendarYear . '-' . $dates->nextStart;
        $encoding = $endCalendarYear . '-' . $dates->encoding;
        $today = ($this->clock)()->setTimezone(AppClock::zone())->format('Y-m-d');

        $period = match (true) {
            strcmp($today, $nextStart) < 0 => DeskImportYearWindow::PERIOD_BEFORE,
            strcmp($today, $encoding) < 0 => DeskImportYearWindow::PERIOD_BETWEEN,
            default => DeskImportYearWindow::PERIOD_AFTER,
        };

        /** @var array<int, true> $usual the ids the rule allows without configuration mode */
        $usual = [];
        if ($period !== DeskImportYearWindow::PERIOD_AFTER) {
            $usual[$public['id']] = true;
        }
        if ($period !== DeskImportYearWindow::PERIOD_BEFORE && $next !== null) {
            $usual[$next['id']] = true;
        }

        $configurationMode = ($this->configurationModeActive)();
        $candidates = $configurationMode ? $this->scoutYears->getAll() : array_values(array_filter(
            [$public, $next],
            static fn(?array $year): bool => $year !== null && isset($usual[$year['id']])
        ));

        $options = [];
        foreach ($candidates as $year) {
            $isPublic = $year['id'] === $public['id'];
            $isNext = $next !== null && $year['id'] === $next['id'];

            $confirmation = null;
            if (!isset($usual[$year['id']])) {
                $confirmation = DeskImportYearOption::CONFIRM_CONFIGURATION_MODE;
            } elseif (
                $isPublic
                && $period === DeskImportYearWindow::PERIOD_BETWEEN
                && isset($usual[$next['id'] ?? 0])
            ) {
                $confirmation = DeskImportYearOption::CONFIRM_PUBLIC_DURING_WINDOW;
            }

            $options[] = new DeskImportYearOption($year['id'], $year['label'], $isPublic, $isNext, $confirmation);
        }

        // Newest first, the way every year picker of the site lists them.
        usort(
            $options,
            static fn(DeskImportYearOption $a, DeskImportYearOption $b): int => strcmp($b->label, $a->label)
        );

        $preselected = $period === DeskImportYearWindow::PERIOD_BEFORE || $next === null ? $public['id'] : $next['id'];
        if (!isset($usual[$preselected])) {
            $preselected = $options[0]->id ?? null;
        }

        return new DeskImportYearWindow(
            $options,
            $preselected,
            $period,
            $configurationMode,
            $public,
            $next,
            $nextStart,
            $encoding,
            $dates->fellBack
        );
    }

    /**
     * Refuse an import into $scoutYearId unless the policy allows it today
     * — and, for a year that needs it, unless $confirmedYearId names that
     * same year.
     *
     * @throws DeskImportYearRefusedException
     */
    public function assertImportable(int $scoutYearId, ?int $confirmedYearId): void
    {
        if ($this->isUnrestricted()) {
            return;
        }

        $window = $this->window();
        $option = $window->option($scoutYearId);
        if ($option === null) {
            $year = $this->scoutYears?->findById($scoutYearId);

            throw DeskImportYearRefusedException::notOffered(
                $year['label'] ?? null,
                array_map(static fn(DeskImportYearOption $o): string => $o->label, $window->options)
            );
        }

        if ($option->needsConfirmation() && $confirmedYearId !== $scoutYearId) {
            throw DeskImportYearRefusedException::confirmationMissing($option->label);
        }
    }
}
