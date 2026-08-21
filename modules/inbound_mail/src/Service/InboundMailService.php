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
    public function detach(string $consumerId, string $businessReference, int $messageId): bool
    {
        if ($this->messageRepository->findOneForReference($consumerId, $businessReference, $messageId) === null) {
            return false;
        }

        $fileIds = $this->messageRepository->findFileIdsForMessage($messageId);

        if (!$this->messageRepository->delete($messageId, $consumerId, $businessReference)) {
            return false;
        }

        // After the message row is gone: a file another message
        // deduplicated onto still has a live attachment row pointing at it,
        // and deleting it would break that one.
        foreach ($fileIds as $fileId) {
            if ($this->messageRepository->countAttachmentsForFile($fileId) === 0) {
                $this->fileRepository?->delete($fileId);
            }
        }

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

        foreach (array_unique($fileIds) as $fileId) {
            if ($this->messageRepository->countAttachmentsForFile($fileId) === 0) {
                $this->fileRepository?->delete($fileId);
            }
        }

        return $removed;
    }

    public function isCollecting(): bool
    {
        return $this->mailboxRepository->countEnabled() > 0;
    }
}
