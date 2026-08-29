<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;
use Core\Security\CapabilityToken;
use Modules\Retro\Api\RetroBoardCreationInterface;
use Modules\Retro\Repository\BoardRepository;

/**
 * Api\RetroBoardCreationInterface implementation for the scheduled,
 * session-less path (the calendar's AutoCreateRetroHandler resolves it
 * through TaskCapabilities).
 *
 * Deliberately NOT BoardService: that class carries the request-path
 * collaborators (MemberService, MailService, Twig, a base URL for short
 * links…) that a task run has no business constructing, and its create()
 * resolves titles and dates against a viewer Role this path does not
 * have. What the two share is the board's normative shape, and that is
 * exactly what this class owns for the automatic case: the defaults the
 * unit configured (`retro_default_*` settings), the 7-day auto-close and
 * its `auto_close_board` scheduling (mirroring
 * BoardService::scheduleAutoClose(), reference `board_{id}`, so
 * Task\AutoCloseHandler treats both origins identically), the capability
 * token, the one-board-per-event rule, and the journal trail.
 *
 * An auto-created board is `link_visibility: chief` and listed — the same
 * stance the manual creation page defaults to: the retro exists for the
 * section's chiefs, and widening it is a decision a human takes on the
 * board afterwards.
 */
class AutoBoardCreationService implements RetroBoardCreationInterface
{
    private const AUTO_CLOSE_DELAY = '7d';
    private const DEFAULT_MAX_COMMENT_LENGTH = 140;
    private const DEFAULT_VOTE_BUDGET = 5;

    public function __construct(
        private BoardRepository $boardRepository,
        private SettingService $settingService,
        private SchedulerService $schedulerService,
        private JournalService $journalService
    ) {
    }

    public function createBoardForEvent(
        int $calendarEventId,
        string $eventTitle,
        string $calendarLabel,
        string $eventStartDate,
        ?string $eventEndDate
    ): ?int {
        // One board per event, whoever created it: a chief may have
        // manually created/linked one between the scheduling and this run,
        // and the creation task may conceivably run twice.
        if ($this->boardRepository->findByCalendarEventId($calendarEventId) !== null) {
            return null;
        }

        $maxCommentLength = (int) ($this->settingService->get('retro_default_max_comment_length', 'retro')
            ?: self::DEFAULT_MAX_COMMENT_LENGTH);
        $voteBudget = (int) ($this->settingService->get('retro_default_vote_budget', 'retro')
            ?: self::DEFAULT_VOTE_BUDGET);

        $title = "Rétrospective {$eventTitle} - {$calendarLabel} - {$eventStartDate}";
        $autoCloseMoment = new \DateTimeImmutable('+' . rtrim(self::AUTO_CLOSE_DELAY, 'd') . ' days');

        $boardId = $this->boardRepository->create(
            $title,
            $eventEndDate ?? $eventStartDate,
            $calendarEventId,
            CapabilityToken::generate(),
            null,
            true,
            'unlimited',
            $voteBudget,
            true,
            'cookie',
            $maxCommentLength,
            self::AUTO_CLOSE_DELAY,
            $autoCloseMoment->format('Y-m-d H:i:s'),
            null,
            true,
            null,
            'chief'
        );

        $this->schedulerService->schedule(
            'retro',
            'auto_close_board',
            $autoCloseMoment,
            ['board_id' => $boardId],
            'board_' . $boardId
        );

        $this->journalService->log(
            'retro',
            'board_auto_created_from_event',
            'info',
            "Rétrospective créée automatiquement pour l'évènement « {$eventTitle} »",
            ['board_id' => $boardId, 'event_id' => $calendarEventId],
            null
        );

        return $boardId;
    }
}
