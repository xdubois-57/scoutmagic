<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Task;

use Core\Config\ScoutYearService;
use Core\Member\SectionAccountRepository;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Service\EventAudienceResolver;

/**
 * Sends the "your activity is tomorrow" notification-centre/push
 * reminder (module.json's "calendar.event_reminder" type) — payload is
 * exactly what Service\CalendarNotificationService::
 * syncActivityReminderForEvent() scheduled: `event_id`. Re-resolves the
 * event fresh at send time rather than trusting anything computed at
 * scheduling time, since the reminder can be scheduled weeks in advance.
 *
 * A fresh set of services is built from TaskContext on every run — task
 * handlers have no persistent DI container (see docs/module-development.md).
 */
class EventReminderHandler implements TaskHandlerInterface
{
    public function handle(array $payload, TaskContext $context): void
    {
        if ($context->notifications === null) {
            return;
        }

        $pdo = $context->connection->getPdo();

        $eventId = isset($payload['event_id']) ? (int) $payload['event_id'] : 0;
        $eventRepository = new CalendarEventRepository($pdo);
        $event = $eventRepository->findById($eventId);
        if ($event === null) {
            return; // deleted since scheduling
        }

        // Resolved now, not when the reminder was scheduled — weeks ago, perhaps
        // (issue #1348): an activity changed, a child moved to another section
        // or a calendar's visibility tightened since is taken into account.
        // The audience is the one the publication path tells — the accounts
        // personally linked to the section, never every account of the unit.
        $calendar = (new CalendarRepository($pdo, $context->encryption))->findById($event->calendarId);
        $recipients = array_map(
            static fn(int $id): array => ['userAccountId' => $id, 'memberId' => null],
            (new EventAudienceResolver(
                $context->userAccounts,
                new SectionAccountRepository($pdo),
                new ScoutYearService($pdo)
            ))->accountIdsFor($calendar, $event)
        );
        if ($recipients === []) {
            return;
        }

        $calendarLabel = $this->resolveCalendarName($calendar, $pdo);

        $context->notifications->dispatch('calendar.event_reminder', $recipients, [
            'title' => 'Rappel d\'activité',
            'body' => "{$calendarLabel} — l'activité « {$event->title} » a lieu demain.",
            'url' => '/calendar',
        ]);
    }

    /**
     * Same resolution Task\AutoCreateRetroHandler::resolveCalendarName()
     * already does (small tolerated duplication — task handlers get no
     * persistent DI container, so sharing a helper isn't worth a new
     * cross-handler dependency): a section calendar's name is its
     * section's own name (falling back to its Desk code), a supplementary
     * calendar's name is its own.
     */
    private function resolveCalendarName(?Calendar $calendar, \PDO $pdo): string
    {
        if ($calendar === null) {
            return 'Calendrier';
        }
        if ($calendar->sectionId === null) {
            return $calendar->name ?? 'Calendrier';
        }

        $stmt = $pdo->prepare('SELECT name, desk_code FROM sections WHERE id = ?');
        $stmt->execute([$calendar->sectionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return 'Section';
        }

        return $row['name'] !== null && $row['name'] !== '' ? (string) $row['name'] : (string) $row['desk_code'];
    }
}
