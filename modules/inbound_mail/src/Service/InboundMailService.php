<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\File\FileRepository;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\CandidateAttachment;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;

/**
 * The public API's implementation (§7.11).
 *
 * Every method takes a consumer id and a business reference, and neither is
 * optional: this is where "a manager who may open a booking must not
 * thereby reach the whole mailbox" stops being a principle and becomes a
 * WHERE clause. A caller with a message id and the wrong reference gets
 * `null` or `false`, never somebody else's mail.
 *
 * What this class does NOT do is check whether the *user* may reach the
 * reference — it cannot: only the consumer knows its own authorisation
 * rules. That check belongs in the consumer's controller, and the interface
 * says so.
 */
class InboundMailService implements InboundMailInterface
{
    public function __construct(
        private InboundMessageRepository $messageRepository,
        private InboundMailboxRepository $mailboxRepository,
        private ?FileRepository $fileRepository = null,
        /**
         * Who to tell when an association goes.
         *
         * Optional so a caller that only reads never has to build it. When
         * it is absent nothing is told — which is fine for a read, and
         * which is why every write path that matters is wired with it: a
         * message that leaves a booking has to take the documents it
         * created there with it (`onUnlinked()`).
         */
        private ?MessageConsumerRegistry $consumerRegistry = null,
        /**
         * Which consumers a box is open to. Optional like the registry
         * above and for the same reason: only `probeAddressesFor()` needs
         * it, and a caller that only reads a thread never builds one.
         */
        private ?MailboxScopeService $scopeService = null,
        /**
         * Where a consumer that throws during a re-run goes. Null writes
         * nothing, which is what a caller that only reads wants.
         */
        private ?AnalysisJournal $analysisJournal = null,
        /**
         * Signed reply addresses (§8.58). Null on a caller that never
         * built one: nothing is minted, and nothing is recognised on
         * re-analysis — the arrival pass has its own.
         */
        private ?ReplyAddressService $replyAddresses = null
    ) {
        $this->consumerRegistry?->setAnalysisJournal($analysisJournal);
    }

    public function replyAddressFor(string $consumerId, string $businessReference): ?string
    {
        return $this->replyAddresses?->addressFor($consumerId, $businessReference);
    }

    public function countCandidatesFor(string $consumerId): int
    {
        return $this->messageRepository->countMessagesWithActiveCandidatesFor($consumerId);
    }

    /**
     * @return InboundMessage[]
     */
    public function findForReference(string $consumerId, string $businessReference): array
    {
        return $this->messageRepository->findForReference($consumerId, $businessReference);
    }

    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage
    {
        return $this->messageRepository->findOneForReference($consumerId, $businessReference, $messageId);
    }

