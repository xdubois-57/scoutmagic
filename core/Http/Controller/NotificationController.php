<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Notification\NotificationRecord;
use Core\Notification\NotificationRepository;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * Notification centre (Core\Notification, Lot 2) — GET /notifications,
 * role_min identified: every notification belongs to the requesting
 * account (never another one's), so the boundary is "identified" plus
 * always filtering by AuthSession::getUserAccountId(), never a param.
 */
class NotificationController extends AbstractController
{
    private const PAGE_SIZE = 100;

    public function __construct(
        protected Environment $twig,
        private NotificationRepository $notificationRepository
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $userId = AuthSession::getUserAccountId();
        $records = $this->notificationRepository->findByUserAccountId((int) $userId, self::PAGE_SIZE);

        return $this->render('notifications/index.html.twig', [
            'day_groups' => $this->groupByDay($records),
            'unread_count' => $this->notificationRepository->countUnread((int) $userId),
        ]);
    }

    /**
     * POST /notifications/{id}/read — marks one notification read, then
     * redirects to its own target url (falls back to the centre itself
     * when it has none). A plain GET link can't carry CSRF protection, so
     * each row in the centre is its own tiny form rather than an <a>.
     *
     * @param array<string, string> $params
     */
    public function markRead(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if (($guard = $this->guardCsrf($request, '/notifications')) !== null) {
            return $guard;
        }

        $userId = AuthSession::getUserAccountId();
        $record = $this->notificationRepository->findById($id);

        if ($record === null || $record->userAccountId !== $userId) {
            return $this->redirect('/notifications');
        }

        $this->notificationRepository->markRead($id);

        return $this->redirect($record->url ?? '/notifications');
    }

    /**
     * @param array<string, string> $params
     */
    public function markAllRead(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/notifications')) !== null) {
            return $guard;
        }

        $userId = AuthSession::getUserAccountId();
        $this->notificationRepository->markAllReadForUser((int) $userId);

        return $this->redirect('/notifications');
    }

    /**
     * GET /api/notifications/unread-count — polled every 60s by
     * public/assets/js/notification-badge.js (same setInterval pattern as
     * the backup-status/magic-link polls), plus refreshed immediately on
     * an incoming push via the service worker's postMessage.
     *
     * @param array<string, string> $params
     */
    public function unreadCount(Request $request, array $params): Response
    {
        $userId = AuthSession::getUserAccountId();

        return $this->json(['count' => $this->notificationRepository->countUnread((int) $userId)]);
    }

    /**
     * Buckets notifications by calendar day, most recent day first,
     * labeled "Aujourd'hui"/"Hier"/a full French date — pure presentation
     * shaping, no domain rule, so it stays here rather than in a
     * dedicated service (compare Core\Http\Controller\
     * MaintenanceController's similarly inline status-list rendering).
     *
     * @param NotificationRecord[] $records
     * @return array<int, array{label: string, notifications: NotificationRecord[]}>
     */
    private function groupByDay(array $records): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        $groups = [];
        foreach ($records as $record) {
            $day = substr($record->createdAt, 0, 10);
            if (!isset($groups[$day])) {
                $label = match ($day) {
                    $today => "Aujourd'hui",
                    $yesterday => 'Hier',
                    default => (new \DateTimeImmutable($day))->format('j') . ' ' . $this->frenchMonth((int) (new \DateTimeImmutable($day))->format('n')) . ' ' . (new \DateTimeImmutable($day))->format('Y'),
                };
                $groups[$day] = ['label' => $label, 'notifications' => []];
            }
            $groups[$day]['notifications'][] = $record;
        }

        return array_values($groups);
    }

    private function frenchMonth(int $month): string
    {
        static $months = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
            7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];

        return $months[$month];
    }
}
