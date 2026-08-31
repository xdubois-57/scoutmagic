<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;

/**
 * The Chef d'Unité's view of everything the unit received.
 *
 * **This screen is one of the three things that make storing everything
 * defensible** (§8.58). The other two are the retention and the named
 * person responsible; without the screen the archive would be exactly what
 * the old "discard the unrecognised" rule was trying to avoid — a copy of
 * the unit's mailbox nobody can consult.
 *
 * **It is deliberately not reachable through `Api\InboundMailInterface`.**
 * That contract is scoped to one consumer and one business reference on
 * every call, and the absence of an unscoped read there is the enforcement
 * (§7.11): a manager who may open a booking must not thereby gain a window
 * onto the unit's whole mail. This service is the exception, it is
 * ADMIN-only by its route, and it does not leave this module.
 */
class GeneralMailboxService
{
    /**
     * One page. Small enough to render on a phone over a slow connection,
     * large enough that a chef d'unité clearing a backlog is not clicking
     * « suivant » all evening.
     */
    public const PAGE_SIZE = 40;

    public function __construct(
        private InboundMessageRepository $messages,
        private InboundMailboxRepository $mailboxes,
        private MessageConsumerRegistry $consumers
    ) {
    }

    /**
     * @param array{mailbox_id?: int|null, association?: string, include_bulk?: bool} $filters
     * @param array{sent_at: string, id: int}|null $after
     * @return array{messages: InboundMessage[], next_cursor: string|null}
     */
    public function page(array $filters, ?array $after): array
    {
        // One more than the page, to find out whether there IS a next page
        // without a second COUNT over a table that grows without bound.
        $rows = $this->messages->findPage($filters, $after, self::PAGE_SIZE + 1);

        $hasMore = count($rows) > self::PAGE_SIZE;
        $messages = array_slice($rows, 0, self::PAGE_SIZE);
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        return [
            'messages' => $messages,
            'next_cursor' => $hasMore && $last !== null ? self::encodeCursor($last) : null,
        ];
    }

    /**
     * The cursor as it travels through a URL: the pair the ORDER BY uses,
     * and nothing else.
     *
     * Not a message id alone, and not an offset. `sent_at` alone would skip
     * every message sharing a second with the last one shown — a mailing
     * list delivering a batch does that routinely — and an offset makes the
     * page slower the further back somebody reads.
     *
     * It carries no personal data and needs no signature: the worst a
     * tampered cursor can do is start the listing at a different date, on a
     * page its reader may already see in full.
     */
    public static function encodeCursor(InboundMessage $message): string
    {
        return $message->sentAt->format('Y-m-d H:i:s') . '|' . $message->id;
    }

    /**
     * @return array{sent_at: string, id: int}|null
     */
    public static function decodeCursor(string $raw): ?array
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', trim($raw), $matches)) {
            return null;
        }

        return ['sent_at' => $matches[1], 'id' => (int) $matches[2]];
    }

    public function find(int $messageId): ?InboundMessage
    {
        return $this->messages->findAnyById($messageId);
    }

    /**
     * The propositions still awaiting a decision on this message.
     *
     * @return MessageCandidate[]
     */
    public function candidatesFor(int $messageId): array
    {
        return $this->messages->findActiveCandidates($messageId);
    }

    /**
     * Confirm a proposition: it becomes an association, made by a person.
     *
     * `LinkOrigin::MANUAL` rather than whatever the proposition guessed on
     * (D20). The origin answers « comment ce rattachement a-t-il été fait »,
     * and once somebody has looked at the message and said yes, the honest
     * answer is « quelqu'un l'a décidé » — keeping the machine's guess
     * would present a human decision as a heuristic and make every later
     * reader trust it less than they should.
     *
     * The proposition is dismissed in the same movement: it has been
     * answered, and leaving it standing would ask the question again on the
     * next page load.
     */
    public function confirmCandidate(int $messageId, int $candidateId, ?int $userAccountId): bool
    {
        foreach ($this->messages->findActiveCandidates($messageId) as $candidate) {
            if ($candidate->id !== $candidateId) {
                continue;
            }

            $this->messages->addLink(
                $messageId,
                $candidate->consumerId,
                $candidate->businessReference,
                LinkOrigin::MANUAL,
                $candidate->attachmentId,
                $userAccountId
            );
            $this->dismiss($messageId, $candidate);

            $this->notifyLinked($messageId, $candidate);

            return true;
        }

        return false;
    }

    /**
     * Set a proposition aside. Final, and that is the point (A3): a
     * technical job that contradicted a human decision is the surest way to
     * make people stop using the screen. It also protects nothing — a
     * dismissed proposition does not keep the message past its retention,
     * or « écarter » would mean the opposite of what it says.
     */
    public function dismissCandidate(int $messageId, int $candidateId): bool
    {
        foreach ($this->messages->findActiveCandidates($messageId) as $candidate) {
            if ($candidate->id === $candidateId) {
                $this->dismiss($messageId, $candidate);

                return true;
            }
        }

        return false;
    }

    /**
     * The mailboxes, for the filter. `publicSummary()` is not enough here —
     * the filter needs an id — but nothing about the host, the port or the
     * account leaves this method either.
     *
     * @return array<int, string>
     */
    public function mailboxNames(): array
    {
        $names = [];
        foreach ($this->mailboxes->findAll() as $mailbox) {
            $names[$mailbox->id] = $mailbox->name;
        }

        return $names;
    }

    /** How many messages the list is hiding behind « courrier automatique ». */
    public function bulkCount(): int
    {
        return $this->messages->countBulk();
    }

    /**
     * The French name of a consumer, for a chip that would otherwise read
     * « rental ». An unregistered consumer keeps its id: a module that has
     * been deactivated still left associations behind, and hiding them
     * would make a message look unattached when it is not.
     */
    public function consumerName(string $consumerId): string
    {
        return $this->consumers->find($consumerId)?->displayName() ?? $consumerId;
    }

    /**
     * The repository identifies a proposition by what it is ABOUT
     * (consumer, reference, attachment) rather than by its row id, because
     * that is the shape the unique index has. The screen has only the id,
     * so it is resolved through the candidate it already read.
     */
    private function dismiss(int $messageId, MessageCandidate $candidate): void
    {
        $this->messages->dismissCandidate(
            $messageId,
            $candidate->consumerId,
            $candidate->businessReference,
            $candidate->attachmentId,
            new \DateTimeImmutable()
        );
    }

    private function notifyLinked(int $messageId, MessageCandidate $candidate): void
    {
        $consumer = $this->consumers->find($candidate->consumerId);
        $message = $this->messages->findAnyById($messageId);
        if ($consumer === null || $message === null) {
            return;
        }

        try {
            $consumer->onLinked($message, new \Modules\InboundMail\Api\MessageLink(
                $candidate->consumerId,
                $candidate->businessReference,
                LinkOrigin::MANUAL,
                $candidate->attachmentId
            ));
        } catch (\Throwable) {
            // The association is already written. One module's bookkeeping
            // throwing must not undo a decision a person just made, nor
            // show them an error about a click that worked.
        }
    }
}
