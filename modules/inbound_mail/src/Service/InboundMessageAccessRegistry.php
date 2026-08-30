<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Repository\InboundMessageRepository;

/**
 * Who may download an inbound message's attachment (`owner_type =
 * 'inbound_message'`, `owner_id = inbound_messages.id`).
 *
 * **This closed a real hole.** Attachments were written through
 * `UploadHandler` with `role_min = 'intendant'` and no owner at all, so any
 * intendant could read any of them by walking `/files/{id}` — a rental
 * contract, a camp's medical form, an invoice, whatever had arrived in any
 * watched box. The `role_min` floor was never the partition; it only ever
 * said "not the public".
 *
 * **The rule is delegation, not knowledge.** `inbound_mail` has no idea
 * whether a given intendant manages the booking a contract arrived for, and
 * acquiring that idea would mean keeping a copy of every consumer's
 * authorisation rules in step with the original forever. So each consumer
 * associated with the message is asked (`MessageConsumerInterface::
 * canRead()`), and access is granted as soon as **one** of them says yes.
 *
 * Two cases are decided here rather than delegated:
 *
 * - **A message nobody is associated with**: only the Chef d'Unité. There
 *   is no consumer to ask, and this is the archive their general mailbox
 *   opens onto.
 * - **The Chef d'Unité, always.** They read the whole box by design, and an
 *   attachment they could see on screen but not open would be a broken
 *   page rather than a protection. The partition this class exists for is
 *   between intendants, not above them.
 *
 * A link whose consumer is not registered — its module disabled since — is
 * skipped rather than granted. Disabling a module never deletes data
 * (§7.3), and it must not hand its data to somebody else either.
 */
class InboundMessageAccessRegistry implements FileOwnershipCheckerInterface
{
    public const OWNER_TYPE = 'inbound_message';

    public function __construct(
        private InboundMessageRepository $messageRepository,
        private MessageConsumerRegistry $consumerRegistry
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === self::OWNER_TYPE;
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
    {
        if ($currentRole->hasAccess(Role::ADMIN)) {
            return true;
        }

        $links = $this->messageRepository->findLinksForMessage($ownerId);
        if ($links === []) {
            // Nobody recognised this message. The only reader is the Chef
            // d'Unité, and they were already answered above.
            return false;
        }

        foreach ($links as $link) {
            if ($this->grants($link, $linkedMemberIds, $currentRole)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    private function grants(MessageLink $link, array $linkedMemberIds, Role $currentRole): bool
    {
        try {
            // `find()` rather than `all()`: only the consumer the
            // association names is built, so a download never assembles
            // the whole cross-module graph and a page view never builds
            // any of it.
            $consumer = $this->consumerRegistry->find($link->consumerId);
            if ($consumer === null) {
                // The module that made this association is not registered
                // — most likely disabled. Its data stays its data.
                return false;
            }

            return $consumer->canRead($link->businessReference, $linkedMemberIds, $currentRole->value);
        } catch (\Throwable) {
            // A consumer that cannot answer has not said yes. Nothing
            // about the failure is logged: anything identifying enough to
            // be useful would be personal data in the journal (§7.9), and
            // the refusal itself is already recorded as file_access_denied
            // with the file id alone.
            return false;
        }
    }
}