    /**
     * Associate a message with a business object because a person said so.
     *
     * `LinkOrigin::MANUAL`, always (D20), and idempotent: the repository's
     * unique index is what makes it so, and `addLink()` returns false
     * rather than throwing when the association already exists.
     */
    public function attach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        ?int $userAccountId = null
    ): bool {
        $created = $this->messageRepository->addLink(
            $messageId,
            $consumerId,
            $businessReference,
            LinkOrigin::MANUAL,
            0,
            $userAccountId
        );

        if (!$created) {
            return false;
        }

        $stored = $this->messageRepository->findOneForReference($consumerId, $businessReference, $messageId);
        if ($stored !== null) {
            $this->notifyLinked($stored, new MessageLink(
                $consumerId,
                $businessReference,
                LinkOrigin::MANUAL,
                0,
                $userAccountId
            ));
        }

        return true;
    }

    /**
     * The business triage list — see `Api\InboundMailInterface`.
     *
     * The full-read mailboxes come from the configuration screen, never
     * from the consumer: `Repository\InboundMailboxRepository::mailboxIdsReadableInFull()`
     * answers only for boxes a superadmin declared this consumer may read
     * entirely, so a consumer cannot widen its own scope by calling this.
     *
     * @param string[] $ownReferences
     * @return InboundMessage[]
     */
    public function findForTriage(string $consumerId, array $ownReferences, int $limit = 50): array
    {
        return $this->messageRepository->findForTriage(
            $consumerId,
            array_values(array_unique($ownReferences)),
            $this->mailboxRepository->mailboxIdsReadableInFull($consumerId),
            $limit
        );
    }

    /**
     * @param int[] $messageIds
     * @return array<int, \Modules\InboundMail\Api\MessageCandidate[]>
     */
    public function findCandidatesFor(string $consumerId, array $messageIds): array
    {
        return $this->messageRepository->findCandidatesForConsumer($messageIds, $consumerId);
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
        $candidate = $this->ownCandidate($consumerId, $ownReferences, $messageId, $candidateId);
        if ($candidate === null) {
            return false;
        }

        $this->messageRepository->addLink(
            $messageId,
            $consumerId,
            $candidate->businessReference,
            LinkOrigin::MANUAL,
            $candidate->attachmentId,
            $userAccountId
        );
        $this->dismiss($consumerId, $messageId, $candidate);

        $stored = $this->messageRepository->findOneForReference(
            $consumerId,
            $candidate->businessReference,
            $messageId
        );
        if ($stored !== null) {
            // The author travels with the link. Finance files a receipt
            // « only ever by a person », and the person was being dropped
            // right here — the row named them, the callback did not.
            $this->notifyLinked($stored, new MessageLink(
                $consumerId,
                $candidate->businessReference,
                LinkOrigin::MANUAL,
                $candidate->attachmentId,
                $userAccountId
            ));
        }

        return true;
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
        $candidate = $this->ownCandidate($consumerId, $ownReferences, $messageId, $candidateId);
        if ($candidate === null) {
            return false;
        }

        $this->dismiss($consumerId, $messageId, $candidate);

        return true;
    }

    /**
     * The proposition this caller is actually allowed to answer.
     *
     * Three conditions, all of them: it is on this message, it belongs to
     * this consumer, and its target is one the requester may reach. The
     * third is what stops a screen being talked into filing a message
     * under an object its user has no business touching.
     *
     * @param string[] $ownReferences
     */
    private function ownCandidate(
        string $consumerId,
        array $ownReferences,
        int $messageId,
        int $candidateId
    ): ?\Modules\InboundMail\Api\MessageCandidate {
        foreach ($this->messageRepository->findActiveCandidates($messageId) as $candidate) {
            if ($candidate->id === $candidateId
                && $candidate->consumerId === $consumerId
                && in_array($candidate->businessReference, $ownReferences, true)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function dismiss(
        string $consumerId,
        int $messageId,
        \Modules\InboundMail\Api\MessageCandidate $candidate
    ): void {
        $this->messageRepository->dismissCandidate(
            $messageId,
            $consumerId,
            $candidate->businessReference,
            $candidate->attachmentId,
            new \DateTimeImmutable()
        );
    }

    /**
     * Detaching removes **one association**, and nothing else.
     *
     * It used to destroy the message once the last association went. It no
     * longer does, and the reason is what detaching almost always is: a
     * correction. Somebody noticed the message was filed under the wrong
     * booking. Deleting it on the spot meant the right booking could never
     * receive it — the correction destroyed the thing it was correcting.
     *
     * The message now falls back into the unit's general mail, where the
     * chef d'unité can re-orient it, and `Task\PurgeUnlinkedMessagesHandler`
     * removes it if nobody ever does. `last_unlinked_at` gives it a floor of
     * thirty days so a mis-click on an old message has a window to be
     * noticed (A4).
     *
     * `$preserveFileIds` still matters, and now matters *more*: a file the
     * consumer re-classified into a document of its own is released from
     * the message (`AttachmentOmission::RECLASSIFIED`), so the purge does
     * not delete a booking's signed contract along with the email it
     * arrived in three months later.
     *
     * @param int[] $preserveFileIds
     */
    public function detach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        array $preserveFileIds = []
    ): bool {
        // Read once, before the association goes: the consumer is told
        // about the message it filed things from, and after the removal
        // this very query returns nothing.
        $stored = $this->messageRepository->findOneForReference($consumerId, $businessReference, $messageId);
        if ($stored === null) {
            return false;
        }

        if (!$this->messageRepository->removeLink($messageId, $consumerId, $businessReference)) {
            return false;
        }

        $this->notifyUnlinked($stored, $consumerId, $businessReference);

        foreach (array_unique($preserveFileIds) as $fileId) {
            // Whether or not other associations remain: the file has become
            // the consumer's document, and the message must stop being what
            // decides its lifetime.
            $this->messageRepository->releaseAttachmentFile($messageId, $fileId);
            $this->handOverFileOwnership($fileId, $messageId);
        }

        return true;
    }

    public function move(
        string $consumerId,
        string $fromReference,
        string $toReference,
        int $messageId,
        ?int $userAccountId = null
    ): bool {
        if ($fromReference === $toReference) {
            return false;
        }

        $before = $this->messageRepository->findOneForReference($consumerId, $fromReference, $messageId);

        if (!$this->messageRepository->moveToReference(
            $messageId,
            $consumerId,
            $fromReference,
            $toReference,
            $userAccountId
        )) {
            return false;
        }

        // Both halves, in this order. The consumer has to take back what it
        // filed on the old object before it files anything on the new one —
        // leaving the first behind is the exact bug `onUnlinked()` exists
        // for.
        if ($before !== null) {
            $this->notifyUnlinked($before, $consumerId, $fromReference);
        }

        $after = $this->messageRepository->findOneForReference($consumerId, $toReference, $messageId);
        if ($after !== null) {
            $this->notifyLinked(
                $after,
                new MessageLink($consumerId, $toReference, LinkOrigin::MANUAL, 0, $userAccountId)
            );
        }

        return true;
    }

    /**
     * Tell a consumer one of its associations is gone.
     *
     * Swallowed like every other consumer callback: the association is
     * already removed, and one module's bookkeeping throwing must not turn
     * a filing correction into an error the user cannot act on. Nothing is
     * logged, since anything identifying enough to be useful would be
     * personal data in the journal (§7.9).
     */
    private function notifyUnlinked(InboundMessage $message, string $consumerId, string $businessReference): void
    {
        $consumer = $this->consumerRegistry?->find($consumerId);
        if ($consumer === null) {
            return;
        }

        try {
            $consumer->onUnlinked($message, new MessageLink($consumerId, $businessReference, LinkOrigin::MANUAL));
        } catch (\Throwable) {
            // See the docblock.
        }
    }

    private function notifyLinked(InboundMessage $message, MessageLink $link): void
    {
        $consumer = $this->consumerRegistry?->find($link->consumerId);
        if ($consumer === null) {
            return;
        }

        try {
            $consumer->onLinked($message, $link);
        } catch (\Throwable) {
            // See notifyUnlinked()'s docblock.
        }
    }

    /**
     * Everything held for a business object, association by association —
     * and the messages that end up belonging to nobody.
     *
     * The count is of associations removed, not of messages destroyed: a
     * message another module still recognises stays, and telling the caller
     * "nothing was removed" when its own object no longer carries it would
     * be the wrong answer to the question it asked.
     */
    public function purgeReference(string $consumerId, string $businessReference): int
    {
        $fileIds = $this->messageRepository->findFileIdsForReference($consumerId, $businessReference);
        $messageIds = $this->messageRepository->findMessageIdsForReference($consumerId, $businessReference);

        $removed = 0;
        foreach ($messageIds as $messageId) {
            if (!$this->messageRepository->removeLink($messageId, $consumerId, $businessReference)) {
                continue;
            }

            $removed++;

            if ($this->messageRepository->countLinks($messageId) === 0) {
                // Unlike detach(), this one really does destroy: it is the
                // consumer's own RGPD erasure of a business object running,
                // and the promise made to the person concerned is that the
                // mail attached to their file goes with the file.
                $this->messageRepository->deleteMessage($messageId);
            }
        }

        $this->deleteUnreferencedFiles($fileIds, []);

        return $removed;
    }

    /**
     * Delete the stored files nothing points at any more.
     *
     * Two things can still point at one: another message that deduplicated
     * onto the same bytes (§7.8), and the consumer itself, which may have
     * re-classified the attachment into something of its own and asked for
     * it to be kept. Both have to be checked *after* the message rows are
     * gone, since the first is a live count rather than a fixed list.
     *
     * @param int[] $fileIds
     * @param int[] $preserveFileIds
     */
    private function deleteUnreferencedFiles(array $fileIds, array $preserveFileIds): void
    {
        foreach (array_unique($fileIds) as $fileId) {
            if (in_array($fileId, $preserveFileIds, true)) {
                continue;
            }

            if ($this->messageRepository->countAttachmentsForFile($fileId) === 0) {
                $this->fileRepository?->delete($fileId);
                continue;
            }

            // The file survives because another message deduplicated onto
            // the same bytes — and `files.owner_id` may still name the
            // message that has just gone.
            $this->handOverFileOwnership($fileId, null);
        }
    }

    /**
     * Point `files.owner_id` at a message that really holds the file.
     *
     * Without this the file keeps naming a message that no longer holds it,
     * `Service\InboundMessageAccessRegistry` finds no associations to ask
     * about, and the very people who may read it are locked out. When
     * nothing holds it any more the owner is left alone: the file is either
     * about to be deleted or has become a consumer's own document, and
     * inventing an owner for it here would be this module guessing at
     * another module's business.
     */
    private function handOverFileOwnership(int $fileId, ?int $except): void
    {
        $holder = $this->messageRepository->findMessageHoldingFile($fileId);
        if ($holder === null || $holder === $except) {
            return;
        }

        $this->fileRepository?->updateOwner(
            $fileId,
            InboundMessageAccessRegistry::OWNER_TYPE,
            $holder
        );
    }

    /**
     * One-time reprise: give every attachment stored before this existed
     * the `inbound_message` ownership it should have had.
     *
     * Until it runs, those files carry no owner at all and are gated by
     * their `role_min` floor alone — which is to say, readable by any
     * intendant. Guarded by a setting in the composition root; idempotent
     * regardless, since it rewrites the same owner to the same value.
     *
     * @return int the number of files given an owner
     */
    public function backfillAttachmentOwners(): int
    {
        if ($this->fileRepository === null) {
            return 0;
        }

        $updated = 0;
        foreach ($this->messageRepository->findAttachmentFileOwners() as $pair) {
            $file = $this->fileRepository->findById($pair['file_id']);
            if ($file === null || $file->ownerType !== null) {
                continue;
            }

            $this->fileRepository->updateOwner(
                $pair['file_id'],
                InboundMessageAccessRegistry::OWNER_TYPE,
                $pair['message_id']
            );
            $updated++;
        }

        return $updated;
    }

    /**
     * @param string[] $messageIds
     */
    public function findReferenceByThread(string $consumerId, int $mailboxId, array $messageIds): ?string
    {
        $found = $this->messageRepository->findReferenceByThread($mailboxId, $consumerId, $messageIds);

        return $found === null ? null : $found['reference'];
    }

    public function recordOutboundMessageId(string $consumerId, string $businessReference, string $messageId): void
    {
        $this->messageRepository->recordOutboundMessageId($consumerId, $businessReference, $messageId);
    }

    /**
     * @return array<int, array{name: string, state: string, is_enabled: bool}>
     */
    public function listMailboxSummaries(): array
    {
        $summaries = [];
        foreach ($this->mailboxRepository->findAll() as $mailbox) {
            $summaries[$mailbox->id] = $mailbox->publicSummary();
        }

        return $summaries;
    }

    public function probeAddressesFor(string $consumerId): array
    {
        $addresses = [];
        foreach ($this->mailboxRepository->findEnabled() as $mailbox) {
            // A box's IMAP username is its address on every provider this
            // module supports, but it is not *required* to be one — some
            // servers authenticate on a bare account name. A bare name is
            // not a destination, and offering it would produce a bounce
            // rather than a diagnosis, so it is left out rather than
            // guessed at.
            if (filter_var($mailbox->username, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            // Only the boxes this consumer may actually analyse: probing a
            // box it never reads would produce a message nobody claims and
            // a « jamais reçu » that means nothing.
            foreach ($this->scopeService?->analyzingConsumers($mailbox) ?? [] as $consumer) {
                if ($consumer->consumerId() === $consumerId) {
                    $addresses[] = $mailbox->username;
                    break;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    public function isCollecting(): bool
    {
        return $this->mailboxRepository->countEnabled() > 0;
    }

    /**
     * Answered from the box's own purpose, and from nowhere else.
     *
     * A missing box answers false rather than throwing: a consumer asking
     * about a message whose mailbox has since been deleted is a normal
     * race, and « je ne sais pas » and « non » lead to the same, safe,
     * behaviour here.
     */
    public function isDedicatedTo(string $consumerId, int $mailboxId): bool
    {
        $mailbox = $this->mailboxRepository->findById($mailboxId);

        return $mailbox !== null && $mailbox->isDedicated() && $mailbox->dedicatedTo === $consumerId;
    }

    /**
     * @return array{examined: int, linked: int, proposed: int}
     */
    public function reanalyzeUnlinked(string $consumerId, int $limit = 100): array
    {
        $none = ['examined' => 0, 'linked' => 0, 'proposed' => 0];
        $consumer = $this->consumerRegistry?->find($consumerId);
        if ($consumer === null) {
            return $none;
        }

        $mailboxes = $this->mailboxesAnalysedBy($consumerId);
        if ($mailboxes === []) {
            return $none;
        }

        $applier = new AnalysisResultApplier($this->messageRepository);
        $notifier = new LinkedMessageNotifier(
            $this->messageRepository,
            $this->consumerRegistry ?? new MessageConsumerRegistry(),
            $this->analysisJournal
        );

        $examined = 0;
        $linked = 0;
        $proposed = 0;
        $ids = [];

        foreach ($this->messageRepository->findUnlinkedForReanalysis(array_keys($mailboxes), $limit) as $message) {
            $examined++;
            $ids[] = $message->id;

            // ONE consumer, and the registry's own `$only` narrowing is
            // what says so: this is the caller's module re-reading its own
            // mail, not a request that every module have another look.
            $results = $this->consumerRegistry->analyzeAll(
                $this->candidateFrom($message, $mailboxes[$message->mailboxId]),
                [$consumer]
            );

            $applied = $applier->applyAndReport($message->id, $results);
            $notifier->notify($message->id, $applied->links, $applied->candidates);

            foreach ($results as $result) {
                $linked += count($result->links);
                $proposed += count($result->candidates);
            }
        }

        // And the slow half, for the hourly task: an attachment's text and
        // a model call are readings a request cannot afford to wait for.
        $this->messageRepository->queueForStoredAnalysis($ids);

        return ['examined' => $examined, 'linked' => $linked, 'proposed' => $proposed];
    }

    /**
     * The enabled boxes this consumer may analyse, keyed by id, each with
     * the consumer its operator declared it dedicated to.
     *
     * Without a scope service — a caller that never built one — every
     * enabled box qualifies, which is what the contract was before the
     * configuration screen existed.
     *
     * @return array<int, string|null>
     */
    private function mailboxesAnalysedBy(string $consumerId): array
    {
        $mailboxes = [];
        foreach ($this->mailboxRepository->findEnabled() as $mailbox) {
            if ($this->scopeService !== null
                && !$this->scopeService->scopeFor($mailbox, $consumerId)->analyzes
            ) {
                continue;
            }

            $mailboxes[$mailbox->id] = $mailbox->isDedicated() ? $mailbox->dedicatedTo : null;
        }

        return $mailboxes;
    }

    /**
     * A stored message, in the shape the arrival pass reads.
     *
     * **`references` is empty and cannot be otherwise**: the header is not
     * stored, only `In-Reply-To` is. A re-run therefore threads a DIRECT
     * reply onto the message it answers, and a deeper one only once its own
     * parent has been attached — which a second press settles. Storing the
     * whole chain to close that gap would mean a schema change and a column
     * of somebody else's Message-IDs, for a case one more click already
     * covers.
     */
    private function candidateFrom(InboundMessage $message, ?string $dedicatedTo): CandidateMessage
    {
        return new CandidateMessage(
            mailboxId: $message->mailboxId,
            subject: $message->subject,
            fromEmail: $message->fromEmail,
            fromName: $message->fromName,
            messageId: $message->messageId,
            inReplyTo: $message->inReplyTo,
            references: [],
            toEmails: $message->toEmails,
            sentAt: $message->sentAt,
            // Already sanitised on the way in (§7.9) — what is stored is
            // what a consumer was always given.
            bodyText: $message->bodyText,
            bodyHtml: $message->bodyHtml,
            attachments: array_map(
                static fn(InboundAttachment $attachment): CandidateAttachment => new CandidateAttachment(
                    $attachment->filename,
                    $attachment->mimeType,
                    $attachment->sizeBytes
                ),
                $message->attachments
            ),
            rawHeaders: $message->rawHeaders,
            mailboxDedicatedTo: $dedicatedTo,
            addressedTo: $this->replyAddresses?->resolve($message->toEmails)
        );
    }
}
