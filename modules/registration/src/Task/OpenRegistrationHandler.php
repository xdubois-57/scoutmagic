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
 * it annually. Fires exactly once per calendar day the day's own m-d
 * matches — `registration_scheduled_open_applied_on` (a real Y-m-d) is
 * the guard against firing again on a later poll the same day, and
 * against ever "catching up" a date that already passed earlier this
 * year (cron-like: waits for the next real occurrence, never retroactive)
 * — never cleared, unlike the old one-shot design, since the schedule
 * itself is meant to persist. A chief can still open/close the form
 * immediately at any time via Controller\RegistrationConfigController's
 * own manual toggle; that path never touches this applied-on marker, so
 * it never interferes with next year's automatic transition.
 */
class OpenRegistrationHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'poll';
    private const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $monthDay = trim((string) ($context->settings->get('registration_scheduled_open_at', 'registration') ?: ''));
        if (preg_match('/^\d{2}-\d{2}$/', $monthDay) === 1) {
            $now = new \DateTimeImmutable();
            if ($now->format('m-d') === $monthDay) {
                $today = $now->format('Y-m-d');
                $appliedOn = (string) ($context->settings->get('registration_scheduled_open_applied_on', 'registration') ?: '');
                if ($appliedOn !== $today) {
                    $context->settings->set('registration_form_open', '1', 'registration');
                    $context->settings->set('registration_scheduled_open_applied_on', $today, 'registration');
                    $context->journal->log(
                        'registration',
                        'registration_form_auto_opened',
                        'info',
                        'Formulaire d\'inscription ouvert automatiquement (ouverture programmée annuelle)'
                    );
                }
            }
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('registration', 'open_registration', self::INTERVAL_SECONDS, [], self::REFERENCE);
    }
}
