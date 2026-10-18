<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Config\AppClock;
use Core\Service\DateInput;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;

/**
 * What the site may say about when a Google Drive location's grant stops
 * working (issue #718 § C points 10.2 to 10.5) — read by the storage page
 * and by StorageAttentionProvider, so the two never disagree.
 *
 * It used to say one thing for every Drive location, connected or not:
 * « an authorisation expires after 7 days while the Google project is in
 * Test ». That was a guess about somebody else's project. What the site
 * can actually know is what Google announced when the grant was obtained
 * (`refresh_token_expires_in`, kept on the location's config), and what
 * the location's own checks have since observed.
 *
 * Google sends no event when a project changes status, so a deadline is
 * dropped on evidence only: **a check that succeeded after it** proves the
 * announced end no longer applies. No check, or one older than the
 * deadline, proves nothing, and the risk stays shown.
 */
final class DriveGrantExpiry
{
    /** Disconnected, or connected with no end announced: nothing to say. */
    public const NONE = 'none';
    /** An end was announced and nothing has disproved it yet. */
    public const KNOWN = 'known';
    /** Connected before the lifetime was looked at: nobody knows. */
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $state,
        /** The announced end, for KNOWN. */
        public readonly ?\DateTimeImmutable $expiresAt = null,
        /** Whether a check after the end has failed — the grant is very likely gone. */
        public readonly bool $failedSince = false
    ) {
    }

    public static function of(StorageLocation $location): self
    {
        $config = $location->config;
        if (!$config instanceof GoogleDriveLocationConfig || !$config->isConnected()) {
            return new self(self::NONE);
        }
        if (!$config->grantObserved) {
            return new self(self::UNKNOWN);
        }

        $expiresAt = DateInput::fromStorage($config->grantExpiresAt, AppClock::zone());
        if ($expiresAt === null) {
            // Observed, and Google announced no end. Not « permanent », and
            // not « en production » either: only « nothing to count down ».
            return new self(self::NONE);
        }

        $checkedAt = DateInput::fromStorage($location->lastCheckedAt, AppClock::zone());
        $checkedSince = $checkedAt !== null && $checkedAt > $expiresAt;
        if ($checkedSince && $location->lastCheckOk === true) {
            return new self(self::NONE);
        }

        return new self(self::KNOWN, $expiresAt, $checkedSince && $location->lastCheckOk === false);
    }

    /** Whole days from $now to the end, negative once it has passed. */
    public function daysLeft(\DateTimeImmutable $now): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }
        $days = (int) $now->setTime(0, 0)->diff($this->expiresAt->setTimezone($now->getTimezone())->setTime(0, 0))
            ->format('%r%a');

        return $days;
    }
}
