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
     * Detaching deletes (§7.6). There is no unattached queue for the
     * message to fall into, so leaving the row behind with no reference
     * would create exactly the invisible archive the module exists to
     * avoid.
     */
    /**
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

        if (!$this->messageRepository->delete($messageId, $consumerId, $businessReference)) {
            return false;
        }

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

    public function purgeReference(string $consumerId, string $businessReference): int
    {
        $fileIds = $this->messageRepository->findFileIdsForReference($consumerId, $businessReference);
        $removed = $this->messageRepository->deleteForReference($consumerId, $businessReference);

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
            }
        }
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
