<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\AppClock;
use Modules\Gallery\Repository\ZipDownloadRepository;

/**
 * At most LIMIT whole-album ZIPs per account per hour (issue #1112, audit
 * SEC-013) — the same mechanism as Modules\Groups\Service\RateLimitService,
 * over this module's own table.
 *
 * A ZIP is the one gallery route that hands over a whole album in a single
 * request. Ten an hour is far more than anybody saving the photos of
 * their child's camp needs, and far less than a script walking the album
 * ids. One media at a time is not counted: that is what the lightbox's
 * own button does, and it carries the gallery away no faster than a
 * browser does.
 *
 * GalleryController::downloadZip() asks AFTER the visibility check, so an
 * album the caller may not see never spends their budget, and BEFORE any
 * archive work, so a refusal costs no storage read. An accepted attempt is
 * counted whether or not the archive then succeeds (a 413 for an album
 * too big, say) — the work was started either way.
 */
class ZipDownloadLimiter
{
    public const LIMIT = 10;

    public const WINDOW = '-1 hour';

    public function __construct(private ZipDownloadRepository $repository)
    {
    }

    /**
     * Whether this account may start one more ZIP now — and, when it may,
     * counts it.
     */
    public function tryAcquire(int $userAccountId): bool
    {
        // Both ends from the same clock: the row is written with now() and
        // the window starts at now() - 1 hour, so a timezone between them
        // can neither widen nor close it (see Modules\Groups\Support\
        // Timestamps for the day that went wrong).
        $now = AppClock::now();
        $since = $now->modify(self::WINDOW)->format('Y-m-d H:i:s');

        if ($this->repository->countSince($userAccountId, $since) >= self::LIMIT) {
            return false;
        }

        $this->repository->record($userAccountId, $now->format('Y-m-d H:i:s'));

        return true;
    }
}
