<?php

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Polls the `registration_scheduled_close_at` setting hourly and flips
 * `registration_form_open` off once due — mirrors Task\
 * OpenRegistrationHandler exactly (see its docblock for the "poll rather
 * than react", recurring MM-DD, and applied-on-marker rationale).
 */
class CloseRegistrationHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'poll';
    private const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $monthDay = trim((string) ($context->settings->get('registration_scheduled_close_at', 'registration') ?: ''));
        if (preg_match('/^\d{2}-\d{2}$/', $monthDay) === 1) {
            $now = new \DateTimeImmutable();
            if ($now->format('m-d') === $monthDay) {
                $today = $now->format('Y-m-d');
                $appliedOn = (string) ($context->settings->get('registration_scheduled_close_applied_on', 'registration') ?: '');
                if ($appliedOn !== $today) {
                    $context->settings->set('registration_form_open', '0', 'registration');
                    $context->settings->set('registration_scheduled_close_applied_on', $today, 'registration');
                    $context->journal->log(
                        'registration',
                        'registration_form_auto_closed',
                        'info',
                        'Formulaire d\'inscription fermé automatiquement (fermeture programmée annuelle)'
                    );
                }
            }
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('registration', 'close_registration', self::INTERVAL_SECONDS, [], self::REFERENCE);
    }
}
