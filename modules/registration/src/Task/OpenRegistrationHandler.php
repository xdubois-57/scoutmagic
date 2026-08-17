<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

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
 * shortly after the configured day — see dueDateForYear() and
 * CATCH_UP_DAYS: a cron that missed the day itself still catches up, while
 * a date configured (or a module enabled) months later never fires
 * retroactively. `registration_scheduled_open_applied_on` stores the
 * OCCURRENCE's own date and is the guard against firing twice — never
 * cleared, unlike the old one-shot design, since the schedule itself is
 * meant to persist. A chief can still open/close the form
 * immediately at any time via Controller\RegistrationConfigController's
 * own manual toggle; that path never touches this applied-on marker, so
 * it never interferes with next year's automatic transition.
 */
class OpenRegistrationHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'poll';
    private const INTERVAL_SECONDS = 3600;

    /**
     * How many days after the configured date a missed occurrence may still
     * fire. Wide enough to absorb a cron outage of a day or two, far too
     * narrow to resurrect a date configured months later — see
     * dueDateForYear().
     */
    public const CATCH_UP_DAYS = 2;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $monthDay = trim((string) ($context->settings->get('registration_scheduled_open_at', 'registration') ?: ''));
        if (preg_match('/^\d{2}-\d{2}$/', $monthDay) === 1) {
            $dueOn = self::dueDateForYear($monthDay, new \DateTimeImmutable());
            $appliedOn = (string) ($context->settings->get('registration_scheduled_open_applied_on', 'registration') ?: '');

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
        $schedulerService->scheduleAfter('registration', 'open_registration', self::INTERVAL_SECONDS, [], self::REFERENCE);
    }

    /**
     * The occurrence of a recurring MM-DD that is currently DUE, as Y-m-d,
     * or null when none is.
     *
     * "Due" means reached, and reached recently: within CATCH_UP_DAYS of the
     * date itself. That window is the whole point —
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
     * Last year's occurrence is considered too, so a MM-DD near the turn of
     * the year (e.g. 12-31) can still be caught up on the 1st or the 2nd.
     *
     * The applied-on marker stores this DUE date, never the day the task
     * happened to run — that is what keeps "at most once per occurrence"
     * true across several polls inside the window.
     *
     * Public and static: pure date arithmetic, worth testing directly.
     */
    public static function dueDateForYear(string $monthDay, \DateTimeImmutable $now): ?string
    {
        $today = $now->setTime(0, 0);
        $currentYear = (int) $now->format('Y');

        foreach ([$currentYear, $currentYear - 1] as $year) {
            $candidate = sprintf('%04d-%s', $year, $monthDay);
            $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
            // The round-trip check rejects a date PHP would silently roll
            // over (02-29 on a non-leap year becomes 03-01).
            if ($due === false || $due->format('Y-m-d') !== $candidate) {
                continue;
            }

            $daysSinceDue = (int) $due->diff($today)->format('%r%a');
            if ($daysSinceDue >= 0 && $daysSinceDue <= self::CATCH_UP_DAYS) {
                return $candidate;
            }
        }

        return null;
    }
}
