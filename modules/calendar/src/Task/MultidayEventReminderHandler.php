<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Task;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Mail\MailException;
use Core\Member\SectionService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Service\DateInput;
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

        $calendarRepository = new CalendarRepository($pdo, $context->encryption);
        $calendar = $calendarRepository->findById($event->calendarId);
        if ($calendar === null || $calendar->sectionId === null) {
            return; // calendar deleted, or no longer a section calendar
        }

        $sectionService = new SectionService(
            $context->connection,
            $context->encryption,
            new MemberBadgeRepository($pdo)
        );
        // The year the EVENT falls in, not "today's". getCurrentYear() is
        // the date-computed year and it goes further than reading: it
        // CREATES the new year's row (ScoutYearService::ensureYear()). A
        // reminder for an event of late August, running after the 1st of
        // September, therefore looked for the section's staff in a year
        // whose roster has not been imported yet, found nobody, and
        // returned — no e-mail, no journal line, nothing anywhere saying
        // the reminder had been dropped. The staff of that event exist;
        // they exist in the event's own year.
        $scoutYearService = new ScoutYearService($pdo);
        $eventDate = DateInput::iso($event->startDate);
        $year = $eventDate !== null
            ? $scoutYearService->findByLabel(ScoutYearService::labelForDate($eventDate))
            : null;
        $scoutYearId = (int) ($year['id'] ?? $scoutYearService->getCurrentYear()['id']);

        $staff = $sectionService->getSectionStaff($calendar->sectionId, $scoutYearId);
        if ($staff === []) {
            // Said out loud rather than returned in silence: "the section
            // has no staff that year" is a state somebody has to be able
            // to find afterwards, and it is the shape the disappearance
            // above took.
            $context->journal->log(
                'calendar',
                'multiday_event_reminder_skipped',
                'warning',
                "Rappel non envoyé pour l'évènement « {$event->title} » : aucun animateur pour cette section",
                ['event_id' => $event->id, 'calendar_id' => $calendar->id, 'scout_year_id' => $scoutYearId],
                null
            );

            return;
        }

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['calendar' => dirname(__DIR__, 4) . '/modules/calendar/views']
        );

        // Core's templates plus this module's own: a handler runs outside
        // the composition root, so nothing has aggregated the manifests
        // for it (ARCHITECTURE.md §8.7bis). A customisation is honoured
        // all the same — that lives in the database, not in the registry.
        $registry = new \Core\Mail\Template\EmailTemplateRegistry();
        $registry->registerModuleManifest(
            \Core\Module\ModuleManifest::fromFile(dirname(__DIR__, 2) . '/module.json')
        );
        $renderer = new \Core\Mail\Template\EmailTemplateRenderer(
            $twig,
            $registry,
            new \Core\Mail\Template\EmailTemplateOverrideRepository($context->connection->getPdo()),
            $context->journal
        );

        $sent = 0;
        $failed = 0;

        foreach ($staff as $profile) {
            if ($profile->email === null || $profile->email === '') {
                continue;
            }

            try {
                // The declared subject is « Rappel — {{ event_title }} »,
                // so with nothing customised this is the same message as
                // the direct Twig renders it replaces — recipient by
                // recipient, since display_name differs for each.
                $email = $renderer->render('calendar.multiday_event_reminder', [
                    'display_name' => $profile->getDisplayName(),
                    'event_title' => $event->title,
                    'start_date' => $event->startDate,
                    'end_date' => $event->endDate,
                ]);

                $context->mailService->send(
                    to: $profile->email,
                    subject: $email->subject,
                    bodyHtml: $email->bodyHtml,
                    bodyText: $email->bodyText
                );
                $sent++;
            } catch (MailException) {
                // Best-effort per recipient — one bad address must never
                // stop the rest of the section's staff from being
                // reminded — but counted, never swallowed whole. The
                // reason itself is already in the journal:
                // Core\Mail\MailService writes `mail_send_failed` for
                // every send that does not leave.
                $failed++;
            }
        }

        // AFTER the loop, and counting what actually left. The line used
        // to be written before the first send, with « recipients » set to
        // the number of people AIMED AT — so a run where every single
        // send failed left a journal reading « Rappel envoyé », and
        // nothing else anywhere.
        $context->journal->log(
            'calendar',
            'multiday_event_reminder_sent',
            $failed > 0 ? 'warning' : 'info',
            "Rappel envoyé pour l'évènement « {$event->title} »",
            [
                'event_id' => $event->id,
                'calendar_id' => $calendar->id,
                'recipients' => count($staff),
                'sent' => $sent,
                'failed' => $failed,
            ],
            null
        );
    }
}
