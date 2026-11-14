<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\AppClock;
use Modules\Registration\Repository\ConfirmationSendRepository;

/**
 * At most LIMIT secondary-address confirmation e-mails per registration
 * request per rolling hour, first sends and re-sends alike (issue #1126) —
 * the same mechanism as Modules\Gallery\Service\ZipDownloadLimiter, over
 * this module's own table.
 *
 * SecondaryEmailService::MAX_SECONDARY_EMAILS caps how many addresses a
 * request holds, but removing one deletes its row, so remove-then-add
 * would stay under that cap for ever and send an e-mail every time. This
 * counts the e-mails instead. Twenty an hour is far more than a family
 * adding its addresses needs, and far less than a script using a tracking
 * link to mail strangers in the unit's name.
 */
class ConfirmationSendLimiter
{
    public const LIMIT = 20;

    public const WINDOW = '-1 hour';

    public function __construct(private ConfirmationSendRepository $repository)
    {
    }

    /**
     * Whether this request may send one more confirmation e-mail now — and,
     * when it may, counts it.
     */
    public function tryAcquire(int $requestId): bool
    {
        // Both ends from the same clock: the row is written with now() and
        // the window starts at now() - 1 hour (see ZipDownloadLimiter).
        $now = AppClock::now();
        $since = $now->modify(self::WINDOW)->format('Y-m-d H:i:s');

        if ($this->repository->countSince($requestId, $since) >= self::LIMIT) {
            return false;
        }

        $this->repository->record($requestId, $now->format('Y-m-d H:i:s'));

        return true;
    }
}
