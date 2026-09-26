<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Modules\Social\Api\SocialDestination;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Api\SocialSharingInterface;
use Modules\Social\Repository\ConnectionRepository;

/**
 * The Api's implementation: the connections as the rest of the site may
 * see them — which accounts, never how.
 */
final class SocialSharingService implements SocialSharingInterface
{
    public function __construct(private readonly ConnectionRepository $connections)
    {
    }

    public function connectedDestinations(): array
    {
        $now = new \DateTimeImmutable();
        $destinations = [];

        foreach (SocialPlatform::cases() as $platform) {
            try {
                $connection = $this->connections->find($platform);
            } catch (\Throwable) {
                continue;
            }
            if ($connection !== null && $connection->isUsable($now)) {
                $destinations[] = new SocialDestination($platform, (string) $connection->accountName);
            }
        }

        return $destinations;
    }
}
