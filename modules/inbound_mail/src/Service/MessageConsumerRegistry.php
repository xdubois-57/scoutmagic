<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\MessageClaim;
use Modules\InboundMail\Api\MessageConsumerInterface;

/**
 * Who gets asked whether an incoming message is theirs.
 *
 * Mutable and owned by this module — the ARCHITECTURE.md §7.6 pattern, the
 * same shape as `Calendar\Service\VirtualEventRegistry`. The composition
 * root builds it empty inside this module's block and each consumer
 * module's block appends to it, which is what lets `inbound_mail` be wired
 * before `rental` without either knowing about the other.
 *
 * **A consumer that throws is skipped, not fatal.** One module's claiming
 * logic failing must not stop a synchronisation and leave every other
 * consumer's mail unread behind a stuck cursor.
 */
class MessageConsumerRegistry
{
    /** @var MessageConsumerInterface[] */
    private array $consumers = [];

    /** @var array<string, \Closure(): MessageConsumerInterface> */
    private array $factories = [];

    public function register(MessageConsumerInterface $consumer): void
    {
        $this->consumers[] = $consumer;
    }

    /**
     * Register a consumer that is only built if something actually asks for
     * it.
     *
     * The web path needs this. Answering "may this person download that
     * attachment?" means asking one consumer — the one the association
     * names — and building the whole three-module graph on every page view
     * to have it ready would undo exactly what the sync task's own lazy
     * factory was written to avoid. The download is rare; the page view is
     * not.
     *
     * @param \Closure(): MessageConsumerInterface $factory
     */
    public function registerFactory(string $consumerId, \Closure $factory): void
    {
        $this->factories[$consumerId] = $factory;
    }

    public function hasConsumers(): bool
    {
        return $this->consumers !== [] || $this->factories !== [];
    }

    /**
     * One consumer by its id, built on the spot if it was registered as a
     * factory. Null when no module claims that id — most often because it
     * has been disabled since the association was made.
     */
    public function find(string $consumerId): ?MessageConsumerInterface
    {
        foreach ($this->consumers as $consumer) {
            if ($consumer->consumerId() === $consumerId) {
                return $consumer;
            }
        }

        if (!isset($this->factories[$consumerId])) {
            return null;
        }

        $consumer = ($this->factories[$consumerId])();
        // Built once per request: a second question about the same
        // consumer must not rebuild its dependency graph.
        $this->consumers[] = $consumer;
        unset($this->factories[$consumerId]);

        return $consumer;
    }

    /**
     * @return MessageConsumerInterface[]
     */
    public function all(): array
    {
        foreach (array_keys($this->factories) as $consumerId) {
            $this->find($consumerId);
        }

        return $this->consumers;
    }

    /**
     * The first consumer that recognises this message, with its claim.
     *
     * **First, not best.** Two modules claiming the same message would mean
     * storing it twice under two references, and there is no rule that says
     * which is right — registration order is at least a decision somebody
     * made in the composition root, and in practice a message belongs to
     * one workflow.
     *
     * @return array{consumer: MessageConsumerInterface, claim: MessageClaim}|null
     */
    public function firstClaim(CandidateMessage $message): ?array
    {
        foreach ($this->consumers as $consumer) {
            try {
                $claim = $consumer->claim($message);
            } catch (\Throwable) {
                // Deliberately swallowed and deliberately not logged with
                // any part of the message: a log line naming the sender
                // would be personal data in the journal (§7.9).
                continue;
            }

            if ($claim !== null) {
                return ['consumer' => $consumer, 'claim' => $claim];
            }
        }

        return null;
    }
}
