<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Retro\Repository\BoardRepository;

/**
 * Scheduled by Service\CalendarRetroAutoCreateService::syncAutoCreateForEvent()
 * (reference "event-{id}") for an event whose auto_create_retro flag is
 * set. Self-contained — task handlers get no persistent DI container (see
 * docs/module-development.md) — and re-checks everything fresh at run
 * time rather than trusting anything true when it was scheduled, since a
 * task can be scheduled weeks in advance:
 *   1. the retro module must still be enabled right now (task handlers get
 *      no ModuleManager — the established raw-query workaround, same
 *      precedent used wherever else this codebase needs it);
 *   2. the event must still exist;
 *   3. no board must already be linked (idempotency — a chief may have
 *      manually created/linked one before this ran, or this handler may
 *      somehow run twice).
 * Creates the board directly via BoardRepository (not Modules\Retro\
 * Service\BoardService — its constructor needs MemberService/
 * SectionService/SchedulerService/ShortUrlService/MailService/Twig, all
 * irrelevant here) — same "small tolerated duplication" convention as
 * Modules\Retro\Task\AutoCloseHandler.
 */
class AutoCreateRetroHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $eventId = (int) ($payload['event_id'] ?? 0);
        if ($eventId <= 0) {
            return;
        }

        $pdo = $context->connection->getPdo();

        $retroEnabled = $pdo->query("SELECT enabled FROM module_registry WHERE module_id = 'retro'")->fetchColumn();
        if ((int) $retroEnabled !== 1) {
            return;
        }

        $eventRepository = new CalendarEventRepository($pdo);
        $event = $eventRepository->findById($eventId);
        if ($event === null) {
            return; // deleted since scheduling
        }

        $boardRepository = new BoardRepository($pdo);
        if ($boardRepository->findByCalendarEventId($eventId) !== null) {
            return; // already linked — nothing to do
        }

        $calendarRepository = new CalendarRepository($pdo);
        $calendar = $calendarRepository->findById($event->calendarId);
        $calendarName = $this->resolveCalendarName($calendar, $pdo);

        $settingService = new SettingService(new SettingRepository($pdo));
        $maxCommentLength = (int) ($settingService->get('retro_default_max_comment_length', 'retro') ?: 140);
        $voteBudget = (int) ($settingService->get('retro_default_vote_budget', 'retro') ?: 5);

        $title = "Rétrospective {$event->title} - {$calendarName} - {$event->startDate}";
        $token = bin2hex(random_bytes(32));
        $autoCloseAt = (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');

        $boardId = $boardRepository->create(
            $title,
            $event->endDate ?? $event->startDate,
            $eventId,
            $token,
            null,
            true,
            'unlimited',
            $voteBudget,
            true,
            'cookie',
            $maxCommentLength,
            '7d',
            $autoCloseAt,
            null,
            true,
            null,
            'chief'
        );

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->schedule(
            'retro',
            'auto_close_board',
            new \DateTimeImmutable($autoCloseAt),
            ['board_id' => $boardId],
            'board_' . $boardId
        );

        $context->journal->log(
            'retro',
            'board_auto_created_from_event',
            'info',
            "Rétrospective créée automatiquement pour l'évènement « {$event->title} »",
            ['board_id' => $boardId, 'event_id' => $eventId],
            null
        );
    }

    /**
     * Section calendars never have their own `name` column set — the
     * display label is the section's own name, resolved via a direct join
     * rather than constructing a full Core\Member\SectionService here.
     * Mirrors Service\CalendarService::labelsByCalendarId()'s fallback
     * chain (section name → desk_code → 'Section'; own name → 'Calendrier'
     * for a supplementary calendar).
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
