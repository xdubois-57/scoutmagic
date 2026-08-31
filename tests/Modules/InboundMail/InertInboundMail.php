<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageCandidate;

/**
 * Inert answers for the parts of `Api\InboundMailInterface` a given test
 * double does not care about.
 *
 * The interface is a contract several modules implement doubles of, and
 * every method added to it would otherwise have to be re-typed in each of
 * them — three copies that drift, and three chances for a double to answer
 * something a real gateway never would. The defaults here are all the SAME
 * inert answer: nothing found, nothing changed, false. A double that means
 * something else overrides the one method it is about.
 *
 * @phpstan-require-implements \Modules\InboundMail\Api\InboundMailInterface
 */
trait InertInboundMail
{
    /**
     * @param string[] $ownReferences
     * @return InboundMessage[]
     */
    public function findForTriage(string $consumerId, array $ownReferences, int $limit = 50): array
    {
        return [];
    }

    /**
     * @param int[] $messageIds
     * @return array<int, MessageCandidate[]>
     */
    public function findCandidatesFor(string $consumerId, array $messageIds): array
    {
        return [];
    }

    public function attach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        ?int $userAccountId = null
    ): bool {
        return false;
    }

    /**
     * @param string[] $ownReferences
     */
    public function confirmCandidate(
        string $consumerId,
        array $ownReferences,
        int $messageId,
        int $candidateId,
        ?int $userAccountId = null
    ): bool {
        return false;
    }

    /**
     * @param string[] $ownReferences
     */
    public function dismissCandidate(
        string $consumerId,
        array $ownReferences,
        int $messageId,
        int $candidateId
    ): bool {
        return false;
    }
}
