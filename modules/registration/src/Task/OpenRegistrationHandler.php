<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Service\DateInput;

/**
 * Polls the `registration_scheduled_open_at` setting hourly and flips
 * `registration_form_open` on once due — self-reschedules at the end of
 * every run rather than being a first-class recurring task (same pattern
 * as Modules\Retro\Task\PurgeRateLimitHandler). A poll (rather than
 * reacting to the setting being saved) is used because the generic
 * Core\Http\Controller\SettingsController::update() AJAX endpoint has no
 * per-setting side-effect hook.
 *
 * Recurring, never a one-shot: `registration_scheduled_open_at` holds a
 * day/month only ("MM-DD", e.g. "09-30" — Core\Config\SettingService key,
 * same convention as SlotService::referenceMonthDay()'s own
 * `registration_reference_date`), deliberately never a year, so the same
 * configuration auto-fires every scout year without a chief re-entering
 * it annually. Fires at most once per occurrence, on the first poll at or
 * shortly after the configured day — see dueDateForYear() and the
 * configurable catch-up window: a cron that missed the day itself still
 * catches up, while a date configured (or a module enabled) months later
 * never fires retroactively. `registration_scheduled_open_applied_on` stores the
 * OCCURRENCE's own date and is the guard against firing twice — never
 * cleared, unlike the old one-shot design, since the schedule itself is
 * meant to persist. A chief can still open/close the form
 * immediately at any time via Controller\RegistrationConfigController's
 * own manual toggle; that path settles the marker for the occurrence
 * CURRENTLY inside its catch-up window and nothing beyond it (see
 * settleDueOccurrences()), so the manual decision is not undone by a
 * transition that had not fired yet, and next year's transition is not
 * disturbed either.
 */
class OpenRegistrationHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'poll';
    private const INTERVAL_SECONDS = 3600;

    /**
     * Setting holding how many days after the configured date a missed
     * occurrence may still fire — see dueDateForYear() and
     * catchUpDays().
     */
    public const CATCH_UP_SETTING = 'registration_scheduled_catch_up_days';

    /** Used when the setting is absent (module not yet re-registered). */
    public const DEFAULT_CATCH_UP_DAYS = 7;

    /**
     * Upper bound. Enforced at READ time (catchUpDays()) rather than at
     * input: the setting is edited on the generic Configuration >
     * Paramètres page, and module.json-declared settings carry no
     * validation regex (Module\ModuleManager::load()'s registration loop
     * only passes key/default/type/label/description/moduleId to
     * SettingService::register()), so nothing would stop a larger number
     * from being stored. Beyond a month, "a missed run" stops being a
     * plausible explanation and the window starts resurrecting dates
     * configured long after the fact.
     */
    public const MAX_CATCH_UP_DAYS = 30;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $monthDay = trim((string) ($context->settings->get('registration_scheduled_open_at', 'registration') ?: ''));
        if (preg_match('/^\d{2}-\d{2}$/', $monthDay) === 1) {
            $dueOn = self::dueDateForYear($monthDay, new \DateTimeImmutable(), self::catchUpDays($context->settings));
            $appliedOn = (string) ($context->settings->get(
                'registration_scheduled_open_applied_on',
                'registration'
            ) ?: '');

            if ($dueOn !== null && $appliedOn < $dueOn) {
                $context->settings->set('registration_form_open', '1', 'registration');
                $context->settings->set('registration_scheduled_open_applied_on', $dueOn, 'registration');
                $context->journal->log(
                    'registration',
                    'registration_form_auto_opened',
                    'info',
                    'Formulaire d\'inscription ouvert automatiquement (ouverture programmée annuelle)'
                );
            }
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->rearmAfter('registration', 'open_registration', self::REFERENCE, self::INTERVAL_SECONDS);
    }

    /**
     * The occurrence of a recurring MM-DD that is currently DUE, as Y-m-d,
     * or null when none is.
     *
     * "Due" means reached, and reached recently: within $catchUpDays of the
     * date itself (setting CATCH_UP_SETTING, 7 days by default). That window
     * is the whole point —
     *
     * - Strict equality with today (what this replaces) meant a poll that
     *   didn't land on the day itself — host down, container asleep, cron
     *   paused — lost the transition for a full year, silently.
     * - An UNBOUNDED catch-up would be worse: configuring "ouverture le
     *   30 septembre" in November, or simply enabling the module then,
     *   would immediately open registration for a date months behind. That
     *   is exactly the retroactive firing the recurring design was built to
     *   avoid, and it stays avoided.
     *
     * A window of 0 restores the strict "the day itself or never" behavior,
     * for a unit that would rather lose a transition than see one fire late.
     *
     * Last year's occurrence is considered too, so a MM-DD near the turn of
     * the year (e.g. 12-31) can still be caught up in early January.
     *
     * The applied-on marker stores this DUE date, never the day the task
     * happened to run — that is what keeps "at most once per occurrence"
     * true across several polls inside the window.
     *
     * Public and static: pure date arithmetic, worth testing directly.
     */
    public static function dueDateForYear(string $monthDay, \DateTimeImmutable $now, int $catchUpDays): ?string
    {
        $catchUpDays = max(0, min(self::MAX_CATCH_UP_DAYS, $catchUpDays));
        $today = $now->setTime(0, 0);
        $currentYear = (int) $now->format('Y');

        foreach ([$currentYear, $currentYear - 1] as $year) {
            $candidate = sprintf('%04d-%s', $year, $monthDay);
            // DateInput round-trips, which rejects a date PHP would
            // silently roll over (02-29 on a non-leap year becomes 03-01).
            $due = DateInput::parse('!Y-m-d', $candidate);
            if ($due === null) {
                continue;
            }

            $daysSinceDue = (int) $due->diff($today)->format('%r%a');
            if ($daysSinceDue >= 0 && $daysSinceDue <= $catchUpDays) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Writes both applied-on markers to whichever occurrence is currently
     * inside its catch-up window, so a transition that has NOT fired yet
     * stops being pending. Returns the occurrences it settled, keyed by
     * setting, for a caller that wants to say so in a journal line.
     *
     * **Why a manual open/close has to do this.** The catch-up window
     * (dueDateForYear()) lets a missed occurrence still fire days later.
     * The marker is what stops it firing twice — and on a fresh
     * installation it is empty, so EVERY occurrence inside the window is
     * still pending. A unit that installs the site on 2 September, with
     * the shipped « fermeture le 31 août », and opens the form by hand
     * from Configuration › Inscriptions, had it closed again by the next
     * poll, under a journal line announcing a « fermeture programmée
     * annuelle » it never programmed (issue #215).
     *
     * Deciding by hand IS deciding about the occurrence in progress. So
     * the manual toggle settles it: the window's occurrence is marked
     * applied, the poll finds nothing pending, and the chief's choice
     * holds. Next year's occurrence has a later date than the marker and
     * fires normally — the reciprocal of the promise this handler's
     * docblock already made in the other direction.
     *
     * @return array<string, string> applied-on setting key => occurrence date
     */
    public static function settleDueOccurrences(SettingService $settings, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $catchUpDays = self::catchUpDays($settings);
        $settled = [];

        foreach ([
            'registration_scheduled_open_at' => 'registration_scheduled_open_applied_on',
            'registration_scheduled_close_at' => 'registration_scheduled_close_applied_on',
        ] as $scheduleKey => $markerKey) {
            $monthDay = trim((string) ($settings->get($scheduleKey, 'registration') ?: ''));
            if (preg_match('/^\d{2}-\d{2}$/', $monthDay) !== 1) {
                continue;
            }

            $dueOn = self::dueDateForYear($monthDay, $now, $catchUpDays);
            if ($dueOn === null) {
                continue;
            }

            $appliedOn = (string) ($settings->get($markerKey, 'registration') ?: '');
            if ($appliedOn >= $dueOn) {
                continue;
            }

            try {
                $settings->set($markerKey, $dueOn, 'registration');
            } catch (SettingException) {
                // The marker is declared in module.json and created when
                // the module is activated. If it is absent, the schedule
                // setting read just above came from an older registration
                // of the same block — settle what can be settled rather
                // than turning a manual open into a 500.
                continue;
            }
            $settled[$markerKey] = $dueOn;
        }

        return $settled;
    }

    /**
     * The configured catch-up window, clamped to [0, MAX_CATCH_UP_DAYS]. A
     * missing or non-numeric setting falls back to the default rather than
     * to 0, so an install that predates this setting keeps a working
     * catch-up instead of silently reverting to same-day-only.
     */
    public static function catchUpDays(SettingService $settings): int
    {
        $raw = $settings->get(self::CATCH_UP_SETTING, 'registration');
        if ($raw === null || !is_numeric((string) $raw)) {
            return self::DEFAULT_CATCH_UP_DAYS;
        }

        return max(0, min(self::MAX_CATCH_UP_DAYS, (int) $raw));
    }
}
