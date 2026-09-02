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
     * @return InboundMessage[]
     */
    public function findForReference(string $consumerId, string $businessReference): array
    {
        return [];
    }

    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage
    {
        return null;
    }

    /**
     * @param int[] $preserveFileIds
     */
    public function detach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        array $preserveFileIds = []
    ): bool {
        return false;
    }

    public function move(string $consumerId, string $fromReference, string $toReference, int $messageId): bool
    {
        return false;
    }

    public function purgeReference(string $consumerId, string $businessReference): int
    {
        return 0;
    }

    public function isCollecting(): bool
    {
        return false;
    }

    public function isDedicatedTo(string $consumerId, int $mailboxId): bool
    {
        return false;
    }

    /**
     * @return array{examined: int, linked: int, proposed: int}
     */
    public function reanalyzeUnlinked(string $consumerId, int $limit = 100): array
    {
        return ['examined' => 0, 'linked' => 0, 'proposed' => 0];
    }

    /**
     * @param string[] $messageIds
     */
    public function findReferenceByThread(string $consumerId, int $mailboxId, array $messageIds): ?string
    {
        return null;
    }

    /**
     * @return array<int, array{name: string, state: string, is_enabled: bool}>
     */
    public function listMailboxSummaries(): array
    {
        return [];
    }

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
     * @return list<string>
     */
    public function probeAddressesFor(string $consumerId): array
    {
        return [];
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
