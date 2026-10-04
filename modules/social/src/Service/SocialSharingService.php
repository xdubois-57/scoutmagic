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
use Modules\Social\Service\GroupPublishingService;

/**
 * The Api's implementation: the connections as the rest of the site may
 * see them — which accounts, never how — and whether a given person has
 * anywhere at all to publish.
 */
final class SocialSharingService implements SocialSharingInterface
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        /**
         * The discussion groups as a destination, or null when the groups
         * module is off — in which case Meta is the whole answer.
         */
        private readonly ?GroupPublishingService $groups = null
    ) {
    }

    public function canPublish(?string $email, string $role, ?int $userAccountId): bool
    {
        if ($this->connectedDestinations() !== []) {
            return true;
        }
        if ($this->groups === null || $userAccountId === null) {
            return false;
        }

        // Never throws: postableGroups() answers an empty list rather
        // than failing, so a groups module in trouble reads as « no
        // group » and the button simply does not appear.
        return $this->groups->postableGroups($email, $role, $userAccountId) !== [];
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
