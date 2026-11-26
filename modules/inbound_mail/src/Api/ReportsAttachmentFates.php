<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * An **optional** companion of `MessageConsumerInterface`, in the same
 * family as `ReferenceDirectory`: a consumer that turns attachments into
 * objects of its own can say, for the message page, what became of each.
 *
 * A consumer that does not implement it is exactly what it was — its link
 * is shown and nothing more is said under it.
 */
interface ReportsAttachmentFates
{
    /**
     * What became of each attachment the link covers.
     *
     * Answers for the viewer: `$role` and `$linkedMemberIds` are the same
     * pair `MessageConsumerInterface::canRead()` takes, and a consumer that
     * would name or link an object the viewer may not see leaves `$url` and
     * `$targetLabel` null and says the state alone.
     *
     * @param array<int, int> $linkedMemberIds
     * @return list<AttachmentFate> one per attachment the consumer has
     *         something to say about, in the message's own order
     */
    public function attachmentFates(
        InboundMessage $message,
        MessageLink $link,
        string $role,
        array $linkedMemberIds
    ): array;
}
