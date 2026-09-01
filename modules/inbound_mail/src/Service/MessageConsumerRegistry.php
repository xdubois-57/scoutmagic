<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;

/**
 * Who gets asked what an incoming message means to them.
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

    /**
     * Where a swallowed failure goes.
     *
     * Null is the read path and the tests: a registry built to answer « may
     * this person open that attachment » has no business writing to the
     * journal. Every analysing path is wired with one.
     */
    private ?AnalysisJournal $analysisJournal = null;

    public function setAnalysisJournal(?AnalysisJournal $journal): void
    {
        $this->analysisJournal = $journal;
    }

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
     * What **every** consumer makes of this message.
     *
     * This replaced `firstClaim()`, which asked them in registration order
     * and stopped at the first that said yes. That made "one email is both
     * a booking's correspondence and an invoice" unrepresentable — the
     * second module was never even asked — and it made registration order
     * load-bearing in a way nobody could see from the modules themselves.
     *
     * **A consumer that throws is skipped, never fatal.** One module's
     * analysis failing must not stop a synchronisation and leave every
     * other consumer's mail unread behind a stuck cursor. It is no longer
     * skipped *silently*, though: the failure reaches the journal as an
     * `error`, naming the module and the box and nothing of the message
     * itself (§7.9). Swallowing it whole was how a module could decline to
     * read a unit's mail for weeks with nothing anywhere to say so.
     *
     * `$only` narrows the question to the consumers the mailbox's own
     * configuration allows to look at it (IT-05). Narrowing here rather
     * than letting every consumer answer and discarding what the box did
     * not authorise is the difference between a setting and a suggestion:
     * a consumer never sees a message it may not analyse, so it cannot act
     * on one by mistake.
     *
     * @param MessageConsumerInterface[]|null $only null means everybody
     * @return array<string, AnalysisResult> keyed by consumer id, empty
     *   results left out
     */
    public function analyzeAll(CandidateMessage $message, ?array $only = null): array
    {
        $results = [];

        foreach ($only ?? $this->all() as $consumer) {
            try {
                $result = $consumer->analyze($message);
            } catch (\Throwable $e) {
                $this->analysisJournal?->failed(
                    $consumer->consumerId(),
                    $message->mailboxId,
                    AnalysisJournal::PASS_ARRIVAL,
                    $e
                );
                continue;
            }

            if (!$result->isEmpty()) {
                $results[$consumer->consumerId()] = $result;
            }
        }

        return $results;
    }

    /**
     * The same question, on a message already written down, for the
     * deferred pass that may read an attachment's content.
     *
     * @param MessageConsumerInterface[]|null $only null means everybody
     * @return array<string, AnalysisResult> keyed by consumer id
     */
    public function analyzeAllStored(InboundMessage $message, ?array $only = null): array
    {
        $results = [];

        foreach ($only ?? $this->all() as $consumer) {
            try {
                $result = $consumer->analyzeStored($message);
            } catch (\Throwable $e) {
                $this->analysisJournal?->failed(
                    $consumer->consumerId(),
                    $message->mailboxId,
                    AnalysisJournal::PASS_STORED,
                    $e
                );
                continue;
            }

            if (!$result->isEmpty()) {
                $results[$consumer->consumerId()] = $result;
            }
        }

        return $results;
    }
}
