<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageLink;

/**
 * A mailbox that remembers what a triage screen did to it — links per
 * consumer, set-asides per consumer — so the camps' screen and the
 * rentals' can be driven through the same scenario and read back the same
 * way (`TriageScreenScenario`, issue #462).
 *
 * Deliberately naive about scope: every message is on every consumer's list
 * unless another consumer's reference claims it. The scope itself is the
 * real service's to enforce and `InboundMailServiceTest`'s to test; what
 * this double answers is whether both screens make the same calls and show
 * the same result.
 *
 * @internal
 */
final class InMemoryTriageMail implements InboundMailInterface
{
    use InertInboundMail;

    /** @var array<int, InboundMessage> */
    private array $messages = [];

    /** @var array<int, list<MessageLink>> */
    private array $links = [];

    /** @var array<string, array<int, true>> consumer => set-aside message ids */
    private array $setAside = [];

    /** @var array<int, array{message: int, candidate: MessageCandidate}> standing propositions, by id */
    private array $candidates = [];

    /** How many times « Relancer l'analyse » reached this double. */
    public int $reanalyses = 0;

    /**
     * Files a message under an object, as the automatic rules would have —
     * the state a test starts from.
     */
    public function link(int $messageId, string $consumerId, string $businessReference): void
    {
        $this->links[$messageId][] = new MessageLink($consumerId, $businessReference, LinkOrigin::REFERENCE);
    }

    /**
     * Proposes a message for an object, as an analysis would have. Returns
     * the proposition's id.
     */
    public function propose(int $messageId, string $consumerId, string $businessReference): int
    {
        $id = count($this->candidates) + 1;
        $this->candidates[$id] = [
            'message' => $messageId,
            'candidate' => new MessageCandidate(
                $businessReference,
                $businessReference,
                'sender',
                'Même expéditeur',
                0,
                $id,
                $consumerId
            ),
        ];

        return $id;
    }

    public function __construct(InboundMessage ...$messages)
    {
        foreach ($messages as $message) {
            $this->messages[$message->id] = $message;
            $this->links[$message->id] = [];
        }
    }

    public function isCollecting(): bool
    {
        return true;
    }

    public function findForTriage(
        string $consumerId,
        array $ownReferences,
        int $limit = 50,
        bool $dismissed = false
    ): array {
        $list = [];
        foreach ($this->messages as $id => $message) {
            $isSetAside = isset($this->setAside[$consumerId][$id]);
            if ($isSetAside === $dismissed) {
                $list[] = $this->withLinks($message);
            }
        }

        return array_slice($list, 0, $limit);
    }

    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage
    {
        foreach ($this->links[$messageId] ?? [] as $link) {
            if ($link->consumerId === $consumerId && $link->businessReference === $businessReference) {
                return $this->withLinks($this->messages[$messageId]);
            }
        }

        return null;
    }

    public function attach(string $consumerId, string $businessReference, int $messageId, ?int $userAccountId = null): bool
    {
        if (!isset($this->messages[$messageId]) || $this->findOneForReference($consumerId, $businessReference, $messageId) !== null) {
            return false;
        }

        $this->links[$messageId][] = new MessageLink($consumerId, $businessReference, LinkOrigin::MANUAL);

        return true;
    }

    public function detach(string $consumerId, string $businessReference, int $messageId, array $preserveFileIds = []): bool
    {
        $before = count($this->links[$messageId] ?? []);
        $this->links[$messageId] = array_values(array_filter(
            $this->links[$messageId] ?? [],
            static fn(MessageLink $link): bool => !($link->consumerId === $consumerId && $link->businessReference === $businessReference)
        ));

        return count($this->links[$messageId]) < $before;
    }

    public function dismissMessage(string $consumerId, array $ownReferences, int $messageId, ?int $userAccountId = null): bool
    {
        if (!isset($this->messages[$messageId]) || $this->withLinks($this->messages[$messageId])->linksFor($consumerId) !== []) {
            return false;
        }
        $this->setAside[$consumerId][$messageId] = true;

        return true;
    }

