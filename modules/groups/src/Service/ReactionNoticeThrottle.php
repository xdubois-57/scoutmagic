<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Config\AppClock;
use Core\Config\SettingService;
use Modules\Groups\Repository\ReactionNoticeRepository;
use Modules\Groups\Support\Timestamps;

/**
 * "Has this item already produced a reaction notification recently?"
 *
 * A reaction is by far the cheapest thing to do in a group and therefore
 * by far the noisiest thing to notify about: a post that picks up twelve
 * reactions over a coffee break would otherwise put twelve entries in its
 * author's centre and twelve buzzes on their phone, which turns the
 * feature from useful into something people switch off. One notification
 * per item per window, and the rest are simply not sent — the reactions
 * themselves are all still there on the post, which is where anyone
 * curious about them actually looks.
 *
 * The window is a setting rather than a constant because the right value
 * depends on how a unit uses its groups, and because "make it stop"
 * needs an answer that is not "edit the code".
 *
 * State lives in this module's own two small tables
 * (Repository\ReactionNoticeRepository). Core's `notifications` table is
 * never read to find out what was already sent: it is core's, and a
 * module must not read it (ARCHITECTURE.md §8.24).
 */
class ReactionNoticeThrottle
{
    public const SETTING_WINDOW = 'groups_reaction_notification_window_minutes';

    private const DEFAULT_WINDOW_MINUTES = 60;

    public function __construct(
        private ReactionNoticeRepository $postNotices,
        private ReactionNoticeRepository $replyNotices,
        private SettingService $settingService
    ) {
    }

    /**
     * Whether a notification should be sent for this post right now, and
     * — when it should — stamps it in the same call.
     *
     * Check-and-stamp together rather than as two public steps: a caller
     * that checked and then forgot to stamp would silently disable the
     * whole debounce, and nothing about the resulting flood would point
     * back here.
     */
    public function shouldNotifyForPost(int $postId): bool
    {
        return $this->shouldNotify($this->postNotices, $postId);
    }

    public function shouldNotifyForReply(int $replyId): bool
    {
        return $this->shouldNotify($this->replyNotices, $replyId);
    }

    /**
     * The configured window in minutes, floored at 0. Zero is a legitimate
     * setting and means "no debouncing at all" — every reaction notifies —
     * which is why this is not floored at 1 the way
     * Service\ReportService::threshold() is: there, 0 would have made the
     * feature dangerous; here it only makes it noisy, and that is the
     * admin's call to make.
     */
    public function windowMinutes(): int
    {
        $raw = $this->settingService->get(self::SETTING_WINDOW, 'groups', self::DEFAULT_WINDOW_MINUTES);
        $configured = is_numeric($raw) ? (int) $raw : self::DEFAULT_WINDOW_MINUTES;

        return max(0, $configured);
    }

    private function shouldNotify(ReactionNoticeRepository $repository, int $itemId): bool
    {
        $window = $this->windowMinutes();
        $now = AppClock::now();

        if ($window > 0) {
            $last = $repository->lastNotifiedAt($itemId);
            if ($last !== null && Timestamps::parse($last)->modify('+' . $window . ' minutes') > $now) {
                return false;
            }
        }

        $repository->stamp($itemId, $now->format('Y-m-d H:i:s'));

        return true;
    }
}
