<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What a consumer made of ONE attachment of a message it is linked to,
 * for the line the message page shows under that link (#1406).
 *
 * **Why it exists.** A message can be linked to Finance and yet produce no
 * receipt — finance already held the file, or somebody deleted it on
 * purpose — and that is correct behaviour that looked exactly like a
 * failure: the message said « rattaché », the receipts screen was empty,
 * nothing explained it. The consumer is the only one that knows, and
 * `inbound_mail` never reads another module's tables, so the answer comes
 * through `Api\ReportsAttachmentFates`.
 *
 * The state is one of the constants below, so the screen decides nothing
 * about wording it cannot know; `$label` is the consumer's own sentence.
 * `$url` and `$targetLabel` are filled only when the viewer may see what
 * they point to — without that right the line says the state and nothing
 * else, not even the name of the account.
 */
final class AttachmentFate
{
    /** A receipt was created from this attachment. */
    public const CREATED = 'created';

    /** The consumer already held an identical document: nothing was created. */
    public const ALREADY_PRESENT = 'already_present';

    /** An identical document was deleted on purpose and is not brought back. */
    public const DELETED = 'deleted';

    /** The consumer refused to file it (the journal says why). */
    public const REFUSED = 'refused';

    /** The file could not be read. */
    public const UNREADABLE = 'unreadable';

    /** Not looked at yet. */
    public const PENDING = 'pending';

    public function __construct(
        public readonly int $attachmentId,
        public readonly string $state,
        public readonly string $label,
        public readonly ?string $url = null,
        public readonly ?string $targetLabel = null
    ) {
    }
}
