<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\File\FileRepository;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
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
        private ?FileRepository $fileRepository = null
    ) {
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
     * Detaching removes **one association**, not the message.
     *
     * The message itself only goes when nothing points at it any more —
     * and its attachments with it, minus the ones a consumer re-classified
     * and asked to keep. A message another module also recognised survives
     * untouched, which is exactly what stopped being possible while the
     * business reference was a column of the message itself.
     *
     * @param int[] $preserveFileIds
     */
    public function detach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        array $preserveFileIds = []
    ): bool {
        if ($this->messageRepository->findOneForReference($consumerId, $businessReference, $messageId) === null) {
            return false;
        }

        $fileIds = $this->messageRepository->findFileIdsForMessage($messageId);

        if (!$this->messageRepository->removeLink($messageId, $consumerId, $businessReference)) {
            return false;
        }

        if ($this->messageRepository->countLinks($messageId) > 0) {
            // Somebody else still recognises this message. Neither it nor
            // its files are anybody's to remove.
            return true;
        }

        $this->messageRepository->deleteMessage($messageId);
        $this->deleteUnreferencedFiles($fileIds, $preserveFileIds);

        return true;
    }

    public function move(string $consumerId, string $fromReference, string $toReference, int $messageId): bool
    {
        if ($fromReference === $toReference) {
            return false;
        }

        return $this->messageRepository->moveToReference($messageId, $consumerId, $fromReference, $toReference);
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
            // message that has just gone. Hand the ownership to a message
            // that really holds it, or the access registry finds no
            // associations to ask about and locks out the very people who
            // may read it.
            $holder = $this->messageRepository->findMessageHoldingFile($fileId);
            if ($holder !== null) {
                $this->fileRepository?->updateOwner(
                    $fileId,
                    InboundMessageAccessRegistry::OWNER_TYPE,
                    $holder
                );
            }
        }
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

    public function isCollecting(): bool
    {
        return $this->mailboxRepository->countEnabled() > 0;
    }
}
