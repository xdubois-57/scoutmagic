<?php

declare(strict_types=1);

namespace Modules\Calendar\Task;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Mail\MailException;
use Core\Member\SectionService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\View\TwigFactory;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;

/**
 * Sends the multi-day event reminder (module spec: Configuration >
 * Calendrier > Notifications) — payload is exactly what
 * Service\CalendarNotificationService::syncReminderForEvent() scheduled:
 * `event_id` and `calendar_id`. Re-resolves everything fresh at send time
 * (the event, the calendar, the current scout year's staff roster) rather
 * than trusting anything computed at scheduling time, since a reminder can
 * be scheduled many weeks in advance of the event itself.
 *
 * A fresh set of services is built from TaskContext on every run — task
 * handlers have no persistent DI container (see docs/module-development.md).
 */
class MultidayEventReminderHandler implements TaskHandlerInterface
{
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $eventId = isset($payload['event_id']) ? (int) $payload['event_id'] : 0;
        $eventRepository = new CalendarEventRepository($pdo);
        $event = $eventRepository->findById($eventId);
        if ($event === null) {
            return; // deleted since scheduling
        }

        $calendarRepository = new CalendarRepository($pdo);
        $calendar = $calendarRepository->findById($event->calendarId);
        if ($calendar === null || $calendar->sectionId === null) {
            return; // calendar deleted, or no longer a section calendar
        }

        $sectionService = new SectionService($context->connection, $context->encryption, new MemberBadgeRepository($pdo));
        $scoutYearId = (int) (new ScoutYearService($pdo))->getCurrentYear()['id'];
        $staff = $sectionService->getSectionStaff($calendar->sectionId, $scoutYearId);
        if ($staff === []) {
            return;
        }

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['calendar' => dirname(__DIR__, 4) . '/modules/calendar/views']
        );

        $context->journal->log(
            'calendar',
            'multiday_event_reminder_sent',
            'info',
            "Rappel envoyé pour l'évènement « {$event->title} »",
            ['event_id' => $event->id, 'calendar_id' => $calendar->id, 'recipients' => count($staff)],
            null
        );

        foreach ($staff as $profile) {
            if ($profile->email === null || $profile->email === '') {
                continue;
            }

            try {
                $context->mailService->send(
                    to: $profile->email,
                    subject: "Rappel — {$event->title}",
                    bodyHtml: $twig->render('@calendar/email/multiday_event_reminder.html.twig', [
                        'display_name' => $profile->getDisplayName(),
                        'event_title' => $event->title,
                        'start_date' => $event->startDate,
                        'end_date' => $event->endDate,
                    ]),
                    bodyText: $twig->render('@calendar/email/multiday_event_reminder.text.twig', [
                        'display_name' => $profile->getDisplayName(),
                        'event_title' => $event->title,
                        'start_date' => $event->startDate,
                        'end_date' => $event->endDate,
                    ])
                );
            } catch (MailException $e) {
                // Best-effort per recipient — one bad address must never
                // stop the rest of the section's staff from being reminded.
            }
        }
    }
}