    public function restoreMessage(string $consumerId, array $ownReferences, int $messageId): bool
    {
        if (!isset($this->setAside[$consumerId][$messageId])) {
            return false;
        }
        unset($this->setAside[$consumerId][$messageId]);

        return true;
    }

    public function countDismissedMessages(string $consumerId, array $ownReferences): int
    {
        return count($this->setAside[$consumerId] ?? []);
    }

    public function findCandidatesFor(string $consumerId, array $messageIds): array
    {
        $found = [];
        foreach ($this->candidates as $standing) {
            if ($standing['candidate']->consumerId === $consumerId && in_array($standing['message'], $messageIds, true)) {
                $found[$standing['message']][] = $standing['candidate'];
            }
        }

        return $found;
    }

    public function confirmCandidate(
        string $consumerId,
        array $ownReferences,
        int $messageId,
        int $candidateId,
        ?int $userAccountId = null
    ): bool {
        $candidate = $this->ownCandidate($consumerId, $ownReferences, $messageId, $candidateId);
        if ($candidate === null) {
            return false;
        }
        unset($this->candidates[$candidateId]);
        $this->links[$messageId][] = new MessageLink($consumerId, $candidate->businessReference, LinkOrigin::MANUAL);

        return true;
    }

    public function dismissCandidate(string $consumerId, array $ownReferences, int $messageId, int $candidateId): bool
    {
        if ($this->ownCandidate($consumerId, $ownReferences, $messageId, $candidateId) === null) {
            return false;
        }
        unset($this->candidates[$candidateId]);

        return true;
    }

    public function reanalyzeUnlinked(string $consumerId, int $limit = 100): array
    {
        $this->reanalyses++;
        $unlinked = array_filter(
            array_keys($this->messages),
            fn(int $id): bool => $this->withLinks($this->messages[$id])->linksFor($consumerId) === []
        );

        return ['examined' => count($unlinked), 'linked' => 0, 'proposed' => 0];
    }

    /**
     * The standing proposition, when it is this consumer's, on this message
     * and for one of the requester's objects — the same scoping as the
     * real service.
     *
     * @param string[] $ownReferences
     */
    private function ownCandidate(string $consumerId, array $ownReferences, int $messageId, int $candidateId): ?MessageCandidate
    {
        $standing = $this->candidates[$candidateId] ?? null;
        if ($standing === null
            || $standing['message'] !== $messageId
            || $standing['candidate']->consumerId !== $consumerId
            || !in_array($standing['candidate']->businessReference, $ownReferences, true)
        ) {
            return null;
        }

        return $standing['candidate'];
    }

    private function withLinks(InboundMessage $message): InboundMessage
    {
        return new InboundMessage(
            id: $message->id,
            mailboxId: $message->mailboxId,
            consumerId: $message->consumerId,
            businessReference: $message->businessReference,
            linkOrigin: $message->linkOrigin,
            subject: $message->subject,
            fromEmail: $message->fromEmail,
            fromName: $message->fromName,
            messageId: $message->messageId,
            inReplyTo: $message->inReplyTo,
            sentAt: $message->sentAt,
            bodyText: $message->bodyText,
            bodyHtml: $message->bodyHtml,
            links: $this->links[$message->id] ?? [],
            isBulk: $message->isBulk
        );
    }

    public static function aMessage(int $id = 7, string $subject = 'Question sur les draps'): InboundMessage
    {
        return new InboundMessage(
            id: $id,
            mailboxId: 1,
            consumerId: '',
            businessReference: '',
            linkOrigin: LinkOrigin::SENDER,
            subject: $subject,
            fromEmail: 'j.leroy@example.be',
            fromName: 'Jeanne Leroy',
            messageId: '<m' . $id . '@example.be>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-09-18 09:12:00'),
            bodyText: 'Bonjour, faut-il apporter des draps ?',
            bodyHtml: ''
        );
    }
}
