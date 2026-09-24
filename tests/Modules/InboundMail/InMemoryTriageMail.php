<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
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
