<?php

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Modules\MassMail\Api\MassMailQueryInterface;
use Modules\MassMail\Repository\RecipientRepository;

/**
 * The concrete implementation behind Api\MassMailQueryInterface — a thin
 * wrapper so the public API surface never exposes Repository\
 * RecipientRepository (or PDO) directly to other modules/core.
 */
class MassMailQueryService implements MassMailQueryInterface
{
    public function __construct(private RecipientRepository $recipientRepository)
    {
    }

    public function getRecentEmailsForMember(int $memberId, int $limit): array
    {
        return $this->recipientRepository->findRecentSentForMember($memberId, $limit);
    }
}
