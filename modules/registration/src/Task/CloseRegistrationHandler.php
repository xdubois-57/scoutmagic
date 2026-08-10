<?php

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Polls the `registration_scheduled_close_at` setting hourly and flips
 * `registration_form_open` off once it's due — mirrors Task\
 * OpenRegistrationHandler exactly (see its docblock for the "poll rather
 * than react" rationale and the one-shot clear-after-acting behavior).
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
        $scheduledAt = trim((string) ($context->settings->get('registration_scheduled_close_at', 'registration') ?: ''));
        if ($scheduledAt !== '') {
            $due = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $scheduledAt) ?: null;
            if ($due !== null && $due <= new \DateTimeImmutable()) {
                $context->settings->set('registration_form_open', '0', 'registration');
                $context->settings->set('registration_scheduled_close_at', '', 'registration');
                $context->journal->log(
                    'registration',
                    'registration_form_auto_closed',
                    'info',
                    'Formulaire d\'inscription fermé automatiquement (fermeture programmée)'
                );
            }
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('registration', 'close_registration', self::INTERVAL_SECONDS, [], self::REFERENCE);
    }
}
