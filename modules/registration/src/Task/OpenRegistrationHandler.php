<?php

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Polls the `registration_scheduled_open_at` setting hourly and flips
 * `registration_form_open` on once it's due — self-reschedules at the end
 * of every run rather than being a first-class recurring task (same
 * pattern as Modules\Retro\Task\PurgeRateLimitHandler). Clears the
 * scheduled-at setting once acted on so it behaves as a one-shot instant,
 * not a recurring re-open every hour after a chief manually closes the
 * form again. A poll (rather than reacting to the setting being saved) is
 * used because the generic Core\Http\Controller\SettingsController::
 * update() AJAX endpoint has no per-setting side-effect hook.
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
        $scheduledAt = trim((string) ($context->settings->get('registration_scheduled_open_at', 'registration') ?: ''));
        if ($scheduledAt !== '') {
            $due = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $scheduledAt) ?: null;
            if ($due !== null && $due <= new \DateTimeImmutable()) {
                $context->settings->set('registration_form_open', '1', 'registration');
                $context->settings->set('registration_scheduled_open_at', '', 'registration');
                $context->journal->log(
                    'registration',
                    'registration_form_auto_opened',
                    'info',
                    'Formulaire d\'inscription ouvert automatiquement (ouverture programmée)'
                );
            }
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('registration', 'open_registration', self::INTERVAL_SECONDS, [], self::REFERENCE);
    }
}
